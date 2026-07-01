<?php

/**
 * SEO Pulse - Uninstallation cleanup
 *
 * Cleans up all plugin data when uninstalled.
 * Enhanced in 2.0 to use Constants and handle all options/post meta.
 *
 * @package SEOPulse
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Security: guard constant
if (!defined('SEOPULSE_UNINSTALL')) {
    define('SEOPULSE_UNINSTALL', true);
}

// ── Delete all options ─────────────────────────────────────────
$seopulse_options_to_delete = [
    'seopulse_settings',
    'seopulse_meta_seo_global',
    'seopulse_redirections',
    'seopulse_404_logs',
    'seopulse_sitemap_settings',
    'seopulse_local_seo_settings',
    'seopulse_version',
    'seopulse_activated_time',
    'seopulse_modules_enabled',
    'seopulse_analytics_settings',
    'seopulse_meta_templates',
    'seopulse_archive_settings',
    'seopulse_taxonomy_settings',
];

foreach ($seopulse_options_to_delete as $seopulse_option) {
    delete_option($seopulse_option);
}

// ── Delete all post meta ───────────────────────────────────────
global $wpdb;

$seopulse_meta_keys_to_delete = [
    '_seopulse_focus_keyword',
    '_seopulse_score',
    '_seopulse_last_analysis',
    '_seopulse_scores',
    '_seopulse_recommendations_count',
    '_seopulse_dismissed_recommendations',
    '_seopulse_meta_seo',
    '_seopulse_redirect_url',
    '_seopulse_redirect_type',
    '_seopulse_exclude_sitemap',
    '_seopulse_sitemap_priority',
    '_seopulse_sitemap_changefreq',
    '_seopulse_news_keywords',
    '_seopulse_stock_tickers',
];

foreach ($seopulse_meta_keys_to_delete as $seopulse_meta_key) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
    $wpdb->delete($wpdb->postmeta, ['meta_key' => $seopulse_meta_key]);
}

// ── Delete all transients ──────────────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
         OR option_name LIKE %s",
        '%_transient_seopulse_%',
        '%_transient_timeout_seopulse_%',
    ),
);

// ── Delete scheduled cron tasks ────────────────────────────────
wp_clear_scheduled_hook('seopulse_daily_cleanup');

// ── Delete custom tables (if they exist) ───────────────────────
$seopulse_table = $wpdb->prefix . 'seopulse_analysis_history';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->query("DROP TABLE IF EXISTS `{$seopulse_table}`");

// ── Delete the upload directory ────────────────────────────────
$seopulse_upload_dir   = wp_upload_dir();
$seopulse_dir = $seopulse_upload_dir['basedir'] . '/seopulse';
if (is_dir($seopulse_dir)) {
    global $wp_filesystem;
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    $wp_filesystem->delete($seopulse_dir, true);
}

// ── Support multisite ──────────────────────────────────────────
if (is_multisite()) {
    $seopulse_sites = get_sites(['fields' => 'ids']);
    foreach ($seopulse_sites as $seopulse_site_id) {
        switch_to_blog($seopulse_site_id);

        foreach ($seopulse_options_to_delete as $seopulse_option) {
            delete_option($seopulse_option);
        }

        foreach ($seopulse_meta_keys_to_delete as $seopulse_meta_key) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            $wpdb->delete($wpdb->postmeta, ['meta_key' => $seopulse_meta_key]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 OR option_name LIKE %s",
                '%_transient_seopulse_%',
                '%_transient_timeout_seopulse_%',
            ),
        );

        $seopulse_table = $wpdb->prefix . 'seopulse_analysis_history';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query("DROP TABLE IF EXISTS `{$seopulse_table}`");

        restore_current_blog();
    }
}

// ── Delete the physical robots.txt file if created ────────────
$seopulse_robots_file = ABSPATH . 'robots.txt';
if (file_exists($seopulse_robots_file)) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $seopulse_content = file_get_contents($seopulse_robots_file);
    if ($seopulse_content !== false && strpos($seopulse_content, 'SEOPulse') !== false) {
        wp_delete_file($seopulse_robots_file);
    }
}
