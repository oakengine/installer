<?php

declare(strict_types=1);

/**
 * Validates a ZIP entry path against path-traversal attacks (Zip Slip).
 *
 * Returns false for:
 *  - Empty paths
 *  - Paths containing NUL bytes
 *  - Absolute paths (Unix "/foo" or Windows "C:\\foo" / "C:/foo")
 *  - Paths that contain ".." segments
 */
function isZipEntryPathSafe(string $entryName): bool
{
    if ('' === $entryName) {
        return false;
    }

    if (str_contains($entryName, "\0")) {
        return false;
    }

    $normalized = str_replace('\\', '/', $entryName);

    if (str_starts_with($normalized, '/')) {
        return false;
    }

    if (1 === preg_match('~^[A-Za-z]:[\\\\/]~', $normalized)) {
        return false;
    }

    $segments = explode('/', $normalized);
    foreach ($segments as $segment) {
        if ('' === $segment || '.' === $segment) {
            continue;
        }
        if ('..' === $segment) {
            return false;
        }
    }

    return true;
}

/**
 * Opens a ZIP archive and validates every entry's path against path-traversal
 * attacks BEFORE any file is extracted to disk. Returns the open ZipArchive
 * and the number of entries. Caller must close the ZipArchive when done.
 *
 * @return array{zip: ZipArchive, entry_count: int}
 */
function openZipForSafeExtraction(string $zipFile): array
{
    if (!is_file($zipFile)) {
        throw new RuntimeException('ZIP file does not exist: '.$zipFile);
    }

    $zip = new ZipArchive();
    if (true !== $zip->open($zipFile)) {
        throw new RuntimeException('Failed to open ZIP: '.$zipFile);
    }

    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) {
            $zip->close();
            throw new RuntimeException('Failed to read ZIP entry at index '.$i);
        }
        $entryName = (string) ($stat['name'] ?? '');
        if (!isZipEntryPathSafe($entryName)) {
            $zip->close();
            throw new RuntimeException('ZIP entry has unsafe path (Zip Slip): '.$entryName);
        }
    }

    return ['zip' => $zip, 'entry_count' => $zip->numFiles];
}

/**
 * Validates a tar entry path against path-traversal attacks.
 * Mirrors isZipEntryPathSafe() for tar archives.
 */
function isTarEntryPathSafe(string $entryName): bool
{
    if ('' === $entryName) {
        return false;
    }

    if (str_contains($entryName, "\0")) {
        return false;
    }

    $normalized = str_replace('\\', '/', $entryName);

    if (str_starts_with($normalized, '/')) {
        return false;
    }

    if (1 === preg_match('~^[A-Za-z]:[\\\\/]~', $normalized)) {
        return false;
    }

    $segments = explode('/', $normalized);
    foreach ($segments as $segment) {
        if ('' === $segment || '.' === $segment) {
            continue;
        }
        if ('..' === $segment) {
            return false;
        }
    }

    return true;
}