<?php

declare(strict_types=1);

use Oak\Engine\Installer\AppSecretManager;
use Oak\Engine\Installer\InstallManifestManager;
use Oak\Engine\Installer\InstallUuidManager;
use Oak\Engine\Installer\ProjectPackageApiClient;
use Oak\Engine\Installer\ProjectPackageArchiveExtractor;

/**
 * Runs a single package installation (download -> clean -> extract ->
 * env sync -> manifest -> stale cleanup). Shared by the per-package install
 * button and the first-installation wizard ("install all").
 *
 * @param array{
 *     package_type: string,
 *     client: ProjectPackageApiClient,
 *     target_dir: string,
 *     package_id: string,
 *     package_version: string,
 *     exclude_folders: array<string>,
 *     exclude_files: array<string>,
 *     manifest_manager: InstallManifestManager,
 *     archive_extractor: ProjectPackageArchiveExtractor,
 *     install_uuid_manager: InstallUuidManager,
 *     app_secret_manager: AppSecretManager,
 *     log_context: string
 * } $params
 *
 * @return array{
 *     ok: bool,
 *     error: string,
 *     package_type: string,
 *     package_id: string,
 *     package_version: string,
 *     previous_version: ?string,
 *     target_dir: string,
 *     extracted_count: int,
 *     skipped_files_count: int,
 *     skipped_folders_count: int,
 *     clean_deleted_count: int,
 *     clean_preserved_count: int,
 *     clean_failed_count: int,
 *     stale_deleted_count: int,
 *     stale_dirs_count: int,
 *     stale_error_count: int,
 *     stale_error_samples: list<string>,
 *     env_written_lines: list<string>,
 *     env_skipped_lines: list<string>
 * }
 */
