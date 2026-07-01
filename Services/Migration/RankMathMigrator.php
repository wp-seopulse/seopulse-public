<?php

/**
 * Rank Math SEO to SEOPulse migration service
 *
 * Detects the presence of Rank Math SEO, reads its options and post meta,
 * then maps them to the SEOPulse structure.
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services\Migration;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\AbstractMigrator;
use SEOPulse\Core\Constants\Options;

/**
 * RankMathMigrator class
 *
 * Extends AbstractMigrator to provide Rank Math-specific mapping logic.
 * Common detect/scan/import/batch operations are inherited from the parent.
 */
class RankMathMigrator extends AbstractMigrator
{
    // ── Rank Math option keys ────────────────────
    private const RM_GENERAL = 'rank-math-options-general';
    private const RM_TITLES  = 'rank-math-options-titles';
    private const RM_SITEMAP = 'rank-math-options-sitemap';

    // ── Rank Math post meta keys ─────────────────
    private const RM_META_FOCUS_KW  = 'rank_math_focus_keyword';
    private const RM_META_TITLE     = 'rank_math_title';
    private const RM_META_DESC      = 'rank_math_description';
    private const RM_META_CANONICAL = 'rank_math_canonical_url';
    private const RM_META_ROBOTS    = 'rank_math_robots';
    private const RM_META_SCORE     = 'rank_math_seo_score';
    private const RM_META_OG_TITLE  = 'rank_math_facebook_title';
    private const RM_META_OG_DESC   = 'rank_math_facebook_description';
    private const RM_META_OG_IMG    = 'rank_math_facebook_image';
    private const RM_META_TW_TITLE  = 'rank_math_twitter_title';
    private const RM_META_TW_DESC   = 'rank_math_twitter_description';
    private const RM_META_TW_IMG    = 'rank_math_twitter_image';
    private const RM_META_TW_CARD   = 'rank_math_twitter_card_type';
    private const RM_META_PRIMARY   = 'rank_math_primary_category';

    /** Allowed robots directives */
    private const ALLOWED_ROBOTS = ['index', 'noindex', 'follow', 'nofollow', 'noimageindex', 'noarchive', 'nosnippet'];

    // ──────────────────────────────────────────────
    // ABSTRACT IMPLEMENTATIONS
    // ──────────────────────────────────────────────

