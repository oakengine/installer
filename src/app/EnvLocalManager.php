<?php

declare(strict_types=1);

use Oak\Engine\Installer\AppSecretManager;
use Oak\Engine\Installer\InstallUuidManager;

/**
 * @return array{app_env: string, current_db: ?string, databases: array<int, array{id: string, url: string, active: bool}>, install_uuid: ?string, app_secret: ?string, raw_content: string}
 */
function parseEnvLocal(string $envPath): array
{
    $result = [
        'app_env' => 'prod',
        'current_db' => null,
        'databases' => [],
        'install_uuid' => null,
        'app_secret' => null,
        'raw_content' => '',
    ];

    if (!file_exists($envPath)) {
        return $result;
    }

    $content = file_get_contents($envPath);
    if (false === $content) {
        return $result;
    }
    $result['raw_content'] = $content;
    $lines = explode("\n", $result['raw_content']);

    foreach ($lines as $lineNum => $line) {
        $line = trim($line);

        if (preg_match('/^APP_ENV\s*=\s*(dev|prod)/i', $line, $matches)) {
            $result['app_env'] = strtolower($matches[1]);
        }

        if (preg_match('/^#?\s*DATABASE_URL\s*=\s*"([^"]+)"\s*#\s*(.+)$/i', $line, $matches)) {
            $isActive = !str_starts_with(ltrim($lines[$lineNum]), '#');
            $dbId = trim($matches[2]);
            $result['databases'][] = [
                'id' => $dbId,
                'url' => $matches[1],
                'active' => $isActive,
            ];
            if ($isActive) {
                $result['current_db'] = $dbId;
            }
        }

        if (preg_match('/^\s*INSTALL_UUID\s*=\s*("?)([0-9a-fA-F-]+)\1\s*$/', $line, $matches)) {
            $result['install_uuid'] = strtolower($matches[2]);
        }

        if (preg_match('/^\s*APP_SECRET\s*=\s*("?)([^"\s]+)\1\s*$/', $line, $matches)) {
            $candidate = trim($matches[2]);
            if (1 === preg_match('/^[A-Za-z0-9._-]{16,128}$/', $candidate)) {
                $result['app_secret'] = $candidate;
            }
        }
    }

    return $result;
}

function updateEnvLocal(string $envPath, string $appEnv, string $activeDb): bool
{
    if (!file_exists($envPath)) {
        return false;
    }

    $content = file_get_contents($envPath);
    if (false === $content) {
        return false;
    }
    $lines = explode("\n", $content);
    $appEnvWritten = false;

    foreach ($lines as $lineNum => &$line) {
        if (preg_match('/^\s*#?\s*APP_ENV\s*=\s*(dev|prod)/i', $line)) {
            $line = 'APP_ENV='.$appEnv;
            $appEnvWritten = true;
            continue;
        }

        if (preg_match('/^(#?)\s*DATABASE_URL\s*=\s*"[^"]+"\s*#\s*(.+)$/i', $line, $matches)) {
            $isCommented = '#' === $matches[1];
            $dbId = trim($matches[2]);

            if ($dbId === $activeDb && $isCommented) {
                $line = preg_replace('/^#\s*/', '', $line);
            } elseif ($dbId !== $activeDb && !$isCommented) {
                $line = '#'.ltrim($line);
            }
        }
    }
    unset($line);

    if (!$appEnvWritten) {
        $lines[] = 'APP_ENV='.$appEnv;
    }

    return false !== file_put_contents($envPath, implode("\n", $lines));
}

function saveEnvLocalContent(string $envPath, string $content): bool
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $content);

    return false !== file_put_contents($envPath, $normalized);
}

/**
 * Sets (or appends) a `KEY=value` line inside .env.local content.
 *
 * - Existing lines (commented or uncommented) are replaced in place.
 * - Missing keys are appended at the end of the content.
 * - Line endings are normalized to "\n".
 */
function setEnvLocalValue(string $content, string $key, string $value): string
{
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", $content);
    $pattern = '/^\s*#?\s*'.preg_quote($key, '/').'\s*=.*$/m';
    $line = $key.'='.$value;

    if (1 === preg_match($pattern, $normalizedContent)) {
        return (string) preg_replace($pattern, $line, $normalizedContent, 1);
    }

    $trimmedContent = rtrim($normalizedContent, "\n");

    return ('' === $trimmedContent ? '' : $trimmedContent."\n").$line."\n";
}

