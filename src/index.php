<?php

declare(strict_types=1);

namespace Oak\Engine\Installer {
    require_once __DIR__.'/Filesystem.php';
    require_once __DIR__.'/InstallUuidManager.php';
    require_once __DIR__.'/ProjectPackageApiClient.php';
    require_once __DIR__.'/ProjectPackageArchiveExtractor.php';
}

namespace {
    use Oak\Engine\Installer\InstallUuidManager;
    use Oak\Engine\Installer\ProjectPackageApiClient;
    use Oak\Engine\Installer\ProjectPackageArchiveExtractor;

    require_once __DIR__.'/GitHubClient.php';
    require_once __DIR__.'/PackageSupport.php';
    require_once __DIR__.'/HtmlRenderer.php';
    require_once __DIR__.'/InstallerUpdater.php';
    require_once __DIR__.'/GitHubRefsCache.php';
    require_once __DIR__.'/Authenticator.php';
    require_once __DIR__.'/FilesystemSupport.php';
    require_once __DIR__.'/EnvLocalManager.php';
    require_once __DIR__.'/MigrationStatus.php';

    /**
     * Oak Engine Installer - package and self-update installer.
     *
     * Installs runner, plugin, and data packages from the package API and
     * self-updates the installer from the GitHub repository.
     */
    if (PHP_SAPI !== 'cli') {
        session_start();
    } else {
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        if (!isset($_SESSION['lang'])) {
            $_SESSION['lang'] = 'en';
        }
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        return;
    }

    $configPath = __DIR__.'/config.php';
    $loadedConfig = file_exists($configPath) ? require $configPath : require __DIR__.'/config.example.php';
    if (!is_array($loadedConfig)) {
        $loadedConfig = [];
    }
    /** @var array<string, mixed> $config */
    $config = [];
    foreach ($loadedConfig as $k => $v) {
        if (is_string($k)) {
            $config[$k] = $v;
        } elseif (is_int($k)) {
            $config[(string) $k] = $v;
        }
    }

    $langDir = __DIR__.'/lang/';
    $foundLangs = glob($langDir.'*.php');
    /** @var array<string> $availableLangs */
    $availableLangs = (false !== $foundLangs) ? array_map(fn ($f) => basename((string) $f, '.php'), $foundLangs) : [];
    $defaultLang = '';
    if (isset($config['default_language']) && is_scalar($config['default_language'])) {
        $defaultLang = (string) $config['default_language'];
    }
    if ('' === $defaultLang) {
        $defaultLang = 'en';
    }

    if (!isset($_SESSION['lang']) || !is_string($_SESSION['lang'])) {
        $_SESSION['lang'] = $defaultLang;
    }
    $getLangStr = '';
    if (isset($_GET['lang']) && (is_string($_GET['lang']) || is_int($_GET['lang']))) {
        $getLangStr = (string) $_GET['lang'];
    }
    if ('' !== $getLangStr) {
        if (in_array($getLangStr, $availableLangs, true)) {
            $_SESSION['lang'] = $getLangStr;
            // Redirect to remove lang param from URL
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            if (!is_string($requestUri)) {
                $requestUri = '/';
            }
            $cleanUrl = strtok($requestUri, '?');
            $params = $_GET;
            unset($params['lang']);
            if (!empty($params)) {
                $cleanUrl .= '?'.http_build_query($params);
            }
            header('Location: '.$cleanUrl);
            exit;
        }
    }

    $sessionLang = 'en';
    if (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) {
        $sessionLang = $_SESSION['lang'];
    }
    $langFile = $langDir.$sessionLang.'.php';
    $loadedLang = file_exists($langFile) ? require $langFile : [];
    if (!is_array($loadedLang)) {
        $loadedLang = [];
    }
    /** @var array<string, string> $lang */
    $lang = [];
    foreach ($loadedLang as $k => $v) {
        $kStr = (string) $k;
        if (is_scalar($v)) {
            $vStr = (string) $v;
            $lang[$kStr] = $vStr;
        }
    }

