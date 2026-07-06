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
use SEOPulse\Modules\MetaSeo\Engine\ContextBag;
use SEOPulse\Modules\MetaSeo\Engine\MetaEngine;

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

        return self::resolve_variables(trim((string) ($meta_seo['title'] ?? '')), $post_id);
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

        return self::resolve_variables(trim((string) ($meta_seo['description'] ?? '')), $post_id);
    }

    /**
     * Resolve dynamic template variables (e.g. %%post.title%%, %%post.excerpt%%)
     * in a meta value so the admin column shows the actual rendered text
     * rather than the literal template syntax.
     *
     * Values without any %%...%% syntax are returned unchanged.
     *
     * @param string $value Raw meta value (may contain %%variable%% tokens).
     * @param int $post_id Post used to resolve post.* variables.
     *
     * @return string Resolved value.
     */
    private static function resolve_variables(string $value, int $post_id): string
    {
        if ($value === '' || !str_contains($value, '%%')) {
            return $value;
        }

        $post = get_post($post_id);
        if (!($post instanceof \WP_Post)) {
            return $value;
        }

        try {
            $author  = get_userdata((int) $post->post_author);
            $context = new ContextBag(
                type: 'singular',
                post: $post,
                author: ($author instanceof \WP_User) ? $author : null,
            );

            return (new MetaEngine())->resolve($value, $context, 'raw');
        } catch (\Throwable $e) {
            return $value;
        }
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
