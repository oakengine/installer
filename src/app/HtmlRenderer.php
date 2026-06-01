<?php

declare(strict_types=1);

/**
 * Resolves the active dashboard view from an untrusted request value.
 * Falls back to the "home" view for any unknown value.
 */
function resolveDashboardView(mixed $raw): string
{
    $allowed = ['home', 'updates', 'environment', 'databases', 'install-uuid', 'installer'];
    $value = is_string($raw) ? $raw : '';

    return in_array($value, $allowed, true) ? $value : 'home';
}

/**
 * Resolves the active installer-management tab from an untrusted request value.
 * Only "tags" is honoured; everything else defaults to "branches".
 */
function resolveInstallerTab(mixed $raw): string
{
    return ('tags' === $raw) ? 'tags' : 'branches';
}

/**
 * Resolves the dashboard state from request values, preferring explicit POST state
 * so follow-up pages after form submissions keep the originating section selected.
 *
 * @return array{view: string, itab: string|null}
 */
function resolveDashboardState(mixed $getView, mixed $getItab = null, mixed $postView = null, mixed $postItab = null): array
{
    $viewSource = is_string($postView) ? $postView : $getView;
    $view = resolveDashboardView($viewSource);
    if ('installer' !== $view) {
        return ['view' => $view, 'itab' => null];
    }

    $itabSource = is_string($postItab) ? $postItab : $getItab;

    return ['view' => $view, 'itab' => resolveInstallerTab($itabSource)];
}

function buildDashboardViewHref(string $view, ?string $itab = null): string
{
    if ('home' === $view) {
        return '?';
    }

    $params = ['view' => $view];
    if ('installer' === $view) {
        $params['itab'] = resolveInstallerTab($itab);
    }

    return '?'.http_build_query($params);
}

function renderDashboardStateInputs(string $view, ?string $itab = null): string
{
    if ('home' === $view) {
        return '';
    }

    $inputs = '<input type="hidden" name="view" value="'.htmlspecialchars($view).'">';
    if ('installer' === $view) {
        $inputs .= '<input type="hidden" name="itab" value="'.htmlspecialchars(resolveInstallerTab($itab)).'">';
    }

    return $inputs;
}

/**
 * Renders a custom, accessible dropdown control that replaces native <select>.
 * The selected value is submitted through a hidden input named $name.
 *
 * @param list<array{value: string, label: string}> $options
 */
function renderDropdown(string $name, array $options, string $selectedValue = '', bool $autoSubmit = false, string $extraClass = '', bool $disabled = false): string
{
    $selectedLabel = '';
    $hasSelected = false;
    foreach ($options as $option) {
        if ($option['value'] === $selectedValue) {
            $selectedLabel = $option['label'];
            $hasSelected = true;

            break;
        }
    }

    if (!$hasSelected && [] !== $options) {
        $selectedValue = $options[0]['value'];
        $selectedLabel = $options[0]['label'];
    }

    if ([] === $options) {
        $selectedValue = '';
        $selectedLabel = '-';
        $disabled = true;
    }

    $optionsHtml = '';
    foreach ($options as $option) {
        $isSelected = $option['value'] === $selectedValue;
        $optionsHtml .= '<li class="dropdown-option'.($isSelected ? ' is-selected' : '').'" role="option" data-value="'.htmlspecialchars($option['value']).'" aria-selected="'.($isSelected ? 'true' : 'false').'">'.htmlspecialchars($option['label']).'</li>';
    }

    $classAttr = htmlspecialchars(trim('dropdown '.$extraClass.($disabled ? ' is-disabled' : '')));
    $autoAttr = ($autoSubmit && !$disabled) ? ' data-autosubmit="1"' : '';
    $disabledAttr = $disabled ? ' disabled aria-disabled="true"' : '';

    return '<div class="'.$classAttr.'"'.$autoAttr.'>'
        .'<input type="hidden" name="'.htmlspecialchars($name).'" value="'.htmlspecialchars($selectedValue).'">'
        .'<button type="button" class="dropdown-toggle" aria-haspopup="listbox" aria-expanded="false"'.$disabledAttr.'>'
        .'<span class="dropdown-label">'.htmlspecialchars($selectedLabel).'</span>'
        .'<svg class="dropdown-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        .'</button>'
        .'<ul class="dropdown-menu" role="listbox" tabindex="-1">'.$optionsHtml.'</ul>'
        .'</div>';
}

/**
 * Renders a modern status overview as a responsive grid of icon-prefixed cards.
 * Each item value is treated as trusted HTML; callers are responsible for escaping.
 *
 * @param list<array{icon: string, label: string, value: string}> $items
 */