function addDatabaseToEnvLocal(string $envPath, string $dbId, string $dbUrl): bool
{
    if (!file_exists($envPath)) {
        $defaultContent = "APP_ENV=prod\n";
        $dir = dirname($envPath);
        if (!is_dir($dir)) {
            if (!\Oak\Engine\Installer\createDirectoryTree($dir, 0o755)) {
                return false;
            }
        }
        if (false === file_put_contents($envPath, $defaultContent)) {
            return false;
        }
    }

    $dbId = trim($dbId);
    $dbUrl = trim($dbUrl);

    if ('' === $dbId || '' === $dbUrl) {
        return false;
    }

    if (1 === preg_match('/["\n\r]/', $dbId) || 1 === preg_match('/[\n\r]/', $dbUrl)) {
        return false;
    }

    $envConfig = parseEnvLocal($envPath);
    foreach ($envConfig['databases'] as $database) {
        if (0 === strcasecmp((string) $database['id'], $dbId)) {
            return false;
        }
    }

    $line = '#DATABASE_URL="'.str_replace('"', '\\"', $dbUrl).'" # '.$dbId;
    $content = rtrim((string) file_get_contents($envPath), "\n")."\n".$line."\n";

    return false !== file_put_contents($envPath, $content);
}

function removeDatabaseFromEnvLocal(string $envPath, string $dbId): bool
{
    if (!file_exists($envPath)) {
        return false;
    }

    $dbId = trim($dbId);
    if ('' === $dbId) {
        return false;
    }

    $rawContent = file_get_contents($envPath);
    if (false === $rawContent) {
        return false;
    }
    $lines = explode("\n", $rawContent);
    $keptLines = [];
    $removed = false;

    foreach ($lines as $line) {
        if (preg_match('/^(#?)\s*DATABASE_URL\s*=\s*"[^"]+"\s*#\s*(.+)$/i', $line, $matches)) {
            $currentId = trim($matches[2]);
            if (0 === strcasecmp($currentId, $dbId)) {
                $removed = true;
                continue;
            }
        }

        $keptLines[] = $line;
    }

    if (!$removed) {
        return false;
    }

    return false !== file_put_contents($envPath, implode("\n", $keptLines));
}

function updateInstallUuidInEnvLocal(InstallUuidManager $manager, string $envPath, string $installUuid): bool
{
    $content = '';
    if (file_exists($envPath)) {
        $currentContent = file_get_contents($envPath);
        if (false === $currentContent) {
            return false;
        }
        $content = $currentContent;
    }

    $normalizedUuid = strtolower(trim($installUuid));
    if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $normalizedUuid)) {
        return false;
    }

    $updated = $manager->upsertInstallUuid($content, true);
    $replacedContent = str_replace('INSTALL_UUID='.$updated['uuid'], 'INSTALL_UUID='.$normalizedUuid, $updated['content']);
    $directory = dirname($envPath);
    if (!\Oak\Engine\Installer\createDirectoryTree($directory, 0o755)) {
        return false;
    }

    return false !== file_put_contents($envPath, $replacedContent);
}

function updateAppSecretInEnvLocal(AppSecretManager $manager, string $envPath, string $appSecret): bool
{
    $content = '';
    if (file_exists($envPath)) {
        $currentContent = file_get_contents($envPath);
        if (false === $currentContent) {
            return false;
        }
        $content = $currentContent;
    }

    $normalizedSecret = trim($appSecret);
    if (1 !== preg_match('/^[A-Za-z0-9._-]{16,128}$/', $normalizedSecret)) {
        return false;
    }

    $updated = $manager->upsertAppSecret($content, true);
    $replacedContent = str_replace('APP_SECRET='.$updated['secret'], 'APP_SECRET='.$normalizedSecret, $updated['content']);
    $directory = dirname($envPath);
    if (!\Oak\Engine\Installer\createDirectoryTree($directory, 0o755)) {
        return false;
    }

    return false !== file_put_contents($envPath, $replacedContent);
}

/**
 * @return list<string>
 */
function resolvePackageEnvMetadataKeys(string $packageType): array
{
    return match (normalizePackageType($packageType)) {
        'plugin' => ['oak-engine-plugin'],
        'data' => ['oak-engine-data', 'oak-engine-plugin'],
        default => ['oak-engine-runner', 'oak-engine-plugin'],
    };
}

/**
 * @param array<string, mixed> $composerMetadata
 *
 * @return array<string, mixed>
 */
function extractPackageEnvConfig(array $composerMetadata, string $packageType): array
{
    $extra = is_array($composerMetadata['extra'] ?? null) ? $composerMetadata['extra'] : [];
    foreach (resolvePackageEnvMetadataKeys($packageType) as $metadataKey) {
        $metadata = is_array($extra[$metadataKey] ?? null) ? $extra[$metadataKey] : [];
        $candidateEnv = is_array($metadata['env'] ?? null) ? $metadata['env'] : [];
        if ([] !== $candidateEnv) {
            /** @var array<string, mixed> $candidateEnv */
            return $candidateEnv;
        }
    }

    return [];
}

