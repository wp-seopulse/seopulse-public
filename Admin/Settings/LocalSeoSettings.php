<?php

/**
 * Local SEO configuration page
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Admin\AdminPageContent;
use SEOPulse\Core\Contracts\ExecuteHooksAdmin;
use SEOPulse\Core\Module\ModuleManager;
use SEOPulse\Modules\LocalSeo\LocalSeoDefaults;
use SEOPulse\Modules\LocalSeo\LocalSeoValidator;

/**
 * LocalSeoSettings class
 */
class LocalSeoSettings implements ExecuteHooksAdmin
{
    /**
    * Page slug
    *
     * @var string
    */
    private string $page_slug = 'seopulse-local-seo';
    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_settings_page'], 13);
    }
    /**
     * Option name
     *
     * @var string
     */
    private string $option_name = 'seopulse_local_seo_settings';

    /**
     * Registers the settings page
     *
     * @return void
     */
    public function register_settings_page(): void
    {
        add_submenu_page(
            'seopulse',
            __('Local SEO Settings', 'seopulse'),
            AdminPageContent::menuLabel('local_seo', __('Local SEO', 'seopulse')),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page'],
        );

        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Enqueues scripts and styles
     */
    public function enqueue_scripts($hook): void
    {
        if ($hook !== 'seopulse_page_seopulse-local-seo') {
            return;
        }

        // When the module is disabled, skip JS — the API routes would 404.
        if (!ModuleManager::instance()->isModuleEnabled('local_seo')) {
            return;
        }

        // Allow Pro plugin to enqueue Leaflet map assets.
        do_action('seopulse_local_seo_enqueue_map_assets', $hook);

        wp_enqueue_media();

        $asset_file = SEOPULSE_PLUGIN_DIR . 'assets/build/local-seo-settings.asset.php';
        $asset      = file_exists($asset_file) ? require $asset_file : ['dependencies' => [], 'version' => SEOPULSE_VERSION];

        // Add leaflet dependency when available (Pro).
        $deps = $asset['dependencies'];
        if (wp_script_is('leaflet', 'registered') || wp_script_is('leaflet', 'enqueued')) {
            $deps[] = 'leaflet';
        }

        wp_enqueue_script(
            'seopulse-local-seo-settings',
            SEOPULSE_PLUGIN_URL . 'assets/build/local-seo-settings.js',
            $deps,
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-local-seo-settings', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        wp_enqueue_style(
            'seopulse-local-seo-settings',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-settings.min.css',
            ['wp-components', 'seopulse-admin-global'],
            $asset['version'],
        );

        wp_enqueue_style(
            'seopulse-local-seo-css',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-localseo.min.css',
            ['seopulse-local-seo-settings'],
            $asset['version'],
        );

        // Locale / 12h logic
        $locale       = get_locale();
        $country_code = '';
        if (is_string($locale) && strpos($locale, '_') !== false) {
            $parts        = explode('_', $locale);
            $country_code = strtoupper(end($parts));
        }

        $am_pm_countries = apply_filters(
            'seopulse_local_seo_am_pm_countries',
            ['US', 'CA', 'AU', 'NZ', 'PH', 'IN', 'PK', 'BD', 'LK', 'MY', 'SG', 'HK', 'EG', 'SA', 'AE'],
        );
        $use12_hour = !empty($country_code)
            ? in_array($country_code, $am_pm_countries, true)
            : (bool) preg_match('/a|A/', (string) get_option('time_format'));

        wp_localize_script(
            'seopulse-local-seo-settings',
            'seopulseLocalSeo',
            [
                'restUrl'      => rest_url('seopulse/v1/local-seo'),
                'restNonce'    => wp_create_nonce('wp_rest'),
                'pluginUrl'    => SEOPULSE_PLUGIN_URL,
                'daysOfWeek'   => LocalSeoDefaults::get_days_of_week(),
                'use12Hour'    => $use12_hour,
                'allowedTypes' => LocalSeoDefaults::get_allowed_types(),
                'i18n'         => [
                    'settingsSaved' => __('Settings saved.', 'seopulse'),
                ],
            ],
        );

        $settings = get_option($this->option_name, LocalSeoDefaults::get_default_settings());
        wp_localize_script(
            'seopulse-local-seo-settings',
            'seopulseLocalSeoData',
            is_array($settings) ? $settings : [],
        );
    }

    /**
     * Registers WordPress settings
     *
     * @return void
     */
    public function register_settings(): void
    {
        register_setting(
            'seopulse_local_seo_group',
            $this->option_name,
            [
                'sanitize_callback' => [LocalSeoValidator::class, 'sanitize_settings'],
            ],
        );
    }

    /**
     * Renders the settings page
     *
     * @return void
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        AdminPageContent::begin('local_seo', __('Local SEO & Local Business', 'seopulse'));
        if (ModuleManager::instance()->isModuleEnabled('local_seo')) {
            echo '<div id="seopulse-settings-root"></div>';
        }
        AdminPageContent::end();
    }
}
