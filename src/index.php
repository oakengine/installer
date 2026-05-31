<?php

declare(strict_types=1);

namespace Oak\Engine\Installer {
    require_once __DIR__.'/Filesystem.php';
    require_once __DIR__.'/InstallUuidManager.php';
    require_once __DIR__.'/ProjectPackageApiClient.php';
    require_once __DIR__.'/ProjectPackageArchiveExtractor.php';
}

namespace {
    require_once __DIR__.'/GitHubClient.php';
    require_once __DIR__.'/PackageSupport.php';
    require_once __DIR__.'/HtmlRenderer.php';
    require_once __DIR__.'/InstallerUpdater.php';
    require_once __DIR__.'/GitHubRefsCache.php';
    require_once __DIR__.'/Authenticator.php';
    require_once __DIR__.'/FilesystemSupport.php';
    require_once __DIR__.'/EnvLocalManager.php';
    require_once __DIR__.'/MigrationStatus.php';
    require_once __DIR__.'/Translation.php';
    require_once __DIR__.'/InstallerApplication.php';

    /**
     * Oak Engine Installer - package and self-update installer.
     *
     * Installs runner, plugin, and data packages from the package API and
     * self-updates the installer from the GitHub repository.
     */
    if (PHP_SAPI !== 'cli') {
        session_start();
        (new InstallerApplication())->run();
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
    }
}