/**
 * @param array<string, mixed> $composerMetadata
 *
 * @return array{
 *     written: bool,
 *     written_lines: list<string>,
 *     written_keys: list<string>,
 *     skipped_existing_lines: list<string>,
 *     skipped_existing_keys: list<string>
 * }
 */
function syncPackageEnvToEnvLocalDetailed(string $envPath, array $composerMetadata, string $packageType): array
{
    $packageEnv = extractPackageEnvConfig($composerMetadata, $packageType);
    if ([] === $packageEnv) {
        return ['written' => false, 'written_lines' => [], 'written_keys' => [], 'skipped_existing_lines' => [], 'skipped_existing_keys' => []];
    }

    $envVarMap = [
        'dir' => 'OAK_DIR',
        'core-bundle-class' => 'OAK_CORE_BUNDLE_CLASS',
        'language-version' => 'OAK_LANGUAGE_VERSION',
        'default-language' => 'OAK_DEFAULT_LANGUAGE',
        'available-languages' => 'OAK_AVAILABLE_LANGUAGES',
        'default-language-redirect' => 'OAK_DEFAULT_LANGUAGE_REDIRECT',
    ];

    /** @var array<string, string> $linesToAppend */
    $linesToAppend = [];
    /** @var array<string, string> $skippedExistingLines */
    $skippedExistingLines = [];
    $content = '';
    if (is_file($envPath)) {
        $existingContent = file_get_contents($envPath);
        if (false === $existingContent) {
            return ['written' => false, 'written_lines' => [], 'written_keys' => [], 'skipped_existing_lines' => [], 'skipped_existing_keys' => []];
        }
        $content = str_replace(["\r\n", "\r"], "\n", $existingContent);
    }

    foreach ($envVarMap as $sourceKey => $targetKey) {
        $value = $packageEnv[$sourceKey] ?? null;
        if (!is_scalar($value)) {
            continue;
        }

        $normalizedValue = trim((string) $value);
        if ('' === $normalizedValue) {
            continue;
        }

        if (1 === preg_match('/^\s*#?\s*'.preg_quote($targetKey, '/').'\s*=.*$/m', $content)) {
            $skippedExistingLines[$targetKey] = $targetKey.'='.$normalizedValue;
            continue;
        }

        $linesToAppend[$targetKey] = $targetKey.'='.$normalizedValue;
    }

    if ([] === $linesToAppend) {
        return [
            'written' => false,
            'written_lines' => [],
            'written_keys' => [],
            'skipped_existing_lines' => array_values($skippedExistingLines),
            'skipped_existing_keys' => array_keys($skippedExistingLines),
        ];
    }

    $directory = dirname($envPath);
    if (!\Oak\Engine\Installer\createDirectoryTree($directory, 0o755)) {
        return ['written' => false, 'written_lines' => [], 'written_keys' => [], 'skipped_existing_lines' => [], 'skipped_existing_keys' => []];
    }

    $updatedContent = rtrim($content, "\n");
    $updatedContent = '' === $updatedContent
        ? implode("\n", $linesToAppend)."\n"
        : $updatedContent."\n".implode("\n", $linesToAppend)."\n";

    if (false === file_put_contents($envPath, $updatedContent)) {
        return ['written' => false, 'written_lines' => [], 'written_keys' => [], 'skipped_existing_lines' => [], 'skipped_existing_keys' => []];
    }

    return [
        'written' => true,
        'written_lines' => array_values($linesToAppend),
        'written_keys' => array_keys($linesToAppend),
        'skipped_existing_lines' => array_values($skippedExistingLines),
        'skipped_existing_keys' => array_keys($skippedExistingLines),
    ];
}

/**
 * @param array<string, mixed> $composerMetadata
 */
function syncPackageEnvToEnvLocal(string $envPath, array $composerMetadata, string $packageType): bool
{
    return syncPackageEnvToEnvLocalDetailed($envPath, $composerMetadata, $packageType)['written'];
}

/**
 * @return list<array{path: string, package_type: string, metadata: array<string, mixed>}>
 */
