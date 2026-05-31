<?php

declare(strict_types=1);

namespace Oak\Engine\Installer {
    function createDirectoryTree(string $directory, int $mode = 0o755): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        $directoriesToCreate = [];
        $currentDirectory = $directory;
        while (!is_dir($currentDirectory)) {
            $directoriesToCreate[] = $currentDirectory;
            $parentDirectory = dirname($currentDirectory);
            if ($parentDirectory === $currentDirectory) {
                break;
            }

            $currentDirectory = $parentDirectory;
        }

        foreach (array_reverse($directoriesToCreate) as $directoryToCreate) {
            if (!mkdir($directoryToCreate, $mode) && !is_dir($directoryToCreate)) {
                return false;
            }

            if (!chmod($directoryToCreate, $mode)) {
                return false;
            }
        }

        return true;
    }

    final class InstallUuidManager
    {
        public function ensureEnvLocalInstallUuid(string $envPath, bool $replace = false): string
        {
            $content = '';
            if (is_file($envPath)) {
                $existingContent = file_get_contents($envPath);
                if (!is_string($existingContent)) {
                    throw new \RuntimeException(sprintf('Unable to read "%s".', $envPath));
                }

                $content = $existingContent;
            }

            $updated = $this->upsertInstallUuid($content, $replace);
            if (!$updated['changed']) {
                return $updated['uuid'];
            }

            $directory = dirname($envPath);
            if (!createDirectoryTree($directory, 0o755)) {
                throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
            }

            if (false === file_put_contents($envPath, $updated['content'])) {
                throw new \RuntimeException(sprintf('Unable to write "%s".', $envPath));
            }

            return $updated['uuid'];
        }

        /**
         * @return array{content: string, uuid: string, changed: bool}
         */
        public function upsertInstallUuid(string $content, bool $replace = false): array
        {
            $normalizedContent = str_replace(["\r\n", "\r"], "\n", $content);
            $existingUuid = $this->extractInstallUuid($normalizedContent);

            if (null !== $existingUuid && !$replace) {
                return [
                    'content' => $normalizedContent,
                    'uuid' => $existingUuid,
                    'changed' => false,
                ];
            }

            $uuid = $this->generateUuidV7Like();
            $uuidLine = 'INSTALL_UUID='.$uuid;
            $pattern = '/^\s*#?\s*INSTALL_UUID\s*=.*$/m';

            if (1 === preg_match($pattern, $normalizedContent)) {
                $updatedContent = (string) preg_replace($pattern, $uuidLine, $normalizedContent, 1);
            } else {
                $updatedContent = rtrim($normalizedContent, "\n");
                $updatedContent = '' === $updatedContent ? $uuidLine."\n" : $updatedContent."\n".$uuidLine."\n";
            }

            return [
                'content' => $updatedContent,
                'uuid' => $uuid,
                'changed' => $updatedContent !== $normalizedContent,
            ];
        }

        private function extractInstallUuid(string $content): ?string
        {
            if (1 !== preg_match('/^\s*INSTALL_UUID\s*=\s*("?)([0-9a-fA-F-]+)\1\s*$/m', $content, $matches)) {
                return null;
            }

            $uuid = strtolower($matches[2]);

            return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)
                ? $uuid
                : null;
        }

        private function generateUuidV7Like(): string
        {
            $timestamp = (int) floor(microtime(true) * 1000);
            $bytes = random_bytes(16);

            for ($index = 5; $index >= 0; --$index) {
                $bytes[$index] = chr($timestamp & 0xFF);
                $timestamp >>= 8;
            }

            $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
            $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

            $hex = bin2hex($bytes);

            return sprintf(
                '%s-%s-%s-%s-%s',
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 12, 4),
                substr($hex, 16, 4),
                substr($hex, 20, 12),
            );
        }
    }

    final readonly class ProjectPackageApiClient
    {
        public function __construct(
            private string $baseUrl,
            private string $packageType = 'runner',
            private string $installUuid = '',
            private string $projectApiToken = '',
        ) {
        }

        /**
         * @return list<array{
         *     package_type: string,
         *     package_id: string,
         *     version: string,
         *     channel: string,
         *     package_name: string,
         *     archive_size: int,
         *     archive_sha256: string,
         *     download_url: string,
         *     composer: array<string, mixed>
         * }>
         */
        public function listPackages(): array
        {
            $response = $this->request([
                'type' => $this->packageType,
            ]);
            $packages = $response['packages'] ?? null;
            if (!is_array($packages)) {
                throw new \RuntimeException('Project package API returned no package list.');
            }

            $normalized = [];
            foreach ($packages as $package) {
                if (!is_array($package)) {
                    continue;
                }

                $packageType = $this->normalizeScalar($package['package_type'] ?? null, $this->packageType);
                $packageId = $this->normalizeScalar($package['package_id'] ?? null);
                $version = $this->normalizeScalar($package['version'] ?? null);
                $channel = $this->normalizeScalar($package['channel'] ?? null, 'unknown');
                $packageName = $this->normalizeScalar($package['package_name'] ?? null);
                $downloadUrl = $this->resolveDownloadUrl($this->normalizeScalar($package['download_url'] ?? null));
                if ('' === $packageId || '' === $version || '' === $packageName || '' === $downloadUrl) {
                    continue;
                }

                $normalized[] = [
                    'package_type' => $packageType,
                    'package_id' => $packageId,
                    'version' => $version,
                    'channel' => $channel,
                    'package_name' => $packageName,
                    'archive_size' => is_scalar($package['archive_size'] ?? null) ? (int) $package['archive_size'] : 0,
                    'archive_sha256' => $this->normalizeScalar($package['archive_sha256'] ?? null),
                    'download_url' => $downloadUrl,
                    'composer' => $this->normalizeMetadataArray($package['composer'] ?? null),
                ];
            }

            return $normalized;
        }

        /**
         * @return array{
         *     package_type: string,
         *     package_id: string,
         *     version: string,
         *     channel: string,
         *     package_name: string,
         *     archive_size: int,
         *     archive_sha256: string,
         *     download_url: string,
         *     composer: array<string, mixed>
         * }
         */
        public function getPackage(string $packageId, ?string $version = null): array
        {
            foreach ($this->listPackages() as $package) {
                if ($package['package_id'] === $packageId && (null === $version || $package['version'] === $version)) {
                    return $package;
                }
            }

            throw new \RuntimeException(sprintf('%s package "%s" (%s) was not found.', ucfirst($this->packageType), $packageId, $version ?? 'latest'));
        }

        public function downloadPackage(string $packageId, ?string $version = null): string
        {
            $package = $this->getPackage($packageId, $version);
            /** @var non-empty-string $downloadUrl */
            $downloadUrl = $package['download_url'];
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $downloadUrl,
                CURLOPT_POST => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_HTTPHEADER => $this->buildHeaders(),
            ]);

            $response = curl_exec($ch);
            if (false === $response) {
                $error = curl_error($ch);
                curl_close($ch);

                throw new \RuntimeException(sprintf('Package download failed: %s', $error));
            }

            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (200 !== $httpCode) {
                throw new \RuntimeException(sprintf('Package download failed: HTTP %d', $httpCode));
            }

            return (string) $response;
        }

        /**
         * @param array<string, string> $payload
         *
         * @return array<string, mixed>
         */
        private function request(array $payload): array
        {
            /** @var non-empty-string $baseUrl */
            $baseUrl = $this->baseUrl;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $baseUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($this->buildPayload($payload)),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => $this->buildHeaders(true),
            ]);

            $response = curl_exec($ch);
            if (false === $response) {
                $error = curl_error($ch);
                curl_close($ch);

                throw new \RuntimeException(sprintf('Project package API request failed: %s', $error));
            }

            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 400) {
                throw new \RuntimeException(sprintf('Project package API returned HTTP %d.', $httpCode));
            }

            $decoded = json_decode((string) $response, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Project package API returned invalid JSON.');
            }

            return $this->normalizeMetadataArray($decoded);
        }

        /**
         * @param array<string, string> $payload
         *
         * @return array<string, string>
         */
        private function buildPayload(array $payload): array
        {
            $installUuid = trim($this->installUuid);
            if ('' === $installUuid) {
                return $payload;
            }

            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', strtolower($installUuid))) {
                throw new \RuntimeException(sprintf('The install UUID "%s" is invalid.', $installUuid));
            }

            $payload['install_uuid'] = strtolower($installUuid);

            return $payload;
        }

        /**
         * @return list<string>
         */
        private function buildHeaders(bool $acceptJson = false): array
        {
            $headers = [];
            if ($acceptJson) {
                $headers[] = 'Accept: application/json';
            }

            $projectApiToken = trim($this->projectApiToken);
            if ('' !== $projectApiToken) {
                $headers[] = 'Authorization: Bearer '.$projectApiToken;
            }

            $installUuid = trim($this->installUuid);
            if ('' !== $installUuid) {
                $headers[] = 'X-Install-UUID: '.strtolower($installUuid);
            }

            return $headers;
        }

        private function normalizeScalar(mixed $value, string $fallback = ''): string
        {
            if (!is_scalar($value)) {
                return $fallback;
            }

            $normalized = trim((string) $value);

            return '' !== $normalized ? $normalized : $fallback;
        }

        private function resolveDownloadUrl(string $downloadUrl): string
        {
            if ('' === $downloadUrl || str_starts_with($downloadUrl, 'http://') || str_starts_with($downloadUrl, 'https://')) {
                return $downloadUrl;
            }

            return rtrim($this->baseUrl, '/').'/'.ltrim($downloadUrl, '/');
        }

        /**
         * @return array<string, mixed>
         */
        private function normalizeMetadataArray(mixed $value): array
        {
            if (!is_array($value)) {
                return [];
            }

            /** @var array<string, mixed> $normalized */
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string) $key] = $item;
            }

            return $normalized;
        }
    }

    final class ProjectPackageArchiveExtractor
    {
        /**
         * @param array<string> $excludeFolders
         * @param array<string> $excludeFiles
         * @param array<string> $whitelistFolders
         * @param array<string> $whitelistFiles
         *
         * @return array{
         *     extracted: list<string>,
         *     skipped_files: list<string>,
         *     skipped_folders: list<string>
         * }
         */
        public function extractTarGz(
            string $archiveContent,
            string $targetDir,
            array $excludeFolders,
            array $excludeFiles,
            array $whitelistFolders,
            array $whitelistFiles,
        ): array {
            $tempDirectory = sys_get_temp_dir().'/project_package_'.bin2hex(random_bytes(8));
            if (!createDirectoryTree($tempDirectory, 0o755)) {
                throw new \RuntimeException(sprintf('Unable to create temp directory "%s".', $tempDirectory));
            }

            $tarFile = $tempDirectory.'/package.tar';
            $extractionDirectory = $tempDirectory.'/extract';

            try {
                $decoded = gzdecode($archiveContent);
                if (false === $decoded) {
                    throw new \RuntimeException('Failed to decode package archive.');
                }

                if (false === file_put_contents($tarFile, $decoded)) {
                    throw new \RuntimeException(sprintf('Unable to write temp archive "%s".', $tarFile));
                }

                if (!createDirectoryTree($extractionDirectory, 0o755)) {
                    throw new \RuntimeException(sprintf('Unable to create extraction directory "%s".', $extractionDirectory));
                }

                $archive = new \PharData($tarFile);
                $archive->extractTo($extractionDirectory, null, true);

                $sourceDirectory = $this->resolveSourceDirectory($extractionDirectory);

                return $this->copyExtractedDirectory(
                    $sourceDirectory,
                    $targetDir,
                    $excludeFolders,
                    $excludeFiles,
                    $whitelistFolders,
                    $whitelistFiles,
                );
            } finally {
                $this->recursiveDelete($tempDirectory);
            }
        }

        private function resolveSourceDirectory(string $extractionDirectory): string
        {
            $entries = array_values(array_filter(
                scandir($extractionDirectory) ?: [],
                static fn (string $entry): bool => '.' !== $entry && '..' !== $entry,
            ));

            if (1 === count($entries)) {
                $candidate = $extractionDirectory.'/'.$entries[0];
                if (is_dir($candidate)) {
                    return $candidate;
                }
            }

            return $extractionDirectory;
        }

        /**
         * @param array<string> $excludeFolders
         * @param array<string> $excludeFiles
         * @param array<string> $whitelistFolders
         * @param array<string> $whitelistFiles
         *
         * @return array{extracted: list<string>, skipped_files: list<string>, skipped_folders: list<string>}
         */
        private function copyExtractedDirectory(
            string $sourceDir,
            string $targetDir,
            array $excludeFolders,
            array $excludeFiles,
            array $whitelistFolders,
            array $whitelistFiles,
        ): array {
            $extractedFiles = [];
            $skippedFiles = [];
            $skippedFolders = [];
            $hasWhitelistFolders = [] !== $whitelistFolders;
            $hasWhitelistFiles = [] !== $whitelistFiles;
            $targetBasename = basename($targetDir);

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo) {
                    continue;
                }

                $absolutePath = $item->getPathname();
                $relativePath = substr($absolutePath, strlen($sourceDir) + 1);
                if ('' === $relativePath) {
                    continue;
                }

                $relativePathNormalized = str_replace('\\', '/', $relativePath);
                $parentDir = dirname($relativePathNormalized);
                if ('.' === $parentDir) {
                    $parentDir = '';
                }

                $isInWhitelist = false;
                if ($hasWhitelistFolders) {
                    foreach ($whitelistFolders as $whitelistFolder) {
                        $whitelistNormalized = trim(str_replace('\\', '/', $whitelistFolder), '/');
                        $whitelistParent = dirname($whitelistNormalized);
                        $matchPath = $relativePathNormalized;
                        if ($whitelistParent === $targetBasename || '.' === $whitelistParent) {
                            $matchPath = $targetBasename.'/'.$relativePathNormalized;
                        }

                        if ($matchPath === $whitelistNormalized || str_starts_with($matchPath, $whitelistNormalized.'/')) {
                            $isInWhitelist = true;
                            break;
                        }
                    }
                }

                if (!$isInWhitelist && $hasWhitelistFiles && !$item->isDir()) {
                    foreach ($whitelistFiles as $whitelistFile) {
                        $whitelistNormalized = trim(str_replace('\\', '/', $whitelistFile), '/');
                        if ($relativePathNormalized === $whitelistNormalized) {
                            $isInWhitelist = true;
                            break;
                        }
                    }
                }

                if ($isInWhitelist) {
                    if ($item->isDir()) {
                        $skippedFolders[] = $relativePath;
                    } else {
                        $skippedFiles[] = $relativePath;
                    }

                    continue;
                }

                if ($item->isDir()) {
                    foreach ($excludeFolders as $excludeFolder) {
                        $excludeNormalized = trim(str_replace('\\', '/', $excludeFolder), '/');
                        if ($relativePathNormalized === $excludeNormalized || str_starts_with($relativePathNormalized, $excludeNormalized.'/')) {
                            $skippedFolders[] = $relativePath;
                            continue 2;
                        }
                    }

                    $targetPath = rtrim($targetDir, '/').'/'.$relativePath;
                    if (!is_dir($targetPath)) {
                        if (!createDirectoryTree($targetPath, 0o755)) {
                            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $targetPath));
                        }
                    }

                    continue;
                }

                if ('' !== $parentDir) {
                    foreach ($excludeFolders as $excludeFolder) {
                        $excludeNormalized = trim(str_replace('\\', '/', $excludeFolder), '/');
                        if ($parentDir === $excludeNormalized || str_starts_with($parentDir, $excludeNormalized.'/')) {
                            $skippedFiles[] = $relativePath;
                            continue 2;
                        }
                    }
                }

                $fileName = basename($relativePathNormalized);
                foreach ($excludeFiles as $excludeFile) {
                    $excludeNormalized = trim(str_replace('\\', '/', $excludeFile), '/');
                    if ($relativePathNormalized === $excludeNormalized || $fileName === $excludeNormalized) {
                        $skippedFiles[] = $relativePath;
                        continue 2;
                    }
                }

                $targetPath = rtrim($targetDir, '/').'/'.$relativePath;
                $targetDirectoryPath = dirname($targetPath);
                if (!is_dir($targetDirectoryPath)) {
                    if (!createDirectoryTree($targetDirectoryPath, 0o755)) {
                        throw new \RuntimeException(sprintf('Unable to create directory "%s".', $targetDirectoryPath));
                    }
                }

                if (!copy($absolutePath, $targetPath)) {
                    throw new \RuntimeException(sprintf('Unable to copy "%s" to "%s".', $absolutePath, $targetPath));
                }

                $extractedFiles[] = $relativePath;
            }

            return [
                'extracted' => $extractedFiles,
                'skipped_files' => $skippedFiles,
                'skipped_folders' => $skippedFolders,
            ];
        }

        private function recursiveDelete(string $path): void
        {
            if (!file_exists($path)) {
                return;
            }

            if (is_file($path)) {
                unlink($path);

                return;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo) {
                    continue;
                }

                if ($item->isDir()) {
                    rmdir($item->getPathname());

                    continue;
                }

                unlink($item->getPathname());
            }

            rmdir($path);
        }
    }
}