    /**
     * {@inheritDoc}
     */
    protected function getPluginSlugs(): array
    {
        return [
            'seo-by-rank-math/rank-math.php',
            'seo-by-rank-math-premium/rank-math.php',
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getSourceOptionKeys(): array
    {
        return [self::RM_GENERAL, self::RM_TITLES, self::RM_SITEMAP];
    }

    /**
     * {@inheritDoc}
     */
    protected function getVersionConstant(): ?string
    {
        return 'RANK_MATH_VERSION';
    }

    /**
     * {@inheritDoc}
     */
    protected function getPluginFilePath(): string
    {
        return '/seo-by-rank-math/rank-math.php';
    }

    /**
     * {@inheritDoc}
     */
    protected function getScannableMetaKeys(): array
    {
        return [
            self::RM_META_TITLE,
            self::RM_META_DESC,
            self::RM_META_OG_TITLE,
            self::RM_META_OG_DESC,
            self::RM_META_OG_IMG,
            self::RM_META_TW_TITLE,
            self::RM_META_TW_DESC,
            self::RM_META_TW_IMG,
            self::RM_META_TW_CARD,
            self::RM_META_FOCUS_KW,
            self::RM_META_CANONICAL,
            self::RM_META_ROBOTS,
            self::RM_META_SCORE,
            self::RM_META_PRIMARY,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function scanGlobalOptions(): array
    {
        $modules = [];

        $titles = get_option(self::RM_TITLES, []);
        if (!empty($titles) && is_array($titles)) {
            $modules['meta_seo_global'] = count($titles);
        }

        $general = get_option(self::RM_GENERAL, []);
        if (!empty($general) && is_array($general)) {
            $verif_count = count(array_filter(
                ['google_verify', 'bing_verify', 'baidu_verify', 'yandex_verify', 'pinterest_verify'],
                static fn (string $k): bool => !empty($general[$k]),
            ));

            if ($verif_count > 0) {
                $modules['verification'] = $verif_count;
            }
        }

        $sitemap = get_option(self::RM_SITEMAP, []);
        if (!empty($sitemap) && is_array($sitemap)) {
            $modules['sitemap'] = count($sitemap);
        }

        return $modules;
    }

    /**
     * {@inheritDoc}
     */
    protected function importGlobalOptions(array &$result, bool $overwrite): void
    {
        $this->importMetaSeoGlobal($result, $overwrite);
        $this->importRankMathVerificationCodes($result, $overwrite);
    }

    /**
     * {@inheritDoc}
     */
    protected function importSitemapSettings(array &$result, bool $overwrite): void
    {
        $rm_sitemap = get_option(self::RM_SITEMAP, []);

        if (empty($rm_sitemap) || !is_array($rm_sitemap)) {
            return;
        }

        $current = get_option(Options::SITEMAP, []);

        if (!empty($current) && !$overwrite) {
            $result['warnings'][] = __('Sitemap settings already configured — skipped (overwrite disabled).', 'seopulse');

            return;
        }

        $mapped = [];

        // Post type sitemap toggles: pt_{cpt}_sitemap = on/off
        foreach (get_post_types(['public' => true], 'names') as $cpt) {
            $mapped['enable_' . $cpt] = (($rm_sitemap['pt_' . $cpt . '_sitemap'] ?? 'on') === 'on') ? 1 : 0;
        }

        // Taxonomy sitemap toggles: tax_{taxonomy}_sitemap = on/off
        foreach (get_taxonomies(['public' => true], 'names') as $tax) {
            $mapped['enable_' . $tax] = (($rm_sitemap['tax_' . $tax . '_sitemap'] ?? 'on') === 'on') ? 1 : 0;
        }

        if (!empty($mapped)) {
            update_option(Options::SITEMAP, $overwrite ? $mapped : array_merge($current, $mapped));
            $result['options_imported'][] = 'sitemap';
        }
    }

    /**
     * {@inheritDoc}
     *
     * Fetches all post meta in a single DB call instead of one call per field.
     */
    protected function importSinglePostMeta(int $post_id, bool $overwrite): int
    {
        // Single DB call for all post meta
        $all_meta = get_post_meta($post_id);

        $imported = 0;

        // ── Focus keyword (Rank Math stores comma-separated; first = primary) ──
        $rm_kw = $this->extractMeta($all_meta, self::RM_META_FOCUS_KW);
        if ($rm_kw !== '') {
            $keywords  = array_map('trim', explode(',', $rm_kw));
            $imported += $this->importFocusKeyword($post_id, $keywords[0] ?? '', $overwrite);

            $secondary = array_slice($keywords, 1);
            if (!empty($secondary)) {
                $imported += $this->importFocusKeywords($post_id, $secondary, $overwrite);
            }
        }

        // ── SEO score ──
        $imported += $this->importScore($post_id, $this->extractMeta($all_meta, self::RM_META_SCORE), $overwrite);

        // ── Meta SEO (title, desc, OG, Twitter, canonical, robots) ──
        $imported += $this->importSeoMetaData($post_id, $this->buildSeoData($all_meta), $overwrite);

        // ── Primary category ──
        $rm_primary = $this->extractMeta($all_meta, self::RM_META_PRIMARY);
        if ($rm_primary !== '') {
            $imported += $this->importPrimaryCategory($post_id, (int) $rm_primary, $overwrite);
        }

        // Note: Rank Math does not have post-level redirections in the free version

        return $imported;
    }

    // ──────────────────────────────────────────────
    // RANK MATH-SPECIFIC PRIVATE METHODS
    // ──────────────────────────────────────────────

    /**
     * Imports global SEO meta from Rank Math (titles, OG, Twitter)
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importMetaSeoGlobal(array &$result, bool $overwrite): void
    {
        $rm_titles = get_option(self::RM_TITLES, []);

        if (empty($rm_titles) || !is_array($rm_titles)) {
            return;
        }

        $mapped = [];

        // Home title & description
        $mapped['title']       = (string) ($rm_titles['homepage_title'] ?? '');
        $mapped['description'] = (string) ($rm_titles['homepage_description'] ?? '');

        // Open Graph
        $mapped['og_title']       = (string) ($rm_titles['homepage_facebook_title'] ?? '');
        $mapped['og_description'] = (string) ($rm_titles['homepage_facebook_description'] ?? '');
        $mapped['og_image']       = (string) ($rm_titles['homepage_facebook_image'] ?? $rm_titles['open_graph_image'] ?? '');
        $mapped['og_type']        = 'website';

        $site_name = (string) ($rm_titles['knowledgegraph_name'] ?? '');
        if ($site_name !== '') {
            $mapped['og_site_name'] = $site_name;
        }

        // Twitter
        $mapped['twitter_card']        = (string) ($rm_titles['twitter_card_type'] ?? 'summary_large_image');
        $mapped['twitter_title']       = (string) ($rm_titles['homepage_twitter_title'] ?? '');
        $mapped['twitter_description'] = (string) ($rm_titles['homepage_twitter_description'] ?? '');

        // Global robots warnings (author / date archives)
        $author_robots = $rm_titles['author_robots'] ?? [];
        if (is_array($author_robots) && in_array('noindex', $author_robots, true)) {
            $result['warnings'][] = __('Rank Math author archives set to noindex — noted.', 'seopulse');
        }

        $date_robots = $rm_titles['date_archive_robots'] ?? [];
        if (is_array($date_robots) && in_array('noindex', $date_robots, true)) {
            $result['warnings'][] = __('Rank Math date archives set to noindex — noted.', 'seopulse');
        }

        $sep = (string) ($rm_titles['title_separator'] ?? '');
        if ($sep !== '') {
            $result['warnings'][] = sprintf(
                /* translators: %s: separator character */
                __('Rank Math title separator "%s" noted but SEOPulse does not use a global separator.', 'seopulse'),
                $sep,
            );
        }

        // Drop empty strings to avoid overwriting existing data with blank values
        $mapped = array_filter($mapped, static fn ($v): bool => $v !== '');

        $this->mergeIntoOption(
            Options::META_SEO_GLOBAL,
            $mapped,
            $overwrite,
            $result,
            'meta_seo_global',
            __('Global SEO meta already configured — skipped (overwrite disabled).', 'seopulse'),
        );
    }

    /**
     * Imports verification codes from Rank Math (Google, Bing, Baidu, Yandex)
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importRankMathVerificationCodes(array &$result, bool $overwrite): void
    {
        $rm_general = get_option(self::RM_GENERAL, []);

        if (empty($rm_general) || !is_array($rm_general)) {
            return;
        }

        $codes_map = [
            'google_verify' => 'google_verification',
            'bing_verify'   => 'bing_verification',
            'baidu_verify'  => 'baidu_verification',
            'yandex_verify' => 'yandex_verification',
        ];

        $this->importVerificationCodes($codes_map, $rm_general, $overwrite, $result);

        // Pinterest verification has no SEOPulse equivalent — warn only
        if (!empty($rm_general['pinterest_verify'])) {
            $result['warnings'][] = __('Pinterest verification code found but SEOPulse does not support Pinterest verification — skipped.', 'seopulse');
        }
    }

    /**
     * Builds the SEO data array from an already-fetched post meta map.
     *
     * Accepts the full meta array from a single get_post_meta() call
     * instead of issuing individual queries per field.
     *
     * @param array<string, array<int, mixed>> $all_meta Raw result of get_post_meta($post_id)
     * @return array<string, string> Mapped SEO fields
     */
    private function buildSeoData(array $all_meta): array
    {
        $seo_data = [];

        $title = $this->extractMeta($all_meta, self::RM_META_TITLE);
        if ($title !== '') {
            $seo_data['title'] = sanitize_text_field($title);
        }

        $desc = $this->extractMeta($all_meta, self::RM_META_DESC);
        if ($desc !== '') {
            $seo_data['description'] = sanitize_text_field($desc);
        }

        $canonical = $this->extractMeta($all_meta, self::RM_META_CANONICAL);
        if ($canonical !== '') {
            $seo_data['canonical'] = esc_url_raw($canonical);
        }

        // Robots — Rank Math stores as a serialized array: ['noindex', 'nofollow', ...]
        // get_post_meta() with $single=false already unserializes it; the batch call
        // stores it under index [0] of the meta key's array.
        $rm_robots = $all_meta[self::RM_META_ROBOTS][0] ?? null;
        // get_post_meta() auto-unserializes, so we may receive the array directly
        if (!is_array($rm_robots)) {
            $rm_robots = maybe_unserialize($rm_robots);
        }
        $robots = $this->normalizeRobots((array) $rm_robots);
        if ($robots !== '') {
            $seo_data['robots'] = $robots;
        }

        // Open Graph
        $og_title = $this->extractMeta($all_meta, self::RM_META_OG_TITLE);
        if ($og_title !== '') {
            $seo_data['og_title'] = sanitize_text_field($og_title);
        }

        $og_desc = $this->extractMeta($all_meta, self::RM_META_OG_DESC);
        if ($og_desc !== '') {
            $seo_data['og_description'] = sanitize_text_field($og_desc);
        }

        $og_img = $this->extractMeta($all_meta, self::RM_META_OG_IMG);
        if ($og_img !== '') {
            $seo_data['og_image'] = esc_url_raw($og_img);
        }

        // Twitter
        $tw_title = $this->extractMeta($all_meta, self::RM_META_TW_TITLE);
        if ($tw_title !== '') {
            $seo_data['twitter_title'] = sanitize_text_field($tw_title);
        }

        $tw_desc = $this->extractMeta($all_meta, self::RM_META_TW_DESC);
        if ($tw_desc !== '') {
            $seo_data['twitter_description'] = sanitize_text_field($tw_desc);
        }

        $tw_img = $this->extractMeta($all_meta, self::RM_META_TW_IMG);
        if ($tw_img !== '') {
            $seo_data['twitter_image'] = esc_url_raw($tw_img);
        }

        $tw_card = $this->extractMeta($all_meta, self::RM_META_TW_CARD);
        if ($tw_card !== '') {
            $seo_data['twitter_card'] = sanitize_text_field($tw_card);
        }

        return $seo_data;
    }

    /**
     * Normalises a raw Rank Math robots directive array into a ready-to-store string.
     *
     * Rank Math may omit the index/follow pair when only advanced directives are set,
     * so this method completes the pair when necessary and filters invalid values.
     * Shared between post meta (buildSeoData) and term meta (buildTermSeoData).
     *
     * @param array<mixed> $raw Raw directives from Rank Math meta
     * @return string Comma-separated directive string, or '' if nothing actionable
     */
    private function normalizeRobots(array $raw): string
    {
        if (empty($raw)) {
            return '';
        }

        // Sanitise and filter to allowed values only
        $parts = array_values(array_filter(
            array_map(static fn ($d): string => strtolower(trim((string) $d)), $raw),
            static fn (string $d): bool => in_array($d, self::ALLOWED_ROBOTS, true),
        ));

        if (empty($parts)) {
            return '';
        }

        $has_index  = in_array('index', $parts, true)  || in_array('noindex', $parts, true);
        $has_follow = in_array('follow', $parts, true) || in_array('nofollow', $parts, true);

        // Complete index/noindex when absent
        if (!$has_index) {
            array_unshift($parts, 'index');
        }

        // Insert follow/nofollow after the index directive when absent
        if (!$has_follow) {
            $insert_after = array_search('index', $parts, true)
                ?? array_search('noindex', $parts, true)
                ?? 0;

            array_splice($parts, (int) $insert_after + 1, 0, ['follow']);
        }

        return implode(',', $parts);
    }

    /**
     * Extracts a scalar string value from the raw get_post_meta() map.
     *
     * get_post_meta() without a key returns [ 'key' => [ 0 => 'value' ] ].
     *
     * @param array<string, array<int, mixed>> $all_meta
     * @param string $key
     * @return string
     */
    private function extractMeta(array $all_meta, string $key): string
    {
        return isset($all_meta[$key][0]) ? (string) $all_meta[$key][0] : '';
    }

    // ──────────────────────────────────────────────
    // TAXONOMY TERM META IMPORT
    // ──────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * Rank Math stores term meta using the same meta keys as post meta
     * but in the termmeta table: rank_math_title, rank_math_description,
     * rank_math_facebook_*, rank_math_twitter_*, rank_math_robots, etc.
     */
    protected function importTermsMeta(array &$result, bool $overwrite): void
    {
        global $wpdb;

        $term_meta_keys = [
            self::RM_META_TITLE,
            self::RM_META_DESC,
            self::RM_META_CANONICAL,
            self::RM_META_ROBOTS,
            self::RM_META_OG_TITLE,
            self::RM_META_OG_DESC,
            self::RM_META_OG_IMG,
            self::RM_META_TW_TITLE,
            self::RM_META_TW_DESC,
            self::RM_META_TW_IMG,
        ];

        $placeholders = implode(',', array_fill(0, count($term_meta_keys), '%s'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $term_ids = $wpdb->get_col(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key IN ({$placeholders})",
                ...$term_meta_keys,
            ),
        );

        if (empty($term_ids)) {
            return;
        }

        $imported_count = 0;

        foreach ($term_ids as $term_id) {
            $term_id = (int) $term_id;

            if (!get_term($term_id) instanceof \WP_Term) {
                continue;
            }

            $seo_data = $this->buildTermSeoData($term_id);

            if (!empty($seo_data)) {
                $imported_count += $this->importTermSeoData($term_id, $seo_data, $overwrite);
            }
        }

        if ($imported_count > 0) {
            $result['options_imported'][] = 'term_meta';
            $result['warnings'][]         = sprintf(
                /* translators: %d: number of term meta fields imported */
                __('%d taxonomy term meta fields imported.', 'seopulse'),
                $imported_count,
            );
        }
    }

    /**
     * Builds SEO data for a term from Rank Math term meta.
     *
     * Uses a single get_term_meta() call without a key to fetch all fields at once,
     * then extracts values from the resulting map.
     *
     * @param int $term_id Term ID
     * @return array<string, string> Mapped SEO data
     */
    private function buildTermSeoData(int $term_id): array
    {
        // Single DB call for all term meta
        $all_meta = get_term_meta($term_id);

        $data = [];

        $title = $this->extractMeta($all_meta, self::RM_META_TITLE);
        if ($title !== '') {
            $data['title'] = sanitize_text_field($title);
        }

        $desc = $this->extractMeta($all_meta, self::RM_META_DESC);
        if ($desc !== '') {
            $data['description'] = sanitize_text_field($desc);
        }

        $canonical = $this->extractMeta($all_meta, self::RM_META_CANONICAL);
        if ($canonical !== '') {
            $data['canonical'] = esc_url_raw($canonical);
        }

        // Robots — same normalisation as for posts, ensuring index/follow completeness
        $rm_robots = $all_meta[self::RM_META_ROBOTS][0] ?? null;
        if (!is_array($rm_robots)) {
            $rm_robots = maybe_unserialize($rm_robots);
        }
        $robots = $this->normalizeRobots((array) $rm_robots);
        if ($robots !== '') {
            $data['robots'] = $robots;
        }

        // Open Graph
        $og_title = $this->extractMeta($all_meta, self::RM_META_OG_TITLE);
        if ($og_title !== '') {
            $data['og_title'] = sanitize_text_field($og_title);
        }

        $og_desc = $this->extractMeta($all_meta, self::RM_META_OG_DESC);
        if ($og_desc !== '') {
            $data['og_description'] = sanitize_text_field($og_desc);
        }

        $og_img = $this->extractMeta($all_meta, self::RM_META_OG_IMG);
        if ($og_img !== '') {
            $data['og_image'] = esc_url_raw($og_img);
        }

        // Twitter
        $tw_title = $this->extractMeta($all_meta, self::RM_META_TW_TITLE);
        if ($tw_title !== '') {
            $data['twitter_title'] = sanitize_text_field($tw_title);
        }

        $tw_desc = $this->extractMeta($all_meta, self::RM_META_TW_DESC);
        if ($tw_desc !== '') {
            $data['twitter_description'] = sanitize_text_field($tw_desc);
        }

        $tw_img = $this->extractMeta($all_meta, self::RM_META_TW_IMG);
        if ($tw_img !== '') {
            $data['twitter_image'] = esc_url_raw($tw_img);
        }

        return $data;
    }
}
