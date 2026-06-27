<?php

declare(strict_types=1);

function extractSemverFromTag(string $tag): ?string
{
    $normalized = ltrim(trim($tag), 'vV');
    if (1 === preg_match('/^\d+\.\d+\.\d+$/', $normalized)) {
        return $normalized;
    }

    return null;
}

function comparePackageVersionsDesc(string $a, string $b): int
{
    $semverA = extractSemverFromTag($a);
    $semverB = extractSemverFromTag($b);
    if (null !== $semverA && null !== $semverB) {
        return version_compare($semverB, $semverA);
    }

    if (null !== $semverA) {
        return -1;
    }

    if (null !== $semverB) {
        return 1;
    }

    return strnatcasecmp($b, $a);
}

function formatPackageSize(int $bytes): string
{
    if ($bytes < 1024) {
        return sprintf('%d B', $bytes);
    }

    if ($bytes < 1024 * 1024) {
        return sprintf('%0.1f KB', $bytes / 1024);
    }

    return sprintf('%0.1f MB', $bytes / 1024 / 1024);
}

function formatVersionBadge(string $version): string
{
    $version = trim($version);
    if (1 === preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $version, $matches)) {
        return '<code>'.htmlspecialchars(trim($matches[1])).'</code> <span class="status-badge">'.htmlspecialchars(trim($matches[2])).'</span>';
    }

    return '<code>'.htmlspecialchars($version).'</code>';
}

function resolveInstalledProjectVersion(string $targetDir): string
{
    $composerPath = rtrim($targetDir, '/').'/composer.json';
    $decoded = readComposerJsonMetadata($composerPath);
    if ([] !== $decoded) {
        $extra = is_array($decoded['extra'] ?? null) ? $decoded['extra'] : [];
        $runnerMeta = is_array($extra['oak-engine-runner'] ?? null) ? $extra['oak-engine-runner'] : [];
        /** @var array<string, mixed> $runner */
        $runner = $runnerMeta;
        if (isset($runner['version']) && is_scalar($runner['version'])) {
            $version = trim((string) $runner['version']);
            if ('' !== $version) {
                $channel = resolveInstalledPackageChannel($runner);

                return 'unknown' !== $channel ? sprintf('%s (%s)', $version, $channel) : $version;
            }
        }
    }

    return 'unknown';
}

/**
 * @return array<string, mixed>
 */
function readComposerJsonMetadata(string $composerPath): array
{
    if (!is_file($composerPath)) {
        return [];
    }

    $content = file_get_contents($composerPath);
    if (!is_string($content)) {
        return [];
    }

    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return [];
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

function resolveComposerPackageVersion(string $composerPath): string
{
    $decoded = readComposerJsonMetadata($composerPath);
    if ([] === $decoded) {
        return '';
    }

    $version = $decoded['version'] ?? null;
    if (!is_scalar($version)) {
        return '';
    }

    return trim((string) $version);
}

/**
 * @return list<array{name: string, version: string, channel: string}>
 */
function resolveInstalledPackages(string $targetDir, string $packageType): array
{
    $normalizedPackageType = normalizePackageType($packageType);
    $metadataKey = match ($normalizedPackageType) {
        'plugin' => 'oak-engine-plugin',
        'data' => 'oak-engine-data',
        default => 'oak-engine-runner',
    };
    $scanDir = rtrim($targetDir, '/');
    if ('runner' !== $normalizedPackageType) {
        $scanDir .= '/'.('plugin' === $normalizedPackageType ? 'runner' : 'data');
    }

    if (!is_dir($scanDir)) {
        return [];
    }

    $packages = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scanDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $item) {
        \assert($item instanceof SplFileInfo);
        if (!$item->isFile() || 'composer.json' !== $item->getBasename()) {
            continue;
        }

        $content = file_get_contents($item->getPathname());
        if (!is_string($content)) {
            continue;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            continue;
        }

        $extra = is_array($decoded['extra'] ?? null) ? $decoded['extra'] : [];
        $metaRaw = is_array($extra[$metadataKey] ?? null) ? $extra[$metadataKey] : [];
        /** @var array<string, mixed> $metadata */
        $metadata = $metaRaw;
        if ([] === $metadata) {
            continue;
        }

        $version = '';
        if (isset($metadata['version']) && is_scalar($metadata['version'])) {
            $version = trim((string) $metadata['version']);
        }

        if ('' === $version) {
            continue;
        }

        $channel = resolveInstalledPackageChannel($metadata);

        $composerName = '';
        if (isset($decoded['name']) && is_scalar($decoded['name'])) {
            $composerName = trim((string) $decoded['name']);
        }

        $displayName = resolveInstalledPackageDisplayName($composerName, dirname($item->getPathname()), $scanDir);
        $packages[strtolower($displayName.'|'.$version.'|'.$channel)] = [
            'name' => $displayName,
            'version' => $version,
            'channel' => $channel,
        ];
    }

    $packages = array_values($packages);
    usort(
        $packages,
        static fn (array $left, array $right): int => [$left['name'], $left['version'], $left['channel']]
            <=> [$right['name'], $right['version'], $right['channel']],
    );

    return $packages;
}