    /**
     * @param array<string, string>           $lang
     * @param array<string, string|int|float> $placeholders
     */
    function resolveLangKey(string $key, array $lang, array $placeholders = []): string
    {
        $text = (isset($lang[$key])) ? (string) $lang[$key] : $key;
        foreach ($placeholders as $k => $v) {
            $vStr = (string) $v;
            $text = str_replace(':'.$k, $vStr, (string) $text);
        }

        return (string) $text;
    }

    /**
     * @param array<string, string|int|float> $placeholders
     */
    function __(string $key, array $placeholders = []): string
    {
        global $lang;
        /** @var array<string, string> $lang */
        if (!isset($lang) || !is_array($lang)) {
            return $key;
        }

        return resolveLangKey($key, $lang, $placeholders);
    }

    try {
        $token = '';
        if (isset($config['github_token']) && (is_string($config['github_token']) || is_int($config['github_token']))) {
            $token = (string) $config['github_token'];
        }

        $apiBaseUrl = '';
        if (isset($config['api_base_url']) && (is_string($config['api_base_url']) || is_int($config['api_base_url']))) {
            $apiBaseUrl = (string) $config['api_base_url'];
        }
        if ('' === $apiBaseUrl) {
            $apiBaseUrl = 'https://api.github.com';
        }

        $targetDirRelative = '';
        if (isset($config['target_directory']) && (is_string($config['target_directory']) || is_int($config['target_directory']))) {
            $targetDirRelative = (string) $config['target_directory'];
        }
        if ('' === $targetDirRelative) {
            $targetDirRelative = '../';
        }

        $showVersionsBeforeLogin = (bool) ($config['show_versions_before_login'] ?? false);

        $rawExcludeFolders = $config['exclude_folders'] ?? [];
        /** @var array<string> $excludeFolders */
        $excludeFolders = [];
        if (is_array($rawExcludeFolders)) {
            foreach ($rawExcludeFolders as $val) {
                $valStr = (is_scalar($val)) ? (string) $val : '';
                if ('' !== $valStr) {
                    $excludeFolders[] = $valStr;
                }
            }
        }

        $rawExcludeFiles = $config['exclude_files'] ?? [];
        /** @var array<string> $excludeFiles */
        $excludeFiles = [];
        if (is_array($rawExcludeFiles)) {
            foreach ($rawExcludeFiles as $val) {
                $valStr = (is_scalar($val)) ? (string) $val : '';
                if ('' !== $valStr) {
                    $excludeFiles[] = $valStr;
                }
            }
        }

        $rawWhitelistFolders = $config['whitelist_folders'] ?? [];
        /** @var array<string> $whitelistFolders */
        $whitelistFolders = [];
        if (is_array($rawWhitelistFolders)) {
            foreach ($rawWhitelistFolders as $val) {
                $valStr = (is_scalar($val)) ? (string) $val : '';
                if ('' !== $valStr) {
                    $whitelistFolders[] = $valStr;
                }
            }
        }

        $rawWhitelistFiles = $config['whitelist_files'] ?? [];
        /** @var array<string> $whitelistFiles */
        $whitelistFiles = [];
        if (is_array($rawWhitelistFiles)) {
            foreach ($rawWhitelistFiles as $val) {
                $valStr = (is_scalar($val)) ? (string) $val : '';
                if ('' !== $valStr) {
                    $whitelistFiles[] = $valStr;
                }
            }
        }

        $envPath = null;
        $projectApiUrl = '';
        if (isset($config['project_api_url']) && (is_string($config['project_api_url']) || is_int($config['project_api_url']))) {
            $projectApiUrl = rtrim((string) $config['project_api_url'], '/');
        }
        if ('' === $projectApiUrl) {
            throw new RuntimeException(__('repository_not_configured'));
        }

        $installerRepo = 'oakengine/installer';
        if (isset($config['installer_repository']) && is_scalar($config['installer_repository'])) {
            $installerRepo = (string) $config['installer_repository'];
        }

        $targetDir = realpath(__DIR__.'/'.$targetDirRelative);
        if (false === $targetDir) {
            $absoluteTarget = __DIR__.'/'.$targetDirRelative;
            if (!is_dir($absoluteTarget)) {
                if (!\Oak\Engine\Installer\createDirectoryTree($absoluteTarget, 0o755)) {
                    throw new RuntimeException('Target directory cannot be created: '.$absoluteTarget);
                }
            }
            $targetDir = realpath($absoluteTarget);
            if (false === $targetDir) {
                throw new RuntimeException('Target directory cannot be resolved: '.$absoluteTarget);
            }
        }

        $targetDirStr = (string) $targetDir;
        /** @var non-empty-string $targetDirFinal */
        $targetDirFinal = (strlen($targetDirStr) > 0) ? $targetDirStr : '.';
        $targetDirStr = $targetDirFinal;
        $currentProjectVersion = resolveInstalledProjectVersion($targetDirFinal);

        /** @var array<string, mixed> $configForResolver */
        $configForResolver = $config;
        /** @var array<int, array{name: string, commit: string}> $tagsForResolver */
        $tagsForResolver = [];
        $resInstVer = resolveInstallerVersion($configForResolver, $tagsForResolver);
        $currentInstallerVersion = $resInstVer;

        /** @var array<string, mixed> $configForAuth */
        $configForAuth = $config;
        /** @var array<string, mixed> $metaForAuth */
        $metaForAuth = [
            'installer_version' => (string) $currentInstallerVersion,
            'project_version' => (string) $currentProjectVersion,
        ];
        handleAuthentication(
            $configForAuth,
            $showVersionsBeforeLogin,
            $metaForAuth
        );

        $client = new GitHubClient($apiBaseUrl, $token, $currentInstallerVersion);
        $githubCacheDir = dirname(__DIR__).'/var/cache/github-api';
        $installUuidManager = new InstallUuidManager();
        $envPath = rtrim($targetDirFinal, '/').'/.env.local';
        $installUuid = $installUuidManager->ensureEnvLocalInstallUuid($envPath);

        global $lang;
        /** @var array<string, string> $langForGlobal */
        $langForGlobal = (isset($lang) && is_array($lang)) ? $lang : [];
        $projectApiToken = isset($config['project_api_token']) && is_scalar($config['project_api_token'])
            ? trim((string) $config['project_api_token'])
            : '';
        $runnerClient = new ProjectPackageApiClient($projectApiUrl, 'runner', $installUuid, $projectApiToken);
        $pluginClient = new ProjectPackageApiClient($projectApiUrl, 'plugin', $installUuid, $projectApiToken);
        $dataClient = new ProjectPackageApiClient($projectApiUrl, 'data', $installUuid, $projectApiToken);
        $archiveExtractor = new ProjectPackageArchiveExtractor();

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_env'])) {
            $envPath = rtrim($targetDirStr, '/').'/.env.local';
            $newEnv = 'prod';
            if (isset($_POST['app_env']) && is_scalar($_POST['app_env'])) {
                $newEnv = (string) $_POST['app_env'];
            }
            $newDb = 'DB1';
            if (isset($_POST['database']) && is_scalar($_POST['database'])) {
                $newDb = (string) $_POST['database'];
            }

            if (updateEnvLocal($envPath, $newEnv, $newDb)) {
                $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                $content = '<div class="success">'.resolveLangKey('config_saved', $langForGlobal).'<br>';
                $content .= '<strong>'.resolveLangKey('mode', $langForGlobal).':</strong> '.htmlspecialchars($newEnv).'<br>';
                $content .= '<strong>'.resolveLangKey('database', $langForGlobal).':</strong> '.htmlspecialchars($newDb).'</div>';
                $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
                exit;
            }
            $error = 'Error saving .env.local';
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_env_content'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $newContent = '';
            if (isset($_POST['env_content']) && is_scalar($_POST['env_content'])) {
                $newContent = (string) $_POST['env_content'];
            }

            if (saveEnvLocalContent($envPath, $newContent)) {
                $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                $content = '<div class="success">'.resolveLangKey('env_file_saved', $langForGlobal).'</div>';
                $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
                exit;
            }

            throw new RuntimeException(resolveLangKey('env_file_save_failed', $langForGlobal));
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_install_uuid'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $newInstallUuid = '';
            if (isset($_POST['install_uuid']) && is_scalar($_POST['install_uuid'])) {
                $newInstallUuid = (string) $_POST['install_uuid'];
            }

            if (updateInstallUuidInEnvLocal($installUuidManager, $envPath, $newInstallUuid)) {
                $content = '<div class="success">'.resolveLangKey('install_uuid_saved', $langForGlobal).'<br>';
                $content .= '<strong>'.resolveLangKey('install_uuid', $langForGlobal).':</strong> '.htmlspecialchars(strtolower(trim($newInstallUuid))).'</div>';
                $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
                exit;
            }

            throw new RuntimeException(resolveLangKey('install_uuid_invalid', $langForGlobal));
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['regenerate_install_uuid'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $newInstallUuid = $installUuidManager->ensureEnvLocalInstallUuid($envPath, true);

            $content = '<div class="success">'.resolveLangKey('install_uuid_saved', $langForGlobal).'<br>';
            $content .= '<strong>'.resolveLangKey('install_uuid', $langForGlobal).':</strong> '.htmlspecialchars($newInstallUuid).'</div>';
            $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
            echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['add_database'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $dbId = '';
            if (isset($_POST['db_id']) && is_scalar($_POST['db_id'])) {
                $dbId = (string) $_POST['db_id'];
            }
            $dbUrl = '';
            if (isset($_POST['db_url']) && is_scalar($_POST['db_url'])) {
                $dbUrl = (string) $_POST['db_url'];
            }

            if (addDatabaseToEnvLocal($envPath, $dbId, $dbUrl)) {
                $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                $content = '<div class="success">'.resolveLangKey('database_added', $langForGlobal, ['id' => htmlspecialchars($dbId)]).'</div>';
                $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
                exit;
            }

            throw new RuntimeException(resolveLangKey('database_add_failed', $langForGlobal));
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['remove_database'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $removeDbId = '';
            if (isset($_POST['remove_db_id']) && is_scalar($_POST['remove_db_id'])) {
                $removeDbId = (string) $_POST['remove_db_id'];
            }

            if (removeDatabaseFromEnvLocal($envPath, $removeDbId)) {
                $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                $content = '<div class="success">'.resolveLangKey('database_removed', $langForGlobal, ['id' => htmlspecialchars($removeDbId)]).'</div>';
                $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
                exit;
            }

            throw new RuntimeException(resolveLangKey('database_remove_failed', $langForGlobal));
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['run_migrations'])) {
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $console = rtrim($targetDirFinal, '/').'/bin/console';
            $cmd = 'php '.escapeshellarg($console).' doctrine:migrations:migrate --no-interaction 2>&1';
            $output = shell_exec($cmd);

            $content = '<div class="success">'.resolveLangKey('migrations_run_successfully', $langForGlobal).'</div>';
            $content .= '<h3>'.resolveLangKey('migrations_output', $langForGlobal).'</h3>';
            $content .= '<pre style="background:#f6f8fa; padding:15px; border-radius:6px; font-size:0.9em; white-space:pre-wrap;">'.htmlspecialchars(trim((string) $output)).'</pre>';
            $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
            echo renderPage(resolveLangKey('run_migrations', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['clear_cache'])) {
            $cacheDir = rtrim($targetDirFinal, '/').'/var';
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $cacheResult = clearCacheDirectory($cacheDir);

            $errorsCount = (int) count($cacheResult['errors']);
            $content = '<div class="success">'.resolveLangKey('cache_cleared', $langForGlobal).'<br>';
            $content .= resolveLangKey('files_deleted', $langForGlobal, ['count' => (int) $cacheResult['deleted_count'], 'dir' => htmlspecialchars($cacheDir)]);
            if ($errorsCount > 0) {
                $content .= '<br><small>'.resolveLangKey('errors', $langForGlobal).': '.$errorsCount.'</small>';
            }
            $content .= '</div>';
            $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
            echo renderPage(resolveLangKey('cache_cleared', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['self_update'])) {
            $tag = '';
            if (isset($_POST['ref']) && is_scalar($_POST['ref'])) {
                $tag = trim((string) $_POST['ref']);
            }
            if ('' === $tag) {
                throw new RuntimeException(resolveLangKey('no_ref_specified', $langForGlobal));
            }

            $installerRefs = getCachedGitHubRepositoryRefs($client, $installerRepo, $githubCacheDir);
            $instTags = $installerRefs['tags'];
            $instBranches = $installerRefs['branches'];
            $instTagNames = array_map(static fn (array $tagItem): string => $tagItem['name'], $instTags);
            $instBranchNames = array_map(static fn (array $branchItem): string => $branchItem['name'], $instBranches);
            $allRefs = array_merge($instTagNames, $instBranchNames);

            if (!in_array($tag, $allRefs, true)) {
                throw new RuntimeException(resolveLangKey('tag_not_found', $langForGlobal));
            }

            if (!canUpdateInstallerToTag($currentInstallerVersion, $tag)) {
                // Downgrade protection removed as requested by user ("lass mich dort den Installier installieren downgrade usw")
                // but we might still want to check if it's the SAME version to avoid unnecessary work,
                // however the user explicitly said "downgrade usw".
            }

            $updaterSourcePath = 'src';
            if (isset($_GET['manage']) && 'installer' === $_GET['manage']) {
                $updaterSourcePath = 'src';
            } elseif (isset($config['updater_source_path']) && is_scalar($config['updater_source_path'])) {
                $updaterSourcePath = (string) $config['updater_source_path'];
            }

            $selfUpdateResult = updateUpdaterFromTag($client, $installerRepo, $tag, $updaterSourcePath, __DIR__);
            $updatedCount = (int) count($selfUpdateResult['updated_files']);

            writeConfigValues($configPath, [
                'installer_version' => (string) $tag,
            ]);

            $content = '<div class="success">'.resolveLangKey('updater_updated', $langForGlobal, ['tag' => htmlspecialchars($tag)]).'<br>';
            $content .= resolveLangKey('files_updated', $langForGlobal, ['count' => $updatedCount]).'</div>';
            $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';

            if ($updatedCount > 0) {
                $content .= '<h3>'.resolveLangKey('updated_files', $langForGlobal).'</h3><ul class="file-list">';
                /** @var array<string> $updatedFilesList */
                $updatedFilesList = $selfUpdateResult['updated_files'];
                foreach ($updatedFilesList as $file) {
                    $content .= '<li>'.htmlspecialchars($file).'</li>';
                }
                $content .= '</ul>';
            }

            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            echo renderPage(resolveLangKey('title', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['install'])) {
            $packageType = normalizePackageType($_POST['package_type'] ?? null);
            $packageId = '';
            if (isset($_POST['package_id']) && is_scalar($_POST['package_id'])) {
                $packageId = (string) $_POST['package_id'];
            }
            $packageVersion = '';
            if (isset($_POST['version']) && is_scalar($_POST['version'])) {
                $packageVersion = (string) $_POST['version'];
            }
            if ('' === $packageId) {
                throw new RuntimeException(resolveLangKey('no_ref_specified', $langForGlobal));
            }

            $packageClient = match ($packageType) {
                'plugin' => $pluginClient,
                'data' => $dataClient,
                default => $runnerClient,
            };

            $cleanResult = ['deleted_count' => 0, 'preserved' => []];
            if ('runner' === $packageType) {
                $cleanResult = cleanTargetDirectory((string) $targetDirStr, $whitelistFolders, $whitelistFiles);
            }

            $package = $packageClient->getPackage($packageId, '' !== $packageVersion ? $packageVersion : null);
            $packageContent = $packageClient->downloadPackage($package['package_id'], $package['version']);
            $packageTargetDir = resolvePackageInstallTargetDir((string) $targetDirStr, $packageType);
            $extractZipResult = $archiveExtractor->extractTarGz(
                $packageContent,
                $packageTargetDir,
                $excludeFolders,
                $excludeFiles,
                $whitelistFolders,
                $whitelistFiles,
            );
            $envPath = rtrim((string) $targetDirStr, '/').'/.env.local';
            $composerMetadataSources = resolveProjectEnvComposerMetadataSources((string) $targetDirStr);
            $envSyncResult = syncPackageEnvComposerMetadataSourcesToEnvLocalDetailed(
                $envPath,
                $composerMetadataSources,
            );

            if ('runner' === $packageType) {
                $installUuidManager->ensureEnvLocalInstallUuid($envPath);
            }

            $extractedCount = count($extractZipResult['extracted']);
            $skippedFilesCount = count($extractZipResult['skipped_files']);
            $skippedFoldersCount = count($extractZipResult['skipped_folders']);
            $preservedCount = count($cleanResult['preserved']);

            $content = '<div class="success">'.resolveLangKey('installation_successful', $langForGlobal).'<br>';
            $content .= resolveLangKey('files_extracted', $langForGlobal, ['count' => $extractedCount, 'dir' => htmlspecialchars($packageTargetDir)]);
            if ($preservedCount > 0) {
                $content .= '<br>'.resolveLangKey('preserved_files', $langForGlobal, ['count' => $preservedCount]);
            }
            if ($skippedFilesCount > 0 || $skippedFoldersCount > 0) {
                $content .= '<br><small>'.resolveLangKey('skipped', $langForGlobal, ['folders' => $skippedFoldersCount, 'files' => $skippedFilesCount]).'</small>';
            }
            $content .= '</div>';

            if ([] !== $envSyncResult['written_lines'] || [] !== $envSyncResult['skipped_existing_lines']) {
                $content .= '<div class="success"><strong>.env.local</strong><ul class="file-list">';
                $content .= '<li>'.resolveLangKey('env_values_created', $langForGlobal, ['count' => count($envSyncResult['written_lines'])]).'</li>';
                foreach ($envSyncResult['written_lines'] as $writtenEnvLine) {
                    $content .= '<li><code>'.htmlspecialchars($writtenEnvLine).'</code></li>';
                }
                $content .= '<li>'.resolveLangKey('env_values_skipped_existing', $langForGlobal, ['count' => count($envSyncResult['skipped_existing_lines'])]).'</li>';
                foreach ($envSyncResult['skipped_existing_lines'] as $skippedEnvLine) {
                    $content .= '<li><code>'.htmlspecialchars($skippedEnvLine).'</code></li>';
                }
                $content .= '</ul></div>';
            }

            $content .= renderComposerMetadataSourceListHtml($composerMetadataSources, $langForGlobal);

            if ($preservedCount > 0) {
                $content .= '<div class="warning"><strong>'.resolveLangKey('preserved_list_title', $langForGlobal).'</strong><ul class="file-list">';
                foreach (array_slice($cleanResult['preserved'], 0, 20) as $item) {
                    $content .= '<li>'.htmlspecialchars((string) $item).'</li>';
                }
                if ($preservedCount > 20) {
                    $content .= '<li><em>'.resolveLangKey('and_more', $langForGlobal, ['count' => ($preservedCount - 20)]).'</em></li>';
                }
                $content .= '</ul></div>';
            }

            $content .= '<a href="?" class="back-link">'.htmlspecialchars((string) resolveLangKey('back', $langForGlobal)).'</a><h3>'.htmlspecialchars((string) resolveLangKey('installed_files', $langForGlobal)).'</h3><ul class="file-list">';
            /** @var array<string> $extractedFiles */
            $extractedFiles = $extractZipResult['extracted'];
            $slice = array_slice($extractedFiles, 0, 50);
            foreach ($slice as $file) {
                $content .= '<li>'.htmlspecialchars($file).'</li>';
            }
            if ($extractedCount > 50) {
                $content .= '<li><em>'.resolveLangKey('and_more', $langForGlobal, ['count' => ($extractedCount - 50)]).'</em></li>';
            }
            $content .= '</ul>';
            echo renderPage(resolveLangKey('installation_successful', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        $runnerPackages = $runnerClient->listPackages();
        $pluginPackages = $pluginClient->listPackages();
        $dataPackages = $dataClient->listPackages();
        $installedPlugins = resolveInstalledPackages($targetDirFinal, 'plugin');
        $installedDataPackages = resolveInstalledPackages($targetDirFinal, 'data');
        $runnerPackageHtml = renderPackageListHtml($runnerPackages, 'runner', $langForGlobal);
        $pluginPackageHtml = renderPackageListHtml($pluginPackages, 'plugin', $langForGlobal);
        $dataPackageHtml = renderPackageListHtml($dataPackages, 'data', $langForGlobal);
        $installedPluginHtml = renderInstalledPackageListHtml($installedPlugins, $langForGlobal);
        $installedDataHtml = renderInstalledPackageListHtml($installedDataPackages, $langForGlobal);

        $envPath = rtrim($targetDirStr, '/').'/.env.local';
        if (isset($_GET['manage']) && 'installer' === $_GET['manage']) {
            $installerRefs = getCachedGitHubRepositoryRefs($client, $installerRepo, $githubCacheDir);
            $instTags = $installerRefs['tags'];
            $instBranches = $installerRefs['branches'];

            $instBranchHtml = '';
            foreach ($instBranches as $branch) {
                $bName = (isset($branch['name'])) ? (string) $branch['name'] : '';
                $bCommit = (isset($branch['commit'])) ? (string) $branch['commit'] : '';
                $bCommitShort = substr($bCommit, 0, 7);
                $instBranchHtml .= '<li><span><span class="branch-name">'.htmlspecialchars($bName).'</span>'
                    .'<span class="commit-sha">'.htmlspecialchars($bCommitShort).'</span></span>';
                $instBranchHtml .= '<form method="post" style="display:inline" onsubmit="return confirm(\''.htmlspecialchars(resolveLangKey('confirm_install_installer', $langForGlobal)).'\')"><input type="hidden" name="ref" value="'.htmlspecialchars($bName).'"><input type="hidden" name="ref_type" value="branch"><button type="submit" name="self_update" class="btn">'.resolveLangKey('install', $langForGlobal).'</button></form></li>';
            }

            $instTagHtml = '';
            foreach ($instTags as $tag) {
                $tName = (isset($tag['name'])) ? (string) $tag['name'] : '';
                $tCommit = (isset($tag['commit'])) ? (string) $tag['commit'] : '';
                $tCommitShort = substr($tCommit, 0, 7);
                $instTagHtml .= '<li><span><span class="tag-name">'.htmlspecialchars($tName).'</span><span class="commit-sha">'.htmlspecialchars($tCommitShort).'</span></span>';
                $instTagHtml .= '<form method="post" style="display:inline" onsubmit="return confirm(\''.htmlspecialchars(resolveLangKey('confirm_install_installer', $langForGlobal)).'\')"><input type="hidden" name="ref" value="'.htmlspecialchars($tName).'"><button type="submit" name="self_update" class="btn">'.resolveLangKey('install', $langForGlobal).'</button></form></li>';
            }

            if (empty($instBranches)) {
                $instBranchHtml = '<li><em>'.resolveLangKey('no_branches_found', $langForGlobal).'</em></li>';
            }
            if (empty($instTags)) {
                $instTagHtml = '<li><em>'.resolveLangKey('no_tags_found', $langForGlobal).'</em></li>';
            }

            $content = '<div class="repo-info"><strong>'.resolveLangKey('updater_version', $langForGlobal).':</strong> <code>'.htmlspecialchars($currentInstallerVersion).'</code><br><strong>'.resolveLangKey('installer_repository', $langForGlobal).':</strong> <code>'.htmlspecialchars($installerRepo).'</code></div>';
            $content .= '<a href="?" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
            $content .= '<div class="tabs"><button class="tab active" onclick="showTab(\'branches\')">'.resolveLangKey('branches', $langForGlobal).' ('.count($instBranches).')</button><button class="tab" onclick="showTab(\'tags\')">'.resolveLangKey('tags', $langForGlobal).' ('.count($instTags).')</button></div>';
            $content .= '<div id="branches" class="tab-content active"><ul class="branch-list">'.$instBranchHtml.'</ul></div>';
            $content .= '<div id="tags" class="tab-content"><ul class="tag-list">'.$instTagHtml.'</ul></div>';
            $content .= '<script>function showTab(t){document.querySelectorAll(".tab-content").forEach(e=>e.classList.remove("active"));document.querySelectorAll(".tab").forEach(e=>e.classList.remove("active"));document.getElementById(t).classList.add("active");event.target.classList.add("active");}</script>';

            echo renderPage(resolveLangKey('installer_management', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        $content = '<div class="repo-info"><strong>'.resolveLangKey('updater_version', $langForGlobal).':</strong> <code>'.htmlspecialchars($currentInstallerVersion).'</code> <a href="?manage=installer" style="font-size:0.8em;">['.resolveLangKey('manage', $langForGlobal).']</a><br><strong>'.resolveLangKey('runner_version', $langForGlobal).':</strong> <code>'.htmlspecialchars($currentProjectVersion).'</code><br><strong>'.resolveLangKey('installed_plugins', $langForGlobal).':</strong>'.$installedPluginHtml.'<br><strong>'.resolveLangKey('installed_data', $langForGlobal).':</strong>'.$installedDataHtml.'<br><strong>'.resolveLangKey('repository', $langForGlobal).':</strong> <code>'.htmlspecialchars($projectApiUrl).'</code><br><strong>'.resolveLangKey('target_directory', $langForGlobal).':</strong> <code>'.htmlspecialchars($targetDirStr).'</code>';
        if (!empty($whitelistFolders) || !empty($whitelistFiles)) {
            $content .= '<br><strong>'.resolveLangKey('whitelist_active', $langForGlobal).':</strong> ';
            $wlItems = array_merge($whitelistFolders, $whitelistFiles);
            /** @var array<string> $wlItemsString */
            $wlItemsString = array_map(fn ($item) => (string) $item, $wlItems);
            $content .= htmlspecialchars(implode(', ', array_slice($wlItemsString, 0, 5)));
            if (count($wlItems) > 5) {
                $content .= ' ...';
            }
        }
        $content .= '</div>';

        $text_confirm_clear_cache = resolveLangKey('confirm_clear_cache', $langForGlobal);
        $content .= '<form method="post" style="margin-bottom:20px"><button type="submit" name="clear_cache" class="btn btn-secondary" onclick="return confirm(\''.htmlspecialchars($text_confirm_clear_cache).'\')">'.resolveLangKey('clear_cache', $langForGlobal).'</button></form>';
        $content .= '<h3 style="margin-bottom:10px;">Runner</h3><ul class="tag-list" style="margin-bottom:20px;">'.$runnerPackageHtml.'</ul>';
        $content .= '<h3 style="margin-bottom:10px;">Plugin</h3><ul class="tag-list" style="margin-bottom:20px;">'.$pluginPackageHtml.'</ul>';
        $content .= '<h3 style="margin-bottom:10px;">Data</h3><ul class="tag-list">'.$dataPackageHtml.'</ul>';

        $envPath = rtrim($targetDirStr, '/').'/.env.local';
        $hasPassword = (isset($config['password']) && is_scalar($config['password']) && '' !== (string) $config['password']);
        echo renderPage(resolveLangKey('title', $langForGlobal), $content, null, $envPath, $hasPassword);
    } catch (Exception $e) {
        /** @var array<string, string> $langForCatch */
        $langForCatch = (isset($lang) && is_array($lang)) ? $lang : [];
        $targetDirStrCatch = (isset($targetDirStr) && is_string($targetDirStr)) ? $targetDirStr : '';
        $envPathCatch = ('' !== $targetDirStrCatch) ? rtrim($targetDirStrCatch, '/').'/.env.local' : null;
        $hasPasswordCatch = (isset($config['password']) && is_scalar($config['password']) && '' !== (string) $config['password']);
        echo renderPage(resolveLangKey('error', $langForCatch), '<p>'.resolveLangKey('error_occurred', $langForCatch).'</p>', $e->getMessage(), $envPathCatch, $hasPasswordCatch);
    }
}
