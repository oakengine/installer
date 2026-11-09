<?php

declare(strict_types=1);

/**
 * Builds the installer bootstrap archive consumed by the srcm "?installer=1"
 * endpoint (storage/installer/dist/<version>.tar.gz).
 *
 * The archive contains the installer source (index.php, app/, lang/, logo/)
 * at the archive root. config.php, logs/ and tmp/ are excluded: config.php
 * must never be shipped (it may contain local credentials) — the install.php
 * bootstrap writes a pre-filled config.php after extraction.
 *
 * Usage:
 *   php installer/bin/build-installer-archive.php <version> [output-dir]
 *   php installer/bin/build-installer-archive.php 1.0.0 ../srcm/storage/installer/dist
 */

$usage = 'Usage: php build-installer-archive.php <version> [output-dir]'.PHP_EOL;

$version = $argv[1] ?? '';
if ('' === $version || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]*$/', $version)) {
    fwrite(STDERR, 'Invalid or missing version.'.PHP_EOL.$usage);

    exit(1);
}

$srcRoot = dirname(__DIR__).'/src';
if (!is_dir($srcRoot)) {
    fwrite(STDERR, sprintf('Installer source directory "%s" not found.', $srcRoot).PHP_EOL);

    exit(1);
}

$outputDir = $argv[2] ?? dirname(__DIR__).'/dist';
if (!is_dir($outputDir) && !mkdir($outputDir, 0o775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, sprintf('Unable to create output directory "%s".', $outputDir).PHP_EOL);

    exit(1);
}

$excludedFiles = ['config.php', 'installed-version.json'];
$excludedDirectories = ['logs', 'tmp'];

$archivePath = $outputDir.'/'.$version.'.tar.gz';

// Prefer the tar binary: deterministic output and no Phar cache pitfalls.
$tarBinary = null;
foreach (['/bin/tar', '/usr/bin/tar', '/usr/local/bin/tar'] as $candidate) {
    if (is_file($candidate) && is_executable($candidate)) {
        $tarBinary = $candidate;

        break;
    }
}

$tempArchive = rtrim(sys_get_temp_dir(), '/').'/oak-installer-build-'.bin2hex(random_bytes(4)).'.tar';
@unlink($tempArchive);
@unlink($archivePath);

$exitCode = 1;
if (null !== $tarBinary) {
    $fileList = $outputDir.'/'.basename($tempArchive).'.list';
    $entries = buildEntryList($srcRoot, $excludedFiles, $excludedDirectories);
    file_put_contents($fileList, implode("\n", $entries)."\n");
    $command = sprintf(
        '%s --create --gzip --file=%s --directory=%s --files-from=%s 2>&1',
        escapeshellarg($tarBinary),
        escapeshellarg($archivePath),
        escapeshellarg($srcRoot),
        escapeshellarg($fileList),
    );
    $output = shell_exec($command);
    unlink($fileList);
    $exitCode = is_file($archivePath) ? 0 : 1;
    if (0 !== $exitCode) {
        fwrite(STDERR, 'tar failed'.(is_string($output) && '' !== trim($output) ? ': '.trim($output) : '').PHP_EOL);
    }
} else {
    // PharData fallback (build uncompressed tar first, then compress).
    $pharPath = preg_replace('/\.tar$/', '', $tempArchive);
    $phar = new PharData($pharPath.'.tar');
    $entries = buildEntryList($srcRoot, $excludedFiles, $excludedDirectories);
    foreach ($entries as $relativePath) {
        $phar->addFile($srcRoot.'/'.$relativePath, $relativePath);
    }
    $phar->compress(Phar::GZ, '.tar.gz');
    $exitCode = rename($pharPath.'.tar.gz', $archivePath) ? 0 : 1;
    @unlink($pharPath.'.tar');
}

@unlink($tempArchive);

if (0 !== $exitCode || !is_file($archivePath)) {
    fwrite(STDERR, 'Archive creation failed.'.PHP_EOL);

    exit(1);
}

printf(
    'Installer archive created: %s (%s, sha256 %s)%s',
    $archivePath,
    formatSize((int) filesize($archivePath)),
    (string) hash_file('sha256', $archivePath),
    PHP_EOL,
);

/**
 * @param list<string> $excludedFiles
 * @param list<string> $excludedDirectories
 *
 * @return list<string>
 */
function buildEntryList(string $srcRoot, array $excludedFiles, array $excludedDirectories): array
{
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        assert($item instanceof SplFileInfo);
        $relativePath = substr($item->getPathname(), strlen($srcRoot) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);
        if ('' === $relativePath) {
            continue;
        }

        if ($item->isDir()) {
            foreach ($excludedDirectories as $excluded) {
                if ($relativePath === $excluded || str_starts_with($relativePath, $excluded.'/')) {
                    continue 2;
                }
            }
            $entries[] = $relativePath;

            continue;
        }

        $parentDirectory = str_replace('\\', '/', dirname($relativePath));
        if (in_array($parentDirectory, $excludedDirectories, true)) {
            continue;
        }
        if (in_array($item->getFilename(), $excludedFiles, true) || in_array($relativePath, $excludedFiles, true)) {
            continue;
        }
        $entries[] = $relativePath;
    }

    sort($entries, SORT_STRING);

    return $entries;
}

function formatSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes.' B';
    }
    foreach (['KB', 'MB', 'GB'] as $unit) {
        $bytes = (int) round($bytes / 1024);
        if ($bytes < 1024) {
            return $bytes.' '.$unit;
        }
    }

    return $bytes.' GB';
}
