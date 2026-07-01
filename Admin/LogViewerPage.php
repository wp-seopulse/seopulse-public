<?php

/**
 * Log Viewer administration page
 *
 * Shows recent log entries with level filtering, export and clear actions.
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
use SEOPulse\Core\Logger;

use function SEOPulse\seopulse_get_service;

/**
 * LogViewerPage class
 */
class LogViewerPage implements ExecuteHooksAdmin
{
    /**
     * Page slug
     *
     * @var string
     */
    private string $page_slug = 'seopulse-logs';

    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_page'], 70);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueues admin CSS and JS for the logs page
     *
     * @param string $hook Current admin page
     * @return void
     */
    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, $this->page_slug) === false) {
            return;
        }

        $react_asset_file = SEOPULSE_PLUGIN_DIR . 'assets/build/logs-viewer.asset.php';
        $asset = require $react_asset_file;

        wp_enqueue_script(
            'seopulse-logs-viewer',
            SEOPULSE_PLUGIN_URL . 'assets/build/logs-viewer.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-logs-viewer', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        wp_enqueue_style(
            'seopulse-logs-viewer',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-settings.min.css',
            ['wp-components', 'seopulse-admin-global'],
            $asset['version'],
        );

        wp_enqueue_style(
            'seopulse-logs-viewer-css',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-logs.min.css',
            ['seopulse-logs-viewer'],
            $asset['version'],
        );

        $logger      = seopulse_get_service('Logger');
        $dailyCounts = $logger instanceof Logger ? $logger->getDailyCounts(7) : [];
        $sources     = $logger instanceof Logger ? $logger->getDistinctSources() : [];
        $retention   = $logger instanceof Logger ? $logger->getRetentionSettings() : ['max_file_size' => 5242880, 'max_files' => 5];
        $fileSize    = $logger instanceof Logger ? $logger->getFileSize() : 0;

        // Current filter
        $level_filter   = isset($_GET['level']) ? sanitize_text_field(wp_unslash($_GET['level'])) : '';
        $allowed_levels = ['', Logger::LEVEL_DEBUG, Logger::LEVEL_INFO, Logger::LEVEL_WARNING, Logger::LEVEL_ERROR];
        if (!in_array($level_filter, $allowed_levels, true)) {
            $level_filter = '';
        }

        // Initial entries for SSR-like fast paint
        $all_entries = $logger instanceof Logger ? $logger->readLastLines(500, $level_filter ?: null) : [];
        $entries     = array_values(array_reverse($all_entries));
        $paged       = array_slice($entries, 0, 50);
        $counts      = $logger instanceof Logger ? $logger->countByLevel(500) : ['error' => 0, 'warning' => 0, 'info' => 0, 'debug' => 0];

        wp_localize_script(
            'seopulse-logs-viewer',
            'seopulseLogs',
            [
                'restUrl'        => rest_url('seopulse/v1/logs'),
                'nonce'          => wp_create_nonce('wp_rest'),
                'pluginUrl'      => SEOPULSE_PLUGIN_URL,
                'dailyCounts'    => $dailyCounts,
                'sources'        => $sources,
                'settings'       => $retention,
                'initialEntries' => $paged,
                'initialTotal'   => count($entries),
                'initialCounts'  => $counts,
                'fileSize'       => $fileSize,
                'levelFilter'    => $level_filter,
                'i18n'           => [
                    'all'            => __('All', 'seopulse'),
                    'errors'         => __('Errors', 'seopulse'),
                    'warnings'       => __('Warnings', 'seopulse'),
                    'info'           => __('Info', 'seopulse'),
                    'debug'          => __('Debug', 'seopulse'),
                    'search'         => __('Search logs…', 'seopulse'),
                    'noEntries'      => __('No log entries found.', 'seopulse'),
                    'copied'         => __('Copied!', 'seopulse'),
                    /* translators: %d: number of selected log entries */
                    'deleteConfirm'  => __('Delete %d selected entries?', 'seopulse'),
                    /* translators: %d: number of deleted log entries */
                    'deleteSuccess'  => __('%d entries deleted.', 'seopulse'),
                    'refreshOn'      => __('Auto-refresh ON', 'seopulse'),
                    'refreshOff'     => __('Auto-refresh OFF', 'seopulse'),
                    'settingsSaved'  => __('Settings saved.', 'seopulse'),
                    'exportFiltered' => __('Exporting filtered logs…', 'seopulse'),
                    'page'           => __('Page', 'seopulse'),
                    'of'             => __('of', 'seopulse'),
                    /* translators: %1$d: first entry number, %2$d: last entry number, %3$d: total entries */
                    'showing'        => __('Showing %1$d–%2$d of %3$d entries', 'seopulse'),
                ],
            ],
        );

    }

    /**
     * Registers the "Logs" submenu in the SEOPulse menu
     *
     * @return void
     */
    public function register_page(): void
    {
        $menu_title = __('Logs', 'seopulse');

        // Add unseen error badge count
        $unseen = $this->get_unseen_error_count();
        if ($unseen > 0) {
            $menu_title .= ' <span class="awaiting-mod seopulse-logs-badge">' . esc_html((string) $unseen) . '</span>';
        }

        add_submenu_page(
            'seopulse',
            __('Logs', 'seopulse'),
            AdminPageContent::menuLabel('logs', __('Logs', 'seopulse')),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page'],
        );
    }

    /**
     * Handle clear and export POST actions
     *
     * @return void
     */
    public function handle_actions(): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== $this->page_slug) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // Clear action
        if (isset($_POST['seopulse_clear_logs'])) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'seopulse_clear_logs')) {
                wp_die(esc_html__('Security check failed.', 'seopulse'));
            }

            $logger = seopulse_get_service('Logger');
            if ($logger instanceof Logger) {
                $logger->clear();
            }

            wp_safe_redirect(admin_url('admin.php?page=' . $this->page_slug . '&cleared=1'));
            exit;
        }

        // Export action
        if (isset($_POST['seopulse_export_logs'])) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'seopulse_export_logs')) {
                wp_die(esc_html__('Security check failed.', 'seopulse'));
            }

            $logger = seopulse_get_service('Logger');
            if (!$logger instanceof Logger) {
                return;
            }

            $contents = $logger->getContents();

            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="seopulse-logs-' . gmdate('Y-m-d') . '.log"');
            header('Content-Length: ' . strlen($contents));
            echo esc_html($contents);
            exit;
        }
    }

    /**
     * Renders the log viewer page
     *
     * @return void
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $logger = seopulse_get_service('Logger');
        if (!$logger instanceof Logger) {
            echo '<div class="wrap"><h1>' . esc_html__('Logs', 'seopulse') . '</h1>';
            echo '<p>' . esc_html__('Logger service is not available.', 'seopulse') . '</p></div>';

            return;
        }

        // Mark errors as seen on page visit
        $this->mark_errors_seen($logger);

        AdminPageContent::begin('logs', __('Logs', 'seopulse'));
        echo '<div id="seopulse-settings-root"></div>';
        AdminPageContent::end();
    }

    /**
     * Get count of unseen error log entries since last visit
     *
     * @return int
     */
    private function get_unseen_error_count(): int
    {
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            return 0;
        }

        $last_seen = get_user_meta($user_id, 'seopulse_logs_last_seen', true);
        if (empty($last_seen)) {
            return 0;
        }

        $logger = seopulse_get_service('Logger');
        if (!$logger instanceof Logger) {
            return 0;
        }

        $entries = $logger->readLastLines(500, Logger::LEVEL_ERROR);
        $count   = 0;

        foreach ($entries as $entry) {
            if (($entry['timestamp'] ?? '') > $last_seen) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Mark errors as seen by saving current timestamp to user meta
     *
     * @param Logger $logger
     * @return void
     */
    private function mark_errors_seen(Logger $logger): void
    {
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            return;
        }

        update_user_meta($user_id, 'seopulse_logs_last_seen', gmdate('Y-m-d\TH:i:s\Z'));
    }
}
