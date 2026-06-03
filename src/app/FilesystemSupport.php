<?php

declare(strict_types=1);

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

    // Always preserve Oak Engine system directories (plugins and data)
    foreach (['runner', 'data'] as $systemDir) {
        $fullPath = rtrim($targetDir, '/').'/'.$systemDir;
        if (is_dir($fullPath)) {
            $preservePaths[] = $fullPath;
        }
    }

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
    $tempFile = tempnam(sys_get_temp_dir(), 'oak_installer_');
    file_put_contents($tempFile, $zipContent);
    try {
        $zip = new ZipArchive();
        if (true !== $zip->open($tempFile)) {
            throw new RuntimeException('Failed to open ZIP');
        }
        $tempExtractDir = sys_get_temp_dir().'/oak_installer_'.uniqid();
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
