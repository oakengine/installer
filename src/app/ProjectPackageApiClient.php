<?php

declare(strict_types=1);

namespace Oak\Engine\Installer;

final readonly class ProjectPackageApiClient
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private string $baseUrl,
        private string $packageType = 'runner',
        private string $installUuid = '',
        private string $projectApiToken = '',
        private ?string $cacheDirectory = null,
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
    public function listPackages(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->readCache();
            if (null !== $cached) {
                return $cached;
            }
        }

        $packages = $this->fetchPackages();
        $this->writeCache($packages);

        return $packages;
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
    public function refreshPackages(): array
    {
        return $this->listPackages(true);
    }

    public function getCacheAge(): ?int
    {
        $cacheFile = $this->getCacheFile();
        if (null === $cacheFile || !is_file($cacheFile)) {
            return null;
        }

        $mtime = @filemtime($cacheFile);

        return false !== $mtime ? max(0, time() - $mtime) : null;
    }

    public function getCacheTtl(): int
    {
        return self::CACHE_TTL_SECONDS;
    }

    public function invalidateCache(): void
    {
        $cacheFile = $this->getCacheFile();
        if (null !== $cacheFile && is_file($cacheFile)) {
            @unlink($cacheFile);
        }
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
    private function fetchPackages(): array
    {
        $response = $this->request([
            'type' => $this->packageType,
            'package_type' => $this->packageType,
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

            $packageType = $this->normalizeScalar($package['package_type'] ?? null);
            $packageId = $this->normalizeScalar($package['package_id'] ?? null);
            $version = $this->normalizeScalar($package['version'] ?? null);
            $channel = $this->normalizeScalar($package['channel'] ?? null, 'unknown');
            $packageName = $this->normalizeScalar($package['package_name'] ?? null);
            $downloadUrl = $this->resolveDownloadUrl($this->normalizeScalar($package['download_url'] ?? null));
            if (
                $this->packageType !== $packageType
                || '' === $packageId
                || '' === $version
                || '' === $packageName
                || '' === $downloadUrl
            ) {
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

        /** @var non-empty-string $tempFile */
        $tempFile = (string) tempnam(sys_get_temp_dir(), 'oak-package-');
        $handle = fopen($tempFile, 'wb');
        \assert(false !== $handle);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $downloadUrl,
            CURLOPT_POST => false,
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ]);

        $executed = curl_exec($ch);
        fclose($handle);
        curl_close($ch);

        if (false === $executed) {
            $error = curl_error($ch);
            @unlink($tempFile);

            throw new \RuntimeException('Package download failed: '.$error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (200 !== $httpCode) {
            @unlink($tempFile);

            throw new \RuntimeException(sprintf('Package download failed: HTTP %d', $httpCode));
        }

        return $tempFile;
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
        $requestPayload = $this->buildPayload($payload);
        $querySeparator = str_contains($baseUrl, '?') ? '&' : '?';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl.$querySeparator.http_build_query($requestPayload),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($requestPayload),
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

    private function getCacheFile(): ?string
    {
        $cacheDir = $this->cacheDirectory;
        if (null === $cacheDir || '' === $cacheDir) {
            return null;
        }

        $type = preg_replace('/[^a-z0-9_-]+/i', '_', $this->packageType) ?? 'package';
        $base = preg_replace('/[^a-zA-Z0-9]+/', '_', rtrim($this->baseUrl, '/')) ?? 'endpoint';

        return rtrim($cacheDir, '/').'/'.md5($base.'|'.$type.'|'.$this->installUuid).'.json';
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
     * }>|null
     */
    private function readCache(): ?array
    {
        $cacheFile = $this->getCacheFile();
        if (null === $cacheFile || !is_file($cacheFile)) {
            return null;
        }

        $mtime = @filemtime($cacheFile);
        if (false === $mtime || (time() - $mtime) >= self::CACHE_TTL_SECONDS) {
            return null;
        }

        $raw = @file_get_contents($cacheFile);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            return null;
        }

        $normalized = [];
        foreach ($decoded as $package) {
            if (!is_array($package)
                || !isset($package['package_type'], $package['package_id'], $package['version'], $package['package_name'], $package['download_url'])
                || !is_string($package['package_type'])
                || !is_string($package['package_id'])
                || !is_string($package['version'])
                || !is_string($package['package_name'])
                || !is_string($package['download_url'])
            ) {
                continue;
            }
            $composer = [];
            if (isset($package['composer']) && is_array($package['composer'])) {
                foreach ($package['composer'] as $key => $value) {
                    $composer[(string) $key] = $value;
                }
            }
            $archiveSize = 0;
            if (isset($package['archive_size']) && is_numeric($package['archive_size'])) {
                $archiveSize = (int) $package['archive_size'];
            }
            $normalized[] = [
                'package_type' => $package['package_type'],
                'package_id' => $package['package_id'],
                'version' => $package['version'],
                'channel' => isset($package['channel']) && is_string($package['channel']) ? $package['channel'] : 'unknown',
                'package_name' => $package['package_name'],
                'archive_size' => $archiveSize,
                'archive_sha256' => isset($package['archive_sha256']) && is_string($package['archive_sha256']) ? $package['archive_sha256'] : '',
                'download_url' => $package['download_url'],
                'composer' => $composer,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function writeCache(array $packages): void
    {
        $cacheFile = $this->getCacheFile();
        if (null === $cacheFile) {
            return;
        }

        $cacheDir = dirname($cacheFile);
        if (!createDirectoryTree($cacheDir, 0o755)) {
            return;
        }

        @file_put_contents($cacheFile, json_encode($packages, JSON_THROW_ON_ERROR));
        @chmod($cacheFile, 0o644);
    }
}
