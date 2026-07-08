<?php

declare(strict_types=1);

namespace Oak\Engine\Installer;

final class InstallManifestManager
{
    public const MANIFEST_FILENAME = '.oak-install-manifest.json';

    /**
     * @return array{
     *     package_type: string,
     *     package_id: string,
     *     version: string,
     *     files: array<string, string>
     * }|null
     */
    public function loadManifest(string $packageTargetDir): ?array
    {
        $path = $this->manifestPath($packageTargetDir);
        if (!is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if (!is_string($content)) {
            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }

        $files = $decoded['files'] ?? null;
        if (!is_array($files)) {
            return null;
        }

        $normalizedFiles = [];
        foreach ($files as $relativePath => $hash) {
            if (!is_string($relativePath) || !is_scalar($hash)) {
                continue;
            }
            $normalizedFiles[$relativePath] = (string) $hash;
        }

        return [
            'package_type' => $this->normalizeString($decoded['package_type'] ?? null),
            'package_id' => $this->normalizeString($decoded['package_id'] ?? null),
            'version' => $this->normalizeString($decoded['version'] ?? null),
            'files' => $normalizedFiles,
        ];
    }

    public function manifestExists(string $packageTargetDir): bool
    {
        return is_file($this->manifestPath($packageTargetDir));
    }

    /**
     * @param array{
     *     package_type: string,
     *     package_id: string,
     *     version: string,
     *     files: array<string, string>
     * } $manifest
     */
    public function saveManifest(string $packageTargetDir, array $manifest): bool
    {
        $path = $this->manifestPath($packageTargetDir);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            if (!createDirectoryTree($directory, 0o755)) {
                return false;
            }
        }

        $content = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($content)) {
            return false;
        }

