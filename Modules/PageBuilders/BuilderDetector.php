<?php

/**
 * Server-side detection of active page builder plugins.
 *
 * Follows the same detection pattern already used by
 * {@see \SEOPulse\Core\Abstracts\AbstractMigrator::detect()}: prefer a
 * class/constant check (works in any context) and fall back to
 * is_plugin_active() (admin-only helper) when useful.
 *
 * @package SEOPulse\Modules\PageBuilders
 * @since 1.7.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\PageBuilders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BuilderDetector — read-only helpers to know which builders are installed.
 */
final class BuilderDetector
{
    /**
     * Elementor (free or pro) is loaded.
     */
    public static function isElementorActive(): bool
    {
        return did_action('elementor/loaded') || class_exists('\Elementor\Plugin');
    }

    /**
     * Divi (Divi 4's classic module framework, or Divi 5) is loaded.
     *
     * UPDATED: added `et_builder_d5_enabled()` as a detection signal —
     * confirmed via Divi's own developer docs (dev.elegantthemes.com,
     * "Extending Divi 5 Page Settings" / "Create Custom Builder Bar
     * Button" tutorials) as the function Elegant Themes itself uses to
     * gate Divi 5 Visual Builder extensions. The original D4-era
     * signals (`\ET_Builder_Element`, `ET_BUILDER_VERSION`) are kept
     * alongside it for sites still running Divi 4, or Divi 5 sites in
     * backward-compatibility mode where they may still be defined.
     *
     * This is "is Divi installed" only — whether the Visual Builder is
     * *currently active* for the request is a separate, narrower check
     * (see DiviIntegration::enqueue_panel_assets()).
     */
    public static function isDiviActive(): bool
    {
        return class_exists('\ET_Builder_Element')
            || defined('ET_BUILDER_VERSION')
            || function_exists('et_builder_d5_enabled');
    }

    /**
     * Beaver Builder (free or pro) is loaded.
     */
    public static function isBeaverBuilderActive(): bool
    {
        return class_exists('\FLBuilder');
    }

    /**
     * Live Composer is loaded.
     */
    public static function isLiveComposerActive(): bool
    {
        return defined('DS_LIVE_COMPOSER_VER') || class_exists('\DSLC_Html');
    }

    /**
     * Kubio Builder (free or pro) is loaded.
     */
    public static function isKubioActive(): bool
    {
        return defined('KUBIO_VERSION') || apply_filters('kubio_is_enabled', false);
    }

    /**
     * Returns the detection state of all 4 builders.
     *
     * @return array<string, bool>
     */
    public static function detectAll(): array
    {
        return [
            'elementor'      => self::isElementorActive(),
            //'divi'           => self::isDiviActive(),
            //'beaver_builder' => self::isBeaverBuilderActive(),
            'live_composer'  => self::isLiveComposerActive(),
            'kubio'          => self::isKubioActive(),
        ];
    }
}