function renderStatusOverview(array $items): string
{
    $paths = [
        'installer' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12l8.73-5.04"/><path d="M12 22V12"/>',
        'runner' => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
        'plugin' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'data' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
        'endpoint' => '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><path d="M6 6h.01"/><path d="M6 18h.01"/>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    ];

    $icons = [];
    foreach ($paths as $iconKey => $iconPath) {
        $icons[$iconKey] = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$iconPath.'</svg>';
    }

    $rows = '';
    foreach ($items as $item) {
        $iconSvg = $icons[$item['icon']] ?? '';
        $rows .= '<div class="status-item">'
            .'<span class="status-icon" aria-hidden="true">'.$iconSvg.'</span>'
            .'<div class="status-body">'
            .'<span class="status-label">'.htmlspecialchars($item['label']).'</span>'
            .'<div class="status-value">'.$item['value'].'</div>'
            .'</div></div>';
    }

    return '<section class="status-overview">'.$rows.'</section>';
}

/**
 * Renders an accessible modal dialog. The body is treated as trusted HTML.
 */
function renderModal(string $modalId, string $title, string $bodyHtml, string $closeLabel = 'Close'): string
{
    $idAttr = htmlspecialchars($modalId);

    return '<div class="modal" id="'.$idAttr.'" role="dialog" aria-modal="true" aria-hidden="true">'
        .'<div class="modal-backdrop" data-modal-close="'.$idAttr.'"></div>'
        .'<div class="modal-dialog" role="document">'
        .'<div class="modal-header">'
        .'<h3 class="modal-title">'.htmlspecialchars($title).'</h3>'
        .'<button type="button" class="modal-close" data-modal-close="'.$idAttr.'" aria-label="'.htmlspecialchars($closeLabel).'">&times;</button>'
        .'</div>'
        .'<div class="modal-body">'.$bodyHtml.'</div>'
        .'</div></div>';
}

function renderConfirmAttributes(string $title, string $message, string $submitLabel): string
{
    return ' data-confirm-title="'.htmlspecialchars($title).'"'
        .' data-confirm-message="'.htmlspecialchars($message).'"'
        .' data-confirm-submit-label="'.htmlspecialchars($submitLabel).'"';
}

function renderConfirmationModal(string $modalId, string $closeLabel): string
{
    $idAttr = htmlspecialchars($modalId);
    $closeLabelEscaped = htmlspecialchars($closeLabel);

    return '<div class="modal modal-confirm" id="'.$idAttr.'" role="dialog" aria-modal="true" aria-hidden="true">'
        .'<div class="modal-backdrop" data-modal-close="'.$idAttr.'"></div>'
        .'<div class="modal-dialog" role="document">'
        .'<div class="modal-header">'
        .'<h3 class="modal-title" data-confirm-title></h3>'
        .'<button type="button" class="modal-close" data-modal-close="'.$idAttr.'" aria-label="'.$closeLabelEscaped.'">&times;</button>'
        .'</div>'
        .'<div class="modal-body">'
        .'<p class="confirm-message" data-confirm-message></p>'
        .'<div class="modal-actions">'
        .'<button type="button" class="btn btn-secondary" data-modal-close="'.$idAttr.'">'.$closeLabelEscaped.'</button>'
        .'<button type="button" class="btn" data-confirm-submit></button>'
        .'</div>'
        .'</div>'
        .'</div>'
        .'</div>';
}

/**
 * Renders a list of whitelist entries. A single entry is shown as a chip;
 * multiple entries collapse into a count badge that opens a details modal.
 *
 * @param list<string> $items
 */
function renderWhitelistValue(array $items, string $countLabel, string $modalTitle, string $closeLabel = 'Close'): string
{
    if ([] === $items) {
        return '';
    }

    if (1 === count($items)) {
        return '<div class="status-chips"><span class="status-chip">'.htmlspecialchars($items[0]).'</span></div>';
    }

    $modalId = 'modal-whitelist';
    $countText = trim($countLabel);
    $countPrefix = (string) count($items);
    if (str_starts_with($countText, $countPrefix)) {
        $trimmedCountText = ltrim(substr($countText, strlen($countPrefix)), " \t\n\r\0\x0B:.-");
        if ('' !== $trimmedCountText) {
            $countText = $trimmedCountText;
        }
    }
    $trigger = '<button type="button" class="status-count" data-modal-open="'.$modalId.'">'
        .'<span class="status-count-num">'.count($items).'</span>'
        .'<span class="status-count-text">'.htmlspecialchars($countText).'</span>'
        .'</button>';

    $listHtml = '';
    foreach ($items as $item) {
        $listHtml .= '<li><code>'.htmlspecialchars($item).'</code></li>';
    }

    return $trigger.renderModal($modalId, $modalTitle, '<ul class="modal-list">'.$listHtml.'</ul>', $closeLabel);
}

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

    /** @var array<string, list<array{package_type: string, package_id: string, version: string, channel: string, package_name: string, archive_size: int, archive_sha256: string, download_url: string, composer: array<string, mixed>}>> $groups */
    $groups = [];
    $order = [];
    foreach ($packages as $package) {
        $packageId = $package['package_id'];
        if (!isset($groups[$packageId])) {
            $groups[$packageId] = [];
            $order[] = $packageId;
        }

        $groups[$packageId][] = $package;
    }

    $html = '';
    foreach ($order as $packageId) {
        $items = $groups[$packageId];
        usort($items, static fn (array $a, array $b): int => comparePackageVersionsDesc($a['version'], $b['version']));

        $newest = $items[0];
        $newestVersion = $newest['version'];
        $packageName = ('' !== $newest['package_name']) ? $newest['package_name'] : $packageId;
        $title = $packageName;

        $options = [];
        foreach ($items as $item) {
            $version = $item['version'];
            $channel = $item['channel'];
            $archiveSize = $item['archive_size'];
            $options[] = [
                'value' => $version,
                'label' => sprintf('%s · %s · %s', $version, $channel, formatPackageSize($archiveSize)),
            ];
        }

        $dropdown = renderDropdown('version', $options, $newestVersion, false, 'dropdown-version');

        $html .= '<li><span class="tag-name">'.htmlspecialchars($title).'</span>';
        $html .= '<form method="post" class="install-form">'
            .'<input type="hidden" name="package_type" value="'.htmlspecialchars($packageType).'">'
            .'<input type="hidden" name="package_id" value="'.htmlspecialchars($packageId).'">'
            .$dropdown
            .'<button type="submit" name="install" class="btn">'.resolveLangKey('install', $lang).'</button>'
            .'</form></li>';
    }

    return $html;
}