namespace {
    use Oak\Engine\Installer\InstallUuidManager;
    use Oak\Engine\Installer\ProjectPackageApiClient;
    use Oak\Engine\Installer\ProjectPackageArchiveExtractor;

    /**
     * GitInstall - GitHub Repository Installer.
     *
     * Downloads and extracts branches or tags from GitHub repositories.
     * Style inspired by Composer.
     */
    if (PHP_SAPI !== 'cli') {
        session_start();
    } else {
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        if (!isset($_SESSION['lang'])) {
            $_SESSION['lang'] = 'en';
        }
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        return;
    }

    $configPath = __DIR__.'/config.php';
    $loadedConfig = file_exists($configPath) ? require $configPath : require __DIR__.'/config.example.php';
    if (!is_array($loadedConfig)) {
        $loadedConfig = [];
    }
    /** @var array<string, mixed> $config */
    $config = [];
    foreach ($loadedConfig as $k => $v) {
        if (is_string($k)) {
            $config[$k] = $v;
        } elseif (is_int($k)) {
            $config[(string) $k] = $v;
        }
    }

    $langDir = __DIR__.'/lang/';
    $foundLangs = glob($langDir.'*.php');
    /** @var array<string> $availableLangs */
    $availableLangs = (false !== $foundLangs) ? array_map(fn ($f) => basename((string) $f, '.php'), $foundLangs) : [];
    $defaultLang = '';
    if (isset($config['default_language']) && is_scalar($config['default_language'])) {
        $defaultLang = (string) $config['default_language'];
    }
    if ('' === $defaultLang) {
        $defaultLang = 'en';
    }

    if (!isset($_SESSION['lang']) || !is_string($_SESSION['lang'])) {
        $_SESSION['lang'] = $defaultLang;
    }
    $getLangStr = '';
    if (isset($_GET['lang']) && (is_string($_GET['lang']) || is_int($_GET['lang']))) {
        $getLangStr = (string) $_GET['lang'];
    }
    if ('' !== $getLangStr) {
        if (in_array($getLangStr, $availableLangs, true)) {
            $_SESSION['lang'] = $getLangStr;
            // Redirect to remove lang param from URL
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            if (!is_string($requestUri)) {
                $requestUri = '/';
            }
            $cleanUrl = strtok($requestUri, '?');
            $params = $_GET;
            unset($params['lang']);
            if (!empty($params)) {
                $cleanUrl .= '?'.http_build_query($params);
            }
            header('Location: '.$cleanUrl);
            exit;
        }
    }

    $sessionLang = 'en';
    if (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) {
        $sessionLang = $_SESSION['lang'];
    }
    $langFile = $langDir.$sessionLang.'.php';
    $loadedLang = file_exists($langFile) ? require $langFile : [];
    if (!is_array($loadedLang)) {
        $loadedLang = [];
    }
    /** @var array<string, string> $lang */
    $lang = [];
    foreach ($loadedLang as $k => $v) {
        $kStr = (string) $k;
        if (is_scalar($v)) {
            $vStr = (string) $v;
            $lang[$kStr] = $vStr;
        }
    }

    /**
     * @param array<string, string>           $lang
     * @param array<string, string|int|float> $placeholders
     */
    function resolveLangKey(string $key, array $lang, array $placeholders = []): string
    {
        $text = (isset($lang[$key])) ? (string) $lang[$key] : $key;
        foreach ($placeholders as $k => $v) {
            $vStr = (string) $v;
            $text = str_replace(':'.$k, $vStr, (string) $text);
        }

        return (string) $text;
    }

    /**
     * @param array<string, string|int|float> $placeholders
     */
    function __(string $key, array $placeholders = []): string
    {
        global $lang;
        /** @var array<string, string> $lang */
        if (!isset($lang) || !is_array($lang)) {
            return $key;
        }

        return resolveLangKey($key, $lang, $placeholders);
    }

    function extractSemverFromTag(string $tag): ?string
    {
        $normalized = ltrim(trim($tag), 'vV');
        if (1 === preg_match('/^\d+\.\d+\.\d+$/', $normalized)) {
            return $normalized;
        }

        return null;
    }

