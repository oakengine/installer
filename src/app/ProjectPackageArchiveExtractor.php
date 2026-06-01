<?php

declare(strict_types=1);

namespace Oak\Engine\Installer;

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
