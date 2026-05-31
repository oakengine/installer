<?php

declare(strict_types=1);

/**
 * @param list<array{
 *     package_type: string,
 *     package_id: string,
 *     version: string,
 *     channel: string,
 *     package_name: string,
 *     archive_size: int,
 *     archive_sha256: string,
 *     download_url: string,
 *     composer: array<string, mixed>
 * }> $packages
 * @param array<string, string> $lang
 */
function renderPackageListHtml(array $packages, string $packageType, array $lang): string
{
    if ([] === $packages) {
        return '<li><em>'.resolveLangKey('no_tags_found', $lang).'</em></li>';
    }

    $html = '';
    foreach ($packages as $package) {
        $packageId = $package['package_id'];
        $version = $package['version'];
        $channel = $package['channel'];
        $archiveSize = (int) $package['archive_size'];
        $title = 'runner' === $packageType ? $version : sprintf('%s %s', $packageId, $version);
        $metadataLabel = sprintf('%s · %s', $channel, formatPackageSize($archiveSize));
        $html .= '<li><span><span class="tag-name">'.htmlspecialchars($title).'</span> <span class="commit-sha">'.htmlspecialchars($metadataLabel).'</span></span>';
        $html .= '<form method="post" style="display:inline"><input type="hidden" name="package_type" value="'.htmlspecialchars($packageType).'"><input type="hidden" name="package_id" value="'.htmlspecialchars($packageId).'"><input type="hidden" name="version" value="'.htmlspecialchars($version).'"><button type="submit" name="install" class="btn">'.resolveLangKey('install', $lang).'</button></form></li>';
    }

    return $html;
}

/**
 * @param list<array{name: string, version: string, channel: string}> $packages
 * @param array<string, string>                                       $lang
 */
function renderInstalledPackageListHtml(array $packages, array $lang): string
{
    if ([] === $packages) {
        return '<em>'.resolveLangKey('none_installed', $lang).'</em>';
    }

    $parts = [];
    foreach ($packages as $package) {
        $metadata = $package['version'];
        if ('' !== $package['channel']) {
            $metadata .= sprintf(' (%s)', $package['channel']);
        }

        $parts[] = '<code>'.htmlspecialchars($package['name']).'</code> <span class="commit-sha">'.htmlspecialchars($metadata).'</span>';
    }

    return implode(', ', $parts);
}

/**
 * @param list<array{path: string, package_type: string, metadata: array<string, mixed>}> $composerMetadataSources
 * @param array<string, string>                                                           $lang
 */
function renderComposerMetadataSourceListHtml(array $composerMetadataSources, array $lang): string
{
    if ([] === $composerMetadataSources) {
        return '';
    }

    $html = '<div class="success"><strong>'.resolveLangKey('processed_composer_files', $lang).'</strong><ul class="file-list">';
    foreach (array_slice($composerMetadataSources, 0, 20) as $composerMetadataSource) {
        $html .= '<li><code>'.htmlspecialchars($composerMetadataSource['path']).'</code></li>';
    }

    if (count($composerMetadataSources) > 20) {
        $html .= '<li><em>'.resolveLangKey('and_more', $lang, ['count' => (count($composerMetadataSources) - 20)]).'</em></li>';
    }

    return $html.'</ul></div>';
}

/**
 * @param array<string, mixed> $versionMeta
 */
