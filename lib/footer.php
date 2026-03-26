<?php
require_once __DIR__ . '/../i18n.php';
require_once __DIR__ . '/version.php';

function render_back_link(string $fallbackPath, string $className = ''): string
{
    $href = htmlspecialchars(url_with_lang($fallbackPath), ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8');
    $classAttr = $className !== ''
        ? ' class="' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '"'
        : '';

    return '<a href="' . $href . '"' . $classAttr . ' onclick="return appGoBack(this);">' . $label . '</a>';
}

function build_page_url(string $path, array $params = []): string
{
    $url = $path;
    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }
    return url_with_lang($url);
}

function render_app_footer(string $extraHtml = ''): string
{
    $year = date('Y');
    $slogan = htmlspecialchars(t('footer_slogan'), ENT_QUOTES, 'UTF-8');
    $branchLabel = htmlspecialchars(t('footer_branch'), ENT_QUOTES, 'UTF-8');
    $branch = htmlspecialchars(webui_selected_branch(), ENT_QUOTES, 'UTF-8');
    $backScript = <<<HTML
<script>
function appGoBack(link) {
    try {
        if (document.referrer) {
            const referrer = new URL(document.referrer, window.location.href);
            if (referrer.origin === window.location.origin && window.history.length > 1) {
                window.history.back();
                return false;
            }
        }
    } catch (e) {
    }
    return true;
}
</script>
HTML;

    return '<footer class="app-footer">'
        . $extraHtml
        . '<div>&copy; 2022-' . $year . ' IT Army of Ukraine. ' . $slogan . '.</div>'
        . '<div class="footer-version">' . $branchLabel . ': ' . $branch . '</div>'
        . '</footer>'
        . $backScript;
}
