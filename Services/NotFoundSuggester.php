<?php

/**
 * 404 Not-Found Suggester
 *
 * Compares a 404 path against existing redirect sources and published post
 * slugs using Levenshtein distance. Returns at most 3 suggestions above
 * a normalised similarity threshold of 0.7.
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

class NotFoundSuggester
{
    /**
     * Minimum normalised similarity score (0–1) to keep a candidate.
     */
    private const THRESHOLD = 0.7;

    /**
     * Maximum number of suggestions returned.
     */
    private const MAX_SUGGESTIONS = 3;

    /**
     * Suggest plausible redirect targets for a 404 URL.
     *
     * @param string $url The 404 request URI (path only or full URL).
     * @return array<int, array{url: string, score: float, type: string}>
     */
    public static function suggest(string $url): array
    {
        $path = self::toPath($url);

        if ($path === '/' || $path === '') {
            return [];
        }

        $candidates = [];

        // 1. Existing redirect sources.
        foreach (self::getRedirectSources() as $source => $destination) {
            $score = self::similarity($path, $source);
            if ($score >= self::THRESHOLD) {
                $candidates[] = [
                    'url'   => $destination,
                    'score' => $score,
                    'type'  => 'redirect',
                ];
            }
        }

        // 2. Published post/page slugs.
        foreach (self::getPublishedSlugs() as $slug => $permalink) {
            $score = self::similarity($path, $slug);
            if ($score >= self::THRESHOLD) {
                $candidates[] = [
                    'url'   => $permalink,
                    'score' => $score,
                    'type'  => 'post',
                ];
            }
        }

        // Sort by score DESC.
        usort($candidates, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        // De-duplicate by URL (keep highest score).
        $seen   = [];
        $unique = [];
        foreach ($candidates as $c) {
            $key = $c['url'];
            if (!isset($seen[ $key ])) {
                $seen[ $key ] = true;
                $unique[]     = $c;
            }
        }

        return array_slice($unique, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Normalised Levenshtein similarity between two path strings.
     *
     * @param string $a Path A.
     * @param string $b Path B.
     * @return float 0.0 (no match) – 1.0 (identical).
     */
    private static function similarity(string $a, string $b): float
    {
        $a = self::normalizePath($a);
        $b = self::normalizePath($b);

        if ($a === $b) {
            return 1.0;
        }

        $maxLen = max(strlen($a), strlen($b));

        if ($maxLen === 0) {
            return 0.0;
        }

        $distance = levenshtein($a, $b);

        if ($distance === -1) {
            // Strings longer than 255 — fall back to similar_text.
            similar_text($a, $b, $percent);

            return round($percent / 100, 4);
        }

        return round(1 - ($distance / $maxLen), 4);
    }

    /**
     * Get redirect sources mapped to their destinations.
     *
     * Tries the SQL table first, falls back to the wp_options storage.
     *
     * @return array<string, string> source_path => destination_url
     */
    private static function getRedirectSources(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'seopulse_redirects';

        if (!isset($wpdb->seopulse_redirects)) {
            $wpdb->seopulse_redirects = $table;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table),
        );

        if ($exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT source_url, target_url FROM `{$wpdb->seopulse_redirects}`
                     WHERE status = %s AND regex = %d
                     LIMIT %d",
                    'active',
                    0,
                    500,
                ),
                ARRAY_A,
            );

            $map = [];
            foreach ((array) $rows as $row) {
                $map[ self::normalizePath($row['source_url']) ] = $row['target_url'];
            }

            return $map;
        }

        // Fallback: Free option storage.
        $redirects = get_option('seopulse_redirections', []);
        $map       = [];

        foreach ((array) $redirects as $r) {
            if (($r['status'] ?? 'active') !== 'active') {
                continue;
            }
            $source = $r['source'] ?? '';
            $dest   = $r['destination'] ?? '';
            if ($source !== '' && $dest !== '') {
                $map[ self::normalizePath($source) ] = $dest;
            }
        }

        return $map;
    }

    /**
     * Get published post/page slugs mapped to their permalinks.
     *
     * @return array<string, string> /slug => permalink
     */
    private static function getPublishedSlugs(): array
    {
        $post_types = get_post_types(['public' => true]);

        $posts = get_posts(
            [
                'post_type'      => array_values($post_types),
                'post_status'    => 'publish',
                // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
                'posts_per_page' => 500,
                'no_found_rows'  => true,
                'fields'         => 'ids',
            ],
        );

        $map = [];
        foreach ($posts as $post_id) {
            $permalink = get_permalink($post_id);
            if (!$permalink) {
                continue;
            }

            $path = wp_parse_url($permalink, PHP_URL_PATH);
            if ($path) {
                $map[ self::normalizePath($path) ] = $permalink;
            }
        }

        return $map;
    }

    /**
     * Extract a path from a URL.
     *
     * @param string $value Full URL or relative path.
     * @return string Path only.
     */
    private static function toPath(string $value): string
    {
        $value = trim($value);

        if (preg_match('#^https?://#i', $value)) {
            $path = wp_parse_url($value, PHP_URL_PATH);

            return $path ? '/' . ltrim($path, '/') : '/';
        }

        return '/' . ltrim($value, '/');
    }

    /**
     * Normalize a path for Levenshtein comparison.
     *
     * Strips leading/trailing slashes, lowercases, removes file extensions.
     *
     * @param string $path Path.
     * @return string Normalized string.
     */
    private static function normalizePath(string $path): string
    {
        // Extract path from full URL if needed.
        if (preg_match('#^https?://#i', $path)) {
            $path = wp_parse_url($path, PHP_URL_PATH) ?: '/';
        }

        $path = strtolower(trim($path, '/'));

        // Remove common file extensions that add noise.
        $path = preg_replace('/\.(html?|php|asp)$/i', '', $path) ?? $path;

        return $path;
    }
}
