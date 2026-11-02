<?php
/**
 * Headless feature switches.
 *
 * @package SEOPulse\Modules\MetaSeo\Headless
 * @since   1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Headless;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;

/**
 * Reads the headless switches of the global Meta SEO settings.
 */
final class HeadSettings
{
    /**
     * Whether the "head" REST routes are registered (default: yes).
     *
     * @return bool
     */
    public static function endpoint_enabled(): bool
    {
        /**
         * Filters whether the head REST routes are registered.
         *
         * @since 1.8.0
         * @param bool $enabled Setting value.
         */
        return (bool) apply_filters('seopulse_head_endpoint_enabled', self::flag('headless_endpoint_enabled', true));
    }

    /**
     * Whether the "seopulse_head_json" REST field is registered (default: yes).
     *
     * @return bool
     */
    public static function field_enabled(): bool
    {
        /**
         * Filters whether the "seopulse_head_json" REST field is registered.
         *
         * @since 1.8.0
         * @param bool $enabled Setting value.
         */
        return (bool) apply_filters('seopulse_head_field_enabled', self::flag('headless_field_enabled', true));
    }

    /**
     * Whether unknown URLs get the site's 404 head (HTTP 404) instead of an error (default: no).
     *
     * @return bool
     */
    public static function not_found_head(): bool
    {
        /**
         * Filters whether unknown URLs return the head of the 404 page.
         *
         * @since 1.8.0
         * @param bool $enabled Setting value.
         */
        return (bool) apply_filters('seopulse_head_not_found_head', self::flag('headless_not_found_head', false));
    }

    /**
     * Reads a boolean from the global settings.
     *
     * @param string $key Setting key.
     * @param bool $default Value when the setting was never saved.
     * @return bool
     */
    private static function flag(string $key, bool $default): bool
    {
        $settings = get_option(Options::META_SEO_GLOBAL, []);

        return is_array($settings) && array_key_exists($key, $settings) ? (bool) $settings[ $key ] : $default;
    }
}