function resolveInstalledPackageDisplayName(string $composerName, string $packageDirectory, string $targetDir): string
{
    $normalizedTargetDir = rtrim(str_replace('\\', '/', $targetDir), '/');
    $normalizedPackageDirectory = str_replace('\\', '/', $packageDirectory);

    if (str_starts_with($normalizedPackageDirectory, $normalizedTargetDir.'/')) {
        $relativePath = substr($normalizedPackageDirectory, strlen($normalizedTargetDir) + 1);
        $parts = '' !== $relativePath ? explode('/', $relativePath) : [];

        return $parts[0] ?? basename($packageDirectory);
    }

    return '' !== $composerName ? basename(str_replace('\\', '/', $composerName)) : basename($packageDirectory);
}

/**
 * @param array<string, mixed> $metadata
 */
function resolveInstalledPackageChannel(array $metadata): string
{
    foreach (['channel', 'chanel'] as $key) {
        if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
            $normalized = trim((string) $metadata[$key]);
            if ('' !== $normalized) {
                return $normalized;
            }
        }
    }

    return 'unknown';
}

function resolvePackageInstallTargetDir(string $targetDir, string $packageType, string $packageDir = ''): string
{
    $normalizedTargetDir = rtrim($targetDir, '/');
    $normalizedType = normalizePackageType($packageType);

    if ('runner' === $normalizedType) {
        return $normalizedTargetDir;
    }

    $normalizedPackageDir = trim($packageDir, '/');
    if ('' === $normalizedPackageDir) {
        throw new InvalidArgumentException(sprintf('A package directory is required for %s packages.', $normalizedType));
    }

    return match ($normalizedType) {
        'plugin' => $normalizedTargetDir.'/runner/'.$normalizedPackageDir,
        'data' => $normalizedTargetDir.'/data/'.$normalizedPackageDir,
        default => $normalizedTargetDir,
    };
}

function normalizePackageType(mixed $value): string
{
    if (!is_scalar($value)) {
        return 'runner';
    }

    $normalized = trim((string) $value);

    return in_array($normalized, ['runner', 'plugin', 'data'], true) ? $normalized : 'runner';
}

/**
 * @param array<string, mixed> $composerMetadata
 */
function resolvePackageInstallDirFromMetadata(array $composerMetadata, string $packageType): string
{
    $envConfig = extractPackageEnvConfig($composerMetadata, $packageType);
    $dir = $envConfig['dir'] ?? null;
    if (is_string($dir)) {
        $normalized = trim($dir);
        if ('' !== $normalized) {
            return $normalized;
        }
    }

    $composerName = $composerMetadata['name'] ?? null;
    if (is_string($composerName)) {
        $normalized = trim(str_replace('\\', '/', $composerName));
        if ('' !== $normalized) {
            $basename = basename($normalized);
            if ('' !== $basename) {
                return $basename;
            }
        }
    }

    throw new RuntimeException(sprintf('Cannot determine install directory for %s package (missing env.dir and composer name).', $packageType));
}
