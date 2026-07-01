<?php

/**
 * 404 Monitor – Admin Page
 *
 * Registers the admin submenu page and enqueues the React SPA assets
 * with a rich `seopulse404Monitor` config object for the frontend.
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Contracts\ExecuteHooksAdmin;
use SEOPulse\Core\Module\ModuleManager;

class Monitor404Page implements ExecuteHooksAdmin
{
    private string $page_slug = 'seopulse-404-monitor';

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'registerPage'], 15);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerPage(): void
    {
        add_submenu_page(
            'seopulse',
            __('404 Monitor', 'seopulse'),
            AdminPageContent::menuLabel('monitor_404', __('404 Monitor', 'seopulse')),
            'manage_options',
            $this->page_slug,
            [$this, 'render_page'],
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'seopulse_page_' . $this->page_slug) {
            return;
        }

        // CSS always loaded for disabled overlay styling
        wp_enqueue_style(
            'seopulse-404-monitor',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-settings.min.css',
            ['wp-components', 'seopulse-admin-global'],
            SEOPULSE_VERSION,
        );

        // 404 Monitor-specific styles (decoupled from the settings shell since 1.3.0)
        wp_enqueue_style(
            'seopulse-404-monitor-css',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-monitor.min.css',
            ['seopulse-404-monitor'],
            SEOPULSE_VERSION,
        );

        // Skip JS when module is disabled — API routes are gated
        if (!ModuleManager::instance()->isModuleEnabled('monitor_404')) {
            return;
        }

        wp_enqueue_script(
            'seopulse-404-monitor',
            SEOPULSE_ASSETS_URL . 'build/404-monitor.js',
            ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-hooks'],
            SEOPULSE_VERSION,
            true,
        );

        wp_set_script_translations('seopulse-404-monitor', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        // Load saved settings for React
        $settings = get_option('seopulse_404_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        wp_localize_script(
            'seopulse-404-monitor',
            'seopulse404Monitor',
            [
                'restUrl'    => rest_url('seopulse/v1/'),
                'nonce'      => wp_create_nonce('wp_rest'),
                'pluginUrl'  => SEOPULSE_ASSETS_URL,
                'siteUrl'    => get_bloginfo('url'),
                'homeUrl'    => home_url('/'),
                'settings'   => $settings,
                'i18n'       => [
                    // General
                    'title'                               => __('404 Monitor', 'seopulse'),
                    'loading'                             => __('Loading…', 'seopulse'),
                    'save'                                => __('Save Settings', 'seopulse'),
                    'saved'                               => __('Settings saved.', 'seopulse'),
                    'error'                               => __('An error occurred. Please try again.', 'seopulse'),
                    'confirm_delete'                      => __('Are you sure? This cannot be undone.', 'seopulse'),
                    'no_results'                          => __('No 404 errors detected yet.', 'seopulse'),
                    'actions'                             => __('Actions', 'seopulse'),
                    // Tabs
                    'tab_dashboard'                       => __('Dashboard', 'seopulse'),
                    'tab_logs'                            => __('Logs', 'seopulse'),
                    'tab_redirections'                    => __('Redirections', 'seopulse'),
                    'tab_settings'                        => __('Settings', 'seopulse'),
                    'tab_reports'                         => __('Reports', 'seopulse'),
                    // Dashboard
                    'total_hits'                          => __('Total Hits', 'seopulse'),
                    'unique_urls'                         => __('Unique URLs', 'seopulse'),
                    'active'                              => __('Active', 'seopulse'),
                    'redirected'                          => __('Redirected', 'seopulse'),
                    'ignored'                             => __('Ignored', 'seopulse'),
                    'bot_hits'                            => __('Bot Hits', 'seopulse'),
                    'top_urls'                            => __('Top 404 URLs', 'seopulse'),
                    'top_referrers'                       => __('Top Referrers', 'seopulse'),
                    'chart_title'                         => __('Daily 404 Errors', 'seopulse'),
                    // Logs table
                    'url'                                 => __('URL', 'seopulse'),
                    'hits'                                => __('Hits', 'seopulse'),
                    'first_hit'                           => __('First Hit', 'seopulse'),
                    'last_hit'                            => __('Last Hit', 'seopulse'),
                    'referrer'                            => __('Main Referrer', 'seopulse'),
                    'status'                              => __('Status', 'seopulse'),
                    'is_bot'                              => __('Bot', 'seopulse'),
                    'suggestion'                          => __('Suggested Redirect', 'seopulse'),
                    'create_redirect'                     => __('Create Redirect', 'seopulse'),
                    'get_suggestion'                      => __('Get Suggestion', 'seopulse'),
                    'ignore'                              => __('Ignore', 'seopulse'),
                    'delete'                              => __('Delete', 'seopulse'),
                    'filter_all'                          => __('All', 'seopulse'),
                    'filter_active'                       => __('Active', 'seopulse'),
                    'filter_redirected'                   => __('Redirected', 'seopulse'),
                    'filter_ignored'                      => __('Ignored', 'seopulse'),
                    'filter_bots'                         => __('Bots', 'seopulse'),
                    'filter_humans'                       => __('Humans', 'seopulse'),
                    'search_placeholder'                  => __('Search URLs or referrers…', 'seopulse'),
                    'bulk_select_all'                     => __('Select all', 'seopulse'),
                    'bulk_action'                         => __('Bulk action', 'seopulse'),
                    'bulk_delete'                         => __('Delete selected', 'seopulse'),
                    'bulk_ignore'                         => __('Ignore selected', 'seopulse'),
                    'bulk_redirect'                       => __('Redirect selected', 'seopulse'),
                    'apply'                               => __('Apply', 'seopulse'),
                    'clear_all_logs'                      => __('Clear all logs', 'seopulse'),
                    'export_csv'                          => __('Export CSV', 'seopulse'),
                    'export_json'                         => __('Export JSON', 'seopulse'),
                    // Redirect modal
                    'modal_title'                         => __('Create Redirect', 'seopulse'),
                    'source_url'                          => __('Source URL', 'seopulse'),
                    'target_url'                          => __('Destination URL', 'seopulse'),
                    'redirect_type'                       => __('Redirect Type', 'seopulse'),
                    'cancel'                              => __('Cancel', 'seopulse'),
                    'confirm'                             => __('Create', 'seopulse'),
                    'redirect_301'                        => __('301 - Permanent', 'seopulse'),
                    'redirect_302'                        => __('302 - Temporary', 'seopulse'),
                    'redirect_307'                        => __('307 - Temporary (method preserved)', 'seopulse'),
                    'redirect_410'                        => __('410 - Gone', 'seopulse'),
                    // Settings
                    'settings_general'                    => __('General', 'seopulse'),
                    'settings_privacy'                    => __('Privacy & IP', 'seopulse'),
                    'settings_cleanup'                    => __('Cleanup', 'seopulse'),
                    'settings_email'                      => __('Email Reports', 'seopulse'),
                    'settings_advanced'                   => __('Advanced', 'seopulse'),
                    'enable_tracking'                     => __('Enable 404 tracking', 'seopulse'),
                    'track_bots'                          => __('Track SEO bot traffic', 'seopulse'),
                    'track_logged_in'                     => __('Track logged-in users', 'seopulse'),
                    'ignore_static'                       => __('Ignore static assets (images, JS, CSS)', 'seopulse'),
                    'ip_mode'                             => __('IP Address mode', 'seopulse'),
                    'ip_disabled'                         => __('Disabled (no IP stored)', 'seopulse'),
                    'ip_anonymised'                       => __('Anonymised (GDPR)', 'seopulse'),
                    'ip_hashed'                           => __('Hashed (default)', 'seopulse'),
                    'ip_full'                             => __('Full IP (not recommended)', 'seopulse'),
                    'retention_days'                      => __('Log retention (days)', 'seopulse'),
                    'retention_never'                     => __('Never delete', 'seopulse'),
                    'global_redirect'                     => __('Global 404 redirect', 'seopulse'),
                    'global_redirect_url'                 => __('Redirect destination URL', 'seopulse'),
                    'global_redirect_url_logged_in_label' => __('Separate URL for logged-in users', 'seopulse'),
                    'global_redirect_url_logged_in'       => __('Redirect URL for logged-in users', 'seopulse'),
                    'chart_period'                        => __('Period', 'seopulse'),
                    'chart_7d'                            => __('7 days', 'seopulse'),
                    'chart_14d'                           => __('14 days', 'seopulse'),
                    'chart_30d'                           => __('30 days', 'seopulse'),
                    'chart_90d'                           => __('90 days', 'seopulse'),
                    'disable_canonical'                   => __('Disable WordPress redirect_canonical', 'seopulse'),
                    'email_report'                        => __('Enable weekly email report', 'seopulse'),
                    'email_recipient'                     => __('Report recipient email', 'seopulse'),
                    'send_test_email'                     => __('Send test report now', 'seopulse'),
                    'test_email_sent'                     => __('Test email sent!', 'seopulse'),
                    'auto_suggest'                        => __('Auto-suggest redirects', 'seopulse'),
                    // Notices
                    'deleted'                             => __('Entry deleted.', 'seopulse'),
                    'ignored_success'                     => __('Entry ignored.', 'seopulse'),
                    'done'                                => __('Done', 'seopulse'),
                    'all_logs_cleared'                    => __('All logs cleared.', 'seopulse'),
                    'redirect_created'                    => __('Redirect created successfully.', 'seopulse'),
                    'target_url_required'                 => __('Target URL is required.', 'seopulse'),
                    // Filters & categories
                    'filter_all_categories'               => __('All categories', 'seopulse'),
                    'cat_broken_internal'                 => __('Broken Internal', 'seopulse'),
                    'cat_external_broken'                 => __('External Broken', 'seopulse'),
                    'cat_removed_content'                 => __('Removed Content', 'seopulse'),
                    'cat_crawler_artifact'                => __('Crawler Artifact', 'seopulse'),
                    'cat_spam'                            => __('Spam', 'seopulse'),
                    // Extra labels
                    'direct'                              => __('Direct', 'seopulse'),
                    'unknown'                             => __('Unknown', 'seopulse'),
                    // Table columns
                    'severity'                            => __('Severity', 'seopulse'),
                    'category'                            => __('Category', 'seopulse'),
                    // Bulk & selection
                    'item'                                => __('item', 'seopulse'),
                    'items'                               => __('items', 'seopulse'),
                    'selected'                            => __('selected', 'seopulse'),
                    // Pagination
                    'prev'                                => __('Previous', 'seopulse'),
                    'next'                                => __('Next', 'seopulse'),
                    'page'                                => __('Page', 'seopulse'),
                    'entries'                             => __('entries', 'seopulse'),
                    // Retention options
                    'retention_7d'                        => __('7 days', 'seopulse'),
                    'retention_30d'                       => __('30 days', 'seopulse'),
                    'retention_60d'                       => __('60 days', 'seopulse'),
                    'retention_90d'                       => __('90 days', 'seopulse'),
                    // Privacy
                    'privacy_hint'                        => __('Choose how IP addresses are stored. "Hashed" is a good balance between privacy and analytics.', 'seopulse'),
                    // Advanced
                    'disable_notif'                       => __('Disable slug change notifications', 'seopulse'),
                    // Reports
                    'report_preview'                      => __('Email Report Preview', 'seopulse'),
                    'report_desc'                         => __('This is a preview of the weekly 404 summary your site generates.', 'seopulse'),
                    'new_errors_week'                     => __('New errors this week', 'seopulse'),
                ],
            ],
        );
    }

    public function render_page(): void
    {
        AdminPageContent::begin('monitor_404', __('404 Monitor', 'seopulse'));

        if (ModuleManager::instance()->isModuleEnabled('monitor_404')) {
            echo '<div id="seopulse-settings-root"></div>';
        }

        AdminPageContent::end();
    }
}
