<?php

declare(strict_types=1);

function installerLogRelativePath(): string
{
    return 'oak-installer.log';
}

function installerLogPath(string $logDirectory): string
{
    $base = rtrim($logDirectory, '/');
    if ('' === $base) {
        $base = sys_get_temp_dir();
    }

    return $base.'/'.installerLogRelativePath();
}

function installerLogBaseDirectory(string $installerRoot, string $configuredLogDirectory = ''): string
{
    $configured = trim(str_replace('\\', '/', $configuredLogDirectory));
    $normalizedRoot = trim(str_replace('\\', '/', $installerRoot));
    if ('' !== $configured) {
        $isAbsolute = str_starts_with($configured, '/') || 1 === preg_match('#^[A-Za-z]:/#', $configured);
        $base = $isAbsolute ? $configured : rtrim($installerRoot, '/').'/'.$configured;
    } elseif ('' !== $normalizedRoot) {
        $base = rtrim($installerRoot, '/').'/logs';
    } else {
        $base = str_replace('\\', '/', sys_get_temp_dir()).'/oak-installer-logs';
    }

    $base = rtrim($base, '/');
    if ('' === $base) {
        $base = str_replace('\\', '/', sys_get_temp_dir()).'/oak-installer-logs';
    }

    if (!is_dir($base)) {
        \Oak\Engine\Installer\createDirectoryTree($base, 0o755);
    }

    return $base;
}

/**
 * @param array<string, scalar|null> $context
 */
function logInstallerEvent(
    string $logDirectory,
    string $level,
    string $message,
    array $context = [],
): void {
    $path = installerLogPath($logDirectory);
    $directory = dirname($path);
    if (!is_dir($directory)) {
        if (!\Oak\Engine\Installer\createDirectoryTree($directory, 0o755)) {
            return;
        }
    }

    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $line = '['.$timestamp.'] ['.strtoupper($level).'] '.$message;
    if ([] !== $context) {
        $parts = [];
        foreach ($context as $key => $value) {
            if (is_bool($value)) {
                $parts[] = $key.'='.($value ? 'true' : 'false');
            } else {
                $parts[] = $key.'='.(string) $value;
            }
        }
        $line .= ' '.implode(' ', $parts);
    }
    $line .= "\n";

    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * @return list<string>
 */
function readInstallerLog(string $logDirectory, int $maxLines = 200): array
{
    $path = installerLogPath($logDirectory);
    if (!is_file($path)) {
        return [];
    }

    $content = @file_get_contents($path);
    if (!is_string($content) || '' === $content) {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    /** @var list<string> $nonEmptyLines */
    $nonEmptyLines = array_values(array_filter($lines, static fn (string $line): bool => '' !== $line));
    if (count($nonEmptyLines) > $maxLines) {
        $nonEmptyLines = array_slice($nonEmptyLines, -$maxLines);
    }

    return $nonEmptyLines;
}

/**
 * @param array{
 *     previous_version?: ?string,
 *     new_version: string,
 *     files_added?: int,
 *     files_removed?: int,
 *     dirs_removed?: int,
 *     errors?: int,
 *     same_version?: bool
 * } $context
 */
function logInstallerPackageSummary(
    string $logDirectory,
    string $packageType,
    string $packageId,
    array $context,
): void {
    $newVersion = (string) ($context['new_version'] ?? '');
    $previousVersion = isset($context['previous_version']) ? (string) $context['previous_version'] : null;
    $sameVersion = (bool) ($context['same_version'] ?? (null !== $previousVersion && $previousVersion === $newVersion));

    $level = ($context['errors'] ?? 0) > 0 ? 'warning' : 'info';
    $headline = $sameVersion
        ? sprintf('Package %s reinstall (same version)', $packageType)
        : sprintf('Package %s update %s → %s', $packageType, $previousVersion ?? 'none', $newVersion);

    logInstallerEvent(
        $logDirectory,
        $level,
        $headline,
        [
            'package_type' => $packageType,
            'package_id' => $packageId,
            'previous_version' => $previousVersion ?? '',
            'new_version' => $newVersion,
            'same_version' => $sameVersion,
            'files_added' => (int) ($context['files_added'] ?? 0),
            'files_removed' => (int) ($context['files_removed'] ?? 0),
            'dirs_removed' => (int) ($context['dirs_removed'] ?? 0),
            'errors' => (int) ($context['errors'] ?? 0),
        ]
    );
}

function getDirectorySize(string $directory): int
{
    if (!is_dir($directory)) {
        return 0;
    }

    $total = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            \assert($item instanceof SplFileInfo);
            if ($item->isFile()) {
                $size = $item->getSize();
                if (is_int($size) && $size > 0) {
                    $total += $size;
                }
            }
        }
    } catch (Exception) {
        return $total;
    }

    return $total;
}

