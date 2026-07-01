<?php

/**
 * Trait for building the current request URL
 *
 * Shared by RedirectionsTracker and RedirectionsManager.
 *
 * @package SEOPulse\Core\Traits
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Traits;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CurrentUrlTrait
 *
 * Builds the full current URL from server variables.
 */
trait CurrentUrlTrait
{
    /**
     * Retrieves the current request URL
     *
     * @return string
     */
    private function get_current_url(): string
    {
        $protocol = is_ssl() ? 'https://' : 'http://';
        $host     = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''));
        $uri      = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

        return $protocol . $host . $uri;
    }
}
