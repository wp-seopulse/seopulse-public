<?php

/**
 * URL hash helper.
 *
 * Redirect sources and 404 URLs are VARCHAR(2048) columns that can only be
 * indexed on a 191-character prefix. A fixed-width `url_hash` column
 * (md5 of the lower-cased URL) gives MySQL a complete, selective index so
 * lookups no longer scan prefix collisions.
 *
 * Rows written by other code paths (e.g. SEOPulse Pro) may carry an empty
 * hash until the backfill reaches them, so every lookup also accepts ''.
 *
 * @package SEOPulse\Core\Database
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class UrlHash
{
    /** Column name shared by every hashed table. */
    public const COLUMN = 'url_hash';

    /** Option holding the schema level once the column and index exist everywhere. */
    public const SCHEMA_OPTION = 'seopulse_url_hash_schema';

    /** Current schema level. */
    public const SCHEMA_LEVEL = '1';

    private static ?bool $ready = null;

    /**
     * Computes the hash of a URL (case-insensitive, 32 hex characters).
     */
    public static function of(string $url): string
    {
        return md5(strtolower($url));
    }

    /**
     * True when the url_hash column and index exist on every hashed table.
     *
     * Queries and writes must only reference the column when this is true.
     */
    public static function isReady(): bool
    {
        if (null === self::$ready) {
            self::$ready = self::SCHEMA_LEVEL === (string) get_option(self::SCHEMA_OPTION, '');
        }

        return self::$ready;
    }

    /**
     * Forgets the cached readiness (after a schema change or in tests).
     */
    public static function reset(): void
    {
        self::$ready = null;
    }

    /**
     * Adds the hash to a row about to be written, when the column exists.
     *
     * @param array<string, mixed> $row Column => value.
     * @param string[] $formats Matching $wpdb format list.
     * @param string $url URL the hash is derived from.
     * @return array{0: array<string, mixed>, 1: string[]} Row and formats.
     */
    public static function withHash(array $row, array $formats, string $url): array
    {
        if (self::isReady()) {
            $row[ self::COLUMN ] = self::of($url);
            $formats[]           = '%s';
        }

        return [$row, $formats];
    }
}
