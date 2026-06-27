<?php

declare(strict_types=1);

use Oak\Engine\Installer\AppSecretManager;
use Oak\Engine\Installer\InstallManifestManager;
use Oak\Engine\Installer\InstallUuidManager;
use Oak\Engine\Installer\ProjectPackageApiClient;
use Oak\Engine\Installer\ProjectPackageArchiveExtractor;

final class InstallerApplication
{
    public function run(): void
    {
        global $lang, $availableLangs;

        $srcRoot = dirname(__DIR__);
        $projectRoot = dirname($srcRoot);
        $configPath = $srcRoot.'/config.php';
        $loadedConfig = file_exists($configPath) ? require $configPath : require $srcRoot.'/config.example.php';
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

        $langDir = $srcRoot.'/lang/';
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

            $targetDir = realpath($srcRoot.'/'.$targetDirRelative);
            if (false === $targetDir) {
                $absoluteTarget = $srcRoot.'/'.$targetDirRelative;
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
            $authResult = evaluateAuthentication(
                $configForAuth,
                $showVersionsBeforeLogin,
                $metaForAuth
            );

            if ('login-failed' === $authResult['outcome'] || 'show-form' === $authResult['outcome']) {
                renderLoginForm($authResult['error'], $authResult['version_meta']);
                exit;
            }
            if ('login-ok' === $authResult['outcome'] || 'logged-out' === $authResult['outcome']) {
                header('Location: ?');
                exit;
            }

            $client = new GitHubClient($apiBaseUrl, $token, $currentInstallerVersion);
            $githubCacheDir = $projectRoot.'/var/cache/github-api';
            $installUuidManager = new InstallUuidManager();
            $appSecretManager = new AppSecretManager();
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';
            $installUuid = $installUuidManager->ensureEnvLocalInstallUuid($envPath);
            $appSecretManager->ensureEnvLocalAppSecret($envPath);

            global $lang;
            /** @var array<string, string> $langForGlobal */
            $langForGlobal = (isset($lang) && is_array($lang)) ? $lang : [];
            $projectApiToken = isset($config['project_api_token']) && is_scalar($config['project_api_token'])
                ? trim((string) $config['project_api_token'])
                : '';
            $dashboardState = resolveDashboardState($_GET['view'] ?? null, $_GET['itab'] ?? null, $_POST['view'] ?? null, $_POST['itab'] ?? null);
            $dashboardBackHref = buildDashboardViewHref($dashboardState['view'], $dashboardState['itab']);
            $packageCacheDir = rtrim($targetDirFinal, '/').'/var/cache/packages';
            $runnerClient = new ProjectPackageApiClient($projectApiUrl, 'runner', $installUuid, $projectApiToken, $packageCacheDir);
            $pluginClient = new ProjectPackageApiClient($projectApiUrl, 'plugin', $installUuid, $projectApiToken, $packageCacheDir);
            $dataClient = new ProjectPackageApiClient($projectApiUrl, 'data', $installUuid, $projectApiToken, $packageCacheDir);
            $archiveExtractor = new ProjectPackageArchiveExtractor();
            $manifestManager = new InstallManifestManager();

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
                $newAppSecret = '';
                if (isset($_POST['app_secret']) && is_scalar($_POST['app_secret'])) {
                    $newAppSecret = (string) $_POST['app_secret'];
                }

                if (updateEnvLocal($envPath, $newEnv, $newDb)) {
                    $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                    if ('' === $newAppSecret) {
                        $appSecretManager->ensureEnvLocalAppSecret($envPath);
                    } else {
                        updateAppSecretInEnvLocal($appSecretManager, $envPath, $newAppSecret);
                    }
                    $content = '<div class="success">'.resolveLangKey('config_saved', $langForGlobal).'<br>';
                    $content .= '<strong>'.resolveLangKey('mode', $langForGlobal).':</strong> '.htmlspecialchars($newEnv).'<br>';
                    $content .= '<strong>'.resolveLangKey('database', $langForGlobal).':</strong> '.htmlspecialchars($newDb).'</div>';
                    echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view'], $content);
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

                $newEnv = 'prod';
                if (isset($_POST['app_env']) && is_scalar($_POST['app_env'])) {
                    $candidateEnv = strtolower(trim((string) $_POST['app_env']));
                    if (in_array($candidateEnv, ['dev', 'prod'], true)) {
                        $newEnv = $candidateEnv;
                    }
                }

                $appSecretInput = '';
                if (isset($_POST['app_secret']) && is_scalar($_POST['app_secret'])) {
                    $appSecretInput = trim((string) $_POST['app_secret']);
                }

                $appSecretGenerated = false;
                if ('' === $appSecretInput) {
                    $appSecretInput = bin2hex(random_bytes(16));
                    $appSecretGenerated = true;
                } elseif (1 !== preg_match('/^[A-Za-z0-9._-]{16,128}$/', $appSecretInput)) {
                    throw new RuntimeException(resolveLangKey('app_secret_invalid', $langForGlobal));
                }

                $newContent = setEnvLocalValue($newContent, 'APP_ENV', $newEnv);
                $newContent = setEnvLocalValue($newContent, 'APP_SECRET', $appSecretInput);

                if (saveEnvLocalContent($envPath, $newContent)) {
                    $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                    $content = '<div class="success">'.resolveLangKey('env_file_saved', $langForGlobal).'<br>';
                    $content .= '<strong>'.resolveLangKey('mode', $langForGlobal).':</strong> '.htmlspecialchars($newEnv).'<br>';
                    $content .= '<strong>'.resolveLangKey('app_secret', $langForGlobal).':</strong> '.htmlspecialchars($appSecretInput);
                    if ($appSecretGenerated) {
                        $content .= ' <em>('.resolveLangKey('app_secret_generated', $langForGlobal).')</em>';
                    }
                    $content .= '</div>';
                    echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view'], $content);
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
                    echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view'], $content);
                    exit;
                }

                throw new RuntimeException(resolveLangKey('install_uuid_invalid', $langForGlobal));
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['regenerate_install_uuid'])) {
                $envPath = rtrim($targetDirFinal, '/').'/.env.local';
                $newInstallUuid = $installUuidManager->ensureEnvLocalInstallUuid($envPath, true);

                $content = '<div class="success">'.resolveLangKey('install_uuid_saved', $langForGlobal).'<br>';
                $content .= '<strong>'.resolveLangKey('install_uuid', $langForGlobal).':</strong> '.htmlspecialchars($newInstallUuid).'</div>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view'], $content);
                exit;
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['regenerate_app_secret'])) {
                $envPath = rtrim($targetDirFinal, '/').'/.env.local';
                $newAppSecret = $appSecretManager->ensureEnvLocalAppSecret($envPath, true);

                $content = '<div class="success">'.resolveLangKey('app_secret_saved', $langForGlobal).'<br>';
                $content .= '<strong>'.resolveLangKey('app_secret', $langForGlobal).':</strong> '.htmlspecialchars($newAppSecret).'</div>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view'], $content);
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
                    $appSecretManager->ensureEnvLocalAppSecret($envPath);
                    $content = '<div class="success">'.resolveLangKey('database_added', $langForGlobal, ['id' => htmlspecialchars($dbId)]).'</div>';
                    echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view'], $content);
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
                    $appSecretManager->ensureEnvLocalAppSecret($envPath);
                    $content = '<div class="success">'.resolveLangKey('database_removed', $langForGlobal, ['id' => htmlspecialchars($removeDbId)]).'</div>';
                    echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view'], $content);
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
                echo renderPage(resolveLangKey('run_migrations', $langForGlobal), '', null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view'], $content);
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
                echo renderPage(resolveLangKey('cache_cleared', $langForGlobal), '', null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view'], $content);
                exit;
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['refresh_packages'])) {
                $envPath = rtrim($targetDirFinal, '/').'/.env.local';
                try {
                    $runnerClient->refreshPackages();
                    $pluginClient->refreshPackages();
                    $dataClient->refreshPackages();
                    $content = '<div class="success">'.resolveLangKey('updates_refreshed', $langForGlobal).'</div>';
                } catch (Exception $refreshError) {
                    $content = '<div class="error">'.htmlspecialchars($refreshError->getMessage()).'</div>';
                }
                echo renderPage(resolveLangKey('dashboard_installations', $langForGlobal), '', null, $envPath, !empty($config['password'] ?? ''), 'updates', $content);
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
                if ('installer' === ($_GET['view'] ?? null)) {
                    $updaterSourcePath = 'src';
                } elseif (isset($config['updater_source_path']) && is_scalar($config['updater_source_path'])) {
                    $updaterSourcePath = (string) $config['updater_source_path'];
                }

                $selfUpdateResult = updateUpdaterFromTag($client, $installerRepo, $tag, $updaterSourcePath, $srcRoot);
                $updatedCount = (int) count($selfUpdateResult['updated_files']);

                $refCommit = '';
                if (isset($_POST['ref_commit']) && is_scalar($_POST['ref_commit'])) {
                    $refCommit = trim((string) $_POST['ref_commit']);
                }

                writeConfigValues($configPath, [
                    'installer_version' => (string) $tag,
                    'installer_commit' => $refCommit,
                ]);

                $content = '<div class="success">'.resolveLangKey('updater_updated', $langForGlobal, ['tag' => htmlspecialchars($tag)]).'<br>';
                $content .= resolveLangKey('files_updated', $langForGlobal, ['count' => $updatedCount]).'</div>';

                if ($updatedCount > 0) {
                    $content .= '<article class="home-card updates-section-card">'
                        .'<div class="home-card-header home-card-header--static">'
                        .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.lucideIcon('archive', 14).'</span>'.htmlspecialchars((string) resolveLangKey('updated_files', $langForGlobal)).'</span>'
                        .'</div>'
                        .'<ul class="file-list file-list--in-card">';
                    /** @var array<string> $updatedFilesList */
                    $updatedFilesList = $selfUpdateResult['updated_files'];
                    foreach ($updatedFilesList as $file) {
                        $content .= '<li>'.htmlspecialchars($file).'</li>';
                    }
                    $content .= '</ul></article>';
                }

                $content .= '<a href="'.htmlspecialchars((string) $dashboardBackHref).'" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';

                $envPath = rtrim($targetDirFinal, '/').'/.env.local';
                echo renderPage(resolveLangKey('title', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view']);
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

                $package = $packageClient->getPackage($packageId, '' !== $packageVersion ? $packageVersion : null);
                $packageArchivePath = $packageClient->downloadPackage($package['package_id'], $package['version']);

                $packageDir = 'runner' === $packageType
                    ? ''
                    : resolvePackageInstallDirFromMetadata(is_array($package['composer'] ?? null) ? $package['composer'] : [], $packageType);
                $packageTargetDir = resolvePackageInstallTargetDir((string) $targetDirStr, $packageType, $packageDir);

                $oldManifest = $manifestManager->loadManifest($packageTargetDir);
                $cleanResult = ['deleted_count' => 0, 'preserved' => []];
                if (null === $oldManifest) {
                    $cleanResult = cleanTargetDirectory($packageTargetDir);
                }

                try {
                    $extractZipResult = $archiveExtractor->extractTarGzFile(
                        $packageArchivePath,
                        $packageTargetDir,
                        $excludeFolders,
                        $excludeFiles,
                    );
                } finally {
                    if (is_file($packageArchivePath)) {
                        @unlink($packageArchivePath);
                    }
                }
                $envPath = rtrim((string) $targetDirStr, '/').'/.env.local';
                $composerMetadataSources = resolveProjectEnvComposerMetadataSources((string) $targetDirStr);
                $envSyncResult = syncPackageEnvComposerMetadataSourcesToEnvLocalDetailed(
                    $envPath,
                    $composerMetadataSources,
                );

                if ('runner' === $packageType) {
                    $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                    $appSecretManager->ensureEnvLocalAppSecret($envPath);
                }

                /** @var list<string> $extractedFiles */
                $extractedFiles = $extractZipResult['extracted'];
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
                if ([] !== $staleFiles) {
                    $staleResult = $manifestManager->deleteStaleFilesAndEmptyDirs($packageTargetDir, $staleFiles);
                }

                $extractedCount = count($extractZipResult['extracted']);
                $skippedFilesCount = count($extractZipResult['skipped_files']);
                $skippedFoldersCount = count($extractZipResult['skipped_folders']);
                $preservedCount = count($cleanResult['preserved']);
                $staleDeletedCount = count($staleResult['deleted_files']);
                $staleDirsCount = count($staleResult['deleted_dirs']);

                $content = '<div class="success">'.resolveLangKey('installation_successful', $langForGlobal).'<br>';
                $content .= resolveLangKey('files_extracted', $langForGlobal, ['count' => $extractedCount, 'dir' => htmlspecialchars($packageTargetDir)]);
                if ($staleDeletedCount > 0 || $staleDirsCount > 0) {
                    $content .= '<br>'.resolveLangKey('stale_files_removed', $langForGlobal, ['files' => $staleDeletedCount, 'dirs' => $staleDirsCount]);
                }
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

                if ($staleDeletedCount > 0) {
                    $content .= '<div class="warning"><strong>'.resolveLangKey('removed_files_title', $langForGlobal).'</strong><ul class="file-list">';
                    foreach (array_slice($staleResult['deleted_files'], 0, 20) as $item) {
                        $content .= '<li>'.htmlspecialchars((string) $item).'</li>';
                    }
                    if ($staleDeletedCount > 20) {
                        $content .= '<li><em>'.resolveLangKey('and_more', $langForGlobal, ['count' => ($staleDeletedCount - 20)]).'</em></li>';
                    }
                    $content .= '</ul></div>';
                }

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

                $content .= '<a href="'.htmlspecialchars((string) $dashboardBackHref).'" class="back-link">'.htmlspecialchars((string) resolveLangKey('back', $langForGlobal)).'</a><h3>'.htmlspecialchars((string) resolveLangKey('installed_files', $langForGlobal)).'</h3><ul class="file-list">';
                $slice = array_slice($extractedFiles, 0, 50);
                foreach ($slice as $file) {
                    $content .= '<li>'.htmlspecialchars($file).'</li>';
                }
                if ($extractedCount > 50) {
                    $content .= '<li><em>'.resolveLangKey('and_more', $langForGlobal, ['count' => ($extractedCount - 50)]).'</em></li>';
                }
                $content .= '</ul>';
                echo renderPage(resolveLangKey('installation_successful', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''), $dashboardState['view']);
                exit;
            }

            $installedPlugins = resolveInstalledPackages($targetDirFinal, 'plugin');
            $installedDataPackages = resolveInstalledPackages($targetDirFinal, 'data');
            $installedPluginHtml = renderInstalledPackageListHtml($installedPlugins, $langForGlobal, 'modal-installed-plugins', resolveLangKey('installed_plugins', $langForGlobal));
            $installedDataHtml = renderInstalledPackageListHtml($installedDataPackages, $langForGlobal, 'modal-installed-data', resolveLangKey('installed_data', $langForGlobal));

            $envPath = rtrim($targetDirStr, '/').'/.env.local';
            if ('installer' === ($_GET['view'] ?? null)) {
                $installerRefs = getCachedGitHubRepositoryRefs($client, $installerRepo, $githubCacheDir);
                $instTags = $installerRefs['tags'];
                $instBranches = $installerRefs['branches'];
                $itab = resolveInstallerTab($_GET['itab'] ?? null);

                $instBranchHtml = '';
                $installerConfirmAttr = renderConfirmAttributes(
                    resolveLangKey('install', $langForGlobal),
                    resolveLangKey('confirm_install_installer', $langForGlobal),
                    resolveLangKey('install', $langForGlobal),
                );
                foreach ($instBranches as $branch) {
                    $bName = (isset($branch['name'])) ? (string) $branch['name'] : '';
                    $bCommit = (isset($branch['commit'])) ? (string) $branch['commit'] : '';
                    $bCommitShort = substr($bCommit, 0, 7);
                    $instBranchHtml .= '<li><span class="ref-item-info"><span class="branch-name">'.htmlspecialchars($bName).'</span>'
                        .'<span class="commit-sha">'.htmlspecialchars($bCommitShort).'</span></span>';
                    $instBranchHtml .= '<form method="post" class="install-form"'.$installerConfirmAttr.'><input type="hidden" name="self_update" value="1"><input type="hidden" name="ref" value="'.htmlspecialchars($bName).'"><input type="hidden" name="ref_commit" value="'.htmlspecialchars($bCommit).'"><input type="hidden" name="ref_type" value="branch"><button type="submit" name="self_update" class="btn">'.lucideIcon('download', 15).' '.resolveLangKey('install', $langForGlobal).'</button></form></li>';
                }

                $instTagHtml = '';
                foreach ($instTags as $tag) {
                    $tName = (isset($tag['name'])) ? (string) $tag['name'] : '';
                    $tCommit = (isset($tag['commit'])) ? (string) $tag['commit'] : '';
                    $tCommitShort = substr($tCommit, 0, 7);
                    $instTagHtml .= '<li><span class="ref-item-info"><span class="tag-name">'.htmlspecialchars($tName).'</span><span class="commit-sha">'.htmlspecialchars($tCommitShort).'</span></span>';
                    $instTagHtml .= '<form method="post" class="install-form"'.$installerConfirmAttr.'><input type="hidden" name="self_update" value="1"><input type="hidden" name="ref" value="'.htmlspecialchars($tName).'"><input type="hidden" name="ref_commit" value="'.htmlspecialchars($tCommit).'"><button type="submit" name="self_update" class="btn">'.lucideIcon('download', 15).' '.resolveLangKey('install', $langForGlobal).'</button></form></li>';
                }

                $branchesActiveClass = ('tags' === $itab) ? '' : ' active';
                $tagsActiveClass = ('tags' === $itab) ? ' active' : '';

                $installerHeaderCard = '<div class="home-card updates-header-card">'
                    .'<div class="updates-meta">'
                    .'<div class="updates-meta-row"><span class="updates-meta-label">'.resolveLangKey('updater_version', $langForGlobal).':</span><span class="updates-meta-value"><code>'.htmlspecialchars($currentInstallerVersion).'</code></span></div>'
                    .'<div class="updates-meta-row"><span class="updates-meta-label">'.resolveLangKey('installer_repository', $langForGlobal).':</span><span class="updates-meta-value"><code>'.htmlspecialchars($installerRepo).'</code></span></div>'
                    .'</div>'
                    .'</div>';

                $tabsHtml = '<div class="tabs installer-tabs">'
                    .'<a class="tab'.$branchesActiveClass.'" href="?view=installer&itab=branches">'.lucideIcon('git-branch', 15).' '.resolveLangKey('branches', $langForGlobal).' ('.count($instBranches).')</a>'
                    .'<a class="tab'.$tagsActiveClass.'" href="?view=installer&itab=tags">'.lucideIcon('tag', 15).' '.resolveLangKey('tags', $langForGlobal).' ('.count($instTags).')</a>'
                    .'</div>';

                if ('tags' === $itab) {
                    $sectionIcon = 'tag';
                    $sectionTitle = resolveLangKey('tags', $langForGlobal);
                    $isEmpty = [] === $instTags;
                    $emptyText = resolveLangKey('no_tags_found', $langForGlobal);
                    $listBody = '<ul class="tag-list">'.$instTagHtml.'</ul>';
                } else {
                    $sectionIcon = 'git-branch';
                    $sectionTitle = resolveLangKey('branches', $langForGlobal);
                    $isEmpty = [] === $instBranches;
                    $emptyText = resolveLangKey('no_branches_found', $langForGlobal);
                    $listBody = '<ul class="branch-list">'.$instBranchHtml.'</ul>';
                }

                $sectionCard = '<article class="home-card updates-section-card">'
                    .'<div class="home-card-header home-card-header--static">'
                    .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.lucideIcon($sectionIcon, 14).'</span>'.htmlspecialchars($sectionTitle).'</span>'
                    .'</div>'
                    .'<div class="updates-list">'
                    .($isEmpty ? '<p class="updates-empty">'.htmlspecialchars($emptyText).'</p>' : $listBody)
                    .'</div>'
                    .'</article>';

                $content = '<div class="home-stack">'.$installerHeaderCard.$tabsHtml.$sectionCard.'</div>';

                echo renderPage(resolveLangKey('installer_management', $langForGlobal), $content, null, $envPath, !empty($config['password'] ?? ''), 'installer');
                exit;
            }

            $configureLabel = resolveLangKey('home_configure_item', $langForGlobal);
            $homeEnvConfig = parseEnvLocal(rtrim($targetDirStr, '/').'/.env.local');
            $homeEnvValue = static function (string $key, string $default = '') use ($homeEnvConfig): string {
                $value = $homeEnvConfig[$key] ?? null;
                if (is_string($value)) {
                    return $value;
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }

                return $default;
            };
            $homeEnvDisplay = static function (string $value, int $maxLen = 0): string {
                if ('' === $value) {
                    return '';
                }
                $shown = $maxLen > 0 && strlen($value) > $maxLen ? substr($value, 0, $maxLen).'…' : $value;

                return '<code>'.htmlspecialchars($shown).'</code>';
            };

            $cacheDirPath = rtrim($targetDirStr, '/').'/var';
            $cacheSizeBytes = getDirectorySize($cacheDirPath);
            $textClearCache = resolveLangKey('clear_cache', $langForGlobal);
            $textConfirmClearCache = resolveLangKey('confirm_clear_cache', $langForGlobal);
            $textCacheSize = resolveLangKey('cache_size', $langForGlobal);
            $clearCacheConfirmAttr = renderConfirmAttributes($textClearCache, $textConfirmClearCache, $textClearCache);
            $trashIcon = lucideIcon('trash-2', 16);
            $cacheItemActionHtml = '<form method="post" class="info-list-action-form"'.$clearCacheConfirmAttr.'>'
                .'<input type="hidden" name="view" value="system">'
                .'<button type="submit" name="clear_cache" class="info-list-action info-list-action--danger" title="'.htmlspecialchars($textClearCache).'" aria-label="'.htmlspecialchars($textClearCache).'">'.$trashIcon.'</button>'
                .'</form>';

            $phpVersion = PHP_VERSION;
            $phpModules = get_loaded_extensions();
            sort($phpModules, SORT_NATURAL | SORT_FLAG_CASE);
            $phpModulesCount = count($phpModules);
            $textPhpModules = resolveLangKey('php_modules', $langForGlobal);
            $textPhpModulesCount = resolveLangKey('php_modules_count', $langForGlobal, ['count' => $phpModulesCount]);
            $phpModulesListHtml = '';
            foreach ($phpModules as $module) {
                $phpModulesListHtml .= '<li><code>'.htmlspecialchars($module).'</code></li>';
            }
            $phpModulesActionHtml = '<button type="button" class="info-list-action" data-modal-open="modal-php-modules" title="'.htmlspecialchars($textPhpModules).'" aria-label="'.htmlspecialchars($textPhpModules).'">'.lucideIcon('info', 16).'</button>';
            $phpModulesModalHtml = renderModal('modal-php-modules', $textPhpModules, '<ul class="modal-list">'.$phpModulesListHtml.'</ul>', resolveLangKey('close', $langForGlobal));

            $uploadMaxFilesize = (string) ini_get('upload_max_filesize');
            $postMaxSize = (string) ini_get('post_max_size');
            $maxExecutionTime = (string) ini_get('max_execution_time');
            $memoryLimit = (string) ini_get('memory_limit');

            $homeInfoItems = [
                [
                    'icon' => 'endpoint',
                    'label' => resolveLangKey('repository', $langForGlobal),
                    'value' => '<code>'.htmlspecialchars($projectApiUrl).'</code>',
                ],
                [
                    'icon' => 'folder',
                    'label' => resolveLangKey('target_directory', $langForGlobal),
                    'value' => '<code>'.htmlspecialchars($targetDirStr).'</code>',
                ],
                [
                    'icon' => 'code',
                    'label' => resolveLangKey('php_version', $langForGlobal),
                    'value' => '<code>'.htmlspecialchars($phpVersion).'</code>',
                ],
                [
                    'icon' => 'puzzle',
                    'label' => $textPhpModules,
                    'value' => '<span class="info-list-meta">'.htmlspecialchars($textPhpModulesCount).'</span>',
                    'action_html' => $phpModulesActionHtml,
                ],
                [
                    'icon' => 'upload-cloud',
                    'label' => resolveLangKey('upload_limit', $langForGlobal),
                    'value' => '<code>'.htmlspecialchars($uploadMaxFilesize).'</code> · <span class="info-list-meta">'.htmlspecialchars(resolveLangKey('post_max_size', $langForGlobal)).': <code>'.htmlspecialchars($postMaxSize).'</code></span>',
                ],
                [
                    'icon' => 'clock',
                    'label' => resolveLangKey('max_execution_time', $langForGlobal),
                    'value' => '<code>'.htmlspecialchars($maxExecutionTime).'</code> <span class="info-list-meta">'.htmlspecialchars(resolveLangKey('seconds', $langForGlobal)).'</span>',
                ],
                [
                    'icon' => 'memory-stick',
                    'label' => resolveLangKey('memory_limit', $langForGlobal),
                    'value' => '<code>'.htmlspecialchars($memoryLimit).'</code>',
                ],
                [
                    'icon' => 'hard-drive',
                    'label' => $textCacheSize,
                    'value' => '<code>'.htmlspecialchars($cacheDirPath).'</code> · <span class="info-list-meta">'.htmlspecialchars(formatFileSize($cacheSizeBytes)).'</span>',
                    'action_html' => $cacheItemActionHtml,
                ],
            ];
            $homeSections = [
                [
                    'title' => resolveLangKey('home_section_configuration', $langForGlobal),
                    'icon' => 'settings',
                    'href' => '?view=environment',
                    'items' => [
                        [
                            'icon' => 'settings',
                            'label' => resolveLangKey('mode', $langForGlobal),
                            'value' => $homeEnvDisplay(strtolower($homeEnvValue('app_env', 'prod'))) ?: '<em>'.htmlspecialchars(resolveLangKey('none_installed', $langForGlobal)).'</em>',
                            'action' => '?view=environment',
                            'action_title' => $configureLabel,
                        ],
                        [
                            'icon' => 'shield',
                            'label' => resolveLangKey('app_secret', $langForGlobal),
                            'value' => '' !== $homeEnvValue('app_secret') ? $homeEnvDisplay($homeEnvValue('app_secret'), 8) : '<em>'.htmlspecialchars(resolveLangKey('app_secret_invalid', $langForGlobal)).'</em>',
                            'action' => '?view=environment',
                            'action_title' => $configureLabel,
                        ],
                        [
                            'icon' => 'fingerprint',
                            'label' => resolveLangKey('install_uuid', $langForGlobal),
                            'value' => '' !== $homeEnvValue('install_uuid') ? $homeEnvDisplay($homeEnvValue('install_uuid')) : '<em>'.htmlspecialchars(resolveLangKey('none_installed', $langForGlobal)).'</em>',
                            'action' => '?view=install-uuid',
                            'action_title' => $configureLabel,
                        ],
                        [
                            'icon' => 'database',
                            'label' => resolveLangKey('database', $langForGlobal),
                            'value' => '' !== $homeEnvValue('current_db') ? $homeEnvDisplay($homeEnvValue('current_db')) : '<em>'.htmlspecialchars(resolveLangKey('none_installed', $langForGlobal)).'</em>',
                            'action' => '?view=databases',
                            'action_title' => $configureLabel,
                        ],
                    ],
                ],
                [
                    'title' => resolveLangKey('home_section_installations', $langForGlobal),
                    'icon' => 'download',
                    'href' => '?view=updates',
                    'items' => [
                        [
                            'icon' => 'runner',
                            'label' => resolveLangKey('runner_version', $langForGlobal),
                            'value' => formatVersionBadge($currentProjectVersion),
                            'action' => '?view=updates',
                            'action_title' => $configureLabel,
                        ],
                        [
                            'icon' => 'plugin',
                            'label' => resolveLangKey('installed_plugins', $langForGlobal),
                            'value' => $installedPluginHtml,
                            'action' => '?view=updates',
                            'action_title' => $configureLabel,
                        ],
                        [
                            'icon' => 'folder-tree',
                            'label' => resolveLangKey('installed_data', $langForGlobal),
                            'value' => $installedDataHtml,
                            'action' => '?view=updates',
                            'action_title' => $configureLabel,
                        ],
                    ],
                ],
                [
                    'title' => resolveLangKey('home_section_installer', $langForGlobal),
                    'icon' => 'wrench',
                    'href' => '?view=installer',
                    'items' => [
                        [
                            'icon' => 'installer',
                            'label' => resolveLangKey('updater_version', $langForGlobal),
                            'value' => formatVersionBadge($currentInstallerVersion),
                            'action' => '?view=installer',
                            'action_title' => $configureLabel,
                        ],
                    ],
                ],
            ];

            $envPath = rtrim($targetDirStr, '/').'/.env.local';
            $hasPassword = (isset($config['password']) && is_scalar($config['password']) && '' !== (string) $config['password']);
            $view = resolveDashboardView($_GET['view'] ?? null);

            if ('updates' === $view) {
                $runnerPackages = $runnerClient->listPackages();
                $pluginPackages = $pluginClient->listPackages();
                $dataPackages = $dataClient->listPackages();
                $runnerPackageHtml = renderPackageListHtml($runnerPackages, 'runner', $langForGlobal);
                $pluginPackageHtml = renderPackageListHtml($pluginPackages, 'plugin', $langForGlobal);
                $dataPackageHtml = renderPackageListHtml($dataPackages, 'data', $langForGlobal);

                $cacheAges = [
                    $runnerClient->getCacheAge(),
                    $pluginClient->getCacheAge(),
                    $dataClient->getCacheAge(),
                ];
                $freshestCacheAge = null;
                foreach ($cacheAges as $age) {
                    if (null !== $age && (null === $freshestCacheAge || $age < $freshestCacheAge)) {
                        $freshestCacheAge = $age;
                    }
                }
                $cacheTtl = (int) $runnerClient->getCacheTtl();

                if (null === $freshestCacheAge) {
                    $cacheAgeText = '<em>'.resolveLangKey('updates_never_refreshed', $langForGlobal).'</em>';
                } elseif ($freshestCacheAge < 60) {
                    $cacheAgeText = $freshestCacheAge.' '.resolveLangKey('updates_cache_seconds', $langForGlobal);
                } else {
                    $minutes = (int) floor($freshestCacheAge / 60);
                    $cacheAgeText = $minutes.' '.resolveLangKey('updates_cache_minutes', $langForGlobal);
                }

                $iconRefreshCw = lucideIcon('refresh-cw', 16);
                $textRefreshData = resolveLangKey('updates_refresh_data', $langForGlobal);
                $textLastRefresh = resolveLangKey('updates_last_refresh', $langForGlobal);
                $textRunner = resolveLangKey('runner_version', $langForGlobal);
                $textPlugin = resolveLangKey('installed_plugins', $langForGlobal);
                $textData = resolveLangKey('installed_data', $langForGlobal);

                $headerCard = '<div class="home-card updates-header-card">'
                    .'<div class="updates-meta">'
                    .'<div class="updates-meta-row"><span class="updates-meta-label">'.$textLastRefresh.':</span><span class="updates-meta-value">'.$cacheAgeText.'</span></div>'
                    .'</div>'
                    .'<form method="post" class="updates-refresh-form">'
                    .'<input type="hidden" name="view" value="updates">'
                    .'<button type="submit" name="refresh_packages" value="1" class="btn btn-secondary">'.$iconRefreshCw.' '.$textRefreshData.'</button>'
                    .'</form>'
                    .'</div>';

                $packageSection = static function (string $title, string $icon, string $html) use ($langForGlobal): string {
                    $iconSvg = lucideIcon($icon, 14);
                    $empty = '' === trim($html) || '<li><em>'.resolveLangKey('no_tags_found', $langForGlobal).'</em></li>' === trim($html);

                    return '<article class="home-card updates-section-card">'
                        .'<div class="home-card-header home-card-header--static">'
                        .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.$iconSvg.'</span>'.htmlspecialchars($title).'</span>'
                        .'</div>'
                        .'<div class="updates-list">'.($empty ? '<p class="updates-empty">'.resolveLangKey('no_tags_found', $langForGlobal).'</p>' : '<ul class="tag-list">'.$html.'</ul>').'</div>'
                        .'</article>';
                };

                $content = '<div class="home-stack">'
                    .$headerCard
                    .$packageSection($textRunner, 'runner', $runnerPackageHtml)
                    .$packageSection($textPlugin, 'plugin', $pluginPackageHtml)
                    .$packageSection($textData, 'data', $dataPackageHtml)
                    .'</div>';
            } elseif (in_array($view, ['environment', 'databases', 'install-uuid'], true)) {
                $content = '';
            } elseif ('system' === $view) {
                $content = '<div class="home-stack">'
                    .renderHomeSections(
                        resolveLangKey('home_section_system', $langForGlobal),
                        $homeInfoItems,
                        [],
                        '',
                        true,
                    )
                    .'</div>'.$phpModulesModalHtml;
            } else {
                $welcomeBox = renderWelcomeBox(
                    resolveLangKey('welcome_title', $langForGlobal),
                    resolveLangKey('welcome_subtitle', $langForGlobal),
                    [
                        ['label' => resolveLangKey('welcome_link_installations', $langForGlobal), 'href' => '?view=updates', 'icon' => 'download'],
                        ['label' => resolveLangKey('welcome_link_installer', $langForGlobal), 'href' => '?view=installer', 'icon' => 'wrench'],
                        ['label' => resolveLangKey('welcome_link_documentation', $langForGlobal), 'href' => 'https://github.com/oakengine/installer', 'icon' => 'external-link', 'external' => true],
                    ],
                    $langForGlobal,
                );
                $content = '<div class="home-stack">'
                    .$welcomeBox
                    .renderHomeSections(
                        resolveLangKey('home_section_system', $langForGlobal),
                        [],
                        $homeSections,
                        '',
                        false,
                    )
                    .'</div>';
            }

            echo renderPage(resolveLangKey('title', $langForGlobal), $content, null, $envPath, $hasPassword, $view);
        } catch (Exception $e) {
            /** @var array<string, string> $langForCatch */
            $langForCatch = (isset($lang) && is_array($lang)) ? $lang : [];
            $targetDirStrCatch = (isset($targetDirStr) && is_string($targetDirStr)) ? $targetDirStr : '';
            $envPathCatch = ('' !== $targetDirStrCatch) ? rtrim($targetDirStrCatch, '/').'/.env.local' : null;
            $hasPasswordCatch = (isset($config['password']) && is_scalar($config['password']) && '' !== (string) $config['password']);
            echo renderPage(resolveLangKey('error', $langForCatch), '<p>'.resolveLangKey('error_occurred', $langForCatch).'</p>', $e->getMessage(), $envPathCatch, $hasPasswordCatch);
        }
    }
}
