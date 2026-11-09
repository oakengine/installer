<?php

declare(strict_types=1);

use Oak\Engine\Installer\InstallManifestManager;
use Oak\Engine\Installer\ProjectPackageApiClient;

/**
 * Renders the first-installation wizard ("Erstinstallation") view. Shows the
 * install-all form (VIS admin + package preview) while the target has no
 * runner manifest yet; afterwards a summary state with further links.
 *
 * @param ProjectPackageApiClient          $runnerClient
 * @param ProjectPackageApiClient          $pluginClient
 * @param ProjectPackageApiClient          $dataClient
 * @param array<string, mixed>             $config
 * @param array<string, string>            $lang
 */
function renderSetupView(
    string $targetDir,
    ProjectPackageApiClient $runnerClient,
    ProjectPackageApiClient $pluginClient,
    ProjectPackageApiClient $dataClient,
    array $config,
    array $lang,
    string $errorKey = '',
): void {
    $envPath = rtrim($targetDir, '/').'/.env.local';
    $manifestManager = new InstallManifestManager();
    $runnerManifest = $manifestManager->loadManifest((string) $targetDir);

    $content = '';

    if ('' !== $errorKey) {
        $content .= '<div class="error">'.htmlspecialchars((string) resolveLangKey($errorKey, $lang)).'</div>';
    }

    if (null !== $runnerManifest) {
        // Already installed: only show the summary.
        $content .= '<div class="success">'.resolveLangKey('setup_already_installed', $lang, [
            'version' => htmlspecialchars((string) $runnerManifest['version']),
        ]).'</div>';
        $content .= '<article class="home-card updates-section-card">'
            .'<div class="home-card-header home-card-header--static">'
            .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.lucideIcon('info', 14).'</span>'.resolveLangKey('setup_section_next', $lang).'</span>'
            .'</div><div class="updates-list"><ul class="tag-list">'
            .'<li>'.resolveLangKey('setup_next_docroot', $lang, ['dir' => '<code>'.htmlspecialchars($targetDir).'/public</code>']).'</li>'
            .'<li>'.resolveLangKey('setup_next_updates', $lang).'</li>'
            .'</ul></div></article>';
        $content .= '<a class="back-link" href="'.htmlspecialchars((string) buildDashboardViewHref('updates')).'">'.htmlspecialchars((string) resolveLangKey('dashboard_updates', $lang)).'</a>';

        echo renderPage((string) resolveLangKey('setup_title', $lang), $content, null, $envPath, !empty($config['password'] ?? ''), 'setup');

        return;
    }

    $setupPackagesConfig = is_array($config['setup_packages'] ?? null) ? $config['setup_packages'] : [];
    $runnerPackage = pickSetupPackage('', $runnerClient->listPackages());
    $pluginPackage = pickSetupPackage(is_scalar($setupPackagesConfig['plugin'] ?? null) ? (string) $setupPackagesConfig['plugin'] : '', $pluginClient->listPackages());
    $dataPackage = pickSetupPackage(is_scalar($setupPackagesConfig['data'] ?? null) ? (string) $setupPackagesConfig['data'] : '', $dataClient->listPackages());

    $previewRows = '';
    $previewItems = [
        ['setup_package_runner', $runnerPackage],
        ['setup_package_plugin', $pluginPackage],
        ['setup_package_data', $dataPackage],
    ];
    foreach ($previewItems as [$previewLabel, $previewPackage]) {
        $previewRows .= '<li><span class="ref-item-info"><span class="tag-name">'
            .htmlspecialchars((string) resolveLangKey((string) $previewLabel, $lang)).': '
            .(null !== $previewPackage
                ? '<strong>'.htmlspecialchars((string) $previewPackage['package_id']).'</strong> <span class="chip chip-channel">'.htmlspecialchars((string) $previewPackage['version']).'</span>'
                : '<em>'.htmlspecialchars((string) resolveLangKey('setup_package_missing', $lang)).'</em>')
            .'</span></span></li>';
    }

    $visUsersExist = is_file(resolveVisUsersFilePath($targetDir));
    $visAdminHint = $visUsersExist
        ? '<p class="env-help">'.htmlspecialchars((string) resolveLangKey('setup_vis_exists_hint', $lang)).'</p>'
        : '';

    $projectName = '';
    if (isset($config['project']) && is_scalar($config['project'])) {
        $projectName = (string) $config['project'];
    }

    $content .= '<article class="home-card updates-header-card">'
        .'<div class="updates-meta">'
        .'<div class="updates-meta-row"><span class="updates-meta-label">'.htmlspecialchars((string) resolveLangKey('setup_intro', $lang)).'</span></div>'
        .('' !== $projectName
            ? '<div class="updates-meta-row"><span class="updates-meta-label">'.htmlspecialchars((string) resolveLangKey('setup_project', $lang)).':</span><span class="updates-meta-value"><code>'.htmlspecialchars($projectName).'</code></span></div>'
            : '')
        .'</div></article>';

    $content .= '<article class="home-card updates-section-card">'
        .'<div class="home-card-header home-card-header--static">'
        .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.lucideIcon('download', 14).'</span>'.htmlspecialchars((string) resolveLangKey('setup_section_packages', $lang)).'</span>'
        .'</div><div class="updates-list"><ul class="tag-list">'.$previewRows.'</ul></div></article>';

    $content .= '<form method="post" class="env-form">'
        .'<input type="hidden" name="view" value="setup">'
        .'<div class="env-field-group">'
        .'<h3>'.htmlspecialchars((string) resolveLangKey('setup_section_vis', $lang)).'</h3>'
        .$visAdminHint
        .'<label>'.htmlspecialchars((string) resolveLangKey('vis_users_email', $lang))
        .'<input type="email" name="vis_admin_email" required placeholder="admin@example.com"></label>'
        .'<label>'.htmlspecialchars((string) resolveLangKey('vis_users_password', $lang))
        .'<input type="password" name="vis_admin_password" required minlength="8" autocomplete="new-password"></label>'
        .'<label>'.htmlspecialchars((string) resolveLangKey('vis_users_password_repeat', $lang))
        .'<input type="password" name="vis_admin_password_repeat" required minlength="8" autocomplete="new-password"></label>'
        .'<p class="env-help">'.htmlspecialchars((string) resolveLangKey('setup_vis_help', $lang)).'</p>'
        .'</div>'
        .'<div class="env-actions">'
        .'<button type="submit" name="install_all" value="1" '.renderConfirmAttributes(
            (string) resolveLangKey('setup_title', $lang),
            (string) resolveLangKey('setup_confirm', $lang),
            (string) resolveLangKey('setup_title', $lang),
        ).'>'.lucideIcon('sparkles', 16).' '.htmlspecialchars((string) resolveLangKey('setup_button', $lang)).'</button>'
        .'</div>'
        .'</form>';

    echo renderPage((string) resolveLangKey('setup_title', $lang), $content, null, $envPath, !empty($config['password'] ?? ''), 'setup');
}