/**
 * Renders installed packages like renderWhitelistValue: single item as a chip,
 * multiple items as a count badge that opens a details modal.
 *
 * @param list<array{name: string, version: string, channel: string}> $packages
 * @param array<string, string>                                       $lang
 */
function renderInstalledPackageListHtml(array $packages, array $lang, string $modalId = 'modal-installed-packages', string $modalTitle = ''): string
{
    if ([] === $packages) {
        return '<em>'.resolveLangKey('none_installed', $lang).'</em>';
    }

    if (1 === count($packages)) {
        $package = $packages[0];
        $metadata = $package['version'];
        if ('' !== $package['channel']) {
            $metadata .= sprintf(' (%s)', $package['channel']);
        }

        return '<div class="status-chips"><span class="status-chip">'.htmlspecialchars($package['name']).' <span class="commit-sha">'.htmlspecialchars($metadata).'</span></span></div>';
    }

    $countLabel = resolveLangKey('whitelist_count', $lang, ['count' => count($packages)]);
    $countText = trim($countLabel);
    $countPrefix = (string) count($packages);
    if (str_starts_with($countText, $countPrefix)) {
        $trimmedCountText = ltrim(substr($countText, strlen($countPrefix)), " \t\n\r\0\x0B:.-");
        if ('' !== $trimmedCountText) {
            $countText = $trimmedCountText;
        }
    }

    $trigger = '<button type="button" class="status-count" data-modal-open="'.htmlspecialchars($modalId).'">'
        .'<span class="status-count-num">'.count($packages).'</span>'
        .'<span class="status-count-text">'.htmlspecialchars($countText).'</span>'
        .'</button>';

    $listHtml = '';
    foreach ($packages as $package) {
        $metadata = $package['version'];
        if ('' !== $package['channel']) {
            $metadata .= sprintf(' (%s)', $package['channel']);
        }
        $listHtml .= '<li><code>'.htmlspecialchars($package['name']).' <span class="commit-sha">'.htmlspecialchars($metadata).'</span></code></li>';
    }

    if ('' === $modalTitle) {
        $modalTitle = resolveLangKey('installed_plugins', $lang);
    }
    $closeLabel = resolveLangKey('close', $lang);

    return $trigger.renderModal($modalId, $modalTitle, '<ul class="modal-list">'.$listHtml.'</ul>', $closeLabel);
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

function renderPage(
    string $title,
    string $content,
    ?string $error = null,
    ?string $envPath = null,
    bool $showLogout = false,
    string $activeView = '',
    ?string $dashboardNotice = null,
): string {
    global $lang;
    /** @var array<string, string> $langForPage */
    $langForPage = (isset($lang) && is_array($lang)) ? $lang : [];

    $errorHtml = (null !== $error && '' !== $error) ? '<div class="error">'.htmlspecialchars((string) $error).'</div>' : '';
    $text_logout = resolveLangKey('logout', $langForPage);
    $text_close = resolveLangKey('close', $langForPage);
    $logoutButton = $showLogout ? '<form method="get" class="logout-form"><input type="hidden" name="logout" value="1"><button type="submit" class="btn btn-secondary btn-small">'.htmlspecialchars($text_logout).'</button></form>' : '';

    $text_language = resolveLangKey('language', $langForPage);
    global $availableLangs;
    /** @var array<string> $availableLangs */
    $sessionLangVal = (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) ? (string) $_SESSION['lang'] : 'en';
    $langDropdownOptions = [];
    if (is_iterable($availableLangs)) {
        foreach ($availableLangs as $code) {
            $codeStr = (is_scalar($code)) ? (string) $code : '';
            if ('' === $codeStr) {
                continue;
            }

            $langDropdownOptions[] = ['value' => $codeStr, 'label' => strtoupper($codeStr)];
        }
    }

    $langDropdown = renderDropdown('lang', $langDropdownOptions, $sessionLangVal, true, 'dropdown-lang');

    $requestDashboardState = resolveDashboardState($_GET['view'] ?? null, $_GET['itab'] ?? null, $_POST['view'] ?? null, $_POST['itab'] ?? null);
    $langStateInputs = renderDashboardStateInputs($requestDashboardState['view'], $requestDashboardState['itab']);

    $langSwitcherHtml = <<<HTML
<div class="lang-switcher">
<form method="get" class="lang-form" id="langForm">
    <label>{$text_language}:</label>
    {$langStateInputs}
    {$langDropdown}
</form>
</div>
HTML;

    $envConfigHtml = '';
    $dbConfigHtml = '';
    $installUuidHtml = '';
    $dashboardNavHtml = '';
    $mainSection = $content;
    if (null !== $envPath) {
        $envConfig = parseEnvLocal($envPath);
        global $lang;
        /** @var array<string, string> $langForTemplate */
        $langForTemplate = (isset($lang) && is_array($lang)) ? $lang : [];

        $appEnvDropdown = renderDropdown('app_env', [
            ['value' => 'dev', 'label' => 'Dev'],
            ['value' => 'prod', 'label' => 'Prod'],
        ], (string) $envConfig['app_env'], false, 'dropdown-env');

        $databaseOptions = [];
        $activeDbValue = '';
        foreach ($envConfig['databases'] as $db) {
            $dbIdVal = (isset($db['id']) && is_scalar($db['id'])) ? (string) $db['id'] : '';
            if ('' === $dbIdVal) {
                continue;
            }

            $databaseOptions[] = ['value' => $dbIdVal, 'label' => $dbIdVal];
            if (!empty($db['active'])) {
                $activeDbValue = $dbIdVal;
            }
        }

        $hasDatabases = [] !== $databaseOptions;
        $databaseDropdown = renderDropdown('database', $databaseOptions, $activeDbValue, false, 'dropdown-db', !$hasDatabases);

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
        $runMigrationsConfirmAttr = renderConfirmAttributes($text_run_migrations, $confirm_run_migrations, $text_run_migrations);

        $migrationsData = getMigrationsStatus(dirname($envPath));
        /** @var string $migrationsStatusHtml */
        $migrationsStatusHtml = (isset($migrationsData['html']) && is_scalar($migrationsData['html'])) ? (string) $migrationsData['html'] : '';
        $migrationsCount = (isset($migrationsData['count']) && is_scalar($migrationsData['count'])) ? (int) $migrationsData['count'] : 0;
        $migrationsDisabled = (0 === $migrationsCount || !empty($migrationsData['error']) || isset($migrationsData['no_migrations']) || isset($migrationsData['no_db'])) ? 'disabled' : '';

        $removeDbOptions = [];
        foreach ($envConfig['databases'] as $db) {
            $dbIdStr = (isset($db['id']) && is_scalar($db['id'])) ? (string) $db['id'] : '';
            if ('' === $dbIdStr) {
                continue;
            }

            $removeDbOptions[] = ['value' => $dbIdStr, 'label' => $dbIdStr.((!empty($db['active'])) ? ' (active)' : '')];
        }

        $canRemoveDatabases = [] !== $removeDbOptions;
        $removeDbDropdown = renderDropdown('remove_db_id', $removeDbOptions, '', false, 'dropdown-db', !$canRemoveDatabases);
        $databaseActionDisabled = $hasDatabases ? '' : 'disabled';
        $removeDatabaseActionDisabled = $canRemoveDatabases ? '' : 'disabled';

        $envRawContent = htmlspecialchars((string) $envConfig['raw_content']);
        $currentInstallUuid = htmlspecialchars((string) ($envConfig['install_uuid'] ?? ''));
        $dashboardStateInputs = renderDashboardStateInputs($activeView, $requestDashboardState['itab']);

        $envConfigHtml = <<<HTML
<div class="env-config">
<form method="post" class="env-form" style="margin-bottom: 20px;">
    {$dashboardStateInputs}
    <div class="env-row">
        <label>{$text_mode}:</label>
        {$appEnvDropdown}
    </div>
    <button type="submit" name="save_env" class="btn btn-secondary btn-small">{$text_save}</button>
</form>

<h3 style="margin-bottom:10px;">{$text_env_editor}</h3>
<form method="post">
    {$dashboardStateInputs}
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
    <form method="post"{$runMigrationsConfirmAttr}>
        {$dashboardStateInputs}
        <button type="submit" name="run_migrations" class="btn btn-secondary" {$migrationsDisabled}>{$text_run_migrations}</button>
    </form>
</div>

<hr style="margin:15px 0; border:none; border-top:1px solid #d1d5db;">

<form method="post" class="env-form" style="margin-bottom: 20px;">
    {$dashboardStateInputs}
    <div class="env-row">
        <label>{$text_database}:</label>
        {$databaseDropdown}
    </div>
    <button type="submit" name="save_env" class="btn btn-secondary btn-small" {$databaseActionDisabled}>{$text_save}</button>
</form>

<hr style="margin:15px 0; border:none; border-top:1px solid #d1d5db;">

<h3 style="margin-bottom:10px;">{$text_db_manager}</h3>
<form method="post" class="env-form env-form--stack" style="margin-bottom:8px;">
    {$dashboardStateInputs}
    <div class="env-row env-row--stack env-row--wide">
        <label>{$text_db_id}:</label>
        <input type="text" name="db_id" class="env-input env-input--wide" required>
    </div>
    <div class="env-row env-row--stack env-row--wide">
        <label>{$text_db_url}:</label>
        <input type="text" name="db_url" class="env-input env-input--wide" required>
    </div>
    <button type="submit" name="add_database" class="btn btn-secondary btn-small">{$text_add_database}</button>
</form>

<form method="post" class="env-form">
    {$dashboardStateInputs}
    <div class="env-row">
        <label>{$text_select_database}:</label>
        {$removeDbDropdown}
    </div>
    <button type="submit" name="remove_database" class="btn btn-small" {$removeDatabaseActionDisabled}>{$text_remove_database}</button>
</form>
</div>
HTML;

        $installUuidHtml = <<<HTML
<div class="env-config">
<h3 style="margin-bottom:10px;">{$text_install_uuid}</h3>
<p style="margin-bottom:12px; color:#586069;">{$text_install_uuid_help}</p>
<form method="post" class="env-form env-form--inline" style="margin-bottom: 12px;">
    {$dashboardStateInputs}
    <div class="env-row env-row--inline env-row--grow">
        <label>{$text_install_uuid}:</label>
        <input type="text" name="install_uuid" value="{$currentInstallUuid}" class="env-input env-input--uuid" required pattern="[0-9a-fA-F-]{36}">
    </div>
    <button type="submit" name="save_install_uuid" class="btn btn-secondary btn-small">{$text_save}</button>
</form>
<form method="post">
    {$dashboardStateInputs}
    <button type="submit" name="regenerate_install_uuid" class="btn btn-small">{$text_regenerate_install_uuid}</button>
</form>
</div>
HTML;

        $text_dashboard_home = resolveLangKey('home', $langForTemplate);
        $text_dashboard_installer = resolveLangKey('dashboard_installer', $langForTemplate);

        $navItems = [
            ['view' => 'home', 'href' => '?', 'label' => $text_dashboard_home],
            ['view' => 'updates', 'href' => '?view=updates', 'label' => $text_dashboard_updates],
            ['view' => 'environment', 'href' => '?view=environment', 'label' => $text_dashboard_environment],
            ['view' => 'databases', 'href' => '?view=databases', 'label' => $text_dashboard_databases],
            ['view' => 'install-uuid', 'href' => '?view=install-uuid', 'label' => $text_dashboard_install_uuid],
            ['view' => 'installer', 'href' => '?view=installer', 'label' => $text_dashboard_installer],
        ];
        $navLinks = '';
        foreach ($navItems as $navItem) {
            $activeClass = ($navItem['view'] === $activeView) ? ' active' : '';
            $navLinks .= '<a class="btn btn-secondary dashboard-btn'.$activeClass.'" href="'.$navItem['href'].'">'.$navItem['label'].'</a>';
        }
        $dashboardNavHtml = '<div class="dashboard-nav">'.$navLinks.'</div>';

        if ('environment' === $activeView) {
            $mainSection = $envConfigHtml;
        } elseif ('databases' === $activeView) {
            $mainSection = $dbConfigHtml;
        } elseif ('install-uuid' === $activeView) {
            $mainSection = $installUuidHtml;
        }

        if (null !== $dashboardNotice && '' !== $dashboardNotice && 'home' !== $activeView && '' !== $activeView) {
            $mainSection = $dashboardNotice.$mainSection;
        }
    }

    $confirmationModal = renderConfirmationModal('modal-confirm-action', $text_close);

    $dropdownScript = <<<'HTML'
<script>
(function(){
    function initDropdown(dd){
        var toggle = dd.querySelector('.dropdown-toggle');
        var menu = dd.querySelector('.dropdown-menu');
        var labelEl = dd.querySelector('.dropdown-label');
        var input = dd.querySelector('input[type=hidden]');
        var options = Array.prototype.slice.call(dd.querySelectorAll('.dropdown-option'));
        if(!toggle || !menu || !input){ return; }
        if(toggle.disabled || options.length === 0){ return; }
        function open(){
            closeAll(dd);
            dd.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
            setActive(dd.querySelector('.dropdown-option.is-selected') || options[0]);
        }
        function close(){
            dd.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        }
        function setActive(opt){
            options.forEach(function(o){ o.classList.remove('is-active'); });
            if(opt){ opt.classList.add('is-active'); opt.scrollIntoView({ block: 'nearest' }); }
        }
        function select(opt){
            if(!opt){ return; }
            input.value = opt.getAttribute('data-value');
            if(labelEl){ labelEl.textContent = opt.textContent; }
            options.forEach(function(o){ o.classList.remove('is-selected'); o.setAttribute('aria-selected', 'false'); });
            opt.classList.add('is-selected');
            opt.setAttribute('aria-selected', 'true');
            close();
            toggle.focus();
            if(dd.hasAttribute('data-autosubmit')){
                var form = dd.closest('form');
                if(form){
                    if(typeof form.requestSubmit === 'function'){ form.requestSubmit(); } else { form.submit(); }
                }
            }
        }
        toggle.addEventListener('click', function(e){
            e.preventDefault();
            if(dd.classList.contains('is-open')){ close(); } else { open(); }
        });
        options.forEach(function(opt){
            opt.addEventListener('click', function(){ select(opt); });
            opt.addEventListener('mousemove', function(){ setActive(opt); });
        });
        dd.addEventListener('keydown', function(e){
            var isOpen = dd.classList.contains('is-open');
            if(e.key === 'ArrowDown' || e.key === 'ArrowUp'){
                e.preventDefault();
                if(!isOpen){ open(); return; }
                var idx = options.indexOf(dd.querySelector('.dropdown-option.is-active'));
                idx = e.key === 'ArrowDown' ? Math.min(options.length - 1, idx + 1) : Math.max(0, idx - 1);
                setActive(options[idx]);
            } else if(e.key === 'Enter' || e.key === ' '){
                if(isOpen){ e.preventDefault(); select(dd.querySelector('.dropdown-option.is-active')); }
                else if(document.activeElement === toggle){ e.preventDefault(); open(); }
            } else if(e.key === 'Escape'){
                if(isOpen){ e.preventDefault(); close(); toggle.focus(); }
            }
        });
    }
    function closeAll(except){
        Array.prototype.slice.call(document.querySelectorAll('.dropdown.is-open')).forEach(function(dd){
            if(dd !== except){
                dd.classList.remove('is-open');
                var t = dd.querySelector('.dropdown-toggle');
                if(t){ t.setAttribute('aria-expanded', 'false'); }
            }
        });
    }
    document.addEventListener('click', function(e){
        if(!e.target.closest || !e.target.closest('.dropdown')){ closeAll(null); }
    });
    function initAll(){
        Array.prototype.slice.call(document.querySelectorAll('.dropdown')).forEach(initDropdown);
    }
    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
</script>
HTML;

    $modalScript = <<<'HTML_WRAP'
    <script>
    (function(){
        var pendingConfirmForm = null;
        function openModal(modal){
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            var focusTarget = modal.querySelector('[data-confirm-submit]') || modal.querySelector('.modal-close');
            if(focusTarget){ focusTarget.focus(); }
        }
        function closeModal(modal){
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            if(modal.id === 'modal-confirm-action'){ pendingConfirmForm = null; }
            if(!document.querySelector('.modal.is-open')){ document.body.classList.remove('modal-open'); }
        }
        function openConfirmModal(form){
            var modal = document.getElementById('modal-confirm-action');
            if(!modal){ return; }
            pendingConfirmForm = form;
            var title = form.getAttribute('data-confirm-title') || '';
            var message = form.getAttribute('data-confirm-message') || '';
            var submitLabel = form.getAttribute('data-confirm-submit-label') || '';
            var titleNode = modal.querySelector('[data-confirm-title]');
            var messageNode = modal.querySelector('[data-confirm-message]');
            var submitButton = modal.querySelector('[data-confirm-submit]');
            if(titleNode){ titleNode.textContent = title; }
            if(messageNode){ messageNode.textContent = message; }
            if(submitButton){ submitButton.textContent = submitLabel; }
            openModal(modal);
        }
        function initModals(){
            Array.prototype.slice.call(document.querySelectorAll('.modal')).forEach(function(modal){
                document.body.appendChild(modal);
            });
            document.addEventListener('click', function(e){
                var opener = e.target.closest ? e.target.closest('[data-modal-open]') : null;
                if(opener){
                    e.preventDefault();
                    var target = document.getElementById(opener.getAttribute('data-modal-open'));
                    if(target){ openModal(target); }
                    return;
                }
                var closer = e.target.closest ? e.target.closest('[data-modal-close]') : null;
                if(closer){
                    e.preventDefault();
                    var modal = document.getElementById(closer.getAttribute('data-modal-close'));
                    if(modal){ closeModal(modal); }
                }
            });
            document.addEventListener('submit', function(e){
                var form = e.target;
                if(!form || !form.matches || !form.matches('form[data-confirm-message]')){ return; }
                if(form.dataset.confirmApproved === '1'){
                    delete form.dataset.confirmApproved;
                    return;
                }
                e.preventDefault();
                openConfirmModal(form);
            });
            document.addEventListener('click', function(e){
                var confirmButton = e.target.closest ? e.target.closest('[data-confirm-submit]') : null;
                if(!confirmButton || !pendingConfirmForm){ return; }
                e.preventDefault();
                var formToSubmit = pendingConfirmForm;
                formToSubmit.dataset.confirmApproved = '1';
                var modal = document.getElementById('modal-confirm-action');
                if(modal){ closeModal(modal); }
                if(typeof HTMLFormElement !== 'undefined' && HTMLFormElement.prototype.submit){
                    HTMLFormElement.prototype.submit.call(formToSubmit);
                    return;
                }
                formToSubmit.submit();
            });
            document.addEventListener('keydown', function(e){
                if(e.key === 'Escape'){
                    Array.prototype.slice.call(document.querySelectorAll('.modal.is-open')).forEach(closeModal);
                }
            });
        }
        if(document.readyState === 'loading'){
            document.addEventListener('DOMContentLoaded', initModals);
        } else {
            initModals();
        }
    })();
    </script>
    HTML_WRAP;
    global $lang;
    /** @var array<string, string> $langForTitle */
    $langForTitle = (isset($lang) && is_array($lang)) ? $lang : [];
    $sessionLangForTitle = 'en';
    if (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) {
        $sessionLangForTitle = $_SESSION['lang'];
    }
    $langCode = $sessionLangForTitle;
    $brandTitle = 'OakEngine Installer';
    $pageTitle = htmlspecialchars($title);
    $brandTitleEscaped = htmlspecialchars($brandTitle);
    $pageSubtitleHtml = ($title !== $brandTitle) ? '<h2>'.$pageTitle.'</h2>' : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="{$langCode}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$pageTitle} · {$brandTitleEscaped}</title>
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
        width: 54px; height: 54px;
        display: grid; place-items: center;
        padding: 6px;
        border-radius: 16px;
        background: var(--surface);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }
    .brand-mark img { width: 100%; height: 100%; display: block; object-fit: contain; }
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
    .status-overview {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 12px;
        margin-bottom: 24px;
    }
    .status-item {
        display: flex;
        align-items: flex-start;
        gap: 13px;
        padding: 14px 16px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
    }
    .status-item:hover {
        border-color: var(--border-strong);
        box-shadow: var(--shadow-sm);
        transform: translateY(-1px);
    }
    .status-icon {
        flex: none;
        display: grid;
        place-items: center;
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: color-mix(in srgb, var(--brand) 12%, var(--surface));
        color: var(--brand-strong);
    }
    .status-icon svg { width: 19px; height: 19px; display: block; }
    .status-body { min-width: 0; display: flex; flex-direction: column; gap: 4px; flex: 1; }
    .status-label {
        font-size: 0.71rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--text-soft);
    }
    .status-value {
        font-size: 0.92rem;
        color: var(--text);
        line-height: 1.5;
        word-break: break-word;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 7px;
    }
    .status-value em { color: var(--text-soft); font-style: normal; }
    .status-value a { color: var(--brand-strong); text-decoration: none; font-size: 0.8em; font-weight: 600; }
    .status-value a:hover { text-decoration: underline; }
    .status-badge {
        font-size: 0.68rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 2px 9px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--accent) 16%, var(--surface));
        color: var(--accent-strong);
        border: 1px solid color-mix(in srgb, var(--accent) 30%, transparent);
    }
    .status-chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .status-chip {
        font-size: 0.78rem;
        padding: 3px 10px;
        border-radius: 999px;
        background: var(--surface-muted);
        border: 1px solid var(--border);
        color: var(--text-muted);
        font-family: var(--font-mono);
    }
    .status-chip .commit-sha { color: var(--text-soft); margin-left: 4px; }
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
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
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
    .tab { background: transparent; border: none; padding: 8px 16px; cursor: pointer; border-radius: 9px; font-family: inherit; font-size: 0.88rem; font-weight: 600; color: var(--text-muted); text-decoration: none; display: inline-flex; align-items: center; }
    .tab:hover { color: var(--text); }
    .tab.active { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }
    .file-list { list-style: none; padding: 14px 16px; max-height: 320px; overflow-y: auto; background: var(--surface-muted); border: 1px solid var(--border); border-radius: var(--radius); }
    .file-list li { padding: 4px 0; font-family: var(--font-mono); font-size: 0.84rem; color: var(--text-muted); }
    .back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 20px; color: var(--brand); text-decoration: none; font-weight: 600; font-size: 0.9rem; }
    .back-link::before { content: '←'; }
    .back-link:hover { text-decoration: underline; }
    .logout-form { display: inline-block; }
    .login-form { max-width: 340px; margin: 36px auto; }
    .login-form p { color: var(--text-muted); margin-bottom: 14px; }
    .login-form input[type="password"] { width: 100%; padding: 12px 14px; border: 1px solid var(--border-strong); border-radius: 10px; margin-bottom: 12px; font-size: 1em; font-family: inherit; background: var(--surface-muted); color: var(--text); }
    .login-form input[type="password"]:focus { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    .login-form .btn { width: 100%; padding: 12px; }
    .env-config { background: var(--surface-muted); border: 1px solid var(--border); padding: 20px; border-radius: var(--radius); margin-bottom: 22px; }
    .env-form { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .env-form--stack { align-items: stretch; }
    .env-form--inline { flex-wrap: nowrap; align-items: center; }
    .env-row { display: flex; align-items: center; gap: 8px; }
    .env-row--stack { flex-direction: column; align-items: stretch; gap: 6px; width: min(100%, 420px); }
    .env-row--inline { flex-direction: row; align-items: center; min-width: 0; }
    .env-row--grow { flex: 1 1 auto; }
    .env-row--wide { width: min(100%, 720px); }
    .env-row label { font-weight: 600; color: var(--text-muted); font-size: 0.88rem; }
    .env-row--inline label { white-space: nowrap; }
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
    .env-input--wide { width: 100%; }
    .env-input--uuid { flex: 1 1 auto; width: 100%; min-width: 0; }
    .env-form--inline .btn { margin-left: auto; }
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
    .dropdown { position: relative; display: inline-block; min-width: 130px; text-align: left; }
    .dropdown-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 8px 12px;
        border: 1px solid var(--border-strong);
        border-radius: 9px;
        background: var(--surface);
        color: var(--text);
        font-size: 0.88rem;
        font-family: inherit;
        cursor: pointer;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .dropdown-toggle:disabled { opacity: 0.55; cursor: not-allowed; }
    .dropdown-toggle:hover { border-color: var(--brand); }
    .dropdown.is-open .dropdown-toggle, .dropdown-toggle:focus-visible { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    .dropdown-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dropdown-chevron { width: 16px; height: 16px; flex-shrink: 0; color: var(--text-soft); transition: transform 0.2s ease; }
    .dropdown.is-open .dropdown-chevron { transform: rotate(180deg); }
    .dropdown-menu {
        position: absolute;
        z-index: 50;
        top: calc(100% + 6px);
        left: 0;
        min-width: 100%;
        margin: 0;
        padding: 6px;
        list-style: none;
        background: var(--surface);
        border: 1px solid var(--border-strong);
        border-radius: 11px;
        box-shadow: var(--shadow-lg);
        max-height: 280px;
        overflow-y: auto;
        opacity: 0;
        transform: translateY(-6px) scale(0.98);
        transform-origin: top;
        pointer-events: none;
        transition: opacity 0.15s ease, transform 0.15s ease;
    }
    .dropdown.is-open .dropdown-menu { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }
    .dropdown-option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 8px 10px;
        border-radius: 7px;
        cursor: pointer;
        font-size: 0.88rem;
        color: var(--text);
        white-space: nowrap;
    }
    .dropdown-option.is-active { background: var(--surface-muted); }
    .dropdown-option.is-selected { color: var(--brand-strong); font-weight: 600; }
    .dropdown-option.is-selected::after { content: '✓'; color: var(--brand); font-weight: 700; }
    .dropdown-version { min-width: 220px; }
    .install-form { display: inline-flex; align-items: center; gap: 10px; }
    .tag-list { overflow: visible; }
    .tag-list li:first-child { border-top-left-radius: var(--radius); border-top-right-radius: var(--radius); }
    .tag-list li:last-child { border-bottom-left-radius: var(--radius); border-bottom-right-radius: var(--radius); }
    .status-count {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 4px 6px 4px 4px;
        border: 1px solid var(--border);
        border-radius: 999px;
        background: var(--surface-muted);
        color: var(--text);
        font: inherit;
        font-size: 0.82rem;
        cursor: pointer;
        transition: border-color .15s ease, background .15s ease;
    }
    .status-count:hover { border-color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, var(--surface)); }
    .status-count:focus-visible { outline: none; box-shadow: var(--ring); }
    .status-count-num {
        display: grid;
        place-items: center;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        border-radius: 999px;
        background: var(--brand);
        color: #fff;
        font-size: 0.78rem;
        font-weight: 700;
    }
    .status-count-text { color: var(--text-muted); padding-right: 4px; }
    .modal {
        position: fixed;
        inset: 0;
        z-index: 100;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .modal.is-open { display: flex; }
    .modal-backdrop {
        position: absolute;
        inset: 0;
        background: color-mix(in srgb, #0b1220 55%, transparent);
        backdrop-filter: blur(2px);
    }
    .modal-dialog {
        position: relative;
        width: 100%;
        max-width: 460px;
        max-height: 80vh;
        display: flex;
        flex-direction: column;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-lg);
        animation: modalIn .16s ease;
    }
    @keyframes modalIn { from { opacity: 0; transform: translateY(8px) scale(0.98); } to { opacity: 1; transform: none; } }
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 18px;
        border-bottom: 1px solid var(--border);
    }
    .modal-title { font-size: 1.02rem; font-weight: 650; color: var(--text); }
    .modal-close {
        flex: none;
        width: 30px;
        height: 30px;
        border: none;
        border-radius: 8px;
        background: transparent;
        color: var(--text-muted);
        font-size: 1.3rem;
        line-height: 1;
        cursor: pointer;
        transition: background .15s ease, color .15s ease;
    }
    .modal-close:hover { background: var(--surface-muted); color: var(--text); }
    .modal-body { padding: 14px 18px 18px; overflow-y: auto; }
    .confirm-message { color: var(--text); line-height: 1.55; margin: 0; }
    .modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 18px;
    }
    .modal-list { list-style: none; display: flex; flex-direction: column; gap: 8px; }
    .modal-list li { display: flex; }
    .modal-list code {
        flex: 1;
        background: var(--code-bg);
        color: var(--code-text);
        padding: 7px 11px;
        border-radius: 8px;
        font-size: 0.86rem;
        word-break: break-all;
    }
    body.modal-open { overflow: hidden; }
</style>
</head>
<body>
<div class="container">
    <header>
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <img src="logo/svg/oakengine.svg" alt="">
            </span>
            <div class="header-left">
                <h1>{$brandTitleEscaped}</h1>
                {$pageSubtitleHtml}
            </div>
        </div>
        <div class="header-right">
            <div class="header-actions">
                {$logoutButton}
            </div>
            {$langSwitcherHtml}
        </div>
    </header>
    {$errorHtml}
    {$dashboardNavHtml}
    <main class="dashboard-main">{$mainSection}</main>
</div>
<footer>
    <a href="https://github.com/oakengine/installer" target="_blank" class="footer-link">github.com/oakengine/installer</a>
</footer>
{$confirmationModal}
{$dropdownScript}
{$modalScript}
</body>
</html>
HTML;
}