function renderLoginForm(string $error = '', array $versionMeta = []): void
{
    global $lang;
    /** @var array<string, string> $langForLogin */
    $langForLogin = (isset($lang) && is_array($lang)) ? $lang : [];

    $errorHtml = ('' !== $error) ? '<div class="error">'.htmlspecialchars((string) $error).'</div>' : '';
    $text_please_enter_password = resolveLangKey('please_enter_password', $langForLogin);
    $text_password_placeholder = resolveLangKey('password_placeholder', $langForLogin);
    $text_login = resolveLangKey('login', $langForLogin);

    $versionInfoHtml = '';
    $installerVersionStr = '';
    if (isset($versionMeta['installer_version']) && is_scalar($versionMeta['installer_version'])) {
        $installerVersionStr = (string) $versionMeta['installer_version'];
    }
    $projectVersionStr = '';
    if (isset($versionMeta['project_version']) && is_scalar($versionMeta['project_version'])) {
        $projectVersionStr = (string) $versionMeta['project_version'];
    }
    $installerVersion = htmlspecialchars((string) $installerVersionStr);
    $projectVersion = htmlspecialchars((string) $projectVersionStr);
    if (isset($versionMeta['installer_version']) || isset($versionMeta['project_version'])) {
        $versionInfoHtml = '<div class="repo-info" style="margin-top:15px">'
            .'<strong>'.__('updater_version').':</strong> <code>'.$installerVersion.'</code><br>'
            .'<strong>'.__('runner_version').':</strong> <code>'.$projectVersion.'</code>'
            .'</div>';
    }

    $content = <<<HTML
<div class="login-form">
<p>{$text_please_enter_password}</p>
{$errorHtml}
<form method="post">
    <input type="password" name="password" placeholder="{$text_password_placeholder}" autofocus>
    <button type="submit" class="btn">{$text_login}</button>
</form>
{$versionInfoHtml}
</div>
HTML;
    echo renderPage(__('title'), $content, null, null, false);
    exit;
}

