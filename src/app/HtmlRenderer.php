<?php

declare(strict_types=1);

/**
 * Resolves the active dashboard view from an untrusted request value.
 * Falls back to the "home" view for any unknown value.
 */
function resolveDashboardView(mixed $raw): string
{
    $allowed = ['home', 'updates', 'environment', 'databases', 'install-uuid', 'installer', 'system'];
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
    $params = ['_t' => time()];

    if ('home' !== $view) {
        $params['view'] = $view;
        if ('installer' === $view) {
            $params['itab'] = resolveInstallerTab($itab);
        }
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
        .'<svg class="dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>'
        .'</button>'
        .'<ul class="dropdown-menu" role="listbox" tabindex="-1">'.$optionsHtml.'</ul>'
        .'</div>';
}

/**
 * Renders a friendly welcome card with a short description and quick links.
 * Used at the top of the System dashboard view.
 *
 * @param list<array{label: string, href: string, icon?: string, external?: bool}> $quickLinks
 * @param array<string, string>                                                    $lang
 */
function renderWelcomeBox(string $title, string $subtitle, array $quickLinks, array $lang): string
{
    $linksHtml = '';
    foreach ($quickLinks as $link) {
        $label = htmlspecialchars((string) ($link['label'] ?? ''));
        $href = htmlspecialchars((string) ($link['href'] ?? '#'));
        $iconKey = (string) ($link['icon'] ?? 'arrow-right');
        $iconSvg = lucideIcon($iconKey, 14);
        $external = !empty($link['external']);
        $targetAttr = $external ? ' target="_blank" rel="noopener noreferrer"' : '';
        $linksHtml .= '<a class="welcome-link" href="'.$href.'"'.$targetAttr.'>'.$iconSvg.' <span>'.$label.'</span></a>';
    }

    return '<section class="welcome-card">'
        .'<div class="welcome-card-glow" aria-hidden="true"></div>'
        .'<div class="welcome-card-body">'
        .'<div class="welcome-card-icon" aria-hidden="true">'.lucideIcon('sparkles', 22).'</div>'
        .'<div class="welcome-card-content">'
        .'<h3 class="welcome-card-title">'.htmlspecialchars($title).'</h3>'
        .'<p class="welcome-card-subtitle">'.htmlspecialchars($subtitle).'</p>'
        .'</div>'
        .('' !== $linksHtml ? '<div class="welcome-card-links">'.$linksHtml.'</div>' : '')
        .'</div>'
        .'</section>';
}

/**
 * Renders the home page as a read-only System info card plus a set of
 * actionable sub-area cards. Each card contains a list of items; items may
 * carry an `action` URL rendered as a gear-icon button.
 *
 * Item value is treated as trusted HTML; callers are responsible for escaping.
 * Icon names map to the Lucide-style paths bundled in this file.
 *
 * @param string                                                                                                                                                         $infoFooterHtml Optional trusted HTML appended to the System card body
 * @param list<array{icon: string, label: string, value: string, action_html?: string}>                                                                                  $infoItems
 * @param list<array{title: string, icon: string, href: string, items: list<array{icon: string, label: string, value: string, action?: string, action_title?: string}>}> $sections
 * @param bool                                                                                                                                                           $infoCardFirst  Whether the System info card is rendered before (true) or after (false) the section cards
 */
function renderHomeSections(string $infoTitle, array $infoItems, array $sections, string $infoFooterHtml = '', bool $infoCardFirst = true): string
{
    $paths = [
        'installer' => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/>',
        'runner' => '<path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09"/><path d="M9 12a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.4 22.4 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 .05 5 .05"/>',
        'plugin' => '<path d="M15.39 4.39a1 1 0 0 0 1.68-.474 2.5 2.5 0 1 1 3.014 3.015 1 1 0 0 0-.474 1.68l1.683 1.682a2.414 2.414 0 0 1 0 3.414L19.61 15.39a1 1 0 0 1-1.68-.474 2.5 2.5 0 1 0-3.014 3.015 1 1 0 0 1 .474 1.68l-1.683 1.682a2.414 2.414 0 0 1-3.414 0L8.61 19.61a1 1 0 0 0-1.68.474 2.5 2.5 0 1 1-3.014-3.015 1 1 0 0 0 .474-1.68l-1.683-1.682a2.414 2.414 0 0 1 0-3.414L4.39 8.61a1 1 0 0 1 1.68.474 2.5 2.5 0 1 0 3.014-3.015 1 1 0 0 1-.474-1.68l1.683-1.682a2.414 2.414 0 0 1 3.414 0z"/>',
        'data' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
        'endpoint' => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'folder-tree' => '<path d="M20 10a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-2.5a1 1 0 0 1-.8-.4l-.9-1.2A1 1 0 0 0 15 3h-2a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1Z"/><path d="M20 21a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1h-2.9a1 1 0 0 1-.88-.55l-.42-.85a1 1 0 0 0-.92-.6H13a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1Z"/><path d="M3 5a2 2 0 0 0 2 2h3"/><path d="M3 3v13a2 2 0 0 0 2 2h3"/>',
        'hard-drive' => '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'settings' => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
        'fingerprint' => '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.106-3.105c.32-.322.863-.22.983.218a6 6 0 0 1-8.259 7.057l-7.91 7.91a1 1 0 0 1-2.999-3l7.91-7.91a6 6 0 0 1 7.057-8.259c.438.12.54.662.219.984z"/>',
        'download' => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'code' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'puzzle' => '<path d="M19.439 7.85c-.049.322.059.648.289.878l1.568 1.568c.47.47.706 1.087.706 1.704s-.235 1.233-.706 1.704l-1.611 1.611a.98.98 0 0 1-.837.276c-.47-.07-.802-.48-.968-.925a2.501 2.501 0 1 0-3.214 3.214c.446.166.855.497.925.968a.979.979 0 0 1-.276.837l-1.61 1.61a2.404 2.404 0 0 1-1.705.707 2.402 2.402 0 0 1-1.704-.706l-1.568-1.568a1.026 1.026 0 0 0-.877-.29c-.493.074-.84.504-1.02.968a2.5 2.5 0 1 1-3.237-3.237c.464-.18.894-.527.967-1.02a1.026 1.026 0 0 0-.289-.877l-1.568-1.568a2.402 2.402 0 0 1-.706-1.704c0-.617.236-1.234.706-1.704L4.81 9.475a.98.98 0 0 1 .837-.276c.47.07.802.48.967.925a2.501 2.501 0 1 0 3.214-3.214c-.445-.166-.854-.497-.925-.968a.98.98 0 0 1 .276-.837l1.611-1.611A2.405 2.405 0 0 1 11.485 2.1c.617 0 1.234.236 1.704.706l1.568 1.568c.23.23.556.338.877.29.493-.075.84-.504 1.02-.969a2.5 2.5 0 1 1 3.237 3.237c-.464.18-.894.527-.967 1.02Z"/>',
        'upload-cloud' => '<path d="M4 14.899A7 7 0 1 1 15 21h-1"/><path d="M12 12v9"/><path d="m16 16-4-4-4 4"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'memory-stick' => '<path d="M6 19v-3"/><path d="M10 19v-3"/><path d="M14 19v-3"/><path d="M18 19v-3"/><path d="M8 7V5"/><path d="M16 7V5"/><path d="M12 7V5"/><path d="M12 12V8"/><rect width="20" height="14" x="2" y="3" rx="2"/>',
    ];

    $icons = [];
    foreach ($paths as $iconKey => $iconPath) {
        $icons[$iconKey] = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$iconPath.'</svg>';
    }

    $infoRows = '';
    foreach ($infoItems as $item) {
        $iconKey = (string) ($item['icon'] ?? '');
        $iconSvg = '' !== $iconKey ? ($icons[$iconKey] ?? '') : '';
        $iconHtml = '' !== $iconSvg ? '<span class="info-list-icon" aria-hidden="true">'.$iconSvg.'</span>' : '';
        $actionHtml = isset($item['action_html']) ? (string) $item['action_html'] : '';
        $infoRows .= '<li class="info-list-item">'
            .$iconHtml
            .'<div class="info-list-body">'
            .'<span class="info-list-label">'.htmlspecialchars($item['label']).'</span>'
            .'<span class="info-list-value">'.$item['value'].'</span>'
            .'</div>'.$actionHtml.'</li>';
    }

    $infoCard = '';
    if ([] !== $infoItems) {
        $infoTitleSvg = $icons['info'];
        $infoFooter = '' !== $infoFooterHtml ? '<div class="home-info-footer">'.$infoFooterHtml.'</div>' : '';
        $infoCard = '<article class="home-card">'
            .'<div class="home-card-header home-card-header--static">'
            .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.$infoTitleSvg.'</span>'.htmlspecialchars($infoTitle).'</span>'
            .'</div>'
            .'<ul class="info-list">'.$infoRows.'</ul>'
            .$infoFooter
            .'</article>';
    }

    if ([] === $sections) {
        return '<div class="home-stack">'.$infoCard.'</div>';
    }

    $sectionCards = '';
    foreach ($sections as $section) {
        $sectionIconSvg = $icons[$section['icon']] ?? '';
        $itemsHtml = '';
        foreach ($section['items'] as $item) {
            $itemIconSvg = $icons[$item['icon']] ?? '';
            $actionHtml = '';
            if (isset($item['action']) && '' !== $item['action']) {
                $actionTitle = isset($item['action_title']) ? htmlspecialchars($item['action_title']) : '';
                $actionHtml = '<a class="info-list-action" href="'.htmlspecialchars($item['action']).'" title="'.$actionTitle.'" aria-label="'.$actionTitle.'">'.lucideIcon('settings', 16).'</a>';
            }
            $itemsHtml .= '<li class="info-list-item">'
                .'<span class="info-list-icon" aria-hidden="true">'.$itemIconSvg.'</span>'
                .'<div class="info-list-body">'
                .'<span class="info-list-label">'.htmlspecialchars($item['label']).'</span>'
                .'<span class="info-list-value">'.$item['value'].'</span>'
                .'</div>'.$actionHtml.'</li>';
        }
        $sectionHref = htmlspecialchars($section['href']);
        $sectionTitle = htmlspecialchars($section['title']);
        $sectionCards .= '<article class="home-card">'
            .'<a class="home-card-header" href="'.$sectionHref.'">'
            .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.$sectionIconSvg.'</span>'.$sectionTitle.'</span>'
            .'<span class="home-card-cta" aria-hidden="true">'.lucideIcon('arrow-right', 16).'</span>'
            .'</a>'
            .'<ul class="info-list">'.$itemsHtml.'</ul>'
            .'</article>';
    }

    if ('' === $infoCard) {
        $cardsHtml = $sectionCards;
    } else {
        $cardsHtml = $infoCardFirst ? ($infoCard.$sectionCards) : ($sectionCards.$infoCard);
    }

    return '<div class="home-stack">'.$cardsHtml.'</div>';
}

/**
 * Returns an inline SVG for a Lucide v1.17.0 icon.
 *
 * @param int $size SVG width/height in pixels (default 16)
 */
function lucideIcon(string $name, int $size = 16): string
{
    /** @var array<string, string> $map */
    static $map = [
        'home' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'server' => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
        'sparkles' => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>',
        'refresh-cw' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'settings' => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
        'fingerprint' => '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.106-3.105c.32-.322.863-.22.983.218a6 6 0 0 1-8.259 7.057l-7.91 7.91a1 1 0 0 1-2.999-3l7.91-7.91a6 6 0 0 1 7.057-8.259c.438.12.54.662.219.984z"/>',
        'download' => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'trash-2' => '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'save' => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
        'play' => '<path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'log-in' => '<path d="m10 17 5-5-5-5"/><path d="M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>',
        'log-out' => '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
        'external-link' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'git-branch' => '<path d="M15 6a9 9 0 0 0-9 9V3"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/>',
        'tag' => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'runner' => '<path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09"/><path d="M9 12a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.4 22.4 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 .05 5 .05"/>',
        'plugin' => '<path d="M15.39 4.39a1 1 0 0 0 1.68-.474 2.5 2.5 0 1 1 3.014 3.015 1 1 0 0 0-.474 1.68l1.683 1.682a2.414 2.414 0 0 1 0 3.414L19.61 15.39a1 1 0 0 1-1.68-.474 2.5 2.5 0 1 0-3.014 3.015 1 1 0 0 1 .474 1.68l-1.683 1.682a2.414 2.414 0 0 1-3.414 0L8.61 19.61a1 1 0 0 0-1.68.474 2.5 2.5 0 1 1-3.014-3.015 1 1 0 0 0 .474-1.68l-1.683-1.682a2.414 2.414 0 0 1 0-3.414L4.39 8.61a1 1 0 0 1 1.68.474 2.5 2.5 0 1 0 3.014-3.015 1 1 0 0 1-.474-1.68l1.683-1.682a2.414 2.414 0 0 1 3.414 0z"/>',
        'data' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
        'archive' => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'hard-drive' => '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/>',
    ];

    $inner = $map[$name] ?? '';
    if ('' === $inner) {
        return '';
    }

    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$inner.'</svg>';
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
        .'<button type="button" class="modal-close" data-modal-close="'.$idAttr.'" aria-label="'.htmlspecialchars($closeLabel).'">'.lucideIcon('x', 16).'</button>'
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
        .'<button type="button" class="modal-close" data-modal-close="'.$idAttr.'" aria-label="'.$closeLabelEscaped.'">'.lucideIcon('x', 16).'</button>'
        .'</div>'
        .'<div class="modal-body">'
        .'<p class="confirm-message" data-confirm-message></p>'
        .'<div class="modal-actions">'
        .'<button type="button" class="btn btn-secondary" data-modal-close="'.$idAttr.'">'.lucideIcon('x', 14).' '.$closeLabelEscaped.'</button>'
        .'<button type="button" class="btn" data-confirm-submit>'.lucideIcon('check', 14).' </button>'
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
            .'<button type="submit" name="install" class="btn">'.lucideIcon('download', 15).' '.resolveLangKey('install', $lang).'</button>'
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

    $iconLogin = lucideIcon('log-in', 16);
    $content = <<<HTML
<div class="login-form">
<p>{$text_please_enter_password}</p>
{$errorHtml}
<form method="post">
    <input type="password" name="password" placeholder="{$text_password_placeholder}" autofocus>
    <button type="submit" class="btn">{$iconLogin} {$text_login}</button>
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
    $logoutButton = $showLogout ? '<form method="get" class="logout-form"><input type="hidden" name="logout" value="1"><input type="hidden" name="_t" value="'.time().'"><button type="submit" class="btn btn-secondary btn-small">'.lucideIcon('log-out', 14).' '.htmlspecialchars($text_logout).'</button></form>' : '';

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
        $text_active_database = resolveLangKey('active_database', $langForTemplate);
        $text_active_database_help = resolveLangKey('active_database_help', $langForTemplate);
        $text_remove_database_help = resolveLangKey('remove_database_help', $langForTemplate);
        $text_dashboard_updates = resolveLangKey('dashboard_updates', $langForTemplate);
        $text_dashboard_environment = resolveLangKey('dashboard_environment', $langForTemplate);
        $text_dashboard_databases = resolveLangKey('dashboard_databases', $langForTemplate);
        $text_app_secret = resolveLangKey('app_secret', $langForTemplate);
        $text_app_secret_help = resolveLangKey('app_secret_help', $langForTemplate);
        $text_app_secret_placeholder = resolveLangKey('app_secret_placeholder', $langForTemplate);
        $text_regenerate_app_secret = resolveLangKey('regenerate_app_secret', $langForTemplate);
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
        $currentAppSecret = htmlspecialchars((string) ($envConfig['app_secret'] ?? ''));
        $dashboardStateInputs = renderDashboardStateInputs($activeView, $requestDashboardState['itab']);
        $iconSave = lucideIcon('save', 14);
        $iconPlay = lucideIcon('play', 14);
        $iconPlus = lucideIcon('plus', 14);
        $iconTrash = lucideIcon('trash-2', 14);
        $iconRefresh = lucideIcon('refresh-cw', 14);

        $envConfigHtml = <<<HTML
<div class="env-config">
<form method="post" class="env-form env-form--stack">
    {$dashboardStateInputs}

    <div class="env-row env-row--stack env-row--wide">
        <label>{$text_mode}:</label>
        {$appEnvDropdown}
    </div>

    <div class="env-row env-row--stack env-row--wide">
        <label for="app_secret_input">{$text_app_secret}:</label>
        <div class="input-group">
            <input type="text" id="app_secret_input" name="app_secret" value="{$currentAppSecret}" class="env-input env-input--secret" placeholder="{$text_app_secret_placeholder}">
            <button type="submit" name="regenerate_app_secret" value="1" class="input-group-append" title="{$text_regenerate_app_secret}" aria-label="{$text_regenerate_app_secret}">{$iconRefresh}</button>
        </div>
        <p class="env-help">{$text_app_secret_help}</p>
    </div>

    <div class="env-row env-row--stack env-row--wide">
        <label for="env_content_textarea">{$text_env_editor}:</label>
        <textarea id="env_content_textarea" name="env_content" class="env-textarea">{$envRawContent}</textarea>
    </div>

    <div class="env-actions">
        <button type="submit" name="save_env_content" class="btn btn-secondary">{$iconSave} {$text_save}</button>
    </div>
</form>
</div>
HTML;

        $dbConfigHtml = <<<HTML
<div class="env-config">
<div class="env-section-header">
    <div>
        <h3 class="env-section-title">{$text_migrations_status}</h3>
        <div>{$migrationsStatusHtml}</div>
    </div>
    <form method="post"{$runMigrationsConfirmAttr}>
        {$dashboardStateInputs}
        <button type="submit" name="run_migrations" class="btn btn-secondary" {$migrationsDisabled}>{$iconPlay} {$text_run_migrations}</button>
    </form>
</div>

<hr class="env-divider">

<form method="post" class="env-form env-form--stack">
    {$dashboardStateInputs}
    <div class="env-row env-row--stack env-row--wide">
        <label for="active_database_select">{$text_active_database}:</label>
        <div class="input-group">
            {$databaseDropdown}
            <button type="submit" name="save_env" value="1" class="input-group-append" title="{$text_save}" aria-label="{$text_save}"{$databaseActionDisabled}>{$iconSave}</button>
        </div>
        <p class="env-help">{$text_active_database_help}</p>
    </div>
</form>

<hr class="env-divider">

<form method="post" class="env-form env-form--stack">
    {$dashboardStateInputs}
    <div class="env-row env-row--stack env-row--wide">
        <label for="db_id_input">{$text_db_id}:</label>
        <input type="text" id="db_id_input" name="db_id" class="env-input env-input--wide" required>
    </div>
    <div class="env-row env-row--stack env-row--wide">
        <label for="db_url_input">{$text_db_url}:</label>
        <input type="text" id="db_url_input" name="db_url" class="env-input env-input--wide" required>
    </div>
    <div class="env-actions">
        <button type="submit" name="add_database" class="btn btn-secondary">{$iconPlus} {$text_add_database}</button>
    </div>
</form>

<hr class="env-divider">

<form method="post" class="env-form env-form--stack">
    {$dashboardStateInputs}
    <div class="env-row env-row--stack env-row--wide">
        <label for="remove_db_id_select">{$text_remove_database}:</label>
        <div class="input-group">
            {$removeDbDropdown}
            <button type="submit" name="remove_database" value="1" class="input-group-append input-group-append--danger" title="{$text_remove_database}" aria-label="{$text_remove_database}"{$removeDatabaseActionDisabled}>{$iconTrash}</button>
        </div>
        <p class="env-help">{$text_remove_database_help}</p>
    </div>
</form>
</div>
HTML;

        $installUuidHtml = <<<HTML
<div class="env-config">
<form method="post" class="env-form env-form--stack">
    {$dashboardStateInputs}

    <div class="env-row env-row--stack env-row--wide">
        <label for="install_uuid_input">{$text_install_uuid}:</label>
        <div class="input-group">
            <input type="text" id="install_uuid_input" name="install_uuid" value="{$currentInstallUuid}" class="env-input env-input--uuid" required pattern="[0-9a-fA-F-]{36}">
            <button type="submit" name="regenerate_install_uuid" value="1" class="input-group-append" title="{$text_regenerate_install_uuid}" aria-label="{$text_regenerate_install_uuid}">{$iconRefresh}</button>
        </div>
        <p class="env-help">{$text_install_uuid_help}</p>
    </div>

    <div class="env-actions">
        <button type="submit" name="save_install_uuid" class="btn btn-secondary">{$iconSave} {$text_save}</button>
    </div>
</form>
</div>
HTML;

        $text_dashboard_home = resolveLangKey('home', $langForTemplate);
        $text_dashboard_installer = resolveLangKey('dashboard_installer', $langForTemplate);
        $text_dashboard_system = resolveLangKey('dashboard_system', $langForTemplate);

        $navItems = [
            ['view' => 'home', 'href' => buildDashboardViewHref('home'), 'label' => $text_dashboard_home, 'icon' => 'home'],
            ['view' => 'updates', 'href' => buildDashboardViewHref('updates'), 'label' => $text_dashboard_updates, 'icon' => 'refresh-cw'],
            ['view' => 'environment', 'href' => buildDashboardViewHref('environment'), 'label' => $text_dashboard_environment, 'icon' => 'settings'],
            ['view' => 'databases', 'href' => buildDashboardViewHref('databases'), 'label' => $text_dashboard_databases, 'icon' => 'database'],
            ['view' => 'install-uuid', 'href' => buildDashboardViewHref('install-uuid'), 'label' => $text_dashboard_install_uuid, 'icon' => 'fingerprint'],
            ['view' => 'installer', 'href' => buildDashboardViewHref('installer'), 'label' => $text_dashboard_installer, 'icon' => 'wrench'],
            ['view' => 'system', 'href' => buildDashboardViewHref('system'), 'label' => $text_dashboard_system, 'icon' => 'server'],
        ];
        $navLinks = '';
        foreach ($navItems as $navItem) {
            $activeClass = ($navItem['view'] === $activeView) ? ' active' : '';
            $navLinks .= '<a class="btn btn-secondary dashboard-btn'.$activeClass.'" href="'.$navItem['href'].'">'.lucideIcon($navItem['icon'], 16).' '.$navItem['label'].'</a>';
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
    $footerIcon = lucideIcon('external-link', 14);

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
        max-width: 1040px;
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
    .brand-mark svg { width: 100%; height: 100%; display: block; }
    .brand-mark svg #oakengine-logo-1,
    .brand-mark svg #oakengine-logo-3,
    .brand-mark svg #oakengine-logo-5 { fill: var(--brand); }
    .brand-mark svg #oakengine-logo-2,
    .brand-mark svg #oakengine-logo-4,
    .brand-mark svg #oakengine-logo-6 { fill: var(--surface); }
    .brand-mark svg #oakengine-logo-7 { fill: color-mix(in srgb, var(--brand) 70%, var(--text)); }
    .brand-mark svg #oakengine-logo-8 { fill: var(--surface); }
    .brand-mark svg #oakengine-logo-1,
    .brand-mark svg #oakengine-logo-2,
    .brand-mark svg #oakengine-logo-3,
    .brand-mark svg #oakengine-logo-4,
    .brand-mark svg #oakengine-logo-5,
    .brand-mark svg #oakengine-logo-6,
    .brand-mark svg #oakengine-logo-7,
    .brand-mark svg #oakengine-logo-8 {
        transform-box: view-box;
        transform-origin: center;
    }
    .brand-mark svg #oakengine-logo-1,
    .brand-mark svg #oakengine-logo-2,
    .brand-mark svg #oakengine-logo-3,
    .brand-mark svg #oakengine-logo-4,
    .brand-mark svg #oakengine-logo-5,
    .brand-mark svg #oakengine-logo-6 {
        animation: oak-logo-outer-spin 24s linear infinite;
    }
    .brand-mark svg #oakengine-logo-7,
    .brand-mark svg #oakengine-logo-8 {
        animation: oak-logo-inner-spin 12s linear infinite;
    }
    @keyframes oak-logo-outer-spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    @keyframes oak-logo-inner-spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(-360deg); }
    }
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
    .info-list {
        list-style: none;
        margin: 0;
        padding: 0;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface);
        overflow: hidden;
    }
    .info-list-item {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 14px 18px;
        border-bottom: 1px solid var(--border);
    }
    .info-list-item:last-child { border-bottom: none; }
    .info-list-icon {
        flex: none;
        display: grid;
        place-items: center;
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: color-mix(in srgb, var(--brand) 12%, var(--surface));
        color: var(--brand-strong);
    }
    .info-list-icon svg { width: 19px; height: 19px; display: block; }
    .info-list-body { min-width: 0; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex: 1; flex-wrap: wrap; }
    .info-list-label {
        font-size: 0.92rem;
        font-weight: 500;
        color: var(--text-muted);
        flex: 0 0 auto;
    }
    .info-list-value {
        font-size: 0.92rem;
        color: var(--text);
        line-height: 1.5;
        word-break: break-word;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: 7px;
        min-width: 0;
    }
    .info-list-value em { color: var(--text-soft); font-style: normal; }
    .info-list-value a { color: var(--brand-strong); text-decoration: none; font-size: 0.85em; font-weight: 600; }
    .info-list-value a:hover { text-decoration: underline; }
    .info-list-action {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 9px;
        color: var(--text-muted);
        background: var(--surface-muted);
        border: 1px solid var(--border);
        text-decoration: none;
        transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
    }
    .info-list-action:hover { color: var(--brand-strong); border-color: var(--brand); background: var(--surface); box-shadow: var(--shadow-sm); }
    .info-list-action:focus-visible { outline: none; box-shadow: var(--ring); }
    .info-list-action svg { width: 16px; height: 16px; display: block; }
    .home-stack { display: flex; flex-direction: column; gap: 16px; margin-bottom: 22px; }
    .home-card-header--static { cursor: default; }
    .home-card-header--static:hover { background: var(--surface-muted); color: var(--text); }
    .home-info-footer { border-top: 1px solid var(--border); padding: 14px 18px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .home-info-footer form { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; flex: 1; }
    .info-list-action-form { flex: 0 0 auto; display: inline-flex; margin: 0; }
    .info-list-meta { color: var(--text-muted); font-size: 0.85em; font-family: var(--font-mono); margin-left: 6px; }
    .info-list-action--danger:hover { color: var(--danger); border-color: color-mix(in srgb, var(--danger) 45%, transparent); background: color-mix(in srgb, var(--danger) 10%, var(--surface)); box-shadow: var(--shadow-sm); }
    .updates-header-card { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 18px; }
    .updates-meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .updates-meta-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .updates-meta-label { font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-soft); }
    .updates-meta-value { font-size: 0.95rem; color: var(--text); }
    .updates-refresh-form { flex: 0 0 auto; }
    .updates-list { padding: 0; }
    .updates-empty { margin: 0; padding: 16px 18px; color: var(--text-soft); font-style: normal; font-size: 0.9rem; }
    .updates-section-card .tag-list,
    .updates-section-card .branch-list { border: none; border-radius: 0; }
    .updates-section-card .tag-list li:last-child,
    .updates-section-card .branch-list li:last-child { border-bottom: none; }
    .installer-tabs { margin-bottom: 0; align-self: flex-start; }
    .ref-item-info { display: inline-flex; align-items: center; gap: 4px; min-width: 0; }
    .welcome-card {
        position: relative;
        overflow: hidden;
        border: 1px solid color-mix(in srgb, var(--brand) 25%, var(--border));
        border-radius: var(--radius);
        background: linear-gradient(135deg, color-mix(in srgb, var(--brand) 12%, var(--surface)) 0%, var(--surface) 60%);
        box-shadow: var(--shadow-sm);
        margin-bottom: 0;
    }
    .welcome-card-glow {
        position: absolute;
        inset: -40% -10% auto auto;
        width: 60%;
        height: 220%;
        background: radial-gradient(closest-side, color-mix(in srgb, var(--brand) 22%, transparent) 0%, transparent 70%);
        pointer-events: none;
        opacity: .85;
    }
    .welcome-card-body {
        position: relative;
        display: flex;
        align-items: center;
        gap: 18px;
        padding: 22px 24px;
        flex-wrap: wrap;
    }
    .welcome-card-icon {
        flex: none;
        display: grid;
        place-items: center;
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: var(--brand);
        color: #fff;
        box-shadow: 0 6px 18px -8px color-mix(in srgb, var(--brand) 70%, transparent);
    }
    .welcome-card-icon svg { width: 22px; height: 22px; display: block; }
    .welcome-card-content { min-width: 0; flex: 1 1 280px; }
    .welcome-card-title {
        font-size: 1.18rem;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: var(--text);
        margin: 0 0 4px;
    }
    .welcome-card-subtitle {
        font-size: 0.93rem;
        color: var(--text-muted);
        line-height: 1.55;
        margin: 0;
    }
    .welcome-card-links {
        flex: 0 1 auto;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .welcome-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 13px;
        border-radius: 999px;
        background: var(--surface);
        border: 1px solid var(--border);
        color: var(--brand-strong);
        font-size: 0.85rem;
        font-weight: 600;
        text-decoration: none;
        box-shadow: var(--shadow-sm);
        transition: border-color .15s ease, color .15s ease, transform .06s ease, box-shadow .15s ease;
    }
    .welcome-link:hover { border-color: var(--brand); color: var(--brand); box-shadow: var(--shadow-md); }
    .welcome-link:active { transform: translateY(1px); }
    .welcome-link svg { width: 14px; height: 14px; flex-shrink: 0; }
    @media (max-width: 600px) {
        .welcome-card-body { padding: 18px 18px; gap: 14px; }
        .welcome-card-icon { width: 42px; height: 42px; }
    }
    .home-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; display: flex; flex-direction: column; }
    .home-card-header {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        background: var(--surface-muted);
        text-decoration: none;
        color: var(--text);
        transition: background 0.15s ease, color 0.15s ease;
    }
    .home-card-header:hover { background: color-mix(in srgb, var(--brand) 8%, var(--surface-muted)); color: var(--brand-strong); }
    .home-card-header:focus-visible { outline: none; box-shadow: var(--ring); }
    .home-card-title { display: inline-flex; align-items: center; gap: 8px; font-size: 0.92rem; font-weight: 650; }
    .home-card-icon { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 6px; background: var(--brand-soft); color: var(--brand-strong); }
    .home-card-icon svg { width: 14px; height: 14px; display: block; }
    .home-card-cta { color: var(--text-soft); display: inline-grid; place-items: center; }
    .home-card-cta svg { width: 16px; height: 16px; display: block; }
    .home-card-header:hover .home-card-cta { color: var(--brand-strong); }
    .home-card .info-list { border: none; border-radius: 0; }
    .home-card .info-list-item { padding: 12px 16px; }
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
    .btn svg { width: 15px; height: 15px; flex-shrink: 0; margin-right: 4px; }
    .dashboard-btn svg { width: 16px; height: 16px; }
    .tab svg { width: 15px; height: 15px; flex-shrink: 0; margin-right: 4px; }
    .modal-close svg { width: 16px; height: 16px; margin-right: 0; }
    .status-value a svg { width: 13px; height: 13px; vertical-align: middle; margin-right: 3px; }
    .footer-link svg { width: 14px; height: 14px; flex-shrink: 0; margin-right: 0; }
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
    .file-list--in-card { border: none; border-radius: 0; background: transparent; }
    .back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 20px; color: var(--brand); text-decoration: none; font-weight: 600; font-size: 0.9rem; }
    .back-link::before { content: ''; display: inline-block; width: 16px; height: 16px; background: currentColor; -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m12 19-7-7 7-7'/%3E%3Cpath d='M19 12H5'/%3E%3C/svg%3E") no-repeat center/contain; mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m12 19-7-7 7-7'/%3E%3Cpath d='M19 12H5'/%3E%3C/svg%3E") no-repeat center/contain; }
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
    .env-row--wide { width: 100%; }
    .env-row label { font-weight: 600; color: var(--text-muted); font-size: 0.88rem; }
    .env-help { color: var(--text-muted); font-size: 0.82rem; margin-top: 4px; line-height: 1.45; }
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
    .env-input--uuid { flex: 1 1 auto; width: 100%; min-width: 0; font-family: var(--font-mono); }
    .env-input--secret { flex: 1 1 auto; width: 100%; min-width: 0; font-family: var(--font-mono); }
    .env-form--inline .btn { margin-left: auto; }
    .input-group { display: flex; align-items: stretch; }
    .input-group > .env-input { flex: 1 1 auto; min-width: 0; border-top-right-radius: 0; border-bottom-right-radius: 0; }
    .input-group > .dropdown { flex: 1 1 auto; min-width: 0; }
    .input-group > .dropdown .dropdown-toggle { border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; }
    .input-group > .input-group-append {
        display: inline-flex; align-items: center; justify-content: center;
        flex: 0 0 auto;
        padding: 0 12px;
        background: var(--brand);
        color: #fff;
        border: 1px solid var(--brand);
        border-left: none;
        border-top-left-radius: 0; border-bottom-left-radius: 0;
        border-top-right-radius: 9px; border-bottom-right-radius: 9px;
        cursor: pointer;
        font-family: inherit;
        box-shadow: var(--shadow-sm);
        transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.06s ease;
    }
    .input-group > .input-group-append:hover { background: var(--brand-strong); border-color: var(--brand-strong); box-shadow: var(--shadow-md); }
    .input-group > .input-group-append:active { transform: translateY(1px); }
    .input-group > .input-group-append:focus-visible { outline: none; box-shadow: var(--ring); }
    .input-group > .input-group-append svg { width: 15px; height: 15px; flex-shrink: 0; }
    .input-group > .input-group-append--danger { background: var(--danger); border-color: var(--danger); }
    .input-group > .input-group-append--danger:hover { background: color-mix(in srgb, var(--danger) 85%, #000); border-color: color-mix(in srgb, var(--danger) 85%, #000); }
    .input-group > .input-group-append:disabled { opacity: 0.5; cursor: not-allowed; box-shadow: none; }
    .input-group > .input-group-append:disabled:hover { background: var(--brand); border-color: var(--brand); }
    .input-group > .input-group-append--danger:disabled:hover { background: var(--danger); border-color: var(--danger); }
    .env-section-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
    .env-section-title { font-size: 1.02rem; font-weight: 650; letter-spacing: -0.01em; margin-bottom: 6px; }
    .env-divider { border: none; border-top: 1px solid var(--border); margin: 20px 0; }
    .env-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .env-select:focus, .env-input:focus { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    .env-textarea { width: 100%; min-height: 200px; padding: 12px 14px; border: 1px solid var(--border-strong); border-radius: var(--radius); font-family: var(--font-mono); font-size: 0.86rem; background: var(--surface); color: var(--text); resize: vertical; }
    .env-textarea:focus { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    pre { background: var(--surface-muted); border: 1px solid var(--border); padding: 15px; border-radius: var(--radius); font-size: 0.86rem; white-space: pre-wrap; color: var(--text-muted); }
    hr { border: none; border-top: 1px solid var(--border); }
    .lang-switcher { margin: 0; }
    .lang-form { display: flex; align-items: center; gap: 6px; }
    .lang-form label { font-size: 0.82rem; color: var(--text-muted); }
    footer { margin-top: 28px; text-align: center; }
    .footer-link { color: var(--text-soft); text-decoration: none; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 5px; }
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
    .dropdown-option.is-selected::after { content: ''; display: inline-block; width: 14px; height: 14px; flex-shrink: 0; background: var(--brand); -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20 6 9 17l-5-5'/%3E%3C/svg%3E") no-repeat center/contain; mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20 6 9 17l-5-5'/%3E%3C/svg%3E") no-repeat center/contain; }
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
        display: grid;
        place-items: center;
        cursor: pointer;
        transition: background .15s ease, color .15s ease;
    }
    .modal-close:hover { background: var(--surface-muted); color: var(--text); }
    .modal-body { padding: 14px 18px 18px; overflow-y: auto; border-bottom-left-radius: var(--radius-lg); border-bottom-right-radius: var(--radius-lg); scrollbar-gutter: stable; }
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
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 557 557"><path id="oakengine-logo-1" d="M486.57,100.94c.59.71,4.81,1.58,6.59,2.75,7.77,5.11,30.69,38.51,34.12,47.76,5.6,15.11,3.75,29,11.49,44.88,14.79,30.37,18.97,33.04,16.21,68.88-.71,9.15,1.38,15.5,1.93,23.78.99,14.96-6.66,29.7-10.1,44.27-4.55,19.28-7.6,51.8-19.77,67.22-5.14,6.52-13.02,11.56-17.57,18.91-3.38,5.47-3.76,11.43-6.62,16.1-1.94,3.16-6.04,6.64-8.48,10.26-4.96,7.34-8.93,16.5-14.38,23.12-9.47,11.5-52.58,45.56-65.97,50.25-15.43,5.4-14.22,5.81-27.34,13.98-31.38,19.55-97.88,26.3-134.16,23.01-11.18-1.01-16.96-10.71-30.33-9.93-8.13.47-10.04,2.71-19.33.68-18.13-3.97-41.24-13.2-57.66-21.98-19.56-10.47-28.18-24.67-45.93-36.82-4.18-2.86-10.19-4.62-13.75-7.9-3.69-3.4-7.32-11.23-10.91-15.73-8.59-10.76-17.25-22.04-26.91-31.27s-17.19-26.63-22.92-39.27c-5.02-11.07-14.51-31.07-15.7-42.6-.75-7.24.77-15.61-.21-22.59-.73-5.18-5.17-8.8-5.79-14.95-.73-7.17,1.96-10.19,2.68-16.12,1.16-9.45-4.99-20.41-5.66-30.43-1.23-18.4,9-65.16,16.23-82.77,3.12-7.6,9.31-12.42,12.1-19.48,1.84-4.65,1.86-9.46,3.88-13.92,3.46-7.67,11.49-13.34,13.66-22.86,4.29-18.79,10.29-26.57,24.72-39.42,5.12-4.56,12.68-8.72,17.21-13.32,7.27-7.38,7.86-13.31,18.18-20.28,13.45-9.09,26.67-5.82,37.68-16.41,3.35-3.22,4.42-7.03,7.82-9.93C171.17,12.11,239.32,1.03,265.26.04c9.45-.36,13.73,2.04,21.71,2.91,21.87,2.37,40.63.42,62.35,6.33,18.75,5.11,44.03,17.81,61.9,26.62,18.9,9.31,61.85,31.55,70.7,50.42,1.33,2.84,4.16,14.03,4.65,14.62Z"/><path id="oakengine-logo-2" d="M260.69,21.18c26.18-1.59,51.2,3.07,77.03,5.78,16.32,1.71,24.46,12.94,38.17,18.89,15.45,6.7,26.37,6.87,41.75,17.26,26.25,17.73,37.67,32.37,57.42,55.92,12.24,14.59,20.99,20.79,29.77,39.27,7.76,16.32,9.42,30.96,15.08,47.19,2.22,6.36,6.27,12.85,8.12,19.54,5.13,18.56,9.08,64.25,5.12,82.53-1.23,5.66-4.56,10.88-6.13,16.6-7.7,27.98-4.56,45.67-24.04,70.84-11.2,14.47-15.61,23.16-25.05,38.1-12.12,19.17-51.11,55.68-71.31,65.67-6.83,3.38-14.66,4.62-21.46,8.04-5.84,2.93-11.39,8.26-17.39,11.16-17.61,8.5-49.54,13.75-69.16,15.31-34.02,2.7-61.8-5.27-94.91-11.06-25.77-4.5-39.74-13.68-62.09-26.43-11.41-6.51-20.06-18.5-29.72-27.44-30.51-28.22-50.35-44.71-66.44-85.45-7.73-19.56-20.7-57.84-22.76-78.08-7.39-72.54,15.67-128.09,54.86-186.33,36.15-53.72,119.7-93.45,183.13-97.31Z"/><path id="oakengine-logo-3" d="M265.05,48.93c51.95-3.83,92.15,15.53,137.37,38.14,36.07,18.04,38.01,28.71,59.74,59.63,16.16,23,33.27,35.99,38.47,65.98,5.2,30.01,9.68,60.88,4.99,91.12-2.92,18.86-8.98,42.96-13.76,61.69-9.42,36.91-23.42,41.14-46.88,65.55-4.47,4.65-8.24,11.01-12.45,15.66-17.43,19.24-34.95,32.43-59.66,40.57-27.77,9.14-68.72,23.8-97.23,23.82-29.08.02-103.83-23.89-126.22-42.72-9.28-7.81-15.8-18.38-23.93-27.25-16.42-17.92-38.59-32.38-50.47-53.87-14.79-26.75-15.67-50.47-21.58-79-6.06-29.28-10.46-37.35-1.59-68.37,3.75-13.11,11.1-24.07,14.73-36.53,2.71-9.34,3.42-19.08,6.69-28.52,7.93-22.92,25.64-35.32,41.74-51.6,8.94-9.04,18.2-20.68,27.42-28.76,25.21-22.08,89.42-43.09,122.63-45.54Z"/><path id="oakengine-logo-4" d="M270.04,61.02c44.64-3,82.16,15.2,121.39,34.11,36.88,17.77,36.78,23.72,58.74,55.6,15.12,21.94,34.47,35.13,39.46,63.97,4.88,28.15,9.38,57.81,5,86.07-3.04,19.61-9.38,47.49-14.97,66.52-9.21,31.31-23.98,35.51-43.68,56.69-18.79,20.2-29.32,38.42-57.12,49.17-18.53,7.17-45.27,15.06-64.68,20.38-46.49,12.76-89.5-1.05-132.49-19.47-29.55-12.66-31.94-24.01-51.73-45.54-16.08-17.49-36.69-29.41-47.62-51.71-10.91-22.27-12.76-43.81-17.07-67.42-2.76-15.14-8.71-26.09-7.59-42.47,1.6-23.38,10.97-35.21,18.12-55.28,3.78-10.61,4.28-22.1,7.69-32.55,9.4-28.89,50.34-53.75,68.92-77.57,23.3-19.61,87.33-38.47,117.64-40.5Z"/><path id="oakengine-logo-5" d="M134.86,404.94c-12.93-13.16-23.13-21.12-30.86-38.89-20.78-47.83-27.02-80.7-14.21-132.21,11.72-47.15,53.77-100.71,96.56-123.25,102.46-53.98,229.59-8.52,271.38,100.04,28.31,73.55,14.04,135.77-34.73,195.22-51.41,62.68-128.43,82.88-205.45,58.24-40.64-13-54.05-29.97-82.7-59.14Z"/><path id="oakengine-logo-6" d="M266.05,101.33c-89.47,6.77-162.55,77.39-171.54,167.5-3.32,33.21,13.68,91.82,36.54,116.28,19.29,20.63,45.49,49.18,70.94,60.42,75.11,33.19,158.25,18.03,211.03-45.73,49.44-59.73,61.5-122.14,29.45-194.94-29.51-67.01-104.2-108.99-176.42-103.53Z"/><path id="oakengine-logo-7" d="M420.49,225.81c1.9,5.16.3,10.9-3.79,14.58l-25.66,23.35c.65,4.92,1.01,9.96,1.01,15.05s-.36,10.13-1.01,15.05l25.66,23.35c4.09,3.67,5.69,9.42,3.79,14.58-2.61,7.05-5.75,13.81-9.36,20.33l-2.79,4.8c-3.91,6.52-8.3,12.68-13.1,18.49-3.5,4.27-9.3,5.69-14.52,4.03l-33.01-10.49c-7.94,6.1-16.71,11.2-26.08,15.05l-7.41,33.84c-1.19,5.39-5.33,9.66-10.79,10.55-8.18,1.36-16.59,2.07-25.19,2.07s-17.01-.71-25.19-2.07c-5.45-.89-9.6-5.16-10.79-10.55l-7.41-33.84c-9.36-3.85-18.14-8.95-26.08-15.05l-32.95,10.55c-5.22,1.66-11.02.18-14.52-4.03-4.8-5.81-9.19-11.97-13.1-18.49l-2.79-4.8c-3.62-6.52-6.76-13.28-9.36-20.33-1.9-5.16-.3-10.9,3.79-14.58l25.66-23.35c-.65-4.98-1.01-10.02-1.01-15.11s.36-10.13,1.01-15.05l-25.66-23.35c-4.09-3.67-5.69-9.42-3.79-14.58,2.61-7.05,5.75-13.81,9.36-20.33l2.79-4.8c3.91-6.52,8.3-12.68,13.1-18.49,3.5-4.27,9.3-5.69,14.52-4.03l33.01,10.49c7.94-6.1,16.71-11.2,26.08-15.05l7.41-33.84c1.19-5.39,5.33-9.66,10.79-10.55,8.18-1.42,16.59-2.13,25.19-2.13s17.01.71,25.19,2.07c5.45.89,9.6,5.16,10.79,10.55l7.41,33.84c9.36,3.85,18.14,8.95,26.08,15.05l33.01-10.49c5.22-1.66,11.02-.18,14.52,4.03,4.8,5.81,9.19,11.97,13.1,18.49l2.79,4.8c3.62,6.52,6.76,13.28,9.36,20.33l-.06.06Z"/><circle id="oakengine-logo-8" cx="278.5" cy="278.5" r="47.41"/></svg>
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
    <a href="https://github.com/oakengine/installer" target="_blank" class="footer-link">{$footerIcon} github.com/oakengine/installer</a>
</footer>
{$confirmationModal}
{$dropdownScript}
{$modalScript}
</body>
</html>
HTML;
}
