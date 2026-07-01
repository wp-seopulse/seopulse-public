<?php

/**
 * Installation and uninstallation management
 *
 * @package SEOPulse\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core;

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Contracts\ActivationHook;
use SEOPulse\Core\Contracts\DeactivationHook;

/**
 * Installer class
 *
 * Implements ActivationHook and DeactivationHook for auto-dispatch
 * via the Kernel on plugin activation/deactivation.
 * Also keeps static methods for backward compatibility.
 */
class Installer implements ActivationHook, DeactivationHook
{
    /**
     * Activation via interface (Kernel dispatch)
     *
     * @return void
     */
    public function activate(): void
    {
        self::doActivate();
    }

    /**
     * Deactivation via interface (Kernel dispatch)
     *
     * @return void
     */
    public function deactivate(): void
    {
        self::doDeactivate();
    }

    /**
     * Actions on activation (static, backward compatibility)
     *
     * @return void
     */
    public static function doActivate(): void
    {
        // Create shared SQL tables for redirections (compatible with SEOPulse Pro)
        self::createRedirectionTables();

        // Create indexing log table
        \SEOPulse\Services\IndexingLogger::createTable();

        // Create default options
        self::create_default_options();

        // Flush rewrite rules if we add CPTs/taxonomies
        flush_rewrite_rules();

        // Register the version
        update_option(Options::VERSION, SEOPULSE_VERSION);
        update_option(Options::ACTIVATED_TIME, time());

        // Flag for auto-redirect to setup wizard on first activation
        if (!get_option(Options::SETUP_COMPLETE)) {
            set_transient('seopulse_activation_redirect', true, 30);
        }
    }

    /**
     * Run upgrade routines on plugin update (called via admin_init)
     *
     * Compares stored version with current version and runs
     * necessary migration routines.
     *
     * @return void
     */
    public static function maybe_upgrade(): void
    {
        $stored_version = get_option(Options::VERSION, '0.0.0');

        if (version_compare($stored_version, SEOPULSE_VERSION, '>=')) {
            return;
        }

        update_option(Options::VERSION, SEOPULSE_VERSION);
    }

    /**
     * Actions on deactivation (static)
     *
     * @return void
     */
    public static function doDeactivate(): void
    {
        // Flush rewrite rules
        flush_rewrite_rules();

        // Unschedule indexing health check cron
        $indexing_ts = wp_next_scheduled('seopulse_indexing_health_check');
        if ($indexing_ts) {
            wp_unschedule_event($indexing_ts, 'seopulse_indexing_health_check');
        }
    }

    /**
     * Creates default options
     *
     * @return void
     */
    private static function create_default_options(): void
    {
        $default_settings = [
            'ai_enabled'           => false,
            'ai_provider'          => 'openai',
            'ai_api_key'           => '',
            'target_score'         => 80,
            'cache_duration'       => 3600,
            'analyze_on_save'      => true,
            'show_admin_bar_score' => true,
            'content_min_words'    => 300,
            'readability_target'   => 60,
        ];

        add_option(Options::GENERAL, $default_settings);

        // Defaults Meta SEO
        add_option(Options::META_SEO_GLOBAL, []);

        // Defaults Local SEO
        add_option(Options::LOCAL_SEO, []);

        // Defaults Sitemap
        add_option(
            Options::SITEMAP,
            [
                'enable_post'              => 1,
                'enable_page'              => 1,
                'enable_category'          => 1,
                'enable_post_tag'          => 0,
                'enable_images'            => 1,
                'include_images'           => 1,
                'enable_news_sitemap'      => 0,
                'disable_wp_core_sitemaps' => 0,
                'create_physical_robots'   => 0,
                'priority_post'            => '0.5',
                'priority_page'            => '0.8',
                'changefreq_post'          => 'weekly',
                'changefreq_page'          => 'monthly',
            ],
        );

        // Defaults Redirections
        add_option(Options::REDIRECTIONS, []);
        add_option(Options::REDIRECTIONS_404, []);

        // Defaults Analytics & Cookie Consent
        add_option(
            Options::ANALYTICS,
            [
                'enabled'         => false,
                'cookie_name'     => 'seopulse_consent',
                'cookie_expiry'   => 365,
                'position'        => 'bottom',
                'theme'           => 'light',
                'gcm_v2_enabled'  => true,
                'log_consents'    => false,
                'gtm_enabled'     => false,
                'gtm_id'          => '',
                'ga4_enabled'     => false,
                'ga4_id'          => '',
            ],
        );
    }

