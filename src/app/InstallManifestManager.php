<?php

declare(strict_types=1);

namespace Oak\Engine\Installer;

/**
 * Tracks every file (with its SHA1 hash) that an installation wrote to disk
 * so that subsequent installs can remove files which are no longer part of
 * the package.
 *
 * One manifest is kept per install target directory (runner, plugin, data).
 */
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

        $content = file_get_contents($path);
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

        return false !== file_put_contents($path, $content);
    }

    /**
     * Builds a manifest from the given extracted relative paths by computing
     * the SHA1 hash of every file that still exists on disk.
     *
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

            $hash = sha1_file($absolutePath);
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
     * Returns the relative paths of files that are tracked by the old manifest
     * but are no longer present in the new manifest.
     *
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
     * Removes the given stale files (relative to the package target directory)
     * and afterwards every directory that became empty as a result.
     *
     * @param list<string> $staleFiles
     *
     * @return array{
     *     deleted_files: list<string>,
     *     deleted_dirs: list<string>,
     *     errors: list<string>
     * }
     */
    public function deleteStaleFilesAndEmptyDirs(string $packageTargetDir, array $staleFiles): array
    {
        $basePath = rtrim($packageTargetDir, '/');
        $deletedFiles = [];
        $errors = [];

        foreach ($staleFiles as $relativePath) {
            if (!is_string($relativePath) || '' === $relativePath) {
                continue;
            }

            $normalizedPath = str_replace('\\', '/', $relativePath);
            if ($this->containsTraversal($normalizedPath)) {
                continue;
            }

            $absolutePath = $basePath.'/'.ltrim($normalizedPath, '/');

            if (!is_file($absolutePath)) {
                continue;
            }

            if (@unlink($absolutePath)) {
                $deletedFiles[] = $normalizedPath;
            } else {
                $errors[] = $absolutePath;
            }
        }

        $deletedDirs = $this->removeEmptyDirectories($basePath);

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
    private function removeEmptyDirectories(string $basePath): array
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
            if ($this->isDirectoryEmpty($path) && @rmdir($path)) {
                $deleted[] = $this->relativePathFromBase($path, $basePath);
            }
        }

        return $deleted;
    }

    private function isDirectoryEmpty(string $directory): bool
    {
        $entries = @scandir($directory);

        return is_array($entries) && 2 === count($entries);
    }

    /**
     * Returns true when the relative path contains a `..` segment which would
     * escape the package target directory.
     */
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