/**
 * Renders the VIS user management view (list + create/change/delete).
 *
 * @param array<string, string> $lang
 */
function renderVisUsersView(string $targetDir, array $lang, string $notice = ''): void
{
    $envPath = rtrim($targetDir, '/').'/.env.local';
    $users = loadVisUsers($targetDir);
    $knownRoles = collectVisRoles($users);
    $usersFileExists = is_file(resolveVisUsersFilePath($targetDir));

    $content = $notice;

    if (!$usersFileExists) {
        $content .= '<div class="warning">'.htmlspecialchars((string) resolveLangKey('vis_users_file_missing', $lang)).'</div>';
    }

    // User list with per-user actions.
    $rows = '';
    foreach ($users as $user) {
        $roleChips = '';
        foreach ($user['roles'] as $role) {
            $roleChips .= '<span class="chip chip-channel">'.htmlspecialchars($role).'</span> ';
        }

        $roleCheckboxes = '';
        foreach ($knownRoles as $knownRole) {
            $checked = in_array($knownRole, $user['roles'], true) ? ' checked' : '';
            $roleCheckboxes .= '<label class="vis-role-checkbox"><input type="checkbox" name="vis_user_roles[]" value="'.htmlspecialchars($knownRole).'"'.$checked.'> '.htmlspecialchars($knownRole).'</label>';
        }

        $detailsRoles = '<details class="vis-user-details"><summary>'.htmlspecialchars((string) resolveLangKey('vis_users_edit_roles', $lang)).'</summary>'
            .'<form method="post" class="vis-inline-form">'
            .'<input type="hidden" name="view" value="vis-users">'
            .'<input type="hidden" name="vis_user_id" value="'.htmlspecialchars($user['id']).'">'
            .'<div class="vis-role-list">'.$roleCheckboxes.'</div>'
            .'<button type="submit" name="vis_user_roles" value="1" class="btn btn-secondary btn-sm">'.lucideIcon('save', 14).' '.htmlspecialchars((string) resolveLangKey('save', $lang)).'</button>'
            .'</form></details>';

        $detailsPassword = '<details class="vis-user-details"><summary>'.htmlspecialchars((string) resolveLangKey('vis_users_change_password', $lang)).'</summary>'
            .'<form method="post" class="vis-inline-form">'
            .'<input type="hidden" name="view" value="vis-users">'
            .'<input type="hidden" name="vis_user_id" value="'.htmlspecialchars($user['id']).'">'
            .'<input type="password" name="vis_user_new_password" required minlength="8" autocomplete="new-password" placeholder="'.htmlspecialchars((string) resolveLangKey('vis_users_new_password', $lang)).'">'
            .'<button type="submit" name="vis_user_password" value="1" class="btn btn-secondary btn-sm">'.lucideIcon('key-round', 14).' '.htmlspecialchars((string) resolveLangKey('vis_users_change_password', $lang)).'</button>'
            .'</form></details>';

        $deleteConfirm = renderConfirmAttributes(
            (string) resolveLangKey('vis_users_delete', $lang),
            (string) resolveLangKey('vis_users_confirm_delete', $lang, ['email' => $user['email']]),
            (string) resolveLangKey('vis_users_delete', $lang),
        );
        $deleteForm = '<form method="post" class="vis-inline-form"'.$deleteConfirm.'>'
            .'<input type="hidden" name="view" value="vis-users">'
            .'<input type="hidden" name="vis_user_id" value="'.htmlspecialchars($user['id']).'">'
            .'<button type="submit" name="vis_user_delete" value="1" class="btn btn-secondary btn-sm">'.lucideIcon('x', 14).' '.htmlspecialchars((string) resolveLangKey('vis_users_delete', $lang)).'</button>'
            .'</form>';

        $rows .= '<li class="vis-user-row">'
            .'<div class="ref-item-info"><span class="tag-name">'.htmlspecialchars($user['email']).'</span>'
            .'<span class="commit-sha">'.htmlspecialchars($user['createdAt']).'</span></div>'
            .'<div class="vis-user-roles">'.$roleChips.'</div>'
            .'<div class="vis-user-actions">'.$detailsRoles.$detailsPassword.$deleteForm.'</div>'
            .'</li>';
    }
    if ('' === $rows) {
        $rows = '<li class="updates-empty">'.htmlspecialchars((string) resolveLangKey('vis_users_empty', $lang)).'</li>';
    }

    $content .= '<article class="home-card updates-section-card">'
        .'<div class="home-card-header home-card-header--static">'
        .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.lucideIcon('users', 14).'</span>'.htmlspecialchars((string) resolveLangKey('vis_users_title', $lang)).'</span>'
        .'</div><div class="updates-list"><ul class="tag-list vis-user-list">'.$rows.'</ul></div></article>';

    // Create form.
    $createRoleCheckboxes = '';
    foreach ($knownRoles as $knownRole) {
        $createRoleCheckboxes .= '<label class="vis-role-checkbox"><input type="checkbox" name="vis_user_roles[]" value="'.htmlspecialchars($knownRole).'"'.('ROLE_USER' === $knownRole ? ' checked' : '').'> '.htmlspecialchars($knownRole).'</label>';
    }

    $content .= '<article class="home-card updates-section-card">'
        .'<div class="home-card-header home-card-header--static">'
        .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.lucideIcon('plus', 14).'</span>'.htmlspecialchars((string) resolveLangKey('vis_users_add_title', $lang)).'</span>'
        .'</div><div class="updates-list">'
        .'<form method="post" class="env-form vis-create-form">'
        .'<input type="hidden" name="view" value="vis-users">'
        .'<label>'.htmlspecialchars((string) resolveLangKey('vis_users_email', $lang))
        .'<input type="email" name="vis_user_email" required placeholder="name@example.com"></label>'
        .'<label>'.htmlspecialchars((string) resolveLangKey('vis_users_password', $lang))
        .'<input type="password" name="vis_user_password" required minlength="8" autocomplete="new-password"></label>'
        .'<div class="vis-role-list vis-role-list-create">'.$createRoleCheckboxes.'</div>'
        .'<div class="env-actions"><button type="submit" name="vis_user_create" value="1" class="btn btn-secondary">'.lucideIcon('plus', 16).' '.htmlspecialchars((string) resolveLangKey('vis_users_add', $lang)).'</button></div>'
        .'</form>'
        .'</div></article>';

    $content .= '<p class="env-help">'.htmlspecialchars((string) resolveLangKey('vis_users_help', $lang)).'</p>';

    echo renderPage((string) resolveLangKey('vis_users_title', $lang), $content, null, $envPath, true, 'vis-users');
}
