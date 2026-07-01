<?php

/**
 * Sitemap configuration page
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

/**
 * SitemapSettings class
 *
 * Manages the Sitemap settings administration page
 */
class SitemapSettings implements ExecuteHooksAdmin
{
    /**
     * Page slug
     *
     * @var string
     */
    private string $page_slug = 'seopulse-sitemap';
    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_settings_page'], 16);
    }
    /**
     * Registers the settings page
     *
     * @return void
     */
    public function register_settings_page(): void
    {
        add_submenu_page(
            'seopulse',
            __('Sitemap & Robots Settings', 'seopulse'),
            AdminPageContent::menuLabel('sitemap', __('Sitemap & Robots', 'seopulse')),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page'],
        );

        if (is_admin()) {
            add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        }
    }

    /**
     * Enqueues admin assets
     *
     * @param string $hook Current page
     * @return void
     */
    public function enqueue_scripts(string $hook): void
    {
        // Check that we're on the correct page
        if (strpos($hook, $this->page_slug) === false) {
            return;
        }

        // When the module is disabled, skip JS/localization — the API
        // routes they call are gated and would 404.
        if (!ModuleManager::instance()->isModuleEnabled('sitemap')) {
            return;
        }

        $react_asset_file = SEOPULSE_PLUGIN_DIR . 'assets/build/sitemap-settings.asset.php';
        $asset = require $react_asset_file;

        wp_enqueue_script(
            'seopulse-sitemap-settings',
            SEOPULSE_PLUGIN_URL . 'assets/build/sitemap-settings.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-sitemap-settings', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        wp_enqueue_style(
            'seopulse-sitemap-settings',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-settings.min.css',
            ['wp-components'],
            $asset['version'],
        );

        wp_enqueue_style(
            'seopulse-sitemap-css',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-sitemap.min.css',
            ['seopulse-sitemap-settings'],
            $asset['version'],
        );

        // Prepare post types data
        $post_types = [];
        $custom_post_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        foreach ($custom_post_types as $cpt) {
            $post_types[] = [
                'name'  => $cpt->name,
                'label' => $cpt->label,
            ];
        }

        // Prepare settings & stats — merge stored values with defaults
        // so the React state always has every expected key.
        $defaults = [
            'enable_post'            => '1',
            'priority_post'          => '0.6',
            'changefreq_post'        => 'weekly',
            'enable_page'            => '1',
            'priority_page'          => '0.8',
            'changefreq_page'        => 'monthly',
            'enable_category'        => '1',
            'enable_post_tag'        => '1',
            'enable_images'          => '1',
            'include_images'         => '1',
            'enable_news_sitemap'    => '0',
            'news_publication_name'  => '',
            'news_sitemap_days'      => '2',
            'disable_wp_core_sitemaps' => '0',
            'create_physical_robots' => '0',
            'custom_robots'          => '',
        ];

        // Add dynamic CPT defaults
        foreach ($post_types as $cpt_info) {
            $cpt_name = $cpt_info['name'];
            $defaults["enable_{$cpt_name}"]     = '1';
            $defaults["priority_{$cpt_name}"]   = '0.6';
            $defaults["changefreq_{$cpt_name}"] = 'weekly';
        }

        // Merge then cast every value to string so React always
        // compares with === '1' / === '0' regardless of what the
        // DB stored (int 1 vs string '1').
        $options = array_map('strval', array_merge($defaults, get_option('seopulse_sitemap_settings', [])));
        $stats   = [
            'total_urls' => 0,
            'posts'      => 0,
            'pages'      => 0,
            'images'     => 0,
        ];

        $module = \SEOPulse\seopulse()->get_module('sitemap');
        if ($module && method_exists($module, 'get_generator')) {
            $generator = $module->get_generator();
            if (method_exists($generator, 'get_sitemap_stats')) {
                $stats = array_merge($stats, $generator->get_sitemap_stats());
            }
        }

        $use_custom = !empty($options['disable_wp_core_sitemaps']) && $options['disable_wp_core_sitemaps'] === '1';

        wp_localize_script(
            'seopulse-sitemap-settings',
            'seopulseSitemap',
            [
                'restUrl'   => rest_url('seopulse/v1/sitemap'),
                'nonce'     => wp_create_nonce('wp_rest'),
                'pluginUrl' => SEOPULSE_PLUGIN_URL,
                'siteUrl'   => home_url(),
                'nativeUrl' => home_url('/wp-sitemap.xml'),
                'customUrl' => home_url('/sitemap.xml'),
                'settings'  => (object) $options,
                'stats'     => (object) $stats,
                'postTypes' => $post_types,
                'i18n'      => [
                    'pageTitle'           => __('Sitemaps & Robots', 'seopulse'),
                    'saved'               => __('Settings saved.', 'seopulse'),
                    'error'               => __('An error occurred. Please try again.', 'seopulse'),
                    'confirmClearCache'   => __('Are you sure you want to clear the sitemap cache?', 'seopulse'),
                    'cacheClearedSuccess' => __('Cache cleared successfully.', 'seopulse'),
                ],
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

        AdminPageContent::begin('sitemap', __('Sitemaps & Robots', 'seopulse'));
        if (ModuleManager::instance()->isModuleEnabled('sitemap')) {
            echo '<div id="seopulse-settings-root"></div>';
        }
        AdminPageContent::end();
    }
}
