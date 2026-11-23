<?php

/**
 * url_hash schema upgrade and backfill.
 *
 * Adds `url_hash char(32)` + `KEY url_hash` to the redirects and 404 log
 * tables of existing installs, then fills the hash of existing rows in
 * bounded, resumable batches. Idempotent and safe to call on every admin load.
 *
 * @package SEOPulse\Core\Database
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class UrlHashSchema
{
    /** Option set to 'done' once every existing row carries a hash. */
    public const BACKFILL_OPTION = 'seopulse_url_hash_backfill';

    private const BATCH_SIZE = 500;

    /** Max seconds spent backfilling per request. */
    private const TIME_BUDGET = 3.0;

    /**
     * Hashed tables: unprefixed name => URL column.
     */
    private const TABLES = [
        'seopulse_redirects' => 'source_url',
        'seopulse_404_logs'  => 'url',
    ];

    /**
     * Runs the schema upgrade and, if pending, a backfill slice.
     */
    public static function maybe_migrate(): void
    {
        if (!UrlHash::isReady()) {
            self::upgradeSchema();
        }

        if (UrlHash::isReady() && 'done' !== get_option(self::BACKFILL_OPTION, '')) {
            self::backfill();
        }
    }

    /**
     * Adds the column and the index on every existing table.
     *
     * The schema level is only recorded when every table is covered, so a
     * missing table (module never activated) is retried later.
     */
    public static function upgradeSchema(): void
    {
        global $wpdb;

        $complete = true;

        foreach (array_keys(self::TABLES) as $name) {
            $table = $wpdb->prefix . $name;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
                $complete = false;
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefixed table name.
            $has_column = (bool) $wpdb->get_var(
                $wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", UrlHash::COLUMN),
            );

            if (!$has_column) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prefixed table name, constant DDL.
                $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `url_hash` char(32) NOT NULL DEFAULT ''");
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefixed table name.
            $has_index = (bool) $wpdb->get_var(
                $wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", UrlHash::COLUMN),
            );

            if (!$has_index) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prefixed table name, constant DDL.
                $wpdb->query("ALTER TABLE `{$table}` ADD KEY `url_hash` (`url_hash`)");
            }

            // Both must exist now, otherwise the ALTER failed (permissions, ...).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefixed table name.
            $ok = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", UrlHash::COLUMN))
                && $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", UrlHash::COLUMN));

            if (!$ok) {
                $complete = false;
            }
        }

        if ($complete) {
            update_option(UrlHash::SCHEMA_OPTION, UrlHash::SCHEMA_LEVEL, true);
            UrlHash::reset();
        }
    }

    /**
     * Fills the hash of rows that have none, within the time budget.
     *
     * Marks the backfill done once a full pass finds nothing left.
     */
    public static function backfill(): void
    {
        global $wpdb;

        $deadline = microtime(true) + self::TIME_BUDGET;

        foreach (self::TABLES as $name => $column) {
            $table = $wpdb->prefix . $name;

            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefixed table name, constant column.
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, `{$column}` AS url FROM `{$table}` WHERE `url_hash` = '' ORDER BY id ASC LIMIT %d",
                        self::BATCH_SIZE,
                    ),
                    ARRAY_A,
                );

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $updated = $wpdb->update(
                        $table,
                        [UrlHash::COLUMN => UrlHash::of((string) $row['url'])],
                        ['id' => (int) $row['id']],
                        ['%s'],
                        ['%d'],
                    );

                    // A failing write would loop forever on the same rows.
                    if (false === $updated) {
                        return;
                    }
                }

                if (microtime(true) >= $deadline) {
                    return; // Resume on the next request.
                }
            } while (count($rows) === self::BATCH_SIZE);
        }

        update_option(self::BACKFILL_OPTION, 'done', false);
    }
}
