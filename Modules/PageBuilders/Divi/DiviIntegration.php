<?php

/**
 * Divi adapter for the Page Builders Integration module.
 *
 * Ties SEOPulse's existing Meta SEO REST endpoints into Divi's
 * Visual Builder.
 *
 * REWRITTEN to use Divi 5's native extension API instead of DOM
 * sniffing + the legacy `et_fb_enqueue_assets` hook:
 *
 * - Assets are now registered on `divi_visual_builder_assets_before_enqueue_scripts`,
 *   the Divi 5-native replacement for `et_fb_enqueue_assets`.
 *   `et_fb_enqueue_assets` only fires for requests still running Divi's
 *   D4 shortcode/backward-compatibility framework, which is why it was
 *   never firing (`did_action` = 0) on a page built natively in Divi 5.
 *   See https://dev.elegantthemes.com/docs/hooks/introductions
 *
 * - `PackageBuildManager::register_package_build()` replaces the raw
 *   `wp_enqueue_script()` call, with `enqueue_app_window: true` so the
 *   script loads inside the Visual Builder's own app window (the
 *   iframe hosting the canvas) instead of the parent document. This is
 *   what makes the JS-side detection/MutationObserver-across-an-iframe
 *   problem disappear: the script now runs natively where Divi's own
 *   UI runs, instead of trying to reach into it from outside.
 *   See https://dev.elegantthemes.com/docs/tutorials/general/advanced/create-custom-builder-bar-button
 *
 * - The "is the Visual Builder actually active" check is now the same
 *   server-side condition Elegant Themes uses internally to decide
 *   whether to enqueue their own Visual Builder assets at all:
 *   `et_core_is_fb_enabled() && et_builder_d5_enabled()`. Because this
 *   now gates the *enqueue* itself, the old client-side
 *   `useDiviBuilderReady` DOM-detection hook (et_fb param / #et-fb-app
 *   node / et_fb body class) is no longer needed and has been removed
 *   from the JS bundle — there is no "wrong context" left for the
 *   script to load into.
 *
 * @package SEOPulse\Modules\PageBuilders\Divi
 * @since 1.7.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\PageBuilders\Divi;

if (!defined('ABSPATH')) {
    exit;
}

use ET\Builder\VisualBuilder\Assets\PackageBuildManager;
use SEOPulse\Modules\PageBuilders\BuilderDetector;
use SEOPulse\Modules\PageBuilders\PageBuildersModule;

class DiviIntegration
{
    public const SCRIPT_HANDLE = 'seopulse-page-builder-divi';
    public const STYLE_HANDLE  = 'seopulse-page-builder-divi';

    public function hooks(): void
    {
        // Hook for Divi 5
        add_action('divi_visual_builder_assets_before_enqueue_scripts', [$this, 'enqueue_d5_assets']);
        // Hook for Divi 4
        add_action('et_fb_enqueue_assets', [$this, 'enqueue_d4_assets']);
    }

    /**
     * Exclusive loading for Divi 5 (New UI)
    */
    public function enqueue_d5_assets(): void
    {
        if (
            !function_exists('et_core_is_fb_enabled') || !et_core_is_fb_enabled()
            || !function_exists('et_builder_d5_enabled') || !et_builder_d5_enabled()
        ) {
            return;
        }

        if (!BuilderDetector::isDiviActive() || !PageBuildersModule::isBuilderEnabled('divi')) {
            return;
        }

        if (!class_exists(PackageBuildManager::class)) {
            return;
        }

        $asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-divi.asset.php';
        if (!file_exists($asset_path)) {
            return;
        }
        $asset = require $asset_path;
        /*
                wp_enqueue_script('wp-element');
                wp_enqueue_script('wp-i18n');
                wp_enqueue_script('wp-components');
                wp_enqueue_style('wp-components');
        */
        PackageBuildManager::register_package_build([
            'name'    => self::SCRIPT_HANDLE,
            'version' => $asset['version'],
            'script'  => [
                'src'                => SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-divi.js',
                'deps'               => array_merge($asset['dependencies'], ['divi-module-library', 'divi-vendor-wp-hooks', 'divi-data']),
                'enqueue_top_window' => false,
                'enqueue_app_window' => true,
                'args'               => ['in_footer' => true],
            ],
        ]);

        if (!wp_style_is('seopulse-admin-global', 'enqueued')) {
            PackageBuildManager::register_package_build([
                'name'  => 'seopulse-admin-global',
                'version' => SEOPULSE_VERSION,
                'style' => [
                    'src'                => SEOPULSE_PLUGIN_URL . 'assets/css/seopulse.min.css',
                    'deps'               => [],
                    'enqueue_top_window' => false,
                    'enqueue_app_window' => true,
                ],
            ]);
        }

        if (file_exists(SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-divi.css')) {
            PackageBuildManager::register_package_build([
                'name'    => self::STYLE_HANDLE,
                'version' => $asset['version'],
                'style'   => [
                    'src'                => SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-divi.css',
                    'deps'               => ['wp-components', 'seopulse-admin-global'],
                    'enqueue_top_window' => false,
                    'enqueue_app_window' => true,
                ],
            ]);
        }

        wp_set_script_translations(self::SCRIPT_HANDLE, 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        $this->localize_script(self::SCRIPT_HANDLE);
    }

    /**
     * Loading for Divi 4 (Floating button)
     */
    public function enqueue_d4_assets(): void
    {
        // Stop if Divi 5 is active (to avoid double loading)
        if (function_exists('et_builder_d5_enabled') && et_builder_d5_enabled()) {
            return;
        }
        if (!BuilderDetector::isDiviActive() || !PageBuildersModule::isBuilderEnabled('divi')) {
            return;
        }

        // Load a JS file specific to Divi 4
        $asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-divi4.asset.php';

        if (!file_exists($asset_path)) {
            return;
        }

        $asset = require $asset_path;

        // Enqueue the JS bundle for the active page builder
        wp_enqueue_script(
            self::SCRIPT_HANDLE . '-d4',
            SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-divi4.js',
            array_merge($asset['dependencies'], ['jquery']),
            $asset['version'],
            false,
        );

        if (!wp_style_is('seopulse-admin-global', 'enqueued')) {
            wp_enqueue_style(
                'seopulse-admin-global',
                SEOPULSE_PLUGIN_URL . 'assets/css/seopulse.min.css',
                [],
                SEOPULSE_VERSION,
            );
        }

        if (file_exists(SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-divi4.css')) {
            wp_enqueue_style(
                self::STYLE_HANDLE . '-d4',
                SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-divi4.css',
                ['wp-components', 'seopulse-admin-global'],
                $asset['version'],
            );
        }

        $this->localize_script(self::SCRIPT_HANDLE . '-d4');
    }

    private function localize_script(string $handle): void
    {
        $post_id = get_the_ID() ?: get_queried_object_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only lookup of the current post ID for script localization; no data is processed or persisted, so a nonce is not applicable here.
        if (!$post_id && isset($_GET['post'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only context as above.
            $post_id = (int) sanitize_text_field(wp_unslash($_GET['post']));
        }

        wp_localize_script(
            $handle,
            'seopulseAdmin',
            [
                'restUrl' => rest_url('seopulse/v1'),
                'nonce'   => wp_create_nonce('wp_rest'),
                'postId'  => $post_id,
            ],
        );
    }
}
