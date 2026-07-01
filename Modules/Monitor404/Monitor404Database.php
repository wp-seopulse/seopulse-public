<?php

/**
 * 404 Monitor – Database migration
 *
 * Extends the existing seopulse_404_logs table with Monitor-specific columns
 * and creates the seopulse_404_stats daily aggregation table.
 *
 * Safe to call multiple times (dbDelta + column existence checks).
 *
 * @package SEOPulse\Modules\Monitor404
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Monitor404;

if (!defined('ABSPATH')) {
    exit;
}

class Monitor404Database
{
    private const DB_VERSION_OPTION = 'seopulse_404_monitor_db_version';
    private const DB_VERSION        = '1.1.0';

    /**
     * Runs all migrations. Called on module boot and on version bump.
     */
    public static function migrate(): void
    {
        $current = get_option(self::DB_VERSION_OPTION, '0.0.0');

        if (version_compare($current, self::DB_VERSION, '>=')) {
            return;
        }

        self::extendLogsTable();
        self::createStatsTable();

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Adds Monitor-specific columns to the existing seopulse_404_logs table.
     */
    private static function extendLogsTable(): void
    {
        global $wpdb;

        $table   = $wpdb->prefix . 'seopulse_404_logs';
        $columns = self::getExistingColumns($table);

        // Table may not exist yet if Installer hasn't run
        if (empty($columns)) {
            return;
        }

        $alterations = [];

        if (!in_array('is_bot', $columns, true)) {
            $alterations[] = 'ADD COLUMN is_bot tinyint(1) NOT NULL DEFAULT 0';
        }
        if (!in_array('bot_name', $columns, true)) {
            $alterations[] = 'ADD COLUMN bot_name varchar(50) DEFAULT NULL';
        }
        if (!in_array('user_logged_in', $columns, true)) {
            $alterations[] = 'ADD COLUMN user_logged_in tinyint(1) NOT NULL DEFAULT 0';
        }
        if (!in_array('ignored_reason', $columns, true)) {
            $alterations[] = 'ADD COLUMN ignored_reason varchar(255) DEFAULT NULL';
        }
        if (!in_array('suggestion', $columns, true)) {
            $alterations[] = 'ADD COLUMN suggestion varchar(2048) DEFAULT NULL';
        }
        if (!in_array('suggestion_score', $columns, true)) {
            $alterations[] = 'ADD COLUMN suggestion_score tinyint(3) UNSIGNED DEFAULT NULL';
        }
        if (!in_array('ip_mode', $columns, true)) {
            $alterations[] = 'ADD COLUMN ip_mode varchar(20) NOT NULL DEFAULT \'hashed\'';
        }

        if (empty($alterations)) {
            return;
        }

        foreach ($alterations as $alteration) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$table}` {$alteration}");
        }

        // Add composite index for bot filtering if not present
        $indexes = self::getExistingIndexes($table);
        if (!in_array('idx_is_bot', $indexes, true)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query("ALTER TABLE `{$table}` ADD KEY idx_is_bot (is_bot)");
        }
    }

    /**
     * Creates the daily stats aggregation table.
     */
    private static function createStatsTable(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'seopulse_404_stats';

        $sql = "CREATE TABLE {$table} (
            id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stat_date   date NOT NULL,
            total_hits  bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            unique_urls int(10) UNSIGNED NOT NULL DEFAULT 0,
            bot_hits    bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            human_hits  bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            top_url     varchar(2048) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_stat_date (stat_date)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Returns existing column names for a table.
     *
     * @param string $table Full table name.
     * @return string[]
     */
    private static function getExistingColumns(string $table): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`");

        if (!$results) {
            return [];
        }

        return array_column($results, 'Field');
    }

    /**
     * Returns existing index names for a table.
     *
     * @param string $table Full table name.
     * @return string[]
     */
    private static function getExistingIndexes(string $table): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $wpdb->get_results("SHOW INDEX FROM `{$table}`");

        if (!$results) {
            return [];
        }

        return array_column($results, 'Key_name');
    }
}