    function formatPackageSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%0.1f KB', $bytes / 1024);
        }

        return sprintf('%0.1f MB', $bytes / 1024 / 1024);
    }

    function resolveInstalledProjectVersion(string $targetDir): string
    {
        $composerPath = rtrim($targetDir, '/').'/composer.json';
        $decoded = readComposerJsonMetadata($composerPath);
        if ([] !== $decoded) {
            $extra = is_array($decoded['extra'] ?? null) ? $decoded['extra'] : [];
            $runner = is_array($extra['oak-engine-runner'] ?? null) ? $extra['oak-engine-runner'] : [];
            if (isset($runner['version']) && is_scalar($runner['version'])) {
                $version = trim((string) $runner['version']);
                if ('' !== $version) {
                    $channel = '';
                    if (isset($runner['channel']) && is_scalar($runner['channel'])) {
                        $channel = trim((string) $runner['channel']);
                    } elseif (isset($runner['chanel']) && is_scalar($runner['chanel'])) {
                        $channel = trim((string) $runner['chanel']);
                    }

                    return ('' !== $channel) ? sprintf('%s (%s)', $version, $channel) : $version;
                }
            }
        }

        return 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    function readComposerJsonMetadata(string $composerPath): array
    {
        if (!is_file($composerPath)) {
            return [];
        }

        $content = file_get_contents($composerPath);
        if (!is_string($content)) {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    function resolveComposerPackageVersion(string $composerPath): string
    {
        $decoded = readComposerJsonMetadata($composerPath);
        if ([] === $decoded) {
            return '';
        }

        $version = $decoded['version'] ?? null;
        if (!is_scalar($version)) {
            return '';
        }

        return trim((string) $version);
    }

    /**
     * @return list<array{name: string, version: string, channel: string}>
     */
    function resolveInstalledPackages(string $targetDir, string $packageType): array
    {
        $normalizedPackageType = normalizePackageType($packageType);
        $metadataKey = match ($normalizedPackageType) {
            'plugin' => 'oak-engine-plugin',
            'data' => 'oak-engine-data',
            default => 'oak-engine-runner',
        };
        $scanDir = resolvePackageInstallTargetDir($targetDir, $normalizedPackageType);

        if (!is_dir($scanDir)) {
            return [];
        }

        $packages = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scanDir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile() || 'composer.json' !== $item->getBasename()) {
                continue;
            }

            $content = file_get_contents($item->getPathname());
            if (!is_string($content)) {
                continue;
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                continue;
            }

            $extra = is_array($decoded['extra'] ?? null) ? $decoded['extra'] : [];
            $metadata = is_array($extra[$metadataKey] ?? null) ? $extra[$metadataKey] : [];
            if ([] === $metadata) {
                continue;
            }

            $version = '';
            if (isset($metadata['version']) && is_scalar($metadata['version'])) {
                $version = trim((string) $metadata['version']);
            }

            if ('' === $version) {
                continue;
            }

            $channel = 'unknown';
            if (isset($metadata['channel']) && is_scalar($metadata['channel'])) {
                $normalizedChannel = trim((string) $metadata['channel']);
                if ('' !== $normalizedChannel) {
                    $channel = $normalizedChannel;
                }
            } elseif (isset($metadata['chanel']) && is_scalar($metadata['chanel'])) {
                $normalizedChannel = trim((string) $metadata['chanel']);
                if ('' !== $normalizedChannel) {
                    $channel = $normalizedChannel;
                }
            }

            $composerName = '';
            if (isset($decoded['name']) && is_scalar($decoded['name'])) {
                $composerName = trim((string) $decoded['name']);
            }

            $displayName = resolveInstalledPackageDisplayName($composerName, dirname($item->getPathname()), $scanDir);
            $packages[strtolower($displayName.'|'.$version.'|'.$channel)] = [
                'name' => $displayName,
                'version' => $version,
                'channel' => $channel,
            ];
        }

        $packages = array_values($packages);
        usort(
            $packages,
            static fn (array $left, array $right): int => [$left['name'], $left['version'], $left['channel']]
                <=> [$right['name'], $right['version'], $right['channel']],
        );

        return $packages;
    }

    function resolveInstalledPackageDisplayName(string $composerName, string $packageDirectory, string $targetDir): string
    {
        if ('' !== $composerName) {
            $normalizedName = str_replace('\\', '/', $composerName);

            return basename($normalizedName);
        }

        $normalizedTargetDir = rtrim(str_replace('\\', '/', $targetDir), '/');
        $normalizedPackageDirectory = str_replace('\\', '/', $packageDirectory);
        if (str_starts_with($normalizedPackageDirectory, $normalizedTargetDir.'/')) {
            $relativePath = substr($normalizedPackageDirectory, strlen($normalizedTargetDir) + 1);
            if ('' !== $relativePath) {
                return $relativePath;
            }
        }

        return basename($packageDirectory);
    }

    function resolvePackageInstallTargetDir(string $targetDir, string $packageType): string
    {
        $normalizedTargetDir = rtrim($targetDir, '/');

        return match (normalizePackageType($packageType)) {
            'plugin' => $normalizedTargetDir.'/runner',
            'data' => $normalizedTargetDir.'/data',
            default => $normalizedTargetDir,
        };
    }

    function normalizePackageType(mixed $value): string
    {
        if (!is_scalar($value)) {
            return 'runner';
        }

        $normalized = trim((string) $value);

        return in_array($normalized, ['runner', 'plugin', 'data'], true) ? $normalized : 'runner';
    }

    /**
     * @param list<array{
     *     package_type: string,
     *     package_id: string,
     *     version: string,
     *     channel: string,
     *     package_name: string,
     *     archive_size: int,
     *     archive_sha256: string,
     *     download_url: string,
     *     composer: array<string, mixed>
     * }> $packages
     * @param array<string, string> $lang
     */
    function renderPackageListHtml(array $packages, string $packageType, array $lang): string
    {
        if ([] === $packages) {
            return '<li><em>'.resolveLangKey('no_tags_found', $lang).'</em></li>';
        }

        $html = '';
        foreach ($packages as $package) {
            $packageId = $package['package_id'];
            $version = $package['version'];
            $channel = $package['channel'];
            $archiveSize = (int) $package['archive_size'];
            $title = 'runner' === $packageType ? $version : sprintf('%s %s', $packageId, $version);
            $metadataLabel = sprintf('%s · %s', $channel, formatPackageSize($archiveSize));
            $html .= '<li><span><span class="tag-name">'.htmlspecialchars($title).'</span> <span class="commit-sha">'.htmlspecialchars($metadataLabel).'</span></span>';
            $html .= '<form method="post" style="display:inline"><input type="hidden" name="package_type" value="'.htmlspecialchars($packageType).'"><input type="hidden" name="package_id" value="'.htmlspecialchars($packageId).'"><input type="hidden" name="version" value="'.htmlspecialchars($version).'"><button type="submit" name="install" class="btn">'.resolveLangKey('install', $lang).'</button></form></li>';
        }

        return $html;
    }

    /**
     * @param list<array{name: string, version: string, channel: string}> $packages
     * @param array<string, string>                                       $lang
     */
    function renderInstalledPackageListHtml(array $packages, array $lang): string
    {
        if ([] === $packages) {
            return '<em>'.resolveLangKey('none_installed', $lang).'</em>';
        }

        $parts = [];
        foreach ($packages as $package) {
            $metadata = $package['version'];
            if ('' !== $package['channel']) {
                $metadata .= sprintf(' (%s)', $package['channel']);
            }

            $parts[] = '<code>'.htmlspecialchars($package['name']).'</code> <span class="commit-sha">'.htmlspecialchars($metadata).'</span>';
        }

        return implode(', ', $parts);
    }

    /**
     * @param list<array{path: string, package_type: string, metadata: array<string, mixed>}> $composerMetadataSources
     * @param array<string, string>                                                           $lang
     */
    function renderComposerMetadataSourceListHtml(array $composerMetadataSources, array $lang): string
    {
        if ([] === $composerMetadataSources) {
            return '';
        }

        $html = '<div class="success"><strong>'.resolveLangKey('processed_composer_files', $lang).'</strong><ul class="file-list">';
        foreach (array_slice($composerMetadataSources, 0, 20) as $composerMetadataSource) {
            $html .= '<li><code>'.htmlspecialchars($composerMetadataSource['path']).'</code></li>';
        }

        if (count($composerMetadataSources) > 20) {
            $html .= '<li><em>'.resolveLangKey('and_more', $lang, ['count' => (count($composerMetadataSources) - 20)]).'</em></li>';
        }

        return $html.'</ul></div>';
    }

    /**
     * @param array<string, mixed>                            $config
     * @param array<int, array{name: string, commit: string}> $tags
     */
    function resolveInstallerVersion(array $config, array $tags): string
    {
        /** @var string $configured */
        $configured = '';
        if (isset($config['installer_version']) && is_scalar($config['installer_version'])) {
            $configured = trim((string) $config['installer_version']);
        }
        if ('' !== $configured) {
            return $configured;
        }

        $composerVersion = resolveComposerPackageVersion(dirname(__DIR__).'/composer.json');
        if ('' !== $composerVersion) {
            return $composerVersion;
        }

        return 'unknown';
    }

    /**
     * @return array<int, array{name: string, commit: string}>|null
     */
    function normalizeGitHubRepositoryRefsCacheValue(mixed $refs): ?array
    {
        if (!is_array($refs)) {
            return null;
        }

        $normalized = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                return null;
            }

            $name = $ref['name'] ?? null;
            $commit = $ref['commit'] ?? null;
            if ((!is_string($name) && !is_int($name)) || (!is_string($commit) && !is_int($commit))) {
                return null;
            }

            $nameNormalized = trim((string) $name);
            $commitNormalized = trim((string) $commit);
            if ('' === $nameNormalized || '' === $commitNormalized) {
                return null;
            }

            $normalized[] = [
                'name' => $nameNormalized,
                'commit' => $commitNormalized,
            ];
        }

        return $normalized;
    }

    function buildGitHubRepositoryRefsCacheFilePath(string $cacheDir, string $repo): string
    {
        return rtrim($cacheDir, '/').'/github-repository-refs-'.sha1($repo).'.php';
    }

    /**
     * @return array{tags: array<int, array{name: string, commit: string}>, branches: array<int, array{name: string, commit: string}>}|null
     */
    function readGitHubRepositoryRefsCache(string $cacheFile, int $ttl = 600): ?array
    {
        if (!is_file($cacheFile)) {
            return null;
        }

        $modifiedAt = filemtime($cacheFile);
        if (false === $modifiedAt || $modifiedAt < time() - $ttl) {
            return null;
        }

        $cached = require $cacheFile;
        if (!is_array($cached)) {
            return null;
        }

        $tags = normalizeGitHubRepositoryRefsCacheValue($cached['tags'] ?? null);
        $branches = normalizeGitHubRepositoryRefsCacheValue($cached['branches'] ?? null);
        if (null === $tags || null === $branches) {
            return null;
        }

        return [
            'tags' => $tags,
            'branches' => $branches,
        ];
    }

    /**
     * @param array{tags: array<int, array{name: string, commit: string}>, branches: array<int, array{name: string, commit: string}>} $refs
     */
    function writeGitHubRepositoryRefsCache(string $cacheFile, array $refs): void
    {
        $directory = dirname($cacheFile);
        if (file_exists($directory) && !is_dir($directory)) {
            throw new RuntimeException('GitHub cache directory cannot be created: '.$directory);
        }

        if (!\Oak\Engine\Installer\createDirectoryTree($directory, 0o755)) {
            throw new RuntimeException('GitHub cache directory cannot be created: '.$directory);
        }

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($refs, true).";\n";
        if (false === file_put_contents($cacheFile, $content, LOCK_EX)) {
            throw new RuntimeException('GitHub cache file cannot be written: '.$cacheFile);
        }
    }

    /**
     * @return array{tags: array<int, array{name: string, commit: string}>, branches: array<int, array{name: string, commit: string}>}
     */
    function getCachedGitHubRepositoryRefs(GitHubClient $client, string $repo, string $cacheDir, int $ttl = 600): array
    {
        $cacheFile = buildGitHubRepositoryRefsCacheFilePath($cacheDir, $repo);
        $cached = readGitHubRepositoryRefsCache($cacheFile, $ttl);
        if (null !== $cached) {
            return $cached;
        }

        try {
            $refs = [
                'tags' => $client->getTags($repo),
                'branches' => $client->getBranches($repo),
            ];
        } catch (RuntimeException) {
            return [
                'tags' => [],
                'branches' => [],
            ];
        }

        try {
            writeGitHubRepositoryRefsCache($cacheFile, $refs);
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $updates
     */
    function writeConfigValues(string $configPath, array $updates): bool
    {
        if (!file_exists($configPath)) {
            return false;
        }

        $current = require $configPath;
        if (!is_array($current)) {
            /** @var array<string, mixed> $current */
            $current = [];
        }

        /** @var array<string, mixed> $merged */
        $merged = array_replace($current, $updates);
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($merged, true).";\n";

        return false !== file_put_contents($configPath, $content);
    }

    function canUpdateInstallerToTag(string $currentInstallerVersion, string $targetTag): bool
    {
        $currentSemver = extractSemverFromTag($currentInstallerVersion);
        $targetSemver = extractSemverFromTag($targetTag);

        if (null === $currentSemver || null === $targetSemver) {
            return true;
        }

        if (version_compare($targetSemver, $currentSemver, '>=')) {
            return true;
        }

        return false;
    }

    function normalizeRelativePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    function isAllowedUpdaterFile(string $relativePath): bool
    {
        $relativePath = normalizeRelativePath($relativePath);

        if ('index.php' === $relativePath || '.htaccess' === $relativePath || 'config.example.php' === $relativePath) {
            return true;
        }

        return 1 === preg_match('/^lang\/.+\.php$/i', $relativePath);
    }

    /**
     * @return array{updated_files: array<string>, skipped_files: array<string>}
     */
    function updateUpdaterFromTag(
        GitHubClient $client,
        string $repository,
        string $tag,
        string $updaterSourcePath,
        string $destinationDir,
    ): array {
        $zipContent = $client->downloadArchive($repository, $tag, 'tag');
        $tempFile = (string) tempnam(sys_get_temp_dir(), 'updater_self_');
        file_put_contents($tempFile, $zipContent);

        $updatedFiles = [];
        $skippedFiles = [];

        try {
            $zip = new ZipArchive();
            if (true !== $zip->open($tempFile)) {
                throw new RuntimeException('Failed to open update ZIP');
            }

            $tempExtractDir = sys_get_temp_dir().'/updater_self_'.uniqid();
            if (!\Oak\Engine\Installer\createDirectoryTree($tempExtractDir, 0o755)) {
                throw new RuntimeException('Temp update directory cannot be created: '.$tempExtractDir);
            }
            $zip->extractTo($tempExtractDir);
            $zip->close();

            $dirs = glob($tempExtractDir.'/*', GLOB_ONLYDIR);
            if (false === $dirs || empty($dirs)) {
                throw new RuntimeException('No directory in update archive');
            }

            $archiveRoot = (string) $dirs[0];
            $sourceDir = $archiveRoot;
            if ('' !== $updaterSourcePath) {
                $sourceDir .= '/'.normalizeRelativePath($updaterSourcePath);
            }

            if (!is_dir($sourceDir)) {
                throw new RuntimeException('Updater source path not found in archive: '.$updaterSourcePath);
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo) {
                    continue;
                }
                $pathname = $item->getPathname();
                $absolutePath = (is_string($pathname) || is_int($pathname)) ? (string) $pathname : '';
                if ('' === $absolutePath) {
                    continue;
                }
                if ($item->isDir()) {
                    continue;
                }

                $relativePath = normalizeRelativePath(substr($absolutePath, strlen($sourceDir) + 1));

                if (!isAllowedUpdaterFile($relativePath)) {
                    $skippedFiles[] = $relativePath;
                    continue;
                }

                if (str_contains($relativePath, '..')) {
                    $skippedFiles[] = $relativePath;
                    continue;
                }

                $targetPath = rtrim($destinationDir, '/').'/'.$relativePath;
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    if (!\Oak\Engine\Installer\createDirectoryTree($targetDir, 0o755)) {
                        throw new RuntimeException('Target directory cannot be created: '.$targetDir);
                    }
                }

                if (!copy((string) $item->getPathname(), $targetPath)) {
                    throw new RuntimeException('Failed to update file: '.$relativePath);
                }

                $updatedFiles[] = $relativePath;
            }

            recursiveDelete($tempExtractDir);
        } finally {
            @unlink($tempFile);
        }

        return [
            'updated_files' => $updatedFiles,
            'skipped_files' => $skippedFiles,
        ];
    }

    class GitHubClient
    {
        private readonly string $baseUrl;
        private readonly string $userAgent;

        public function __construct(string $baseUrl, private readonly string $token, private readonly string $installerVersion = 'unknown')
        {
            $this->baseUrl = rtrim($baseUrl, '/');
            $this->userAgent = 'SymfonyGitInstaller/'.$this->installerVersion;
        }

        /**
         * @return array<mixed>
         */
        private function request(string $endpoint): array
        {
            $url = $this->baseUrl.$endpoint;
            $headers = ['User-Agent: '.$this->userAgent, 'Accept: application/vnd.github.v3+json'];
            if ('' !== $this->token) {
                $headers[] = 'Authorization: Bearer '.$this->token;
            }
            $ch = curl_init();
            /** @var array<int, mixed> $options */
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
            ];
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            if (false === $response) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException("CURL Error: {$error}");
            }
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (403 === $httpCode && '' !== $this->token) {
                $headers = ['User-Agent: '.$this->userAgent, 'Accept: application/vnd.github.v3+json'];
                $ch = curl_init();
                $options[CURLOPT_HTTPHEADER] = $headers;
                curl_setopt_array($ch, $options);
                $response = curl_exec($ch);
                if (false !== $response) {
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                }
                curl_close($ch);
            }

            if ($httpCode >= 400) {
                throw new RuntimeException("GitHub API Error: HTTP {$httpCode}");
            }

            if (is_string($response)) {
                $decoded = json_decode($response, true);
            } else {
                $decoded = null;
            }

            if (!is_array($decoded)) {
                return [];
            }

            return $decoded;
        }

        /**
         * @return array<int, array{name: string, commit: string}>
         */
        public function getBranches(string $repo): array
        {
            $branches = [];
            $page = 1;
            do {
                $response = $this->request("/repos/{$repo}/branches?per_page=100&page={$page}");
                if (empty($response)) {
                    break;
                }
                foreach ($response as $branch) {
                    if (!is_array($branch)) {
                        continue;
                    }
                    if (isset($branch['name']) && (is_string($branch['name']) || is_int($branch['name']))) {
                        $branchName = trim((string) $branch['name']);
                    } else {
                        $branchName = '';
                    }

                    $commitData = $branch['commit'] ?? null;
                    if (is_array($commitData)) {
                        $sha = '';
                        if (isset($commitData['sha']) && (is_string($commitData['sha']) || is_int($commitData['sha']))) {
                            $sha = (string) $commitData['sha'];
                        }
                        if ('' !== $branchName && '' !== $sha) {
                            $branches[] = ['name' => $branchName, 'commit' => $sha];
                        }
                    }
                }
                ++$page;
            } while (100 === count($response));

            return $branches;
        }

        /**
         * @return array<int, array{name: string, commit: string}>
         */
        public function getTags(string $repo): array
        {
            $tags = [];
            $page = 1;
            do {
                $response = $this->request("/repos/{$repo}/tags?per_page=100&page={$page}");
                if (empty($response)) {
                    break;
                }
                foreach ($response as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }
                    if (isset($tag['name']) && (is_string($tag['name']) || is_int($tag['name']))) {
                        $tagName = trim((string) $tag['name']);
                    } else {
                        $tagName = '';
                    }

                    $commitData = $tag['commit'] ?? null;
                    if (is_array($commitData)) {
                        $sha = '';
                        if (isset($commitData['sha']) && (is_string($commitData['sha']) || is_int($commitData['sha']))) {
                            $sha = (string) $commitData['sha'];
                        }
                        if ('' !== $tagName && '' !== $sha) {
                            $tags[] = ['name' => $tagName, 'commit' => $sha];
                        }
                    }
                }
                ++$page;
            } while (100 === count($response));

            return $tags;
        }

        public function downloadArchive(string $repo, string $ref, string $refType = 'branch'): string
        {
            $url = $this->baseUrl."/repos/{$repo}/zipball/{$ref}";
            $headers = ['User-Agent: '.$this->userAgent];
            if ('' !== $this->token) {
                $headers[] = 'Authorization: Bearer '.$this->token;
            }
            $ch = curl_init();
            /** @var array<int, mixed> $options */
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 300,
            ];
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            if (false === $response) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException("CURL Error: {$error}");
            }
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // If request with token failed with 403, retry without token (for public repos)
            if (403 === $httpCode && '' !== $this->token) {
                $headers = ['User-Agent: '.$this->userAgent];
                $ch = curl_init();
                $options[CURLOPT_HTTPHEADER] = $headers;
                curl_setopt_array($ch, $options);
                $response = curl_exec($ch);
                if (false !== $response) {
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                }
                curl_close($ch);
            }

            if (200 !== $httpCode) {
                throw new RuntimeException("Download failed: HTTP {$httpCode}");
            }

            return is_string($response) ? $response : '';
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $versionMeta
     */
    function handleAuthentication(array $config, bool $showVersionsBeforeLogin = false, array $versionMeta = []): void
    {
        /** @var string $password */
        $password = '';
        if (isset($config['password']) && is_scalar($config['password'])) {
            $password = (string) $config['password'];
        }

        if ('' === $password) {
            return;
        }

        if (isset($_GET['logout'])) {
            if (PHP_SAPI !== 'cli') {
                session_destroy();
                session_start();
            }
            $_SESSION = [];
            header('Location: ?');
            exit;
        }

        if (isset($_SESSION['gitinstall_authenticated']) && true === $_SESSION['gitinstall_authenticated']) {
            return;
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['password']) && is_string($_POST['password'])) {
            $inputPassword = (string) $_POST['password'];

            if (password_verify($inputPassword, $password) || hash_equals($password, $inputPassword)) {
                $_SESSION['gitinstall_authenticated'] = true;
                $_SESSION['gitinstall_auth_time'] = time();
                header('Location: ?');
                exit;
            }

            renderLoginForm(__('incorrect_password'), $showVersionsBeforeLogin ? $versionMeta : []);
            exit;
        }

        renderLoginForm('', $showVersionsBeforeLogin ? $versionMeta : []);
        exit;
    }

    /**
     * @param array<string, mixed> $versionMeta
     */
    function renderLoginForm(string $error = '', array $versionMeta = []): void
    {
        global $lang;
        /** @var array<string, string> $langForLogin */
        $langForLogin = (isset($lang) && is_array($lang)) ? $lang : [];

        $errorHtml = ('' !== $error) ? '<div class="error">'.htmlspecialchars((string) $error).'</div>' : '';
        $text_please_enter_password = resolveLangKey('please_enter_password', $langForLogin);
        $text_password_placeholder = resolveLangKey('password_placeholder', $langForLogin);
        $text_login = resolveLangKey('login', $langForLogin);

        $versionInfoHtml = '';
        $installerVersionStr = '';
        if (isset($versionMeta['installer_version']) && is_scalar($versionMeta['installer_version'])) {
            $installerVersionStr = (string) $versionMeta['installer_version'];
        }
        $projectVersionStr = '';
        if (isset($versionMeta['project_version']) && is_scalar($versionMeta['project_version'])) {
            $projectVersionStr = (string) $versionMeta['project_version'];
        }
        $installerVersion = htmlspecialchars((string) $installerVersionStr);
        $projectVersion = htmlspecialchars((string) $projectVersionStr);
        if (isset($versionMeta['installer_version']) || isset($versionMeta['project_version'])) {
            $versionInfoHtml = '<div class="repo-info" style="margin-top:15px">'
                .'<strong>'.__('updater_version').':</strong> <code>'.$installerVersion.'</code><br>'
                .'<strong>'.__('runner_version').':</strong> <code>'.$projectVersion.'</code>'
                .'</div>';
        }

        $content = <<<HTML
<div class="login-form">
    <p>{$text_please_enter_password}</p>
    {$errorHtml}
    <form method="post">
        <input type="password" name="password" placeholder="{$text_password_placeholder}" autofocus>
        <button type="submit" class="btn">{$text_login}</button>
    </form>
    {$versionInfoHtml}
</div>
HTML;
        echo renderPage(__('title'), $content, null, null, false);
        exit;
    }

    /**
     * @return array{deleted_count: int, errors: array<string>}
     */
    function clearCacheDirectory(string $cacheDir): array
    {
        $result = ['deleted_count' => 0, 'errors' => []];

        if (!is_dir($cacheDir)) {
            return $result;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $pathname = $item->getPathname();
            $path = (is_string($pathname) || is_int($pathname)) ? (string) $pathname : '';
            if ('' === $path) {
                continue;
            }
            try {
                if ($item->isDir()) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                    ++$result['deleted_count'];
                }
            } catch (Exception $e) {
                $result['errors'][] = $path.': '.$e->getMessage();
            }
        }

        @rmdir($cacheDir);

        return $result;
    }

    /**
     * @param array<string> $whitelistFolders
     * @param array<string> $whitelistFiles
     *
     * @return array{deleted_count: int, preserved: array<string>}
     */
    function cleanTargetDirectory(string $targetDir, array $whitelistFolders, array $whitelistFiles): array
    {
        $preserved = [];
        $deletedCount = 0;

        if (!is_dir($targetDir)) {
            return ['deleted_count' => 0, 'preserved' => []];
        }

        $preservePaths = [];

        // Whitelist entries like "public/update" should match "update" when target is ".../public"
        $targetBasename = basename($targetDir);

        foreach ($whitelistFolders as $folder) {
            $folderNormalized = trim(str_replace('\\', '/', $folder), '/');
            $folderBasename = basename($folderNormalized);
            $folderParent = dirname($folderNormalized);

            // Match if whitelist parent matches target dir name, or try direct path
            if ($folderParent === $targetBasename || '.' === $folderParent) {
                $fullPath = rtrim($targetDir, '/').'/'.$folderBasename;
                if (is_dir($fullPath)) {
                    $preservePaths[] = $fullPath;
                }
            } else {
                $fullPath = rtrim($targetDir, '/').'/'.$folderNormalized;
                if (is_dir($fullPath)) {
                    $preservePaths[] = $fullPath;
                }
            }
        }

        foreach ($whitelistFiles as $file) {
            $fileNormalized = trim(str_replace('\\', '/', $file), '/');
            $fileBasename = basename($fileNormalized);
            $fileParent = dirname($fileNormalized);

            if ($fileParent === $targetBasename || '.' === $fileParent) {
                $fullPath = rtrim($targetDir, '/').'/'.$fileBasename;
                if (is_file($fullPath)) {
                    $preservePaths[] = $fullPath;
                }
            } else {
                $fullPath = rtrim($targetDir, '/').'/'.$fileNormalized;
                if (is_file($fullPath)) {
                    $preservePaths[] = $fullPath;
                }
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $pathname = $item->getPathname();
            $path = (is_string($pathname) || is_int($pathname)) ? (string) $pathname : '';
            if ('' === $path) {
                continue;
            }

            $shouldPreserve = false;
            foreach ($preservePaths as $preservePath) {
                if ($path === $preservePath || str_starts_with($path, (string) $preservePath.'/')) {
                    $shouldPreserve = true;
                    break;
                }
            }

            if (!$shouldPreserve) {
                if ($item->isDir()) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                    ++$deletedCount;
                }
            } else {
                $preserved[] = substr($path, strlen($targetDir) + 1);
            }
        }

        return ['deleted_count' => $deletedCount, 'preserved' => $preserved];
    }

    /**
     * @param array<string> $excludeFolders
     * @param array<string> $excludeFiles
     * @param array<string> $whitelistFolders
     * @param array<string> $whitelistFiles
     *
     * @return array{extracted: array<string>, skipped_files: array<string>, skipped_folders: array<string>}
     */
    function extractZip(string $zipContent, string $targetDir, array $excludeFolders, array $excludeFiles, array $whitelistFolders, array $whitelistFiles): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'gitinstall_');
        file_put_contents($tempFile, $zipContent);
        try {
            $zip = new ZipArchive();
            if (true !== $zip->open($tempFile)) {
                throw new RuntimeException('Failed to open ZIP');
            }
            $tempExtractDir = sys_get_temp_dir().'/gitinstall_'.uniqid();
            if (!\Oak\Engine\Installer\createDirectoryTree($tempExtractDir, 0o755)) {
                throw new RuntimeException('Temp extract directory cannot be created: '.$tempExtractDir);
            }
            $zip->extractTo($tempExtractDir);
            $zip->close();
            $dirs = glob($tempExtractDir.'/*', GLOB_ONLYDIR);
            if (empty($dirs)) {
                throw new RuntimeException('No directory in archive');
            }
            $sourceDir = $dirs[0];
            $extractedFiles = [];
            $skippedFiles = [];
            $skippedFolders = [];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            $hasWhitelistFolders = !empty($whitelistFolders);
            $hasWhitelistFiles = !empty($whitelistFiles);
            $targetBasename = basename($targetDir);

            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo) {
                    continue;
                }
                $pathname = $item->getPathname();
                $absolutePath = (is_string($pathname) || is_int($pathname)) ? (string) $pathname : '';
                if ('' === $absolutePath) {
                    continue;
                }
                $relativePath = substr($absolutePath, strlen($sourceDir) + 1);
                $relativePathNormalized = str_replace('\\', '/', $relativePath);
                $parentDir = dirname($relativePathNormalized);
                if ('.' === $parentDir) {
                    $parentDir = '';
                }

                // Check if this path is in whitelist (should be skipped to preserve existing)
                $isInWhitelist = false;

                if ($hasWhitelistFolders) {
                    foreach ($whitelistFolders as $wlFolder) {
                        $wlNormalized = trim(str_replace('\\', '/', $wlFolder), '/');
                        $wlBasename = basename($wlNormalized);
                        $wlParent = dirname($wlNormalized);

                        // Match relative path against whitelist (accounting for parent dir)
                        $matchPath = $relativePathNormalized;
                        if ($wlParent === $targetBasename || '.' === $wlParent) {
                            $matchPath = $targetBasename.'/'.$relativePathNormalized;
                        }

                        if ($matchPath === $wlNormalized || str_starts_with($matchPath, $wlNormalized.'/')) {
                            $isInWhitelist = true;
                            break;
                        }
                    }
                }

                if (!$isInWhitelist && $hasWhitelistFiles && !$item->isDir()) {
                    foreach ($whitelistFiles as $wlFile) {
                        $wlNormalized = trim(str_replace('\\', '/', $wlFile), '/');
                        if ($relativePathNormalized === $wlNormalized) {
                            $isInWhitelist = true;
                            break;
                        }
                    }
                }

                // Skip whitelisted items (preserve existing)
                if ($isInWhitelist) {
                    if ($item->isDir()) {
                        $skippedFolders[] = $relativePath;
                    } else {
                        $skippedFiles[] = $relativePath;
                    }
                    continue;
                }

                // Always check exclude folders/files
                if ($item->isDir()) {
                    foreach ($excludeFolders as $excludeFolder) {
                        $excludeNormalized = trim(str_replace('\\', '/', $excludeFolder), '/');
                        if ($relativePathNormalized === $excludeNormalized || str_starts_with($relativePathNormalized, $excludeNormalized.'/')) {
                            $skippedFolders[] = $relativePath;
                            continue 2;
                        }
                    }
                    $targetPath = rtrim($targetDir, '/').'/'.$relativePath;
                    if (!is_dir($targetPath)) {
                        if (!\Oak\Engine\Installer\createDirectoryTree($targetPath, 0o755)) {
                            throw new RuntimeException('Target directory cannot be created: '.$targetPath);
                        }
                    }
                    continue;
                }

                // Check if parent dir is in exclude list
                if ('' !== $parentDir) {
                    foreach ($excludeFolders as $excludeFolder) {
                        $excludeNormalized = trim(str_replace('\\', '/', $excludeFolder), '/');
                        if ($parentDir === $excludeNormalized || str_starts_with($parentDir, $excludeNormalized.'/')) {
                            $skippedFiles[] = $relativePath;
                            continue 2;
                        }
                    }
                }

                // Check exclude files
                $fileName = basename($relativePathNormalized);
                foreach ($excludeFiles as $excludeFile) {
                    $excludeNormalized = trim(str_replace('\\', '/', $excludeFile), '/');
                    if ($relativePathNormalized === $excludeNormalized || $fileName === $excludeNormalized) {
                        $skippedFiles[] = $relativePath;
                        continue 2;
                    }
                }

                // Extract file
                $targetPath = rtrim($targetDir, '/').'/'.$relativePath;
                $targetDirPath = dirname($targetPath);
                if (!is_dir($targetDirPath)) {
                    if (!\Oak\Engine\Installer\createDirectoryTree($targetDirPath, 0o755)) {
                        throw new RuntimeException('Target directory cannot be created: '.$targetDirPath);
                    }
                }
                copy($item->getPathname(), $targetPath);
                $extractedFiles[] = $relativePath;
            }

            recursiveDelete($tempExtractDir);

            return ['extracted' => $extractedFiles, 'skipped_files' => $skippedFiles, 'skipped_folders' => $skippedFolders];
        } finally {
            unlink($tempFile);
        }
    }

    function recursiveDelete(string $path): void
    {
        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo) {
                    continue;
                }
                if ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
            rmdir($path);
        }
    }

    /**
     * @return array{app_env: string, current_db: ?string, databases: array<int, array{id: string, url: string, active: bool}>, install_uuid: ?string, raw_content: string}
     */
    function parseEnvLocal(string $envPath): array
    {
        $result = [
            'app_env' => 'prod',
            'current_db' => null,
            'databases' => [],
            'install_uuid' => null,
            'raw_content' => '',
        ];

        if (!file_exists($envPath)) {
            return $result;
        }

        $content = file_get_contents($envPath);
        if (false === $content) {
            return $result;
        }
        $result['raw_content'] = $content;
        $lines = explode("\n", $result['raw_content']);

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);

            if (preg_match('/^APP_ENV\s*=\s*(dev|prod)/i', $line, $matches)) {
                $result['app_env'] = strtolower($matches[1]);
            }

            if (preg_match('/^#?\s*DATABASE_URL\s*=\s*"([^"]+)"\s*#\s*(.+)$/i', $line, $matches)) {
                $isActive = !str_starts_with(ltrim($lines[$lineNum]), '#');
                $dbId = trim($matches[2]);
                $result['databases'][] = [
                    'id' => $dbId,
                    'url' => $matches[1],
                    'active' => $isActive,
                ];
                if ($isActive) {
                    $result['current_db'] = $dbId;
                }
            }

            if (preg_match('/^\s*INSTALL_UUID\s*=\s*("?)([0-9a-fA-F-]+)\1\s*$/', $line, $matches)) {
                $result['install_uuid'] = strtolower($matches[2]);
            }
        }

        return $result;
    }

    function updateEnvLocal(string $envPath, string $appEnv, string $activeDb): bool
    {
        if (!file_exists($envPath)) {
            return false;
        }

        $content = file_get_contents($envPath);
        if (false === $content) {
            return false;
        }
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => &$line) {
            if (preg_match('/^APP_ENV\s*=\s*(dev|prod)/i', $line)) {
                $line = 'APP_ENV='.$appEnv;
                continue;
            }

            if (preg_match('/^(#?)\s*DATABASE_URL\s*=\s*"[^"]+"\s*#\s*(.+)$/i', $line, $matches)) {
                $isCommented = '#' === $matches[1];
                $dbId = trim($matches[2]);

                if ($dbId === $activeDb && $isCommented) {
                    $line = preg_replace('/^#\s*/', '', $line);
                } elseif ($dbId !== $activeDb && !$isCommented) {
                    $line = '#'.ltrim($line);
                }
            }
        }

        return false !== file_put_contents($envPath, implode("\n", $lines));
    }

    function saveEnvLocalContent(string $envPath, string $content): bool
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);

        return false !== file_put_contents($envPath, $normalized);
    }

    function addDatabaseToEnvLocal(string $envPath, string $dbId, string $dbUrl): bool
    {
        if (!file_exists($envPath)) {
            $defaultContent = "APP_ENV=prod\n";
            $dir = dirname($envPath);
            if (!is_dir($dir)) {
                if (!\Oak\Engine\Installer\createDirectoryTree($dir, 0o755)) {
                    return false;
                }
            }
            if (false === file_put_contents($envPath, $defaultContent)) {
                return false;
            }
        }

        $dbId = trim($dbId);
        $dbUrl = trim($dbUrl);

        if ('' === $dbId || '' === $dbUrl) {
            return false;
        }

        if (1 === preg_match('/["\n\r]/', $dbId) || 1 === preg_match('/[\n\r]/', $dbUrl)) {
            return false;
        }

        $envConfig = parseEnvLocal($envPath);
        foreach ($envConfig['databases'] as $database) {
            if (0 === strcasecmp((string) $database['id'], $dbId)) {
                return false;
            }
        }

        $line = '#DATABASE_URL="'.str_replace('"', '\\"', $dbUrl).'" # '.$dbId;
        $content = rtrim((string) file_get_contents($envPath), "\n")."\n".$line."\n";

        return false !== file_put_contents($envPath, $content);
    }

    function removeDatabaseFromEnvLocal(string $envPath, string $dbId): bool
    {
        if (!file_exists($envPath)) {
            return false;
        }

        $dbId = trim($dbId);
        if ('' === $dbId) {
            return false;
        }

        $rawContent = file_get_contents($envPath);
        if (false === $rawContent) {
            return false;
        }
        $lines = explode("\n", $rawContent);
        $keptLines = [];
        $removed = false;

        foreach ($lines as $line) {
            if (preg_match('/^(#?)\s*DATABASE_URL\s*=\s*"[^"]+"\s*#\s*(.+)$/i', $line, $matches)) {
                $currentId = trim($matches[2]);
                if (0 === strcasecmp($currentId, $dbId)) {
                    $removed = true;
                    continue;
                }
            }

            $keptLines[] = $line;
        }

        if (!$removed) {
            return false;
        }

        return false !== file_put_contents($envPath, implode("\n", $keptLines));
    }

    function updateInstallUuidInEnvLocal(InstallUuidManager $manager, string $envPath, string $installUuid): bool
    {
        $content = '';
        if (file_exists($envPath)) {
            $currentContent = file_get_contents($envPath);
            if (false === $currentContent) {
                return false;
            }
            $content = $currentContent;
        }

        $normalizedUuid = strtolower(trim($installUuid));
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $normalizedUuid)) {
            return false;
        }

        $updated = $manager->upsertInstallUuid($content, true);
        $replacedContent = str_replace('INSTALL_UUID='.$updated['uuid'], 'INSTALL_UUID='.$normalizedUuid, $updated['content']);
        $directory = dirname($envPath);
        if (!\Oak\Engine\Installer\createDirectoryTree($directory, 0o755)) {
            return false;
        }

        return false !== file_put_contents($envPath, $replacedContent);
    }

    /**
     * @return list<string>
     */
    function resolvePackageEnvMetadataKeys(string $packageType): array
    {
        return match (normalizePackageType($packageType)) {
            'plugin' => ['oak-engine-plugin'],
            'data' => ['oak-engine-data', 'oak-engine-plugin'],
            default => ['oak-engine-runner', 'oak-engine-plugin'],
        };
    }

    /**
     * @param array<string, mixed> $composerMetadata
     *
     * @return array<string, mixed>
     */
    function extractPackageEnvConfig(array $composerMetadata, string $packageType): array
    {
        $extra = is_array($composerMetadata['extra'] ?? null) ? $composerMetadata['extra'] : [];
        foreach (resolvePackageEnvMetadataKeys($packageType) as $metadataKey) {
            $metadata = is_array($extra[$metadataKey] ?? null) ? $extra[$metadataKey] : [];
            $candidateEnv = is_array($metadata['env'] ?? null) ? $metadata['env'] : [];
            if ([] !== $candidateEnv) {
                /** @var array<string, mixed> $candidateEnv */
                return $candidateEnv;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $composerMetadata
     *
     * @return array{
     *     written: bool,
     *     written_lines: list<string>,
     *     written_keys: list<string>,
     *     skipped_existing_lines: list<string>,
     *     skipped_existing_keys: list<string>
     * }
     */
    function syncPackageEnvToEnvLocalDetailed(string $envPath, array $composerMetadata, string $packageType): array
    {
        $packageEnv = extractPackageEnvConfig($composerMetadata, $packageType);
        if ([] === $packageEnv) {
            return ['written' => false, 'written_lines' => [], 'written_keys' => [], 'skipped_existing_lines' => [], 'skipped_existing_keys' => []];
        }

        $envVarMap = [
            'dir' => 'OAK_DIR',
            'core-bundle-class' => 'OAK_CORE_BUNDLE_CLASS',
            'language-version' => 'OAK_LANGUAGE_VERSION',
            'default-language' => 'OAK_DEFAULT_LANGUAGE',
            'available-languages' => 'OAK_AVAILABLE_LANGUAGES',
            'default-language-redirect' => 'OAK_DEFAULT_LANGUAGE_REDIRECT',
        ];

        /** @var array<string, string> $linesToAppend */
        $linesToAppend = [];
        /** @var array<string, string> $skippedExistingLines */
        $skippedExistingLines = [];
        $content = '';
        if (is_file($envPath)) {
            $existingContent = file_get_contents($envPath);
            if (false === $existingContent) {
                return ['written' => false, 'written_lines' => [], 'written_keys' => [], 'skipped_existing_lines' => [], 'skipped_existing_keys' => []];
            }
            $content = str_replace(["\r\n", "\r"], "\n", $existingContent);
        }

        foreach ($envVarMap as $sourceKey => $targetKey) {
            $value = $packageEnv[$sourceKey] ?? null;
            if (!is_scalar($value)) {
                continue;
            }

            $normalizedValue = trim((string) $value);
            if ('' === $normalizedValue) {
                continue;
            }

            if (1 === preg_match('/^\s*#?\s*'.preg_quote($targetKey, '/').'\s*=.*$/m', $content)) {
                $skippedExistingLines[$targetKey] = $targetKey.'='.$normalizedValue;
                continue;
            }

            $linesToAppend[$targetKey] = $targetKey.'='.$normalizedValue;
        }

        if ([] === $linesToAppend) {
            return [
                'written' => false,
                'written_lines' => [],
                'written_keys' => [],
                'skipped_existing_lines' => array_values($skippedExistingLines),
                'skipped_existing_keys' => array_keys($skippedExistingLines),
            ];
        }

        $directory = dirname($envPath);
        if (!\Oak\Engine\Installer\createDirectoryTree($directory, 0o755)) {
            return ['written' => false, 'written_lines' => [], 'written_keys' => [], 'skipped_existing_lines' => [], 'skipped_existing_keys' => []];
        }

        $updatedContent = rtrim($content, "\n");
        $updatedContent = '' === $updatedContent
            ? implode("\n", $linesToAppend)."\n"
            : $updatedContent."\n".implode("\n", $linesToAppend)."\n";

        if (false === file_put_contents($envPath, $updatedContent)) {
            return ['written' => false, 'written_lines' => [], 'written_keys' => [], 'skipped_existing_lines' => [], 'skipped_existing_keys' => []];
        }

        return [
            'written' => true,
            'written_lines' => array_values($linesToAppend),
            'written_keys' => array_keys($linesToAppend),
            'skipped_existing_lines' => array_values($skippedExistingLines),
            'skipped_existing_keys' => array_keys($skippedExistingLines),
        ];
    }

    /**
     * @param array<string, mixed> $composerMetadata
     */
    function syncPackageEnvToEnvLocal(string $envPath, array $composerMetadata, string $packageType): bool
    {
        return syncPackageEnvToEnvLocalDetailed($envPath, $composerMetadata, $packageType)['written'];
    }

    /**
     * @return list<array{path: string, package_type: string, metadata: array<string, mixed>}>
     */
    function resolveProjectEnvComposerMetadataSources(string $targetDir): array
    {
        $scanDir = rtrim($targetDir, '/');
        $rootComposerPath = $scanDir.'/composer.json';
        /** @var array<string, list<string>> $patternsByPackageType */
        $patternsByPackageType = [
            'runner' => [
                $scanDir.'/runner/*/core/*/*/composer.json',
                $scanDir.'/runner/core/*/*/composer.json',
            ],
            'plugin' => [
                $scanDir.'/runner/*/plugin/*/*/composer.json',
                $scanDir.'/runner/plugin/*/*/composer.json',
            ],
        ];

        /** @var list<array{path: string, package_type: string, metadata: array<string, mixed>}> $runnerMetadataSources */
        $runnerMetadataSources = [];
        /** @var list<array{path: string, package_type: string, metadata: array<string, mixed>}> $pluginMetadataSources */
        $pluginMetadataSources = [];

        if (!is_dir($scanDir.'/runner') && is_file($rootComposerPath)) {
            $rootMetadata = readComposerJsonMetadata($rootComposerPath);
            if ([] !== extractPackageEnvConfig($rootMetadata, 'runner')) {
                $runnerMetadataSources['composer.json'] = [
                    'path' => 'composer.json',
                    'package_type' => 'runner',
                    'metadata' => $rootMetadata,
                ];
            }
        }

        foreach ($patternsByPackageType as $packageType => $patterns) {
            foreach ($patterns as $pattern) {
                $matchedPaths = glob($pattern);
                if (false === $matchedPaths) {
                    continue;
                }

                foreach ($matchedPaths as $matchedPath) {
                    $normalizedPath = str_replace('\\', '/', $matchedPath);
                    $relativePath = substr($normalizedPath, strlen(str_replace('\\', '/', $scanDir)) + 1);
                    if (!is_string($relativePath) || '' === $relativePath) {
                        continue;
                    }

                    $metadata = readComposerJsonMetadata($normalizedPath);
                    if ([] === extractPackageEnvConfig($metadata, $packageType)) {
                        continue;
                    }

                    $metadataSource = [
                        'path' => $relativePath,
                        'package_type' => $packageType,
                        'metadata' => $metadata,
                    ];

                    if ('runner' === $packageType) {
                        $runnerMetadataSources[$relativePath] = $metadataSource;

                        continue;
                    }

                    $pluginMetadataSources[$relativePath] = $metadataSource;
                }
            }
        }

        usort(
            $runnerMetadataSources,
            static fn (array $left, array $right): int => strcmp($left['path'], $right['path']),
        );
        usort(
            $pluginMetadataSources,
            static fn (array $left, array $right): int => strcmp($left['path'], $right['path']),
        );

        return array_merge($runnerMetadataSources, $pluginMetadataSources);
    }

    /**
     * @param list<array{path: string, package_type: string, metadata: array<string, mixed>}> $composerMetadataSources
     *
     * @return array{
     *     written: bool,
     *     written_lines: list<string>,
     *     skipped_existing_lines: list<string>
     * }
     */
    function syncPackageEnvComposerMetadataSourcesToEnvLocalDetailed(string $envPath, array $composerMetadataSources): array
    {
        $writtenLines = [];
        $writtenKeys = [];
        $skippedExistingLines = [];
        $skippedExistingKeys = [];

        foreach ($composerMetadataSources as $composerMetadataSource) {
            $result = syncPackageEnvToEnvLocalDetailed(
                $envPath,
                $composerMetadataSource['metadata'],
                $composerMetadataSource['package_type'],
            );

            foreach ($result['written_lines'] as $writtenLine) {
                if (!in_array($writtenLine, $writtenLines, true)) {
                    $writtenLines[] = $writtenLine;
                }
            }

            foreach ($result['written_keys'] as $writtenKey) {
                if (!in_array($writtenKey, $writtenKeys, true)) {
                    $writtenKeys[] = $writtenKey;
                }
            }

            foreach ($result['skipped_existing_keys'] as $index => $skippedExistingKey) {
                if (in_array($skippedExistingKey, $writtenKeys, true) || in_array($skippedExistingKey, $skippedExistingKeys, true)) {
                    continue;
                }

                $skippedExistingKeys[] = $skippedExistingKey;
                $skippedExistingLines[] = $result['skipped_existing_lines'][$index];
            }
        }

        return [
            'written' => [] !== $writtenLines,
            'written_lines' => $writtenLines,
            'skipped_existing_lines' => $skippedExistingLines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    function resolvePackageEnvComposerMetadata(string $targetDir, string $packageType): array
    {
        foreach (resolveProjectEnvComposerMetadataSources($targetDir) as $metadataSource) {
            if (normalizePackageType($metadataSource['package_type']) !== normalizePackageType($packageType)) {
                continue;
            }

            return $metadataSource['metadata'];
        }

        return [];
    }

    /**
     * @return array{html: string, count: int, error: bool, no_migrations?: bool, no_db?: bool}
     */
    function getMigrationsStatus(string $targetDir): array
    {
        global $lang;
        /** @var array<string, string> $langForMigrations */
        $langForMigrations = (isset($lang) && is_array($lang)) ? $lang : [];

        $console = rtrim($targetDir, '/').'/bin/console';
        if (!file_exists($console)) {
            return ['html' => 'bin/console not found', 'count' => 0, 'error' => true];
        }

        $migrationsDir = rtrim($targetDir, '/').'/migrations';
        $foundMigrations = glob($migrationsDir.'/*.php');
        $hasMigrations = is_dir($migrationsDir) && false !== $foundMigrations && count($foundMigrations) > 0;

        if (!$hasMigrations) {
            return [
                'html' => '<span style="color:#6a737d;">'.resolveLangKey('no_migrations_found', $langForMigrations).'</span>',
                'count' => 0,
                'error' => false,
                'no_migrations' => true,
            ];
        }

        $cmd = 'php '.escapeshellarg($console).' doctrine:migrations:status --no-interaction 2>&1';
        $output = (string) shell_exec($cmd);

        // Try to find the line with "New Migrations"
        if (preg_match('/New Migrations:\s+(\d+)/i', $output, $matches)) {
            $count = (int) $matches[1];
            if ($count > 0) {
                return [
                    'html' => '<span style="color:#d73a49; font-weight:bold;">'.$count.' pending</span>',
                    'count' => $count,
                    'error' => false,
                ];
            }

            return [
                'html' => '<span style="color:#28a745; font-weight:bold;">'.resolveLangKey('no_migrations_to_execute', $langForMigrations).'</span>',
                'count' => 0,
                'error' => false,
            ];
        }

        // Handle errors: extract message from JSON if possible, or just take first line
        $trimmedOutput = trim((string) $output);
        if (str_starts_with($trimmedOutput, '{')) {
            $json = json_decode($trimmedOutput, true);
            if (is_array($json) && isset($json['message']) && is_scalar($json['message'])) {
                $msg = (string) $json['message'];
                // If it's a long message with "Message: ...", try to extract the inner message
                if (preg_match('/Message: "(.*?)"/s', $msg, $m)) {
                    $msg = (string) $m[1];
                }

                if (str_contains($msg, 'could not find driver') || str_contains($msg, 'Connection refused')) {
                    return [
                        'html' => '<span style="color:#6a737d;">'.resolveLangKey('migrations_disabled_no_db', $langForMigrations).'</span>',
                        'count' => 0,
                        'error' => false,
                        'no_db' => true,
                    ];
                }

                $errorMsg = (string) strtok($msg, "\n");

                return [
                    'html' => '<span style="color:#d73a49; font-size:0.9em;">Error: '.htmlspecialchars($errorMsg).'</span>',
                    'count' => 0,
                    'error' => true,
                ];
            }
        }

        // Fallback: take first non-empty line
        $lines = explode("\n", $trimmedOutput);
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' !== $line && !str_contains($line, 'CRITICAL') && !str_contains($line, 'DEBUG')) {
                // Check for common Doctrine/PDO errors to make them compact
                if (str_contains($line, 'ExceptionConverter.php') || str_contains($line, 'Connection refused') || str_contains($line, 'could not find driver')) {
                    return [
                        'html' => '<span style="color:#6a737d;">'.resolveLangKey('migrations_disabled_no_db', $langForMigrations).'</span>',
                        'count' => 0,
                        'error' => false,
                        'no_db' => true,
                    ];
                }

                return [
                    'html' => '<span style="color:#d73a49; font-size:0.9em;">Error: '.htmlspecialchars($line).'</span>',
                    'count' => 0,
                    'error' => true,
                ];
            }
        }

        $errorMsgFallback = (string) strtok($trimmedOutput, "\n");

        return [
            'html' => '<span style="color:#d73a49; font-size:0.9em;">Error: '.htmlspecialchars($errorMsgFallback).'</span>',
            'count' => 0,
            'error' => true,
        ];
    }

    function renderPage(string $title, string $content, ?string $error = null, ?string $envPath = null, bool $showLogout = false): string
    {
        global $lang;
        /** @var array<string, string> $langForPage */
        $langForPage = (isset($lang) && is_array($lang)) ? $lang : [];

        $errorHtml = (null !== $error && '' !== $error) ? '<div class="error">'.htmlspecialchars((string) $error).'</div>' : '';
        $text_home = resolveLangKey('home', $langForPage);
        $homeButton = '<a href="?" class="btn btn-secondary btn-small home-btn">'.htmlspecialchars($text_home).'</a>';
        $text_logout = resolveLangKey('logout', $langForPage);
        $logoutButton = $showLogout ? '<form method="get" class="logout-form"><input type="hidden" name="logout" value="1"><button type="submit" class="btn btn-secondary btn-small">'.htmlspecialchars($text_logout).'</button></form>' : '';

        $text_language = resolveLangKey('language', $langForPage);
        $langOptions = '';
        global $availableLangs;
        /** @var array<string> $availableLangs */
        if (is_iterable($availableLangs)) {
            foreach ($availableLangs as $code) {
                $codeStr = (is_scalar($code)) ? (string) $code : '';
                if ('' === $codeStr) {
                    continue;
                }
                $sessionLangVal = (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) ? (string) $_SESSION['lang'] : 'en';
                $selected = $sessionLangVal === $codeStr ? 'selected' : '';
                $langName = (string) strtoupper($codeStr);
                $langOptions .= '<option value="'.htmlspecialchars($codeStr).'" '.$selected.'>'.htmlspecialchars($langName).'</option>';
            }
        }

        $langSwitcherHtml = <<<HTML
<div class="lang-switcher">
    <form method="get" class="lang-form" id="langForm">
        <label>{$text_language}:</label>
        <select name="lang" class="env-select" onchange="document.getElementById('langForm').submit()">
            {$langOptions}
        </select>
    </form>
</div>
HTML;

        $envConfigHtml = '';
        $dbConfigHtml = '';
        $installUuidHtml = '';
        $dashboardNavHtml = '';
        $dashboardScript = '';
        if (null !== $envPath) {
            $envConfig = parseEnvLocal($envPath);
            global $lang;
            /** @var array<string, string> $langForTemplate */
            $langForTemplate = (isset($lang) && is_array($lang)) ? $lang : [];

            $devSelected = 'dev' === $envConfig['app_env'] ? 'selected' : '';
            $prodSelected = 'prod' === $envConfig['app_env'] ? 'selected' : '';

            $dbOptions = '';
            foreach ($envConfig['databases'] as $db) {
                $dbIdVal = (isset($db['id']) && is_scalar($db['id'])) ? (string) $db['id'] : '';
                if ('' === $dbIdVal) {
                    continue;
                }
                $selected = (!empty($db['active'])) ? 'selected' : '';
                $dbOptions .= '<option value="'.htmlspecialchars($dbIdVal).'" '.$selected.'>'.htmlspecialchars($dbIdVal).'</option>';
            }

            $text_mode = resolveLangKey('mode', $langForTemplate);
            $text_database = resolveLangKey('database', $langForTemplate);
            $text_save = resolveLangKey('save', $langForTemplate);
            $text_env_editor = resolveLangKey('env_editor', $langForTemplate);
            $text_env_content = resolveLangKey('env_content', $langForTemplate);
            $text_save_env_file = resolveLangKey('save_env_file', $langForTemplate);
            $text_db_manager = resolveLangKey('database_manager', $langForTemplate);
            $text_db_id = resolveLangKey('database_id', $langForTemplate);
            $text_db_url = resolveLangKey('database_url', $langForTemplate);
            $text_add_database = resolveLangKey('add_database', $langForTemplate);
            $text_remove_database = resolveLangKey('remove_database', $langForTemplate);
            $text_select_database = resolveLangKey('select_database', $langForTemplate);
            $text_dashboard_updates = resolveLangKey('dashboard_updates', $langForTemplate);
            $text_dashboard_environment = resolveLangKey('dashboard_environment', $langForTemplate);
            $text_dashboard_databases = resolveLangKey('dashboard_databases', $langForTemplate);
            $text_dashboard_install_uuid = resolveLangKey('dashboard_install_uuid', $langForTemplate);
            $text_migrations_status = resolveLangKey('migrations_status', $langForTemplate);
            $text_run_migrations = resolveLangKey('run_migrations', $langForTemplate);
            $confirm_run_migrations = resolveLangKey('confirm_run_migrations', $langForTemplate);
            $text_install_uuid = resolveLangKey('install_uuid', $langForTemplate);
            $text_install_uuid_help = resolveLangKey('install_uuid_help', $langForTemplate);
            $text_regenerate_install_uuid = resolveLangKey('regenerate_install_uuid', $langForTemplate);
            $text_install_uuid_saved = resolveLangKey('install_uuid_saved', $langForTemplate);

            $migrationsData = getMigrationsStatus(dirname($envPath));
            /** @var string $migrationsStatusHtml */
            $migrationsStatusHtml = (isset($migrationsData['html']) && is_scalar($migrationsData['html'])) ? (string) $migrationsData['html'] : '';
            $migrationsCount = (isset($migrationsData['count']) && is_scalar($migrationsData['count'])) ? (int) $migrationsData['count'] : 0;
            $migrationsDisabled = (0 === $migrationsCount || !empty($migrationsData['error']) || isset($migrationsData['no_migrations']) || isset($migrationsData['no_db'])) ? 'disabled' : '';

            $dbRemoveOptions = '';
            foreach ($envConfig['databases'] as $db) {
                $dbIdStr = (isset($db['id']) && is_scalar($db['id'])) ? (string) $db['id'] : '';
                if ('' === $dbIdStr) {
                    continue;
                }
                $dbLabel = htmlspecialchars($dbIdStr.((!empty($db['active'])) ? ' (active)' : ''));
                $dbValue = htmlspecialchars($dbIdStr);
                $dbRemoveOptions .= '<option value="'.$dbValue.'">'.$dbLabel.'</option>';
            }

            if ('' === $dbRemoveOptions) {
                $dbRemoveOptions = '<option value="">-</option>';
            }

            $envRawContent = htmlspecialchars((string) $envConfig['raw_content']);
            $currentInstallUuid = htmlspecialchars((string) ($envConfig['install_uuid'] ?? ''));

            $envConfigHtml = <<<HTML
<div class="env-config">
    <form method="post" class="env-form" style="margin-bottom: 20px;">
        <div class="env-row">
            <label>{$text_mode}:</label>
            <select name="app_env" class="env-select">
                <option value="dev" {$devSelected}>Dev</option>
                <option value="prod" {$prodSelected}>Prod</option>
            </select>
        </div>
        <button type="submit" name="save_env" class="btn btn-secondary btn-small">{$text_save}</button>
    </form>

    <h3 style="margin-bottom:10px;">{$text_env_editor}</h3>
    <form method="post">
        <label style="display:block; margin-bottom:6px; font-weight:500; color:#586069;">{$text_env_content}:</label>
        <textarea name="env_content" class="env-textarea">{$envRawContent}</textarea>
        <button type="submit" name="save_env_content" class="btn btn-secondary btn-small" style="margin-top:8px;">{$text_save_env_file}</button>
    </form>
</div>
HTML;

            $dbConfigHtml = <<<HTML
<div class="env-config">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
        <div>
            <h3 style="margin-bottom:5px;">{$text_migrations_status}</h3>
            <div>{$migrationsStatusHtml}</div>
        </div>
        <form method="post" onsubmit="return confirm('{$confirm_run_migrations}')">
            <button type="submit" name="run_migrations" class="btn btn-secondary" {$migrationsDisabled}>{$text_run_migrations}</button>
        </form>
    </div>

    <hr style="margin:15px 0; border:none; border-top:1px solid #d1d5db;">

    <form method="post" class="env-form" style="margin-bottom: 20px;">
        <div class="env-row">
            <label>{$text_database}:</label>
            <select name="database" class="env-select">
                {$dbOptions}
            </select>
        </div>
        <button type="submit" name="save_env" class="btn btn-secondary btn-small">{$text_save}</button>
    </form>

    <hr style="margin:15px 0; border:none; border-top:1px solid #d1d5db;">

    <h3 style="margin-bottom:10px;">{$text_db_manager}</h3>
    <form method="post" class="env-form" style="margin-bottom:8px;">
        <div class="env-row">
            <label>{$text_db_id}:</label>
            <input type="text" name="db_id" class="env-input" required>
        </div>
        <div class="env-row" style="flex:1; min-width:300px;">
            <label>{$text_db_url}:</label>
            <input type="text" name="db_url" class="env-input" style="width:100%;" required>
        </div>
        <button type="submit" name="add_database" class="btn btn-secondary btn-small">{$text_add_database}</button>
    </form>

    <form method="post" class="env-form">
        <div class="env-row">
            <label>{$text_select_database}:</label>
            <select name="remove_db_id" class="env-select">
                {$dbRemoveOptions}
            </select>
        </div>
        <button type="submit" name="remove_database" class="btn btn-small">{$text_remove_database}</button>
    </form>
</div>
HTML;

            $installUuidHtml = <<<HTML
<div class="env-config">
    <h3 style="margin-bottom:10px;">{$text_install_uuid}</h3>
    <p style="margin-bottom:12px; color:#586069;">{$text_install_uuid_help}</p>
    <form method="post" class="env-form" style="margin-bottom: 12px;">
        <div class="env-row" style="flex:1; min-width:360px;">
            <label>{$text_install_uuid}:</label>
            <input type="text" name="install_uuid" value="{$currentInstallUuid}" class="env-input" style="width:100%;" required pattern="[0-9a-fA-F-]{36}">
        </div>
        <button type="submit" name="save_install_uuid" class="btn btn-secondary btn-small">{$text_save}</button>
    </form>
    <form method="post">
        <button type="submit" name="regenerate_install_uuid" class="btn btn-small">{$text_regenerate_install_uuid}</button>
    </form>
</div>
HTML;

            $dashboardNavHtml = <<<HTML
<div class="dashboard-nav">
    <button type="button" class="btn btn-secondary dashboard-btn active" id="btn-updates" onclick="showDashboardSection('updates')">{$text_dashboard_updates}</button>
    <button type="button" class="btn btn-secondary dashboard-btn" id="btn-environment" onclick="showDashboardSection('environment')">{$text_dashboard_environment}</button>
    <button type="button" class="btn btn-secondary dashboard-btn" id="btn-databases" onclick="showDashboardSection('databases')">{$text_dashboard_databases}</button>
    <button type="button" class="btn btn-secondary dashboard-btn" id="btn-install-uuid" onclick="showDashboardSection('install-uuid')">{$text_dashboard_install_uuid}</button>
</div>
HTML;

            $dashboardScript = <<<HTML
<script>
function showDashboardSection(section){
    var updates = document.getElementById('dashboard-updates');
    var env = document.getElementById('dashboard-environment');
    var dbs = document.getElementById('dashboard-databases');
    var installUuid = document.getElementById('dashboard-install-uuid');
    var btnUpdates = document.getElementById('btn-updates');
    var btnEnvironment = document.getElementById('btn-environment');
    var btnDatabases = document.getElementById('btn-databases');
    var btnInstallUuid = document.getElementById('btn-install-uuid');
    if(!updates || !env || !dbs || !installUuid || !btnUpdates || !btnEnvironment || !btnDatabases || !btnInstallUuid){ return; }

    updates.style.display = 'none';
    env.style.display = 'none';
    dbs.style.display = 'none';
    installUuid.style.display = 'none';
    btnUpdates.classList.remove('active');
    btnEnvironment.classList.remove('active');
    btnDatabases.classList.remove('active');
    btnInstallUuid.classList.remove('active');

    if(section === 'environment'){
        env.style.display = 'block';
        btnEnvironment.classList.add('active');
    } else if(section === 'databases'){
        dbs.style.display = 'block';
        btnDatabases.classList.add('active');
    } else if(section === 'install-uuid'){
        installUuid.style.display = 'block';
        btnInstallUuid.classList.add('active');
    } else {
        updates.style.display = 'block';
        btnUpdates.classList.add('active');
    }
}
</script>
HTML;
        }

        global $lang;
        /** @var array<string, string> $langForTitle */
        $langForTitle = (isset($lang) && is_array($lang)) ? $lang : [];
        $appTitle = resolveLangKey('title', $langForTitle);
        $sessionLangForTitle = 'en';
        if (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) {
            $sessionLangForTitle = $_SESSION['lang'];
        }
        $langCode = $sessionLangForTitle;

        return <<<HTML
<!DOCTYPE html>
<html lang="{$langCode}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - GitInstall</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 30px; }
        header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
        .header-left h1 { color: #24292e; margin-bottom: 5px; }
        .header-left h2 { color: #586069; font-size: 1.1em; margin-bottom: 0; font-weight: normal; }
        .header-right { text-align: right; }
        .repo-info { background: #f6f8fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .repo-info code { background: #24292e; color: #fff; padding: 2px 8px; border-radius: 3px; }
        .error { background: #ffeef0; color: #86181d; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #fdc8c8; }
        .success { background: #e6ffed; color: #144620; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #bef5cb; }
        .warning { background: #fff8e6; color: #735c0f; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f0d8a8; }
        .branch-list, .tag-list { list-style: none; }
        .branch-list li, .tag-list li { padding: 10px 15px; border-bottom: 1px solid #e1e4e8; display: flex; justify-content: space-between; align-items: center; }
        .branch-list li:last-child, .tag-list li:last-child { border-bottom: none; }
        .branch-list li:hover, .tag-list li:hover { background: #f6f8fa; }
        .branch-name, .tag-name { font-family: 'SF Mono', Consolas, monospace; color: #0366d6; font-weight: 500; }
        .commit-sha { font-family: 'SF Mono', Consolas, monospace; font-size: 0.85em; color: #6a737d; margin-left: 10px; }
        .btn { background: #28a745; color: white; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 0.9em; }
        .btn:hover { background: #22863a; }
        .btn-secondary { background: #0366d6; }
        .btn-secondary:hover { background: #0056b3; }
        .dashboard-nav { display: flex; gap: 10px; margin-bottom: 15px; }
        .dashboard-btn.active { background: #24292e; }
        .tabs { margin-bottom: 20px; }
        .tab { background: #f6f8fa; border: 1px solid #e1e4e8; padding: 8px 16px; cursor: pointer; border-radius: 6px 6px 0 0; margin-right: 5px; }
        .tab.active { background: white; border-bottom-color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; border: 1px solid #e1e4e8; border-radius: 0 0 6px 6px; }
        .file-list { list-style: none; padding: 15px; max-height: 300px; overflow-y: auto; }
        .file-list li { padding: 5px 0; font-family: monospace; font-size: 0.9em; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #0366d6; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .logout-form { margin-bottom: 10px; display: inline-block; }
        .home-btn { display: inline-block; margin-bottom: 10px; text-decoration: none; }
        .login-form { max-width: 300px; margin: 20px auto; }
        .login-form input[type="password"] { width: 100%; padding: 12px; border: 1px solid #e1e4e8; border-radius: 6px; margin-bottom: 10px; font-size: 1em; }
        .login-form .btn { width: 100%; padding: 12px; }
        .env-config { background: #f6f8fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .env-form { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .env-row { display: flex; align-items: center; gap: 8px; }
        .env-row label { font-weight: 500; color: #586069; }
        .env-select { padding: 6px 12px; border: 1px solid #e1e4e8; border-radius: 6px; font-size: 0.9em; min-width: 100px; }
        .env-input { padding: 6px 12px; border: 1px solid #e1e4e8; border-radius: 6px; font-size: 0.9em; min-width: 120px; }
        .env-textarea { width: 100%; min-height: 180px; padding: 10px; border: 1px solid #e1e4e8; border-radius: 6px; font-family: monospace; font-size: 0.9em; }
        .btn-small { padding: 6px 12px; font-size: 0.85em; }
        .lang-switcher { margin-bottom: 10px; }
        .lang-form label { font-size: 0.85em; color: #586069; margin-right: 5px; }
        footer { margin-top: 20px; text-align: center; }
        .footer-link { color: #586069; text-decoration: none; font-size: 0.8em; }
        .footer-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-left">
                <h1>🔄 GitInstall</h1>
                <h2>{$appTitle}</h2>
            </div>
            <div class="header-right">
                {$homeButton}
                {$logoutButton}
                {$langSwitcherHtml}
            </div>
        </header>
        {$errorHtml}
        {$dashboardNavHtml}
        <div id="dashboard-updates" style="display:block">{$content}</div>
        <div id="dashboard-environment" style="display:none">{$envConfigHtml}</div>
        <div id="dashboard-databases" style="display:none">{$dbConfigHtml}</div>
        <div id="dashboard-install-uuid" style="display:none">{$installUuidHtml}</div>
    </div>
    <footer>
        <a href="https://github.com/oakengine/installer" target="_blank" class="footer-link">github.com/oakengine/installer</a>
    </footer>
    {$dashboardScript}
</body>
</html>
HTML;
    }

    try {
        $token = '';
        if (isset($config['github_token']) && (is_string($config['github_token']) || is_int($config['github_token']))) {
            $token = (string) $config['github_token'];
        }

        $apiBaseUrl = '';
        if (isset($config['api_base_url']) && (is_string($config['api_base_url']) || is_int($config['api_base_url']))) {
            $apiBaseUrl = (string) $config['api_base_url'];
        }
        if ('' === $apiBaseUrl) {
            $apiBaseUrl = 'https://api.github.com';
        }

        $targetDirRelative = '';
        if (isset($config['target_directory']) && (is_string($config['target_directory']) || is_int($config['target_directory']))) {
            $targetDirRelative = (string) $config['target_directory'];
        }
        if ('' === $targetDirRelative) {
            $targetDirRelative = '../';
        }

        $showVersionsBeforeLogin = (bool) ($config['show_versions_before_login'] ?? false);

        $rawExcludeFolders = $config['exclude_folders'] ?? [];
        /** @var array<string> $excludeFolders */
        $excludeFolders = [];
        if (is_array($rawExcludeFolders)) {
            foreach ($rawExcludeFolders as $val) {
                $valStr = (is_scalar($val)) ? (string) $val : '';
                if ('' !== $valStr) {
                    $excludeFolders[] = $valStr;
                }
            }
        }

        $rawExcludeFiles = $config['exclude_files'] ?? [];
        /** @var array<string> $excludeFiles */
        $excludeFiles = [];
        if (is_array($rawExcludeFiles)) {
            foreach ($rawExcludeFiles as $val) {
                $valStr = (is_scalar($val)) ? (string) $val : '';
                if ('' !== $valStr) {
                    $excludeFiles[] = $valStr;
                }
            }
        }

        $rawWhitelistFolders = $config['whitelist_folders'] ?? [];
        /** @var array<string> $whitelistFolders */
        $whitelistFolders = [];
        if (is_array($rawWhitelistFolders)) {
            foreach ($rawWhitelistFolders as $val) {
                $valStr = (is_scalar($val)) ? (string) $val : '';
                if ('' !== $valStr) {
                    $whitelistFolders[] = $valStr;
                }
            }
        }

        $rawWhitelistFiles = $config['whitelist_files'] ?? [];
        /** @var array<string> $whitelistFiles */
        $whitelistFiles = [];
        if (is_array($rawWhitelistFiles)) {
            foreach ($rawWhitelistFiles as $val) {
                $valStr = (is_scalar($val)) ? (string) $val : '';
                if ('' !== $valStr) {
                    $whitelistFiles[] = $valStr;
                }
            }
        }

        $envPath = null;
        $projectApiUrl = '';
        if (isset($config['project_api_url']) && (is_string($config['project_api_url']) || is_int($config['project_api_url']))) {
            $projectApiUrl = rtrim((string) $config['project_api_url'], '/');
        }
        if ('' === $projectApiUrl) {
            throw new RuntimeException(__('repository_not_configured'));
        }

        $installerRepo = 'oakengine/installer';
        if (isset($config['installer_repository']) && is_scalar($config['installer_repository'])) {
            $installerRepo = (string) $config['installer_repository'];
        }

        $targetDir = realpath(__DIR__.'/'.$targetDirRelative);
        if (false === $targetDir) {
            $absoluteTarget = __DIR__.'/'.$targetDirRelative;
            if (!is_dir($absoluteTarget)) {
                if (!\Oak\Engine\Installer\createDirectoryTree($absoluteTarget, 0o755)) {
                    throw new RuntimeException('Target directory cannot be created: '.$absoluteTarget);
                }
            }
            $targetDir = realpath($absoluteTarget);
            if (false === $targetDir) {
                throw new RuntimeException('Target directory cannot be resolved: '.$absoluteTarget);
            }
        }

        $targetDirStr = (string) $targetDir;
        /** @var non-empty-string $targetDirFinal */
        $targetDirFinal = (strlen($targetDirStr) > 0) ? $targetDirStr : '.';
        $targetDirStr = $targetDirFinal;
        $currentProjectVersion = resolveInstalledProjectVersion($targetDirFinal);

        /** @var array<string, mixed> $configForResolver */
        $configForResolver = $config;
        /** @var array<int, array{name: string, commit: string}> $tagsForResolver */
        $tagsForResolver = [];
        $resInstVer = resolveInstallerVersion($configForResolver, $tagsForResolver);
        $currentInstallerVersion = $resInstVer;

        /** @var array<string, mixed> $configForAuth */
        $configForAuth = $config;
        /** @var array<string, mixed> $metaForAuth */
        $metaForAuth = [
            'installer_version' => (string) $currentInstallerVersion,
            'project_version' => (string) $currentProjectVersion,
        ];
        handleAuthentication(
            $configForAuth,
            $showVersionsBeforeLogin,
            $metaForAuth
        );

        $client = new GitHubClient($apiBaseUrl, $token, $currentInstallerVersion);
        $githubCacheDir = dirname(__DIR__).'/var/cache/github-api';
        $installUuidManager = new InstallUuidManager();
        $envPath = rtrim($targetDirFinal, '/').'/.env.local';
        $installUuid = $installUuidManager->ensureEnvLocalInstallUuid($envPath);

        global $lang;
        /** @var array<string, string> $langForGlobal */
        $langForGlobal = (isset($lang) && is_array($lang)) ? $lang : [];
        $projectApiToken = isset($config['project_api_token']) && is_scalar($config['project_api_token'])
            ? trim((string) $config['project_api_token'])
            : '';
        $runnerClient = new ProjectPackageApiClient($projectApiUrl, 'runner', $installUuid, $projectApiToken);
        $pluginClient = new ProjectPackageApiClient($projectApiUrl, 'plugin', $installUuid, $projectApiToken);
        $dataClient = new ProjectPackageApiClient($projectApiUrl, 'data', $installUuid, $projectApiToken);
        $archiveExtractor = new ProjectPackageArchiveExtractor();

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_env'])) {
            $envPath = rtrim($targetDirStr, '/').'/.env.local';
            $newEnv = 'prod';
            if (isset($_POST['app_env']) && is_scalar($_POST['app_env'])) {
                $newEnv = (string) $_POST['app_env'];
            }
            $newDb = 'DB1';
            if (isset($_POST['database']) && is_scalar($_POST['database'])) {
                $newDb = (string) $_POST['database'];
            }

            if (updateEnvLocal($envPath, $newEnv, $newDb)) {
                $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                $content = '<div class="success">'.resolveLangKey('config_saved', $langForGlobal).'<br>';
                $content .= '<strong>'.resolveLangKey('mode', $langForGlobal).':</strong> '.htmlspecialchars($newEnv).'<br>';
                $content .= '<strong>'.resolveLangKey('database', $langForGlobal).':</strong> '.htmlspecialchars($newDb).'</div>';
                $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
                exit;
            }
            $error = 'Error saving .env.local';
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_env_content'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $newContent = '';
            if (isset($_POST['env_content']) && is_scalar($_POST['env_content'])) {
                $newContent = (string) $_POST['env_content'];
            }

            if (saveEnvLocalContent($envPath, $newContent)) {
                $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                $content = '<div class="success">'.resolveLangKey('env_file_saved', $langForGlobal).'</div>';
                $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
                exit;
            }

            throw new RuntimeException(resolveLangKey('env_file_save_failed', $langForGlobal));
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_install_uuid'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $newInstallUuid = '';
            if (isset($_POST['install_uuid']) && is_scalar($_POST['install_uuid'])) {
                $newInstallUuid = (string) $_POST['install_uuid'];
            }

            if (updateInstallUuidInEnvLocal($installUuidManager, $envPath, $newInstallUuid)) {
                $content = '<div class="success">'.resolveLangKey('install_uuid_saved', $langForGlobal).'<br>';
                $content .= '<strong>'.resolveLangKey('install_uuid', $langForGlobal).':</strong> '.htmlspecialchars(strtolower(trim($newInstallUuid))).'</div>';
                $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
                exit;
            }

            throw new RuntimeException(resolveLangKey('install_uuid_invalid', $langForGlobal));
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['regenerate_install_uuid'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $newInstallUuid = $installUuidManager->ensureEnvLocalInstallUuid($envPath, true);

            $content = '<div class="success">'.resolveLangKey('install_uuid_saved', $langForGlobal).'<br>';
            $content .= '<strong>'.resolveLangKey('install_uuid', $langForGlobal).':</strong> '.htmlspecialchars($newInstallUuid).'</div>';
            $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
            echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['add_database'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $dbId = '';
            if (isset($_POST['db_id']) && is_scalar($_POST['db_id'])) {
                $dbId = (string) $_POST['db_id'];
            }
            $dbUrl = '';
            if (isset($_POST['db_url']) && is_scalar($_POST['db_url'])) {
                $dbUrl = (string) $_POST['db_url'];
            }

            if (addDatabaseToEnvLocal($envPath, $dbId, $dbUrl)) {
                $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                $content = '<div class="success">'.resolveLangKey('database_added', $langForGlobal, ['id' => htmlspecialchars($dbId)]).'</div>';
                $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
                exit;
            }

            throw new RuntimeException(resolveLangKey('database_add_failed', $langForGlobal));
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['remove_database'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $removeDbId = '';
            if (isset($_POST['remove_db_id']) && is_scalar($_POST['remove_db_id'])) {
                $removeDbId = (string) $_POST['remove_db_id'];
            }

            if (removeDatabaseFromEnvLocal($envPath, $removeDbId)) {
                $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                $content = '<div class="success">'.resolveLangKey('database_removed', $langForGlobal, ['id' => htmlspecialchars($removeDbId)]).'</div>';
                $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
                exit;
            }

            throw new RuntimeException(resolveLangKey('database_remove_failed', $langForGlobal));
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['run_migrations'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $console = rtrim($targetDirFinal, '/').'/bin/console';
            $cmd = 'php '.escapeshellarg($console).' doctrine:migrations:migrate --no-interaction 2>&1';
            $output = shell_exec($cmd);

            $content = '<div class="success">'.resolveLangKey('migrations_run_successfully', $langForGlobal).'</div>';
            $content .= '<h3>'.resolveLangKey('migrations_output', $langForGlobal).'</h3>';
            $content .= '<pre style="background:#f6f8fa; padding:15px; border-radius:6px; font-size:0.9em; white-space:pre-wrap;">'.htmlspecialchars(trim((string) $output)).'</pre>';
            $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
            echo renderPage(resolveLangKey('run_migrations', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['clear_cache'])) {
            $cacheDir = rtrim($targetDirFinal, '/').'/var';
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $cacheResult = clearCacheDirectory($cacheDir);

            $errorsCount = (int) count($cacheResult['errors']);
            $content = '<div class="success">'.resolveLangKey('cache_cleared', $langForGlobal).'<br>';
            $content .= resolveLangKey('files_deleted', $langForGlobal, ['count' => (int) $cacheResult['deleted_count'], 'dir' => htmlspecialchars($cacheDir)]);
            if ($errorsCount > 0) {
                $content .= '<br><small>'.resolveLangKey('errors', $langForGlobal).': '.$errorsCount.'</small>';
            }
            $content .= '</div>';
            $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
            echo renderPage(resolveLangKey('cache_cleared', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['self_update'])) {
            $tag = '';
            if (isset($_POST['ref']) && is_scalar($_POST['ref'])) {
                $tag = trim((string) $_POST['ref']);
            }
            if ('' === $tag) {
                throw new RuntimeException(resolveLangKey('no_ref_specified', $langForGlobal));
            }

            $installerRefs = getCachedGitHubRepositoryRefs($client, $installerRepo, $githubCacheDir);
            $instTags = $installerRefs['tags'];
            $instBranches = $installerRefs['branches'];
            $instTagNames = array_map(static fn (array $tagItem): string => $tagItem['name'], $instTags);
            $instBranchNames = array_map(static fn (array $branchItem): string => $branchItem['name'], $instBranches);
            $allRefs = array_merge($instTagNames, $instBranchNames);

            if (!in_array($tag, $allRefs, true)) {
                throw new RuntimeException(resolveLangKey('tag_not_found', $langForGlobal));
            }

            if (!canUpdateInstallerToTag($currentInstallerVersion, $tag)) {
                // Downgrade protection removed as requested by user ("lass mich dort den Installier installieren downgrade usw")
                // but we might still want to check if it's the SAME version to avoid unnecessary work,
                // however the user explicitly said "downgrade usw".
            }

            $updaterSourcePath = 'src';
            if (isset($_GET['manage']) && 'installer' === $_GET['manage']) {
                $updaterSourcePath = 'src';
            } elseif (isset($config['updater_source_path']) && is_scalar($config['updater_source_path'])) {
                $updaterSourcePath = (string) $config['updater_source_path'];
            }

            $selfUpdateResult = updateUpdaterFromTag($client, $installerRepo, $tag, $updaterSourcePath, __DIR__);
            $updatedCount = (int) count($selfUpdateResult['updated_files']);

            writeConfigValues($configPath, [
                'installer_version' => (string) $tag,
            ]);

            $content = '<div class="success">'.resolveLangKey('updater_updated', $langForGlobal, ['tag' => htmlspecialchars($tag)]).'<br>';
            $content .= resolveLangKey('files_updated', $langForGlobal, ['count' => $updatedCount]).'</div>';
            $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';

            if ($updatedCount > 0) {
                $content .= '<h3>'.resolveLangKey('updated_files', $langForGlobal).'</h3><ul class="file-list">';
                /** @var array<string> $updatedFilesList */
                $updatedFilesList = $selfUpdateResult['updated_files'];
                foreach ($updatedFilesList as $file) {
                    $content .= '<li>'.htmlspecialchars($file).'</li>';
                }
                $content .= '</ul>';
            }

            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            echo renderPage(resolveLangKey('title', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['install'])) {
            $packageType = normalizePackageType($_POST['package_type'] ?? null);
            $packageId = '';
            if (isset($_POST['package_id']) && is_scalar($_POST['package_id'])) {
                $packageId = (string) $_POST['package_id'];
            }
            $packageVersion = '';
            if (isset($_POST['version']) && is_scalar($_POST['version'])) {
                $packageVersion = (string) $_POST['version'];
            }
            if ('' === $packageId) {
                throw new RuntimeException(resolveLangKey('no_ref_specified', $langForGlobal));
            }

            $packageClient = match ($packageType) {
                'plugin' => $pluginClient,
                'data' => $dataClient,
                default => $runnerClient,
            };

            $cleanResult = ['deleted_count' => 0, 'preserved' => []];
            if ('runner' === $packageType) {
                $cleanResult = cleanTargetDirectory((string) $targetDirStr, $whitelistFolders, $whitelistFiles);
            }

            $package = $packageClient->getPackage($packageId, '' !== $packageVersion ? $packageVersion : null);
            $packageContent = $packageClient->downloadPackage($package['package_id'], $package['version']);
            $packageTargetDir = resolvePackageInstallTargetDir((string) $targetDirStr, $packageType);
            $extractZipResult = $archiveExtractor->extractTarGz(
                $packageContent,
                $packageTargetDir,
                $excludeFolders,
                $excludeFiles,
                $whitelistFolders,
                $whitelistFiles,
            );
            $envPath = rtrim((string) $targetDirStr, '/').'/.env.local';
            $composerMetadataSources = resolveProjectEnvComposerMetadataSources((string) $targetDirStr);
            $envSyncResult = syncPackageEnvComposerMetadataSourcesToEnvLocalDetailed(
                $envPath,
                $composerMetadataSources,
            );

            if ('runner' === $packageType) {
                $installUuidManager->ensureEnvLocalInstallUuid($envPath);
            }

            $extractedCount = count($extractZipResult['extracted']);
            $skippedFilesCount = count($extractZipResult['skipped_files']);
            $skippedFoldersCount = count($extractZipResult['skipped_folders']);
            $preservedCount = count($cleanResult['preserved']);

            $content = '<div class="success">'.resolveLangKey('installation_successful', $langForGlobal).'<br>';
            $content .= resolveLangKey('files_extracted', $langForGlobal, ['count' => $extractedCount, 'dir' => htmlspecialchars($packageTargetDir)]);
            if ($preservedCount > 0) {
                $content .= '<br>'.resolveLangKey('preserved_files', $langForGlobal, ['count' => $preservedCount]);
            }
            if ($skippedFilesCount > 0 || $skippedFoldersCount > 0) {
                $content .= '<br><small>'.resolveLangKey('skipped', $langForGlobal, ['folders' => $skippedFoldersCount, 'files' => $skippedFilesCount]).'</small>';
            }
            $content .= '</div>';

            if ([] !== $envSyncResult['written_lines'] || [] !== $envSyncResult['skipped_existing_lines']) {
                $content .= '<div class="success"><strong>.env.local</strong><ul class="file-list">';
                $content .= '<li>'.resolveLangKey('env_values_created', $langForGlobal, ['count' => count($envSyncResult['written_lines'])]).'</li>';
                foreach ($envSyncResult['written_lines'] as $writtenEnvLine) {
                    $content .= '<li><code>'.htmlspecialchars($writtenEnvLine).'</code></li>';
                }
                $content .= '<li>'.resolveLangKey('env_values_skipped_existing', $langForGlobal, ['count' => count($envSyncResult['skipped_existing_lines'])]).'</li>';
                foreach ($envSyncResult['skipped_existing_lines'] as $skippedEnvLine) {
                    $content .= '<li><code>'.htmlspecialchars($skippedEnvLine).'</code></li>';
                }
                $content .= '</ul></div>';
            }

            $content .= renderComposerMetadataSourceListHtml($composerMetadataSources, $langForGlobal);

            if ($preservedCount > 0) {
                $content .= '<div class="warning"><strong>'.resolveLangKey('preserved_list_title', $langForGlobal).'</strong><ul class="file-list">';
                foreach (array_slice($cleanResult['preserved'], 0, 20) as $item) {
                    $content .= '<li>'.htmlspecialchars((string) $item).'</li>';
                }
                if ($preservedCount > 20) {
                    $content .= '<li><em>'.resolveLangKey('and_more', $langForGlobal, ['count' => ($preservedCount - 20)]).'</em></li>';
                }
                $content .= '</ul></div>';
            }

            $content .= '<a href="?" class="back-link">'.htmlspecialchars((string) resolveLangKey('back', $langForGlobal)).'</a><h3>'.htmlspecialchars((string) resolveLangKey('installed_files', $langForGlobal)).'</h3><ul class="file-list">';
            /** @var array<string> $extractedFiles */
            $extractedFiles = $extractZipResult['extracted'];
            $slice = array_slice($extractedFiles, 0, 50);
            foreach ($slice as $file) {
                $content .= '<li>'.htmlspecialchars($file).'</li>';
            }
            if ($extractedCount > 50) {
                $content .= '<li><em>'.resolveLangKey('and_more', $langForGlobal, ['count' => ($extractedCount - 50)]).'</em></li>';
            }
            $content .= '</ul>';
            echo renderPage(resolveLangKey('installation_successful', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        $runnerPackages = $runnerClient->listPackages();
        $pluginPackages = $pluginClient->listPackages();
        $dataPackages = $dataClient->listPackages();
        $installedPlugins = resolveInstalledPackages($targetDirFinal, 'plugin');
        $installedDataPackages = resolveInstalledPackages($targetDirFinal, 'data');
        $runnerPackageHtml = renderPackageListHtml($runnerPackages, 'runner', $langForGlobal);
        $pluginPackageHtml = renderPackageListHtml($pluginPackages, 'plugin', $langForGlobal);
        $dataPackageHtml = renderPackageListHtml($dataPackages, 'data', $langForGlobal);
        $installedPluginHtml = renderInstalledPackageListHtml($installedPlugins, $langForGlobal);
        $installedDataHtml = renderInstalledPackageListHtml($installedDataPackages, $langForGlobal);

        $envPath = rtrim($targetDirStr, '/').'/.env.local';
        if (isset($_GET['manage']) && 'installer' === $_GET['manage']) {
            $installerRefs = getCachedGitHubRepositoryRefs($client, $installerRepo, $githubCacheDir);
            $instTags = $installerRefs['tags'];
            $instBranches = $installerRefs['branches'];

            $instBranchHtml = '';
            foreach ($instBranches as $branch) {
                $bName = (isset($branch['name'])) ? (string) $branch['name'] : '';
                $bCommit = (isset($branch['commit'])) ? (string) $branch['commit'] : '';
                $bCommitShort = substr($bCommit, 0, 7);
                $instBranchHtml .= '<li><span><span class="branch-name">'.htmlspecialchars($bName).'</span>'
                    .'<span class="commit-sha">'.htmlspecialchars($bCommitShort).'</span></span>';
                $instBranchHtml .= '<form method="post" style="display:inline" onsubmit="return confirm(\''.htmlspecialchars(resolveLangKey('confirm_install_installer', $langForGlobal)).'\')"><input type="hidden" name="ref" value="'.htmlspecialchars($bName).'"><input type="hidden" name="ref_type" value="branch"><button type="submit" name="self_update" class="btn">'.resolveLangKey('install', $langForGlobal).'</button></form></li>';
            }

            $instTagHtml = '';
            foreach ($instTags as $tag) {
                $tName = (isset($tag['name'])) ? (string) $tag['name'] : '';
                $tCommit = (isset($tag['commit'])) ? (string) $tag['commit'] : '';
                $tCommitShort = substr($tCommit, 0, 7);
                $instTagHtml .= '<li><span><span class="tag-name">'.htmlspecialchars($tName).'</span><span class="commit-sha">'.htmlspecialchars($tCommitShort).'</span></span>';
                $instTagHtml .= '<form method="post" style="display:inline" onsubmit="return confirm(\''.htmlspecialchars(resolveLangKey('confirm_install_installer', $langForGlobal)).'\')"><input type="hidden" name="ref" value="'.htmlspecialchars($tName).'"><button type="submit" name="self_update" class="btn">'.resolveLangKey('install', $langForGlobal).'</button></form></li>';
            }

            if (empty($instBranches)) {
                $instBranchHtml = '<li><em>'.resolveLangKey('no_branches_found', $langForGlobal).'</em></li>';
            }
            if (empty($instTags)) {
                $instTagHtml = '<li><em>'.resolveLangKey('no_tags_found', $langForGlobal).'</em></li>';
            }

            $content = '<div class="repo-info"><strong>'.resolveLangKey('updater_version', $langForGlobal).':</strong> <code>'.htmlspecialchars($currentInstallerVersion).'</code><br><strong>'.resolveLangKey('installer_repository', $langForGlobal).':</strong> <code>'.htmlspecialchars($installerRepo).'</code></div>';
            $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
            $content .= '<div class="tabs"><button class="tab active" onclick="showTab(\'branches\')">'.resolveLangKey('branches', $langForGlobal).' ('.count($instBranches).')</button><button class="tab" onclick="showTab(\'tags\')">'.resolveLangKey('tags', $langForGlobal).' ('.count($instTags).')</button></div>';
            $content .= '<div id="branches" class="tab-content active"><ul class="branch-list">'.$instBranchHtml.'</ul></div>';
            $content .= '<div id="tags" class="tab-content"><ul class="tag-list">'.$instTagHtml.'</ul></div>';
            $content .= '<script>function showTab(t){document.querySelectorAll(".tab-content").forEach(e=>e.classList.remove("active"));document.querySelectorAll(".tab").forEach(e=>e.classList.remove("active"));document.getElementById(t).classList.add("active");event.target.classList.add("active");}</script>';

            echo renderPage(resolveLangKey('installer_management', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        $content = '<div class="repo-info"><strong>'.resolveLangKey('updater_version', $langForGlobal).':</strong> <code>'.htmlspecialchars($currentInstallerVersion).'</code> <a href="?manage=installer" style="font-size:0.8em;">['.resolveLangKey('manage', $langForGlobal).']</a><br><strong>'.resolveLangKey('runner_version', $langForGlobal).':</strong> <code>'.htmlspecialchars($currentProjectVersion).'</code><br><strong>'.resolveLangKey('installed_plugins', $langForGlobal).':</strong>'.$installedPluginHtml.'<br><strong>'.resolveLangKey('installed_data', $langForGlobal).':</strong>'.$installedDataHtml.'<br><strong>'.resolveLangKey('repository', $langForGlobal).':</strong> <code>'.htmlspecialchars($projectApiUrl).'</code><br><strong>'.resolveLangKey('target_directory', $langForGlobal).':</strong> <code>'.htmlspecialchars($targetDirStr).'</code>';
        if (!empty($whitelistFolders) || !empty($whitelistFiles)) {
            $content .= '<br><strong>'.resolveLangKey('whitelist_active', $langForGlobal).':</strong> ';
            $wlItems = array_merge($whitelistFolders, $whitelistFiles);
            /** @var array<string> $wlItemsString */
            $wlItemsString = array_map(fn ($item) => (string) $item, $wlItems);
            $content .= htmlspecialchars(implode(', ', array_slice($wlItemsString, 0, 5)));
            if (count($wlItems) > 5) {
                $content .= ' ...';
            }
        }
        $content .= '</div>';

        $text_confirm_clear_cache = resolveLangKey('confirm_clear_cache', $langForGlobal);
        $content .= '<form method="post" style="margin-bottom:20px"><button type="submit" name="clear_cache" class="btn btn-secondary" onclick="return confirm(\''.htmlspecialchars($text_confirm_clear_cache).'\')">'.resolveLangKey('clear_cache', $langForGlobal).'</button></form>';
        $content .= '<h3 style="margin-bottom:10px;">Runner</h3><ul class="tag-list" style="margin-bottom:20px;">'.$runnerPackageHtml.'</ul>';
        $content .= '<h3 style="margin-bottom:10px;">Plugin</h3><ul class="tag-list" style="margin-bottom:20px;">'.$pluginPackageHtml.'</ul>';
        $content .= '<h3 style="margin-bottom:10px;">Data</h3><ul class="tag-list">'.$dataPackageHtml.'</ul>';

        $envPath = rtrim($targetDirStr, '/').'/.env.local';
        $hasPassword = (isset($config['password']) && is_scalar($config['password']) && '' !== (string) $config['password']);
        echo renderPage(resolveLangKey('title', $langForGlobal), $content, null, $envPath, $hasPassword);
    } catch (Exception $e) {
        /** @var array<string, string> $langForCatch */
        $langForCatch = (isset($lang) && is_array($lang)) ? $lang : [];
        $targetDirStrCatch = (isset($targetDirStr) && is_string($targetDirStr)) ? $targetDirStr : '';
        $envPathCatch = ('' !== $targetDirStrCatch) ? rtrim($targetDirStrCatch, '/').'/.env.local' : null;
        $hasPasswordCatch = (isset($config['password']) && is_scalar($config['password']) && '' !== (string) $config['password']);
        echo renderPage(resolveLangKey('error', $langForCatch), '<p>'.resolveLangKey('error_occurred', $langForCatch).'</p>', $e->getMessage(), $envPathCatch, $hasPasswordCatch);
    }
}
