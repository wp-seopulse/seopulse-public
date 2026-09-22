<?php

/**
 * Indexing submission logger — stores results in a custom database table.
 *
 * Provides methods to log, query, and clean up indexing submission records.
 * Also handles duplicate-submission prevention (cooldown window).
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- IndexingLogger: direct DB access is intentional; caching is handled at the service/caller layer.

class IndexingLogger
{
    /** Cooldown in seconds before re-submitting the same URL to the same provider. */
    private const COOLDOWN = 3600;

    /** Table suffix (appended to $wpdb->prefix). */
    private const TABLE_SUFFIX = 'seopulse_indexing_log';

    /**
     * Get the log table name (with WP prefix).
     *
     * Note: we intentionally do NOT store this on a dynamic $wpdb property
     * (e.g. $wpdb->seopulse_indexing_log) — wpdb is not guaranteed to allow
     * dynamic properties on all PHP versions, and relying on that side
     * effect made several queries below fragile. Callers should always use
     * the return value of this method directly.
     *
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Log a submission result.
     *
     * @param string $url Submitted URL.
     * @param string $service Provider ID (e.g. 'indexnow').
     * @param string $status 'success' | 'error' | 'skipped'.
     * @param string $message Description or error details.
     *
     * @return void
     */
    public static function log(string $url, string $service, string $status, string $message = ''): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            self::tableName(),
            [
                'url'       => $url,
                'service'   => $service,
                'status'    => $status,
                'error_msg' => $message,
                'timestamp' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s'],
        );

        // Don't fail silently: an insert failure here (e.g. missing table,
        // truncated column) would otherwise disappear without a trace.
        if ($inserted === false && defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional, gated behind WP_DEBUG; surfaces otherwise-silent insert failures.
            error_log(
                sprintf(
                    '[SEOPulse] Failed to write indexing log entry for %s (%s): %s',
                    $url,
                    $service,
                    $wpdb->last_error,
                ),
            );
        }
    }

    /**
     * Check whether a URL was successfully submitted recently (within cooldown).
     *
     * Prevents duplicate/rapid re-submissions. Callers should almost always
     * pass a specific $service — checking "any provider" can incorrectly
     * block a provider that has never actually succeeded yet.
     *
     * @param string $url URL to check.
     * @param string $service Provider ID (empty = any provider).
     *
     * @return bool True if a successful submission exists within the cooldown window.
     */
    public static function wasRecentlySubmitted(string $url, string $service = ''): bool
    {
        global $wpdb;

        $table = self::tableName();
        $since = gmdate('Y-m-d H:i:s', time() - self::COOLDOWN);

        if ($service !== '') {
            $row = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE url = %s AND service = %s AND status = 'success' AND timestamp >= %s",
                    $table,
                    $url,
                    $service,
                    $since,
                ),
            );
        } else {
            $row = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE url = %s AND status = 'success' AND timestamp >= %s",
                    $table,
                    $url,
                    $since,
                ),
            );
        }

        return (int) $row > 0;
    }

    /**
     * Get recent log entries.
     *
     * @param int $limit Max rows.
     * @param int $offset Pagination offset.
     * @param string $service Filter by provider (optional).
     *
     * @return array<int, object>
     */
    public static function getRecent(int $limit = 50, int $offset = 0, string $service = ''): array
    {
        global $wpdb;

        $table = self::tableName();

        if ($service !== '') {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE service = %s ORDER BY timestamp DESC LIMIT %d OFFSET %d',
                    $table,
                    $service,
                    $limit,
                    $offset,
                ),
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i ORDER BY timestamp DESC LIMIT %d OFFSET %d',
                    $table,
                    $limit,
                    $offset,
                ),
            );
        }

        // $wpdb returns every column as a string by default (mysqli driver
        // behaviour), even for the bigint `id` column. Left uncast, the
        // REST response serializes it as a JSON string ("42") instead of a
        // number (42), silently breaking the `LogEntry.id: number` contract
        // the React app relies on.
        foreach ($rows as $row) {
            $row->id = (int) $row->id;
        }

        return $rows;
    }

    /**
     * Count total log entries (for pagination).
     *
     * @param string $service Filter by provider (optional).
     *
     * @return int
     */
    public static function count(string $service = ''): int
    {
        global $wpdb;

        $table = self::tableName();

        if ($service !== '') {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE service = %s',
                    $table,
                    $service,
                ),
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', $table),
        );
    }

    /**
     * Purge old entries (older than N days).
     *
     * @param int $days Number of days to keep.
     *
     * @return int Number of deleted rows.
     */
    public static function purge(int $days = 90): int
    {
        global $wpdb;

        $table  = self::tableName();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        return (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE timestamp < %s',
                $table,
                $cutoff,
            ),
        );
    }

    /**
     * Create the log table (called from Installer).
     *
     * @return void
     */
    public static function createTable(): void
    {
        global $wpdb;

        $table           = self::tableName();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            url varchar(2048) NOT NULL,
            service varchar(50) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            error_msg text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_url (url(191)),
            KEY idx_service (service),
            KEY idx_status (status),
            KEY idx_timestamp (timestamp)
        ) {$charset_collate};";

        dbDelta($sql);
    }
}
