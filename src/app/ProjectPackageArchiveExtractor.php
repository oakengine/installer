<?php

declare(strict_types=1);

namespace Oak\Engine\Installer;

final readonly class ProjectPackageArchiveExtractor
{
    /**
     * @param list<string> $tarBinaryCandidates
     */
    public function __construct(
        private string $temporaryDirectory = '',
        private array $tarBinaryCandidates = ['/bin/tar', '/usr/bin/tar', '/usr/local/bin/tar'],
        private string $pathEnvironment = '',
    ) {
    }

    /**
     * @param array<string> $excludeFolders
     * @param array<string> $excludeFiles
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
    ): array {
        $tempDirectory = $this->resolveTempBase().'/project_package_'.bin2hex(random_bytes(8));
        if (!createDirectoryTree($tempDirectory, 0o755)) {
            throw new \RuntimeException(sprintf('Unable to create temp directory "%s".', $tempDirectory));
        }

        $gzFile = $tempDirectory.'/package.tar.gz';

        try {
            file_put_contents($gzFile, $archiveContent);

            return $this->extractGzFileIntoTarget($gzFile, $tempDirectory, $targetDir, $excludeFolders, $excludeFiles);
        } finally {
            $this->recursiveDelete($tempDirectory);
        }
    }

    /**
     * @param array<string> $excludeFolders
     * @param array<string> $excludeFiles
     *
     * @return array{
     *     extracted: list<string>,
     *     skipped_files: list<string>,
     *     skipped_folders: list<string>
     * }
     */
    public function extractTarGzFile(
        string $archivePath,
        string $targetDir,
        array $excludeFolders,
        array $excludeFiles,
    ): array {
        if (!is_file($archivePath)) {
            throw new \RuntimeException(sprintf('Package archive "%s" does not exist.', $archivePath));
        }

        $tempDirectory = $this->resolveTempBase().'/project_package_'.bin2hex(random_bytes(8));
        if (!createDirectoryTree($tempDirectory, 0o755)) {
            throw new \RuntimeException(sprintf('Unable to create temp directory "%s".', $tempDirectory));
        }

        try {
            return $this->extractGzFileIntoTarget($archivePath, $tempDirectory, $targetDir, $excludeFolders, $excludeFiles);
        } finally {
            $this->recursiveDelete($tempDirectory);
        }
    }

    /**
     * @param array<string> $excludeFolders
     * @param array<string> $excludeFiles
     *
     * @return array{
     *     extracted: list<string>,
     *     skipped_files: list<string>,
     *     skipped_folders: list<string>
     * }
     */
    private function extractGzFileIntoTarget(
        string $gzFile,
        string $tempDirectory,
        string $targetDir,
        array $excludeFolders,
        array $excludeFiles,
    ): array {
        $extractionDirectory = $tempDirectory.'/extract';
        createDirectoryTree($extractionDirectory, 0o755);

        $this->streamExtractGzTar($gzFile, $extractionDirectory);

        $sourceDirectory = $this->resolveSourceDirectory($extractionDirectory);

        return $this->copyExtractedDirectory(
            $sourceDirectory,
            $targetDir,
            $excludeFolders,
            $excludeFiles,
        );
    }

    private function resolveTempBase(): string
    {
        return '' !== $this->temporaryDirectory ? $this->temporaryDirectory : sys_get_temp_dir();
    }

    private function streamExtractGzTar(string $gzFile, string $extractionDirectory): void
    {
        $tarBinary = $this->resolveTarBinary();
        if (null !== $tarBinary) {
            $this->streamExtractGzTarWithBinary($tarBinary, $gzFile, $extractionDirectory);

            return;
        }

        $this->streamExtractGzTarWithPhar($gzFile, $extractionDirectory);
    }

    private function resolveTarBinary(): ?string
    {
        foreach ($this->tarBinaryCandidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $pathEnv = '' !== $this->pathEnvironment ? $this->pathEnvironment : getenv('PATH');
        if (is_string($pathEnv) && '' !== $pathEnv) {
            foreach (explode(\PATH_SEPARATOR, $pathEnv) as $directory) {
                $candidate = rtrim($directory, '/').'/tar';
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function streamExtractGzTarWithBinary(string $tarBinary, string $gzFile, string $extractionDirectory): void
    {
        $absoluteArchive = realpath($gzFile);
        if (false === $absoluteArchive) {
            throw new \RuntimeException(sprintf('Package archive "%s" does not exist.', $gzFile));
        }

        $command = [
            $tarBinary,
            '--extract',
            '--gzip',
            '--no-same-owner',
            '--no-same-permissions',
            '--file='.$absoluteArchive,
            '--directory='.$extractionDirectory,
        ];

        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        \assert(is_resource($process));

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (0 !== $exitCode) {
            throw new \RuntimeException(sprintf('Failed to extract package archive (exit %d): %s', $exitCode, '' !== $stderr ? trim($stderr) : (is_string($stdout) ? trim($stdout) : '')));
        }
    }

    private function streamExtractGzTarWithPhar(string $gzFile, string $extractionDirectory): void
    {
        $phar = new \PharData($gzFile);
        $phar->decompress();

        $tarFile = preg_replace('/\.gz$/i', '', $gzFile);
        \assert(is_string($tarFile) && is_file($tarFile));

        try {
            $archive = new \PharData($tarFile);
            $archive->extractTo($extractionDirectory, null, true);
        } finally {
            @unlink($tarFile);
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
     *
     * @return array{extracted: list<string>, skipped_files: list<string>, skipped_folders: list<string>}
     */
    private function copyExtractedDirectory(
        string $sourceDir,
        string $targetDir,
        array $excludeFolders,
        array $excludeFiles,
    ): array {
        $extractedFiles = [];
        $skippedFiles = [];
        $skippedFolders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);

            $absolutePath = $item->getPathname();
            $relativePath = substr($absolutePath, strlen($sourceDir) + 1);
            $relativePathNormalized = str_replace('\\', '/', (string) $relativePath);
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
            } elseif (!is_writable($targetDirectoryPath)) {
                @chmod($targetDirectoryPath, 0o755);
            }

            if (file_exists($targetPath) && !is_writable($targetPath)) {
                @chmod($targetPath, 0o644);
            }

            if (!@copy($absolutePath, $targetPath)) {
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
            \assert($item instanceof \SplFileInfo);

            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
