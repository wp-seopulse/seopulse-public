<?php

/**
 * Optimized query helper for admin list table columns.
 *
 * Primes the post meta cache in a single query so that
 * individual get_post_meta() calls inside renderColumn()
 * hit the object cache instead of the database.
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\Columns;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\PostMeta;

/**
 * ColumnQuery — Batched meta cache primer for admin columns.
 */
final class ColumnQuery
{
    /** @var bool Whether the cache has been primed for the current screen. */
    private static bool $primed = false;

    /**
     * Prime post meta cache for all visible post IDs.
     *
     * Call once before rendering any custom column.
     * WordPress will fetch all meta rows in one query;
     * subsequent get_post_meta() calls become free.
     *
     * @return void
     */
    public static function prime(): void
    {
        if (self::$primed) {
            return;
        }

        global $wp_query;
        if (empty($wp_query->posts)) {
            return;
        }

        $post_ids = wp_list_pluck($wp_query->posts, 'ID');
        update_meta_cache('post', $post_ids);

        self::$primed = true;
    }

    /**
     * Get the SEO score for a post (from primed cache).
     *
     * @param int $post_id Post ID.
     *
     * @return int|null Score 0-100 or null if not analyzed.
     */
    public static function get_score(int $post_id): ?int
    {
        $raw = get_post_meta($post_id, PostMeta::SCORE, true);

        return $raw !== '' ? (int) $raw : null;
    }

    /**
     * Get meta title for a post.
     *
     * @param int $post_id Post ID.
     *
     * @return string Meta title or empty string.
     */
    public static function get_meta_title(int $post_id): string
    {
        $meta_seo = get_post_meta($post_id, PostMeta::META_SEO, true);

        if (!is_array($meta_seo)) {
            return '';
        }

        return trim((string) ($meta_seo['title'] ?? ''));
    }

    /**
     * Get meta description for a post.
     *
     * @param int $post_id Post ID.
     *
     * @return string Meta description or empty string.
     */
    public static function get_meta_description(int $post_id): string
    {
        $meta_seo = get_post_meta($post_id, PostMeta::META_SEO, true);

        if (!is_array($meta_seo)) {
            return '';
        }

        return trim((string) ($meta_seo['description'] ?? ''));
    }

    /**
     * Get the last analysis timestamp.
     *
     * @param int $post_id Post ID.
     *
     * @return int|null Unix timestamp or null if never analyzed.
     */
    public static function get_last_analysis(int $post_id): ?int
    {
        $raw = get_post_meta($post_id, PostMeta::LAST_ANALYSIS, true);

        return $raw !== '' ? (int) $raw : null;
    }

    /**
     * Determine analysis status.
     *
     * @param int $post_id Post ID.
     *
     * @return string 'up-to-date' | 'needs-analysis' | 'not-analyzed'
     */
    public static function get_status(int $post_id): string
    {
        $last = self::get_last_analysis($post_id);

        if ($last === null) {
            return 'not-analyzed';
        }

        $modified = get_post_modified_time('U', true, $post_id);

        if ($modified && (int) $modified > $last) {
            return 'needs-analysis';
        }

        return 'up-to-date';
    }
}
