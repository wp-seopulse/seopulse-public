<?php

/**
 * Instant Indexing admin settings page.
 *
 * Registers the submenu page under SEOPulse and enqueues
 * the React-powered settings UI.
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
use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Contracts\ExecuteHooksAdmin;
use SEOPulse\Services\IndexingLogger;

class IndexingSettingsPage implements ExecuteHooksAdmin
{
    /**
     * Page slug
     *
     * @var string
     */
    private string $page_slug = 'seopulse-indexing';

    /**
     * Register admin hooks.
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_page'], 17);
    }

    /**
     * Register submenu page.
     *
     * @return void
     */
    public function register_page(): void
    {
        $hook = add_submenu_page(
            'seopulse',
            __('Instant Indexing', 'seopulse'),
            AdminPageContent::menuLabel('indexing', __('Instant Indexing', 'seopulse')),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page'],
        );

        if ($hook) {
            add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        }
    }

    /**
     * Enqueue React app and localized data.
     *
     * @param string $hook Current admin page hook.
     *
     * @return void
     */
    public function enqueue_assets(string $hook): void
    {
        if (!str_contains($hook, $this->page_slug)) {
            return;
        }

        $asset_file = SEOPULSE_PLUGIN_DIR . 'assets/build/indexing-settings.asset.php';
        $asset      = file_exists($asset_file)
            ? require $asset_file
            : [
                'dependencies' => ['wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-components'],
                'version'      => SEOPULSE_VERSION,
            ];

        wp_enqueue_style(
            'seopulse-indexing-settings',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-settings.min.css',
            ['wp-components'],
            $asset['version'],
        );

        wp_enqueue_style(
            'seopulse-indexing-css',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-indexing.min.css',
            ['seopulse-indexing-settings'],
            $asset['version'],
        );

        wp_enqueue_script(
            'seopulse-indexing-settings',
            SEOPULSE_PLUGIN_URL . 'assets/build/indexing-settings.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-indexing-settings', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        $settings = (array) get_option(Options::INDEXING, []);

        wp_localize_script(
            'seopulse-indexing-settings',
            'seopulseIndexing',
            [
                'restUrl'    => rest_url('seopulse/v1/indexing'),
                'nonce'      => wp_create_nonce('wp_rest'),
                'pluginUrl'  => SEOPULSE_PLUGIN_URL,
                'settings'   => [
                    'indexnow_enabled'        => !empty($settings['indexnow_enabled']),
                    'indexnow_key'            => $settings['indexnow_key'] ?? '',
                    'google_indexing_enabled' => !empty($settings['google_indexing_enabled']),
                    'google_credentials_set'  => !empty($settings['google_indexing_credentials']['client_email']),
                    'auto_submit_on_publish'  => $settings['auto_submit_on_publish'] ?? true,
                ],
                'recentLogs' => IndexingLogger::getRecent(20),
                'i18n'       => [
                    'title'       => __('Instant Indexing', 'seopulse'),
                    'description' => __('Automatically notify search engines when you publish or update content.', 'seopulse'),
                ],
            ],
        );
    }

    /**
     * Render React root container.
     *
     * @return void
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        AdminPageContent::begin('indexing', __('Instant Indexing', 'seopulse'));
        echo '<div id="seopulse-settings-root"></div>';
        AdminPageContent::end();
    }
}