        return false !== @file_put_contents($path, $content);
    }

    /**
     * @param list<string> $extractedFiles
     *
     * @return array{
     *     package_type: string,
     *     package_id: string,
     *     version: string,
     *     files: array<string, string>
     * }
     */
    public function buildManifest(
        string $packageTargetDir,
        string $packageType,
        string $packageId,
        string $version,
        array $extractedFiles,
    ): array {
        $files = [];
        $basePath = rtrim($packageTargetDir, '/');
        foreach ($extractedFiles as $relativePath) {
            if (!is_string($relativePath) || '' === $relativePath) {
                continue;
            }

            $normalizedPath = str_replace('\\', '/', $relativePath);
            if (self::MANIFEST_FILENAME === $normalizedPath) {
                continue;
            }
            $absolutePath = $basePath.'/'.ltrim($normalizedPath, '/');
            if (!is_file($absolutePath)) {
                continue;
            }

            $hash = @sha1_file($absolutePath);
            if (!is_string($hash)) {
                continue;
            }

            $files[$normalizedPath] = $hash;
        }

        return [
            'package_type' => $packageType,
            'package_id' => $packageId,
            'version' => $version,
            'files' => $files,
        ];
    }

    /**
     * @param array{
     *     package_type: string,
     *     package_id: string,
     *     version: string,
     *     files: array<string, string>
     * }|null $oldManifest
     * @param array{
     *     package_type: string,
     *     package_id: string,
     *     version: string,
     *     files: array<string, string>
     * } $newManifest
     *
     * @return list<string>
     */
    public function diffStaleFiles(?array $oldManifest, array $newManifest): array
    {
        if (null === $oldManifest) {
            return [];
        }

        $oldFiles = $oldManifest['files'];
        $newFiles = $newManifest['files'];

        $stale = [];
        foreach ($oldFiles as $relativePath => $hash) {
            if (!is_string($relativePath)) {
                continue;
            }

            if (!array_key_exists($relativePath, $newFiles)) {
                $stale[] = $relativePath;
            }
        }

        sort($stale);

        return $stale;
    }

    /**
     * @param list<string> $staleFiles
     *
     * @return array{
     *     deleted_files: list<string>,
     *     deleted_dirs: list<string>,
     *     errors: list<string>
     * }
     */
    public function deleteStaleFilesAndEmptyDirs(string $packageTargetDir, array $staleFiles, string $logContext = ''): array
    {
        $basePath = rtrim($packageTargetDir, '/');
        $deletedFiles = [];
        $deletedDirs = [];
        $errors = [];

        $packageType = '';
        if ('' !== $logContext && is_dir($logContext)) {
            $manifestData = $this->loadManifest($packageTargetDir);
            if (null !== $manifestData) {
                $packageType = $manifestData['package_type'];
            }
        }

        foreach ($staleFiles as $relativePath) {
            if (!is_string($relativePath) || '' === $relativePath) {
                continue;
            }

            $normalizedPath = str_replace('\\', '/', $relativePath);
            if ($this->containsTraversal($normalizedPath)) {
                logInstallerEvent(
                    $logContext,
                    'warning',
                    'Refusing to delete stale file with path traversal',
                    ['package_type' => $packageType, 'relative_path' => $normalizedPath]
                );

                continue;
            }

            $absolutePath = $basePath.'/'.ltrim($normalizedPath, '/');

            if (!is_file($absolutePath)) {
                continue;
            }

            if (@unlink($absolutePath)) {
                $deletedFiles[] = $normalizedPath;
                logInstallerEvent(
                    $logContext,
                    'info',
                    'Deleted stale file from previous install',
                    ['package_type' => $packageType, 'relative_path' => $normalizedPath]
                );
            } else {
                $lastError = error_get_last();
                $reason = null !== $lastError ? $lastError['message'] : 'unknown';
                $errors[] = $absolutePath;
                logInstallerEvent(
                    $logContext,
                    'warning',
                    'Could not delete stale file (left in place)',
                    [
                        'package_type' => $packageType,
                        'relative_path' => $normalizedPath,
                        'absolute_path' => $absolutePath,
                        'reason' => $reason,
                    ]
                );
            }
        }

        $deletedDirs = $this->removeEmptyDirectories($basePath, $logContext, $packageType);

        if ([] !== $errors) {
            logInstallerEvent(
                $logContext,
                'warning',
                'Finished stale file cleanup with errors',
                [
                    'package_type' => $packageType,
                    'deleted_files' => count($deletedFiles),
                    'deleted_dirs' => count($deletedDirs),
                    'failed_deletions' => count($errors),
                ]
            );
        } else {
            logInstallerEvent(
                $logContext,
                'info',
                'Stale file cleanup finished',
                [
                    'package_type' => $packageType,
                    'deleted_files' => count($deletedFiles),
                    'deleted_dirs' => count($deletedDirs),
                ]
            );
        }

        return [
            'deleted_files' => $deletedFiles,
            'deleted_dirs' => $deletedDirs,
            'errors' => $errors,
        ];
    }

    public function manifestPath(string $packageTargetDir): string
    {
        return rtrim($packageTargetDir, '/').'/'.self::MANIFEST_FILENAME;
    }

    /**
     * @return list<string>
     */
    public function removeEmptyDirectoriesIn(string $packageTargetDir, string $logContext = ''): array
    {
        $packageType = '';
        if ('' !== $logContext && is_dir($logContext)) {
            $manifestData = $this->loadManifest($packageTargetDir);
            if (null !== $manifestData) {
                $packageType = $manifestData['package_type'];
            }
        }

        $deleted = $this->removeEmptyDirectories($packageTargetDir, $logContext, $packageType);

        if ([] !== $deleted) {
            logInstallerEvent(
                $logContext,
                'info',
                'Removed empty directories',
                ['package_type' => $packageType, 'dirs_removed' => count($deleted)]
            );
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    private function removeEmptyDirectories(string $basePath, string $logContext = '', string $packageType = ''): array
    {
        $deleted = [];

        if (!is_dir($basePath)) {
            return $deleted;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            if (!$item->isDir()) {
                continue;
            }

            $path = $item->getPathname();
            if ($this->isDirectoryEmpty($path)) {
                if (@rmdir($path)) {
                    $relative = $this->relativePathFromBase($path, $basePath);
                    $deleted[] = $relative;
                    logInstallerEvent(
                        $logContext,
                        'info',
                        'Removed empty directory left by previous install',
                        ['package_type' => $packageType, 'relative_path' => $relative]
                    );
                } else {
                    $lastError = error_get_last();
                    $reason = null !== $lastError ? $lastError['message'] : 'unknown';
                    logInstallerEvent(
                        $logContext,
                        'warning',
                        'Could not remove empty directory',
                        [
                            'package_type' => $packageType,
                            'absolute_path' => $path,
                            'reason' => $reason,
                        ]
                    );
                }
            }
        }

        return $deleted;
    }

    private function isDirectoryEmpty(string $directory): bool
    {
        $entries = @scandir($directory);

        return is_array($entries) && 2 === count($entries);
    }

    private function containsTraversal(string $relativePath): bool
    {
        return in_array('..', explode('/', $relativePath), true);
    }

    private function relativePathFromBase(string $absolutePath, string $basePath): string
    {
        $relative = substr($absolutePath, strlen(rtrim($basePath, '/')) + 1);

        return str_replace('\\', '/', (string) $relative);
    }

    private function normalizeString(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