function runPackageInstallation(array $params): array
{
    $packageType = normalizePackageType($params['package_type'] ?? null);
    /** @var ProjectPackageApiClient $client */
    $client = $params['client'];
    $targetDir = (string) $params['target_dir'];
    $packageId = trim((string) ($params['package_id'] ?? ''));
    $packageVersion = trim((string) ($params['package_version'] ?? ''));

    $failure = static function (string $error) use ($packageType, $packageId): array {
        return [
            'ok' => false,
            'error' => $error,
            'package_type' => $packageType,
            'package_id' => $packageId,
            'package_version' => '',
            'previous_version' => null,
            'target_dir' => '',
            'extracted_count' => 0,
            'extracted_files_sample' => [],
            'skipped_files_count' => 0,
            'skipped_folders_count' => 0,
            'clean_deleted_count' => 0,
            'clean_preserved_count' => 0,
            'clean_failed_count' => 0,
            'stale_deleted_count' => 0,
            'stale_dirs_count' => 0,
            'stale_error_count' => 0,
            'stale_error_samples' => [],
            'stale_deleted_samples' => [],
            'clean_preserved_samples' => [],
            'clean_failed_samples' => [],
            'env_written_lines' => [],
            'env_skipped_lines' => [],
        ];
    };

    if ('' === $packageId) {
        return $failure('No package specified.');
    }

    /** @var array<string, mixed> $package */
    $package = $client->getPackage($packageId, '' !== $packageVersion ? $packageVersion : null);
    $packageArchivePath = $client->downloadPackage($package['package_id'], $package['version']);

    $packageDir = 'runner' === $packageType
        ? ''
        : resolvePackageInstallDirFromMetadata(is_array($package['composer'] ?? null) ? $package['composer'] : [], $packageType);
    $packageTargetDir = resolvePackageInstallTargetDir($targetDir, $packageType, $packageDir);

    /** @var InstallManifestManager $manifestManager */
    $manifestManager = $params['manifest_manager'];
    /** @var ProjectPackageArchiveExtractor $archiveExtractor */
    $archiveExtractor = $params['archive_extractor'];
    /** @var InstallUuidManager $installUuidManager */
    $installUuidManager = $params['install_uuid_manager'];
    /** @var AppSecretManager $appSecretManager */
    $appSecretManager = $params['app_secret_manager'];

    $logContext = (string) ($params['log_context'] ?? '');
    $oldManifest = $manifestManager->loadManifest($packageTargetDir);
    $cleanResult = ['deleted_count' => 0, 'preserved' => [], 'failed' => []];
    if (null === $oldManifest) {
        logInstallerEvent(
            $logContext,
            'info',
            'Starting install without previous manifest: cleaning target directory',
            ['package_type' => $packageType, 'target_dir' => $packageTargetDir]
        );
        $cleanResult = cleanTargetDirectory($packageTargetDir, $logContext);
    } else {
        logInstallerEvent(
            $logContext,
            'info',
            'Starting install with previous manifest',
            [
                'package_type' => $packageType,
                'previous_version' => $oldManifest['version'],
                'tracked_files' => count($oldManifest['files']),
            ]
        );
    }

    try {
        $extractResult = $archiveExtractor->extractTarGzFile(
            $packageArchivePath,
            $packageTargetDir,
            is_array($params['exclude_folders'] ?? null) ? $params['exclude_folders'] : [],
            is_array($params['exclude_files'] ?? null) ? $params['exclude_files'] : [],
        );
    } finally {
        if (is_file($packageArchivePath)) {
            @unlink($packageArchivePath);
        }
    }

    $envPath = rtrim($targetDir, '/').'/.env.local';
    $composerMetadataSources = resolveProjectEnvComposerMetadataSources($targetDir);
    $envSyncResult = syncPackageEnvComposerMetadataSourcesToEnvLocalDetailed(
        $envPath,
        $composerMetadataSources,
    );

    if ('runner' === $packageType) {
        $installUuidManager->ensureEnvLocalInstallUuid($envPath);
        $appSecretManager->ensureEnvLocalAppSecret($envPath);
    }

    /** @var list<string> $extractedFiles */
    $extractedFiles = $extractResult['extracted'];
    $newManifest = $manifestManager->buildManifest(
        $packageTargetDir,
        $packageType,
        $package['package_id'],
        $package['version'],
        $extractedFiles,
    );
    $manifestManager->saveManifest($packageTargetDir, $newManifest);

    $staleFiles = $manifestManager->diffStaleFiles($oldManifest, $newManifest);
    $staleResult = ['deleted_files' => [], 'deleted_dirs' => [], 'errors' => []];
    $extraRemovedDirs = [];
    if ([] !== $staleFiles) {
        logInstallerEvent(
            $logContext,
            'info',
            'Starting stale file cleanup',
            [
                'package_type' => $packageType,
                'previous_version' => null !== $oldManifest ? $oldManifest['version'] : '',
                'new_version' => $package['version'],
                'stale_files' => count($staleFiles),
            ]
        );
        $staleResult = $manifestManager->deleteStaleFilesAndEmptyDirs(
            $packageTargetDir,
            $staleFiles,
            $logContext
        );
    } else {
        logInstallerEvent(
            $logContext,
            'info',
            'No stale files to remove',
            ['package_type' => $packageType, 'new_version' => $package['version']]
        );
        $extraRemovedDirs = $manifestManager->removeEmptyDirectoriesIn($packageTargetDir, $logContext);
    }

    $cleanFailedCount = count($cleanResult['failed'] ?? []);
    $staleDeletedCount = count($staleResult['deleted_files']);
    $staleDirsCount = count($staleResult['deleted_dirs']) + count($extraRemovedDirs);
    $staleErrorCount = count($staleResult['errors']);

    $previousVersion = null !== $oldManifest ? $oldManifest['version'] : null;
    logInstallerPackageSummary(
        $logContext,
        $packageType,
        (string) $package['package_id'],
        [
            'previous_version' => $previousVersion,
            'new_version' => (string) $package['version'],
            'files_added' => count($extractedFiles),
            'files_removed' => $staleDeletedCount + (int) $cleanResult['deleted_count'],
            'dirs_removed' => $staleDirsCount,
            'errors' => $staleErrorCount + $cleanFailedCount,
        ]
    );

    return [
        'ok' => true,
        'error' => '',
        'package_type' => $packageType,
        'package_id' => (string) $package['package_id'],
        'package_version' => (string) $package['version'],
        'previous_version' => $previousVersion,
        'target_dir' => $packageTargetDir,
        'extracted_count' => count($extractedFiles),
        'extracted_files_sample' => array_slice(array_map('strval', $extractedFiles), 0, 50),
        'skipped_files_count' => count($extractResult['skipped_files']),
        'skipped_folders_count' => count($extractResult['skipped_folders']),
        'clean_deleted_count' => (int) $cleanResult['deleted_count'],
        'clean_preserved_count' => count($cleanResult['preserved']),
        'clean_failed_count' => $cleanFailedCount,
        'stale_deleted_count' => $staleDeletedCount,
        'stale_dirs_count' => $staleDirsCount,
        'stale_error_count' => $staleErrorCount,
        'stale_error_samples' => array_slice(array_map('strval', $staleResult['errors']), 0, 10),
        'stale_deleted_samples' => array_slice(array_map('strval', $staleResult['deleted_files']), 0, 20),
        'clean_preserved_samples' => array_slice(array_map('strval', $cleanResult['preserved']), 0, 20),
        'clean_failed_samples' => array_slice(array_map('strval', $cleanResult['failed'] ?? []), 0, 20),
        'env_written_lines' => $envSyncResult['written_lines'] ?? [],
        'env_skipped_lines' => $envSyncResult['skipped_existing_lines'] ?? [],
    ];
}

/**
 * Picks the wizard's default package from a package list: the preferred id
 * when present, otherwise the newest package that is not a public demo
 * package ("example"), otherwise the first entry.
 *
 * @param list<array<string, mixed>> $packages
 *
 * @return array<string, mixed>|null
 */
function pickSetupPackage(string $preferredPackageId, array $packages): ?array
{
    if ('' !== $preferredPackageId) {
        foreach ($packages as $package) {
            if ((string) ($package['package_id'] ?? '') === $preferredPackageId) {
                return $package;
            }
        }
    }

    foreach ($packages as $package) {
        $packageId = (string) ($package['package_id'] ?? '');
        if ('' !== $packageId && 'example' !== $packageId) {
            return $package;
        }
    }

    return $packages[0] ?? null;
}
