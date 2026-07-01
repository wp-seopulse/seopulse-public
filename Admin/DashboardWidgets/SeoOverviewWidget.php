<?php

/**
 * Dashboard widget — SEO Overview
 *
 * Registers the WordPress dashboard widget and enqueues the React bundle.
 * All rendering is handled client-side by SeoOverviewWidget.tsx.
 * Data is served by Api/DashboardWidgetController.php via REST.
 *
 * @package SEOPulse\Admin\DashboardWidgets
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\DashboardWidgets;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Contracts\ExecuteHooksAdmin;

class SeoOverviewWidget implements ExecuteHooksAdmin
{
    private const CACHE_KEY = 'seopulse_dashboard_widget_overview';

    public function hooks(): void
    {
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_widget(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_add_dashboard_widget(
            'seopulse_seo_overview',
            __('SEOPulse — SEO Overview', 'seopulse'),
            [$this, 'render'],
        );
    }

    /**
     * Enqueues the React bundle only on the WP admin dashboard page (index.php).
     */
    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'index.php') {
            return;
        }

        if (!current_user_can('edit_posts')) {
            return;
        }

        $asset_file = SEOPULSE_PLUGIN_DIR . 'assets/build/wp-dashboard-widget.asset.php';

        if (!file_exists($asset_file)) {
            return;
        }

        $asset = require $asset_file;

        wp_enqueue_script(
            'seopulse-wp-dashboard-widget',
            SEOPULSE_ASSETS_URL . 'build/wp-dashboard-widget.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations(
            'seopulse-wp-dashboard-widget',
            'seopulse',
            SEOPULSE_PLUGIN_DIR . 'languages',
        );

        wp_enqueue_style(
            'seopulse-wp-dashboard-widget',
            SEOPULSE_ASSETS_URL . 'build/wp-dashboard-widget.css',
            [],
            $asset['version'],
        );

        wp_localize_script(
            'seopulse-wp-dashboard-widget',
            'seopulseWpDashboardWidget',
            [
                'restUrl'      => rest_url(),
                'nonce'        => wp_create_nonce('wp_rest'),
                'dashboardUrl' => admin_url('admin.php?page=seopulse'),
                'initialData'  => $this->get_initial_data(),
            ],
        );
    }

    /**
     * Renders the React mount point.
     */
    public function render(): void
    {
        echo '<div id="seopulse-wp-dashboard-widget-root"></div>';
    }

    /**
     * Returns cached widget data for the initial render (avoids a REST round-trip on load).
     * Returns null if no cached data is available — the React component will fetch it.
     *
     * @return array<string, mixed>|null
     */
    private function get_initial_data(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }
}