    /**
     * Creates SQL tables for the Redirections module.
     *
     * Schema is identical to SEOPulse Pro's tables so both plugins
     * share the same data source without any conversion layer.
     * Uses dbDelta(), which is idempotent — safe to call on every activation.
     *
     * @since 1.0.0
     * @return void
     */
    private static function createRedirectionTables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Redirects table ────────────────────────────────────────────────
        $table_redirects = $wpdb->prefix . 'seopulse_redirects';
        $sql_redirects   = "CREATE TABLE {$table_redirects} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url varchar(2048) NOT NULL,
            target_url varchar(2048) NOT NULL,
            redirect_type smallint(3) NOT NULL DEFAULT 301,
            regex tinyint(1) NOT NULL DEFAULT 0,
            match_type varchar(20) NOT NULL DEFAULT 'exact',
            ignore_case tinyint(1) NOT NULL DEFAULT 1,
            maintenance_code smallint(3) DEFAULT NULL,
            group_name varchar(255) DEFAULT '',
            category varchar(255) DEFAULT '',
            description text DEFAULT NULL,
            pass_query_string tinyint(1) NOT NULL DEFAULT 1,
            hits bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            last_accessed datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_source_url (source_url(191)),
            KEY idx_status (status),
            KEY idx_group (group_name(191)),
            KEY idx_category (category(191)),
            KEY idx_match_type (match_type)
        ) {$charset_collate};";

        dbDelta($sql_redirects);

        // ── 404 logs table ─────────────────────────────────────────────────
        $table_404 = $wpdb->prefix . 'seopulse_404_logs';
        $sql_404   = "CREATE TABLE {$table_404} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            url varchar(2048) NOT NULL,
            referrer varchar(2048) DEFAULT '',
            user_agent varchar(512) DEFAULT '',
            ip_hash varchar(64) DEFAULT '',
            hits bigint(20) UNSIGNED NOT NULL DEFAULT 1,
            first_hit datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_hit datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) NOT NULL DEFAULT 'active',
            redirect_id bigint(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_url (url(191)),
            KEY idx_last_hit (last_hit),
            KEY idx_status (status),
            KEY idx_hits (hits)
        ) {$charset_collate};";

        dbDelta($sql_404);

        // Migrate any existing wp_options data into the fresh tables.
        self::migrateRedirectionsToSql();
        self::migrate404LogsToSql();
        self::migrate404OptionKey();
    }

    /**
     * One-time migration: copies seopulse_redirections option rows into SQL.
     *
     * Skipped when the table already has rows (idempotent).
     *
     * @since 1.0.0
     * @return void
     */
    private static function migrateRedirectionsToSql(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'seopulse_redirects';

        if (!isset($wpdb->seopulse_redirects)) {
            $wpdb->seopulse_redirects = $table;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->seopulse_redirects is a safe prefixed table name.
        $query = $wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->seopulse_redirects}` WHERE 1=%d", 1);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query is safely built via $wpdb->prepare().
        $existing = (int) $wpdb->get_var($query);
        if ($existing > 0) {
            return;
        }

        $redirections = get_option('seopulse_redirections', []);
        if (empty($redirections) || !is_array($redirections)) {
            return;
        }

        foreach ($redirections as $redirect) {
            $source = sanitize_text_field($redirect['source'] ?? '');
            $target = esc_url_raw($redirect['destination'] ?? '');

            if ($source === '' || $target === '') {
                continue;
            }

            $is_wildcard = strpos($source, '*') !== false;
            // Convert wildcard (*) to a regex pattern compatible with Pro's matcher.
            $sql_source = $is_wildcard
                ? str_replace('\*', '.*', preg_quote($source, '@'))
                : $source;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $table,
                [
                    'source_url'    => $sql_source,
                    'target_url'    => $target,
                    'redirect_type' => (int) ($redirect['type'] ?? 301),
                    'regex'         => $is_wildcard ? 1 : 0,
                    'group_name'    => 'free_import',
                    'hits'          => (int) ($redirect['hits'] ?? 0),
                    'last_accessed' => !empty($redirect['last_hit'])
                        ? sanitize_text_field($redirect['last_hit'])
                        : null,
                    'status'        => sanitize_text_field($redirect['status'] ?? 'active'),
                    'created_at'    => !empty($redirect['created_at'])
                        ? sanitize_text_field($redirect['created_at'])
                        : current_time('mysql'),
                ],
                ['%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s'],
            );
        }
    }

    /**
     * One-time migration: copies seopulse_404_log option rows into SQL.
     *
     * Skipped when the table already has rows (idempotent).
     *
     * @since 1.0.0
     * @return void
     */
    private static function migrate404LogsToSql(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'seopulse_404_logs';

        if (!isset($wpdb->seopulse_404_logs)) {
            $wpdb->seopulse_404_logs = $table;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->seopulse_404_logs is a safe prefixed table name.
        $query = $wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->seopulse_404_logs}` WHERE 1=%d", 1);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query is safely built via $wpdb->prepare().
        $existing = (int) $wpdb->get_var($query);
        if ($existing > 0) {
            return;
        }

        $logs = get_option('seopulse_404_log', []);
        if (empty($logs) || !is_array($logs)) {
            return;
        }

        foreach ($logs as $log) {
            $url = sanitize_text_field($log['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $first_hit = sanitize_text_field($log['timestamp'] ?? current_time('mysql'));
            $last_hit  = sanitize_text_field($log['last_seen'] ?? $first_hit);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $table,
                [
                    'url'        => $url,
                    'referrer'   => sanitize_text_field($log['referer'] ?? ''),
                    'user_agent' => sanitize_text_field($log['user_agent'] ?? ''),
                    'ip_hash'    => md5(sanitize_text_field($log['ip'] ?? '')),
                    'hits'       => (int) ($log['count'] ?? 1),
                    'first_hit'  => $first_hit,
                    'last_hit'   => $last_hit,
                    'status'     => 'active',
                ],
                ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'],
            );
        }
    }

    /**
     * One-time migration: merges old seopulse_404_log into seopulse_404_logs.
     *
     * The tracker previously wrote to the wrong key (singular).
     * After merging, the old key is removed.
     *
     * @since 1.0.0
     * @return void
     */
    private static function migrate404OptionKey(): void
    {
        $old_key = 'seopulse_404_log';
        $new_key = Options::REDIRECTIONS_404; // seopulse_404_logs

        $old_logs = get_option($old_key, []);
        if (empty($old_logs) || !is_array($old_logs)) {
            return;
        }

        $new_logs = (array) get_option($new_key, []);

        // Index existing new logs by URL for fast lookup
        $url_index = [];
        foreach ($new_logs as $i => $log) {
            if (isset($log['url'])) {
                $url_index[ $log['url'] ] = $i;
            }
        }

        // Merge old logs into new
        foreach ($old_logs as $log) {
            $url = $log['url'] ?? '';
            if ($url === '') {
                continue;
            }

            if (isset($url_index[ $url ])) {
                // Merge hit counts
                $idx                       = $url_index[ $url ];
                $new_logs[ $idx ]['count'] = ($new_logs[ $idx ]['count'] ?? 1) + ($log['count'] ?? 1);
                if (!empty($log['last_seen'])) {
                    $existing_ts = strtotime($new_logs[ $idx ]['last_seen'] ?? '2000-01-01');
                    $incoming_ts = strtotime($log['last_seen']);
                    if ($incoming_ts > $existing_ts) {
                        $new_logs[ $idx ]['last_seen'] = $log['last_seen'];
                    }
                }
            } else {
                $new_logs[]        = $log;
                $url_index[ $url ] = count($new_logs) - 1;
            }
        }

        update_option($new_key, array_values($new_logs));
        delete_option($old_key);
    }
}
