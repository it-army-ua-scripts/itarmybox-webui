<?php
require_once __DIR__ . '/../i18n.php';
require_once __DIR__ . '/version.php';

function render_app_footer(string $extraHtml = ''): string
{
    $year = date('Y');
    $slogan = htmlspecialchars(t('footer_slogan'), ENT_QUOTES, 'UTF-8');
    $branchLabel = htmlspecialchars(t('footer_branch'), ENT_QUOTES, 'UTF-8');
    $branch = htmlspecialchars(webui_selected_branch(), ENT_QUOTES, 'UTF-8');

    return '<footer class="app-footer">'
        . $extraHtml
        . '<div>&copy; 2022-' . $year . ' IT Army of Ukraine. ' . $slogan . '.</div>'
        . '<div class="footer-version">' . $branchLabel . ': ' . $branch . '</div>'
        . '</footer>';
}
