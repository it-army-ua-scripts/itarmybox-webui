<?php
require_once __DIR__ . '/../i18n.php';

function render_app_footer(string $extraHtml = ''): string
{
    $year = date('Y');
    $slogan = htmlspecialchars(t('footer_slogan'), ENT_QUOTES, 'UTF-8');

    return '<footer class="app-footer">'
        . $extraHtml
        . '<div>&copy; 2022-' . $year . ' IT Army of Ukraine. ' . $slogan . '.</div>'
        . '</footer>';
}
