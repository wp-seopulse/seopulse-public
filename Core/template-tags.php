<?php

/**
 * SEOPulse global template tags
 *
 * Functions in this file are intentionally in the GLOBAL namespace
 * so that theme developers can call them without importing.
 *
 * @package SEOPulse
 * @since 1.0.0
 */

// phpcs:disable PSR1.Files.SideEffects

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('seopulse_breadcrumbs')) {
    /**
     * Renders the SEOPulse breadcrumb trail.
     *
     * This is the **theme-facing** template tag. It lives in the **global namespace**
     * so that theme developers can call it without any `use` import or namespace prefix:
     *
     *     <?php if ( function_exists( 'seopulse_breadcrumbs' ) ) { seopulse_breadcrumbs(); } ?>
     *
     * A second declaration of this function exists in `Core/helpers.php` under the
     * `SEOPulse\` namespace (`SEOPulse\seopulse_breadcrumbs()`). That version is meant
     * for internal plugin code. The two declarations **do not conflict** because PHP
     * treats them as distinct symbols due to their different namespaces. Both delegate
     * to the same `BreadcrumbRenderer::render()` implementation and produce identical output.
     *
     * @since 1.0.0
     *
     * @param bool $echo Whether to echo (true) or return (false) the HTML.
     * @return string Breadcrumb HTML.
     */
    function seopulse_breadcrumbs(bool $echo = true): string
    {
        $renderer = new SEOPulse\Modules\MetaSeo\BreadcrumbRenderer();

        return $renderer->render($echo);
    }
}
