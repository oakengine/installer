<?php

declare(strict_types=1);

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

    if ('.htaccess' === $relativePath) {
        return true;
    }

    if ('config.php' === $relativePath) {
        return false;
    }

    if (1 === preg_match('/^[^\/]+\.php$/', $relativePath)) {
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