function resolveProjectEnvComposerMetadataSources(string $targetDir): array
{
    $scanDir = rtrim($targetDir, '/');
    $rootComposerPath = $scanDir.'/composer.json';
    /** @var array<string, list<string>> $patternsByPackageType */
    $patternsByPackageType = [
        'runner' => [
            $scanDir.'/runner/core/*/*/composer.json',
            $scanDir.'/runner/core/*/composer.json',
            $scanDir.'/runner/*/core/*/*/composer.json',
            $scanDir.'/runner/*/core/*/composer.json',
        ],
        'plugin' => [
            $scanDir.'/runner/plugin/*/*/composer.json',
            $scanDir.'/runner/plugin/*/composer.json',
            $scanDir.'/runner/*/plugin/*/*/composer.json',
            $scanDir.'/runner/*/plugin/*/composer.json',
        ],
    ];

    /** @var list<array{path: string, package_type: string, metadata: array<string, mixed>}> $runnerMetadataSources */
    $runnerMetadataSources = [];
    /** @var list<array{path: string, package_type: string, metadata: array<string, mixed>}> $pluginMetadataSources */
    $pluginMetadataSources = [];

    if (is_file($rootComposerPath)) {
        $rootMetadata = readComposerJsonMetadata($rootComposerPath);
        if ([] !== extractPackageEnvConfig($rootMetadata, 'runner')) {
            $runnerMetadataSources['composer.json'] = [
                'path' => 'composer.json',
                'package_type' => 'runner',
                'metadata' => $rootMetadata,
            ];
        }
    }

    foreach ($patternsByPackageType as $packageType => $patterns) {
        foreach ($patterns as $pattern) {
            $matchedPaths = glob($pattern);
            if (false === $matchedPaths) {
                continue;
            }

            foreach ($matchedPaths as $matchedPath) {
                $normalizedPath = str_replace('\\', '/', $matchedPath);
                $relativePath = substr($normalizedPath, strlen(str_replace('\\', '/', $scanDir)) + 1);
                if ('' === $relativePath) {
                    continue;
                }

                $metadata = readComposerJsonMetadata($normalizedPath);
                if ([] === extractPackageEnvConfig($metadata, $packageType)) {
                    continue;
                }

                $metadataSource = [
                    'path' => $relativePath,
                    'package_type' => $packageType,
                    'metadata' => $metadata,
                ];

                if ('runner' === $packageType) {
                    $runnerMetadataSources[$relativePath] = $metadataSource;

                    continue;
                }

                $pluginMetadataSources[$relativePath] = $metadataSource;
            }
        }
    }

    usort(
        $runnerMetadataSources,
        static fn (array $left, array $right): int => strcmp($left['path'], $right['path']),
    );
    usort(
        $pluginMetadataSources,
        static fn (array $left, array $right): int => strcmp($left['path'], $right['path']),
    );

    return array_merge($runnerMetadataSources, $pluginMetadataSources);
}

/**
 * @param list<array{path: string, package_type: string, metadata: array<string, mixed>}> $composerMetadataSources
 *
 * @return array{
 *     written: bool,
 *     written_lines: list<string>,
 *     skipped_existing_lines: list<string>
 * }
 */
function syncPackageEnvComposerMetadataSourcesToEnvLocalDetailed(string $envPath, array $composerMetadataSources): array
{
    $writtenLines = [];
    $writtenKeys = [];
    $skippedExistingLines = [];
    $skippedExistingKeys = [];

    foreach ($composerMetadataSources as $composerMetadataSource) {
        $result = syncPackageEnvToEnvLocalDetailed(
            $envPath,
            $composerMetadataSource['metadata'],
            $composerMetadataSource['package_type'],
        );

        foreach ($result['written_lines'] as $writtenLine) {
            if (!in_array($writtenLine, $writtenLines, true)) {
                $writtenLines[] = $writtenLine;
            }
        }

        foreach ($result['written_keys'] as $writtenKey) {
            if (!in_array($writtenKey, $writtenKeys, true)) {
                $writtenKeys[] = $writtenKey;
            }
        }

        foreach ($result['skipped_existing_keys'] as $index => $skippedExistingKey) {
            if (in_array($skippedExistingKey, $writtenKeys, true) || in_array($skippedExistingKey, $skippedExistingKeys, true)) {
                continue;
            }

            $skippedExistingKeys[] = $skippedExistingKey;
            $skippedExistingLines[] = $result['skipped_existing_lines'][$index];
        }
    }

    return [
        'written' => [] !== $writtenLines,
        'written_lines' => $writtenLines,
        'skipped_existing_lines' => $skippedExistingLines,
    ];
}

/**
 * @return array<string, mixed>
 */
function resolvePackageEnvComposerMetadata(string $targetDir, string $packageType): array
{
    foreach (resolveProjectEnvComposerMetadataSources($targetDir) as $metadataSource) {
        if (normalizePackageType($metadataSource['package_type']) !== normalizePackageType($packageType)) {
            continue;
        }

        return $metadataSource['metadata'];
    }

    return [];
}