function renderPage(string $title, string $content, ?string $error = null, ?string $envPath = null, bool $showLogout = false): string
{
    global $lang;
    /** @var array<string, string> $langForPage */
    $langForPage = (isset($lang) && is_array($lang)) ? $lang : [];

    $errorHtml = (null !== $error && '' !== $error) ? '<div class="error">'.htmlspecialchars((string) $error).'</div>' : '';
    $text_home = resolveLangKey('home', $langForPage);
    $homeButton = '<a href="?" class="btn btn-secondary btn-small home-btn">'.htmlspecialchars($text_home).'</a>';
    $text_logout = resolveLangKey('logout', $langForPage);
    $logoutButton = $showLogout ? '<form method="get" class="logout-form"><input type="hidden" name="logout" value="1"><button type="submit" class="btn btn-secondary btn-small">'.htmlspecialchars($text_logout).'</button></form>' : '';

    $text_language = resolveLangKey('language', $langForPage);
    $langOptions = '';
    global $availableLangs;
    /** @var array<string> $availableLangs */
    if (is_iterable($availableLangs)) {
        foreach ($availableLangs as $code) {
            $codeStr = (is_scalar($code)) ? (string) $code : '';
            if ('' === $codeStr) {
                continue;
            }
            $sessionLangVal = (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) ? (string) $_SESSION['lang'] : 'en';
            $selected = $sessionLangVal === $codeStr ? 'selected' : '';
            $langName = (string) strtoupper($codeStr);
            $langOptions .= '<option value="'.htmlspecialchars($codeStr).'" '.$selected.'>'.htmlspecialchars($langName).'</option>';
        }
    }

    $langSwitcherHtml = <<<HTML
<div class="lang-switcher">
<form method="get" class="lang-form" id="langForm">
    <label>{$text_language}:</label>
    <select name="lang" class="env-select" onchange="document.getElementById('langForm').submit()">
        {$langOptions}
    </select>
</form>
</div>
HTML;

    $envConfigHtml = '';
    $dbConfigHtml = '';
    $installUuidHtml = '';
    $dashboardNavHtml = '';
    $dashboardScript = '';
    if (null !== $envPath) {
        $envConfig = parseEnvLocal($envPath);
        global $lang;
        /** @var array<string, string> $langForTemplate */
        $langForTemplate = (isset($lang) && is_array($lang)) ? $lang : [];

        $devSelected = 'dev' === $envConfig['app_env'] ? 'selected' : '';
        $prodSelected = 'prod' === $envConfig['app_env'] ? 'selected' : '';

        $dbOptions = '';
        foreach ($envConfig['databases'] as $db) {
            $dbIdVal = (isset($db['id']) && is_scalar($db['id'])) ? (string) $db['id'] : '';
            if ('' === $dbIdVal) {
                continue;
            }
            $selected = (!empty($db['active'])) ? 'selected' : '';
            $dbOptions .= '<option value="'.htmlspecialchars($dbIdVal).'" '.$selected.'>'.htmlspecialchars($dbIdVal).'</option>';
        }

        $text_mode = resolveLangKey('mode', $langForTemplate);
        $text_database = resolveLangKey('database', $langForTemplate);
        $text_save = resolveLangKey('save', $langForTemplate);
        $text_env_editor = resolveLangKey('env_editor', $langForTemplate);
        $text_env_content = resolveLangKey('env_content', $langForTemplate);
        $text_save_env_file = resolveLangKey('save_env_file', $langForTemplate);
        $text_db_manager = resolveLangKey('database_manager', $langForTemplate);
        $text_db_id = resolveLangKey('database_id', $langForTemplate);
        $text_db_url = resolveLangKey('database_url', $langForTemplate);
        $text_add_database = resolveLangKey('add_database', $langForTemplate);
        $text_remove_database = resolveLangKey('remove_database', $langForTemplate);
        $text_select_database = resolveLangKey('select_database', $langForTemplate);
        $text_dashboard_updates = resolveLangKey('dashboard_updates', $langForTemplate);
        $text_dashboard_environment = resolveLangKey('dashboard_environment', $langForTemplate);
        $text_dashboard_databases = resolveLangKey('dashboard_databases', $langForTemplate);
        $text_dashboard_install_uuid = resolveLangKey('dashboard_install_uuid', $langForTemplate);
        $text_migrations_status = resolveLangKey('migrations_status', $langForTemplate);
        $text_run_migrations = resolveLangKey('run_migrations', $langForTemplate);
        $confirm_run_migrations = resolveLangKey('confirm_run_migrations', $langForTemplate);
        $text_install_uuid = resolveLangKey('install_uuid', $langForTemplate);
        $text_install_uuid_help = resolveLangKey('install_uuid_help', $langForTemplate);
        $text_regenerate_install_uuid = resolveLangKey('regenerate_install_uuid', $langForTemplate);
        $text_install_uuid_saved = resolveLangKey('install_uuid_saved', $langForTemplate);

        $migrationsData = getMigrationsStatus(dirname($envPath));
        /** @var string $migrationsStatusHtml */
        $migrationsStatusHtml = (isset($migrationsData['html']) && is_scalar($migrationsData['html'])) ? (string) $migrationsData['html'] : '';
        $migrationsCount = (isset($migrationsData['count']) && is_scalar($migrationsData['count'])) ? (int) $migrationsData['count'] : 0;
        $migrationsDisabled = (0 === $migrationsCount || !empty($migrationsData['error']) || isset($migrationsData['no_migrations']) || isset($migrationsData['no_db'])) ? 'disabled' : '';

        $dbRemoveOptions = '';
        foreach ($envConfig['databases'] as $db) {
            $dbIdStr = (isset($db['id']) && is_scalar($db['id'])) ? (string) $db['id'] : '';
            if ('' === $dbIdStr) {
                continue;
            }
            $dbLabel = htmlspecialchars($dbIdStr.((!empty($db['active'])) ? ' (active)' : ''));
            $dbValue = htmlspecialchars($dbIdStr);
            $dbRemoveOptions .= '<option value="'.$dbValue.'">'.$dbLabel.'</option>';
        }

        if ('' === $dbRemoveOptions) {
            $dbRemoveOptions = '<option value="">-</option>';
        }

        $envRawContent = htmlspecialchars((string) $envConfig['raw_content']);
        $currentInstallUuid = htmlspecialchars((string) ($envConfig['install_uuid'] ?? ''));

        $envConfigHtml = <<<HTML
<div class="env-config">
<form method="post" class="env-form" style="margin-bottom: 20px;">
    <div class="env-row">
        <label>{$text_mode}:</label>
        <select name="app_env" class="env-select">
            <option value="dev" {$devSelected}>Dev</option>
            <option value="prod" {$prodSelected}>Prod</option>
        </select>
    </div>
    <button type="submit" name="save_env" class="btn btn-secondary btn-small">{$text_save}</button>
</form>

<h3 style="margin-bottom:10px;">{$text_env_editor}</h3>
<form method="post">
    <label style="display:block; margin-bottom:6px; font-weight:500; color:#586069;">{$text_env_content}:</label>
    <textarea name="env_content" class="env-textarea">{$envRawContent}</textarea>
    <button type="submit" name="save_env_content" class="btn btn-secondary btn-small" style="margin-top:8px;">{$text_save_env_file}</button>
</form>
</div>
HTML;

        $dbConfigHtml = <<<HTML
<div class="env-config">
<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
    <div>
        <h3 style="margin-bottom:5px;">{$text_migrations_status}</h3>
        <div>{$migrationsStatusHtml}</div>
    </div>
    <form method="post" onsubmit="return confirm('{$confirm_run_migrations}')">
        <button type="submit" name="run_migrations" class="btn btn-secondary" {$migrationsDisabled}>{$text_run_migrations}</button>
    </form>
</div>

<hr style="margin:15px 0; border:none; border-top:1px solid #d1d5db;">

<form method="post" class="env-form" style="margin-bottom: 20px;">
    <div class="env-row">
        <label>{$text_database}:</label>
        <select name="database" class="env-select">
            {$dbOptions}
        </select>
    </div>
    <button type="submit" name="save_env" class="btn btn-secondary btn-small">{$text_save}</button>
</form>

<hr style="margin:15px 0; border:none; border-top:1px solid #d1d5db;">

<h3 style="margin-bottom:10px;">{$text_db_manager}</h3>
<form method="post" class="env-form" style="margin-bottom:8px;">
    <div class="env-row">
        <label>{$text_db_id}:</label>
        <input type="text" name="db_id" class="env-input" required>
    </div>
    <div class="env-row" style="flex:1; min-width:300px;">
        <label>{$text_db_url}:</label>
        <input type="text" name="db_url" class="env-input" style="width:100%;" required>
    </div>
    <button type="submit" name="add_database" class="btn btn-secondary btn-small">{$text_add_database}</button>
</form>

<form method="post" class="env-form">
    <div class="env-row">
        <label>{$text_select_database}:</label>
        <select name="remove_db_id" class="env-select">
            {$dbRemoveOptions}
        </select>
    </div>
    <button type="submit" name="remove_database" class="btn btn-small">{$text_remove_database}</button>
</form>
</div>
HTML;

        $installUuidHtml = <<<HTML
<div class="env-config">
<h3 style="margin-bottom:10px;">{$text_install_uuid}</h3>
<p style="margin-bottom:12px; color:#586069;">{$text_install_uuid_help}</p>
<form method="post" class="env-form" style="margin-bottom: 12px;">
    <div class="env-row" style="flex:1; min-width:360px;">
        <label>{$text_install_uuid}:</label>
        <input type="text" name="install_uuid" value="{$currentInstallUuid}" class="env-input" style="width:100%;" required pattern="[0-9a-fA-F-]{36}">
    </div>
    <button type="submit" name="save_install_uuid" class="btn btn-secondary btn-small">{$text_save}</button>
</form>
<form method="post">
    <button type="submit" name="regenerate_install_uuid" class="btn btn-small">{$text_regenerate_install_uuid}</button>
</form>
</div>
HTML;

        $dashboardNavHtml = <<<HTML
<div class="dashboard-nav">
<button type="button" class="btn btn-secondary dashboard-btn active" id="btn-updates" onclick="showDashboardSection('updates')">{$text_dashboard_updates}</button>
<button type="button" class="btn btn-secondary dashboard-btn" id="btn-environment" onclick="showDashboardSection('environment')">{$text_dashboard_environment}</button>
<button type="button" class="btn btn-secondary dashboard-btn" id="btn-databases" onclick="showDashboardSection('databases')">{$text_dashboard_databases}</button>
<button type="button" class="btn btn-secondary dashboard-btn" id="btn-install-uuid" onclick="showDashboardSection('install-uuid')">{$text_dashboard_install_uuid}</button>
</div>
HTML;

        $dashboardScript = <<<HTML
<script>
function showDashboardSection(section){
var updates = document.getElementById('dashboard-updates');
var env = document.getElementById('dashboard-environment');
var dbs = document.getElementById('dashboard-databases');
var installUuid = document.getElementById('dashboard-install-uuid');
var btnUpdates = document.getElementById('btn-updates');
var btnEnvironment = document.getElementById('btn-environment');
var btnDatabases = document.getElementById('btn-databases');
var btnInstallUuid = document.getElementById('btn-install-uuid');
if(!updates || !env || !dbs || !installUuid || !btnUpdates || !btnEnvironment || !btnDatabases || !btnInstallUuid){ return; }

updates.style.display = 'none';
env.style.display = 'none';
dbs.style.display = 'none';
installUuid.style.display = 'none';
btnUpdates.classList.remove('active');
btnEnvironment.classList.remove('active');
btnDatabases.classList.remove('active');
btnInstallUuid.classList.remove('active');

if(section === 'environment'){
    env.style.display = 'block';
    btnEnvironment.classList.add('active');
} else if(section === 'databases'){
    dbs.style.display = 'block';
    btnDatabases.classList.add('active');
} else if(section === 'install-uuid'){
    installUuid.style.display = 'block';
    btnInstallUuid.classList.add('active');
} else {
    updates.style.display = 'block';
    btnUpdates.classList.add('active');
}
}
</script>
HTML;
    }

    global $lang;
    /** @var array<string, string> $langForTitle */
    $langForTitle = (isset($lang) && is_array($lang)) ? $lang : [];
    $appTitle = resolveLangKey('title', $langForTitle);
    $sessionLangForTitle = 'en';
    if (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) {
        $sessionLangForTitle = $_SESSION['lang'];
    }
    $langCode = $sessionLangForTitle;

    return <<<HTML
<!DOCTYPE html>
<html lang="{$langCode}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title} · Oak Engine Installer</title>
<style>
    :root {
        color-scheme: light dark;
        --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        --font-mono: 'JetBrains Mono', 'SF Mono', 'Cascadia Code', Consolas, 'Liberation Mono', monospace;
        --bg: #eef1f6;
        --bg-gradient: radial-gradient(1200px 600px at 50% -10%, #e7ecf6 0%, #eef1f6 45%, #e9edf3 100%);
        --surface: #ffffff;
        --surface-muted: #f5f7fb;
        --surface-inset: #eef1f7;
        --border: #e3e8f0;
        --border-strong: #d2d9e6;
        --text: #1f2733;
        --text-muted: #5b6675;
        --text-soft: #8a94a6;
        --brand: #5b6cff;
        --brand-strong: #4250e6;
        --brand-soft: rgba(91, 108, 255, 0.12);
        --accent: #1f9d6b;
        --accent-strong: #178257;
        --danger: #d8392b;
        --code-bg: #1f2733;
        --code-text: #e8ecf4;
        --shadow-sm: 0 1px 2px rgba(16, 24, 40, 0.06);
        --shadow-md: 0 10px 30px -12px rgba(16, 24, 40, 0.25);
        --shadow-lg: 0 24px 60px -20px rgba(16, 24, 40, 0.35);
        --radius-sm: 8px;
        --radius: 12px;
        --radius-lg: 18px;
        --ring: 0 0 0 3px var(--brand-soft);
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #0c0f16;
            --bg-gradient: radial-gradient(1200px 600px at 50% -10%, #14304a 0%, #0e1320 45%, #0c0f16 100%);
            --surface: #131825;
            --surface-muted: #171d2c;
            --surface-inset: #1b2233;
            --border: #232c3f;
            --border-strong: #2c374e;
            --text: #e8ecf4;
            --text-muted: #a4afc2;
            --text-soft: #7c879b;
            --brand: #7c8bff;
            --brand-strong: #6677ff;
            --brand-soft: rgba(124, 139, 255, 0.16);
            --accent: #34d399;
            --accent-strong: #10b981;
            --danger: #f87171;
            --code-bg: #0b0f18;
            --code-text: #d7def0;
            --shadow-md: 0 10px 30px -12px rgba(0, 0, 0, 0.6);
            --shadow-lg: 0 24px 60px -20px rgba(0, 0, 0, 0.7);
        }
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: var(--font-sans);
        background: var(--bg);
        background-image: var(--bg-gradient);
        background-attachment: fixed;
        color: var(--text);
        padding: 32px 18px 64px;
        line-height: 1.55;
        -webkit-font-smoothing: antialiased;
        text-rendering: optimizeLegibility;
    }
    h3 { font-size: 1.02rem; font-weight: 650; letter-spacing: -0.01em; }
    a { color: var(--brand); }
    code, pre { font-family: var(--font-mono); }
    .container {
        max-width: 860px;
        margin: 0 auto;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-lg);
        padding: 34px 36px;
    }
    header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 18px;
        margin-bottom: 26px;
        padding-bottom: 22px;
        border-bottom: 1px solid var(--border);
    }
    .brand { display: flex; align-items: center; gap: 14px; }
    .brand-mark {
        width: 46px; height: 46px;
        display: grid; place-items: center;
        border-radius: 13px;
        background: linear-gradient(135deg, var(--brand) 0%, #8b5bff 100%);
        box-shadow: 0 8px 20px -8px var(--brand);
        color: #fff;
    }
    .brand-mark svg { width: 26px; height: 26px; display: block; }
    .header-left h1 { font-size: 1.4rem; font-weight: 720; letter-spacing: -0.02em; color: var(--text); line-height: 1.15; }
    .header-left h2 { color: var(--text-muted); font-size: 0.92rem; font-weight: 500; margin-top: 2px; }
    .header-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
    .header-actions { display: flex; align-items: center; gap: 8px; }
    .repo-info {
        background: var(--surface-muted);
        border: 1px solid var(--border);
        padding: 16px 18px;
        border-radius: var(--radius);
        margin-bottom: 22px;
        font-size: 0.92rem;
        line-height: 1.9;
    }
    .repo-info code, code {
        background: var(--code-bg);
        color: var(--code-text);
        padding: 2px 8px;
        border-radius: 6px;
        font-size: 0.85em;
    }
    .error, .success, .warning {
        padding: 14px 16px 14px 18px;
        border-radius: var(--radius);
        margin-bottom: 20px;
        border: 1px solid transparent;
        border-left-width: 4px;
        font-size: 0.93rem;
    }
    .error { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: var(--danger); border-color: color-mix(in srgb, var(--danger) 35%, transparent); }
    .success { background: color-mix(in srgb, var(--accent) 12%, var(--surface)); color: var(--accent-strong); border-color: color-mix(in srgb, var(--accent) 35%, transparent); }
    .warning { background: color-mix(in srgb, #e0a72e 14%, var(--surface)); color: #9a6b00; border-color: color-mix(in srgb, #e0a72e 40%, transparent); }
    .success code, .warning code, .error code { background: rgba(127,127,127,0.18); color: inherit; }
    .branch-list, .tag-list { list-style: none; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .branch-list li, .tag-list li {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        transition: background 0.15s ease;
    }
    .branch-list li:last-child, .tag-list li:last-child { border-bottom: none; }
    .branch-list li:hover, .tag-list li:hover { background: var(--surface-muted); }
    .branch-name, .tag-name { font-family: var(--font-mono); color: var(--brand); font-weight: 600; font-size: 0.92rem; }
    .commit-sha { font-family: var(--font-mono); font-size: 0.82em; color: var(--text-soft); margin-left: 10px; }
    .btn {
        background: var(--accent);
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 9px;
        cursor: pointer;
        font-size: 0.88rem;
        font-weight: 600;
        font-family: inherit;
        line-height: 1.2;
        transition: transform 0.06s ease, background 0.15s ease, box-shadow 0.15s ease;
        box-shadow: var(--shadow-sm);
    }
    .btn:hover { background: var(--accent-strong); box-shadow: var(--shadow-md); }
    .btn:active { transform: translateY(1px); }
    .btn:focus-visible { outline: none; box-shadow: var(--ring); }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; box-shadow: none; }
    .btn-secondary { background: var(--brand); }
    .btn-secondary:hover { background: var(--brand-strong); }
    .btn-small { padding: 7px 13px; font-size: 0.82rem; }
    .dashboard-nav { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 22px; padding: 5px; background: var(--surface-inset); border: 1px solid var(--border); border-radius: 13px; }
    .dashboard-btn { background: transparent; color: var(--text-muted); box-shadow: none; border-radius: 9px; }
    .dashboard-btn:hover { background: color-mix(in srgb, var(--brand) 10%, transparent); color: var(--text); }
    .dashboard-btn.active { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }
    .tabs { display: flex; gap: 6px; margin-bottom: 18px; padding: 5px; background: var(--surface-inset); border: 1px solid var(--border); border-radius: 13px; width: fit-content; }
    .tab { background: transparent; border: none; padding: 8px 16px; cursor: pointer; border-radius: 9px; font-family: inherit; font-size: 0.88rem; font-weight: 600; color: var(--text-muted); }
    .tab:hover { color: var(--text); }
    .tab.active { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .file-list { list-style: none; padding: 14px 16px; max-height: 320px; overflow-y: auto; background: var(--surface-muted); border: 1px solid var(--border); border-radius: var(--radius); }
    .file-list li { padding: 4px 0; font-family: var(--font-mono); font-size: 0.84rem; color: var(--text-muted); }
    .back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 20px; color: var(--brand); text-decoration: none; font-weight: 600; font-size: 0.9rem; }
    .back-link::before { content: '←'; }
    .back-link:hover { text-decoration: underline; }
    .logout-form { display: inline-block; }
    .home-btn { display: inline-block; text-decoration: none; }
    .login-form { max-width: 340px; margin: 36px auto; }
    .login-form p { color: var(--text-muted); margin-bottom: 14px; }
    .login-form input[type="password"] { width: 100%; padding: 12px 14px; border: 1px solid var(--border-strong); border-radius: 10px; margin-bottom: 12px; font-size: 1em; font-family: inherit; background: var(--surface-muted); color: var(--text); }
    .login-form input[type="password"]:focus { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    .login-form .btn { width: 100%; padding: 12px; }
    .env-config { background: var(--surface-muted); border: 1px solid var(--border); padding: 20px; border-radius: var(--radius); margin-bottom: 22px; }
    .env-form { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .env-row { display: flex; align-items: center; gap: 8px; }
    .env-row label { font-weight: 600; color: var(--text-muted); font-size: 0.88rem; }
    .env-select, .env-input {
        padding: 8px 12px;
        border: 1px solid var(--border-strong);
        border-radius: 9px;
        font-size: 0.88rem;
        font-family: inherit;
        background: var(--surface);
        color: var(--text);
        min-width: 110px;
    }
    .env-input { min-width: 130px; }
    .env-select:focus, .env-input:focus { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    .env-textarea { width: 100%; min-height: 200px; padding: 12px 14px; border: 1px solid var(--border-strong); border-radius: var(--radius); font-family: var(--font-mono); font-size: 0.86rem; background: var(--surface); color: var(--text); resize: vertical; }
    .env-textarea:focus { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    pre { background: var(--surface-muted); border: 1px solid var(--border); padding: 15px; border-radius: var(--radius); font-size: 0.86rem; white-space: pre-wrap; color: var(--text-muted); }
    hr { border: none; border-top: 1px solid var(--border); }
    .lang-switcher { margin: 0; }
    .lang-form { display: flex; align-items: center; gap: 6px; }
    .lang-form label { font-size: 0.82rem; color: var(--text-muted); }
    footer { margin-top: 28px; text-align: center; }
    .footer-link { color: var(--text-soft); text-decoration: none; font-size: 0.82rem; }
    .footer-link:hover { color: var(--brand); text-decoration: underline; }
    @media (max-width: 600px) {
        .container { padding: 24px 20px; border-radius: var(--radius); }
        header { flex-direction: column; align-items: stretch; }
        .header-right { align-items: flex-start; }
    }
</style>
</head>
<body>
<div class="container">
    <header>
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2 3 6.5v7L12 18l9-4.5v-7L12 2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                    <path d="M3 6.5 12 11l9-4.5M12 11v7" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                </svg>
            </span>
            <div class="header-left">
                <h1>Oak Engine Installer</h1>
                <h2>{$appTitle}</h2>
            </div>
        </div>
        <div class="header-right">
            <div class="header-actions">
                {$homeButton}
                {$logoutButton}
            </div>
            {$langSwitcherHtml}
        </div>
    </header>
    {$errorHtml}
    {$dashboardNavHtml}
    <div id="dashboard-updates" style="display:block">{$content}</div>
    <div id="dashboard-environment" style="display:none">{$envConfigHtml}</div>
    <div id="dashboard-databases" style="display:none">{$dbConfigHtml}</div>
    <div id="dashboard-install-uuid" style="display:none">{$installUuidHtml}</div>
</div>
<footer>
    <a href="https://github.com/oakengine/installer" target="_blank" class="footer-link">github.com/oakengine/installer</a>
</footer>
{$dashboardScript}
</body>
</html>
HTML;
}
