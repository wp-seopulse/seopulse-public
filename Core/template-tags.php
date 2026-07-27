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
     * Theme-facing template tag, in the global namespace so themes
     * can call it directly without any `use` import:
     *
     *     <?php if ( function_exists( 'seopulse_breadcrumbs' ) ) { seopulse_breadcrumbs(); } ?>
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