function formatFileSize(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = (int) floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);
    $value = $bytes / (1024 ** $power);
    $formatted = number_format($value, $power > 0 ? 1 : 0, '.', '');

    if (str_contains($formatted, '.')) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted.$units[$power];
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

    $protectedPrefixes = [];
    foreach (glob($cacheDir.'/*', GLOB_ONLYDIR) ?: [] as $childDir) {
        $baseName = basename($childDir);
        if (in_array($baseName, ['log', 'logs'], true)) {
            $protectedPrefixes[] = str_replace('\\', '/', rtrim($childDir, '/'));
        }
    }

    $isProtected = static function (string $path) use ($protectedPrefixes): bool {
        $normalized = str_replace('\\', '/', $path);
        foreach ($protectedPrefixes as $prefix) {
            if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/')) {
                return true;
            }
        }

        return false;
    };

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            \assert($item instanceof SplFileInfo);
            $path = $item->getPathname();
            if ($isProtected($path)) {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
                ++$result['deleted_count'];
            }
        }
    } catch (Exception $e) {
        $result['errors'][] = $cacheDir.': '.$e->getMessage();
    }

    if ([] === $protectedPrefixes) {
        @rmdir($cacheDir);
    }

    return $result;
}

/**
 * @return array{deleted_count: int, preserved: array<string>, failed: array<string>}
 */
function cleanTargetDirectory(string $targetDir, string $logContext = ''): array
{
    $preserved = [];
    $failed = [];
    $deletedCount = 0;

    if (!is_dir($targetDir)) {
        return ['deleted_count' => 0, 'preserved' => [], 'failed' => []];
    }

    $preservePaths = [];

    foreach (['runner', 'data'] as $systemDir) {
        $fullPath = rtrim($targetDir, '/').'/'.$systemDir;
        if (is_dir($fullPath)) {
            $preservePaths[] = $fullPath;
        }
    }

    foreach (['public/update', '.env.local'] as $criticalPath) {
        $fullPath = rtrim($targetDir, '/').'/'.$criticalPath;

        if (file_exists($fullPath)) {
            $preservePaths[] = $fullPath;
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        \assert($item instanceof SplFileInfo);
        $path = $item->getPathname();

        $shouldPreserve = false;
        foreach ($preservePaths as $preservePath) {
            if ($path === $preservePath || str_starts_with($path, (string) $preservePath.'/')) {
                $shouldPreserve = true;
                break;
            }
        }

        if (!$shouldPreserve) {
            $deleted = false;
            if ($item->isDir()) {
                if (@rmdir($path)) {
                    $deleted = true;
                }
            } else {
                if (@unlink($path)) {
                    $deleted = true;
                    ++$deletedCount;
                }
            }
            if ($deleted) {
                $relative = substr($path, strlen($targetDir) + 1);
                logInstallerEvent(
                    $logContext,
                    'info',
                    'Removed leftover from previous install',
                    ['relative_path' => $relative]
                );
            } else {
                $lastError = error_get_last();
                $reason = null !== $lastError ? $lastError['message'] : 'unknown';
                $failed[] = $path;
                logInstallerEvent(
                    $logContext,
                    'warning',
                    'Could not remove leftover (left in place)',
                    [
                        'absolute_path' => $path,
                        'is_dir' => $item->isDir(),
                        'reason' => $reason,
                    ]
                );
            }
        } else {
            $preserved[] = substr($path, strlen($targetDir) + 1);
        }
    }

    logInstallerEvent(
        $logContext,
        'info',
        'Initial target directory cleanup finished',
        [
            'deleted_count' => $deletedCount,
            'preserved_count' => count($preserved),
            'failed_count' => count($failed),
        ]
    );

    return ['deleted_count' => $deletedCount, 'preserved' => $preserved, 'failed' => $failed];
}

/**
 * @param array<string> $excludeFolders
 * @param array<string> $excludeFiles
 *
 * @return array{extracted: array<string>, skipped_files: array<string>, skipped_folders: array<string>}
 */
function extractZip(string $zipContent, string $targetDir, array $excludeFolders, array $excludeFiles): array
{
    $tempFile = tempnam(sys_get_temp_dir(), 'oak_installer_');
    file_put_contents($tempFile, $zipContent);
    try {
        $zip = new ZipArchive();
        if (true !== $zip->open($tempFile)) {
            throw new RuntimeException('Failed to open ZIP');
        }
        $tempExtractDir = rtrim($targetDir, '/').'/.oak_installer_'.uniqid();
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

        foreach ($iterator as $item) {
            \assert($item instanceof SplFileInfo);
            $absolutePath = $item->getPathname();
            $relativePath = substr($absolutePath, strlen($sourceDir) + 1);
            $relativePathNormalized = str_replace('\\', '/', $relativePath);
            $parentDir = dirname($relativePathNormalized);
            if ('.' === $parentDir) {
                $parentDir = '';
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
                    if (!\Oak\Engine\Installer\createDirectoryTree($targetPath, 0o755)) {
                        throw new RuntimeException('Target directory cannot be created: '.$targetPath);
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
            \assert($item instanceof SplFileInfo);
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
