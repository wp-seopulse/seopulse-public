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
    /** Cooldown in seconds before re-submitting the same URL. */
    private const COOLDOWN = 3600;

    /**
     * Get the log table name (with WP prefix).
     *
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;

        // Register custom table on $wpdb so it is recognised as a safe identifier.
        if (!isset($wpdb->seopulse_indexing_log)) {
            $wpdb->seopulse_indexing_log = $wpdb->prefix . 'seopulse_indexing_log';
        }

        return $wpdb->seopulse_indexing_log;
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

        $wpdb->insert(
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
    }

    /**
     * Check whether a URL was successfully submitted recently (within cooldown).
     *
     * Prevents duplicate/rapid re-submissions.
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$wpdb->seopulse_indexing_log}` WHERE url = %s AND service = %s AND status = 'success' AND timestamp >= %s",
                    $url,
                    $service,
                    $since,
                ),
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$wpdb->seopulse_indexing_log}` WHERE url = %s AND status = 'success' AND timestamp >= %s",
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$wpdb->seopulse_indexing_log}` WHERE service = %s ORDER BY timestamp DESC LIMIT %d OFFSET %d",
                    $service,
                    $limit,
                    $offset,
                ),
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->seopulse_indexing_log}` ORDER BY timestamp DESC LIMIT %d OFFSET %d",
                $limit,
                $offset,
            ),
        );
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$wpdb->seopulse_indexing_log}` WHERE service = %s",
                    $service,
                ),
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->seopulse_indexing_log}` WHERE 1=%d", 1),
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->seopulse_indexing_log}` WHERE timestamp < %s",
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
