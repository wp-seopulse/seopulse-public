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
use SEOPulse\Modules\LocalSeo\LocalSeoValidator;

/**
 * RankMathMigrator class
 *
 * Extends AbstractMigrator to provide Rank Math-specific mapping logic.
 * Common detect/scan/import/batch operations are inherited from the parent.
 */
class RankMathMigrator extends AbstractMigrator
{
    // ── Rank Math option keys ────────────────────
    private const RM_GENERAL          = 'rank-math-options-general';
    private const RM_TITLES           = 'rank-math-options-titles';
    private const RM_SITEMAP          = 'rank-math-options-sitemap';
    private const RM_INSTANT_INDEXING = 'rank-math-options-instant-indexing';

    // ── Rank Math redirections table (unprefixed; joined with $wpdb->prefix) ──
    private const RM_REDIRECTIONS_TABLE = 'rank_math_redirections';

    // ── SEOPulse redirections table (unprefixed; joined with $wpdb->prefix) ──
    private const SEOPULSE_REDIRECTS_TABLE = 'seopulse_redirects';

    /** Rank Math header codes worth preserving as-is (others fall back to 301) */
    private const RM_REDIRECT_CODES = ['301', '302', '307', '410', '451'];

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
    private const RM_META_OG_IMG_ID = 'rank_math_facebook_image_id';
    private const RM_META_TW_TITLE  = 'rank_math_twitter_title';
    private const RM_META_TW_DESC   = 'rank_math_twitter_description';
    private const RM_META_TW_IMG    = 'rank_math_twitter_image';
    private const RM_META_TW_IMG_ID = 'rank_math_twitter_image_id';
    private const RM_META_TW_CARD   = 'rank_math_twitter_card_type';
    private const RM_META_PRIMARY   = 'rank_math_primary_category';

    /** WooCommerce product variant of RM_META_PRIMARY; Rank Math stores the two under different keys */
    private const RM_META_PRIMARY_PRODUCT = 'rank_math_primary_product_cat';

    /** Allowed robots directives */
    private const ALLOWED_ROBOTS = ['index', 'noindex', 'follow', 'nofollow', 'noimageindex', 'noarchive', 'nosnippet'];

    /**
     * Rank Math %tag% => SEOPulse {{tag}} placeholder translation, applied regardless of context.
     *
     * @var array<string, string>
     */
    private const TAG_MAP_COMMON = [
        '%sep%'          => '{{sep}}',
        '%sitename%'     => '{{site.name}}',
        '%sitedesc%'     => '{{site.tagline}}',
        '%currentyear%'  => '{{date.current_year}}',
        '%currentmonth%' => '{{date.current_month}}',
        '%currentday%'   => '{{date.current_day}}',
        '%currenttime%'  => '{{date.current_time}}',
        '%currentdate%'  => '{{date.current_date}}',
    ];

    /** Rank Math post-context tags, only relevant for post title/description templates */
    private const TAG_MAP_POST = [
        '%title%'            => '{{post.title}}',
        '%seo_title%'        => '{{post.title}}',
        '%excerpt%'          => '{{post.excerpt}}',
        '%excerpt_only%'     => '{{post.excerpt}}',
        '%category%'         => '{{post.category}}',
        '%primary_category%' => '{{post.category}}',
        '%tag%'              => '{{post.categories}}',
        '%author%'           => '{{post.author}}',
        '%date%'             => '{{post.date}}',
        '%modified%'         => '{{post.modified}}',
        '%id%'               => '{{post.id}}',
    ];

    /** Rank Math term-context tags, only relevant for taxonomy title/description templates */
    private const TAG_MAP_TERM = [
        '%term%'              => '{{taxonomy.name}}',
        '%term_title%'        => '{{taxonomy.name}}',
        '%term_description%'  => '{{taxonomy.description}}',
        '%count%'             => '{{taxonomy.count}}',
    ];

    // ── Cached option values (populated lazily, see getters below) ──
    /** @var array<string, mixed>|null */
    private ?array $titles = null;

    /** @var array<string, mixed>|null */
    private ?array $general = null;

    /** @var array<string, mixed>|null */
    private ?array $sitemap = null;

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
            self::RM_META_OG_IMG_ID,
            self::RM_META_TW_TITLE,
            self::RM_META_TW_DESC,
            self::RM_META_TW_IMG,
            self::RM_META_TW_IMG_ID,
            self::RM_META_TW_CARD,
            self::RM_META_FOCUS_KW,
            self::RM_META_CANONICAL,
            self::RM_META_ROBOTS,
            self::RM_META_SCORE,
            self::RM_META_PRIMARY,
            self::RM_META_PRIMARY_PRODUCT,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function scanGlobalOptions(): array
    {
        $modules = [];

        $titles = $this->getTitles();
        if (!empty($titles)) {
            $modules['meta_seo_global'] = count($titles);
        }

        $general = $this->getGeneral();
        if (!empty($general)) {
            $verif_count = count(array_filter(
                ['google_verify', 'bing_verify', 'baidu_verify', 'yandex_verify', 'pinterest_verify'],
                static fn (string $k): bool => !empty($general[$k]),
            ));

            if ($verif_count > 0) {
                $modules['verification'] = $verif_count;
            }
        }

        $sitemap = $this->getSitemap();
        if (!empty($sitemap)) {
            $modules['sitemap'] = count($sitemap);
        }

        $archive_keys  = ['author_archive_title', 'date_archive_title', 'search_title', '404_title'];
        $archive_count = count(array_filter($archive_keys, static fn (string $k): bool => !empty($titles[$k] ?? '')));
        if ($archive_count > 0) {
            $modules['archive_settings'] = $archive_count;
        }

        if (!empty($titles['knowledgegraph_name'] ?? '') || !empty($titles['social_url_facebook'] ?? '') || !empty($titles['twitter_author_names'] ?? '')) {
            $modules['local_seo'] = 1;
        }

        $instant_indexing = get_option(self::RM_INSTANT_INDEXING, []);
        if (is_array($instant_indexing) && !empty($instant_indexing['indexnow_api_key'] ?? '')) {
            $modules['indexing'] = 1;
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
        $this->importRedirections($result, $overwrite);
        $this->importArchiveSettings($result, $overwrite);
        $this->importOrganizationSettings($result, $overwrite);
        $this->importIndexNowSettings($result, $overwrite);
    }

    /**
     * {@inheritDoc}
     */
    protected function importSitemapSettings(array &$result, bool $overwrite): void
    {
        $rm_sitemap = $this->getSitemap();

        if (empty($rm_sitemap)) {
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

        // Include images in sitemap (Rank Math: include_images, on/off)
        $mapped['include_images'] = (($rm_sitemap['include_images'] ?? 'on') === 'on') ? 1 : 0;

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

        // ── Primary category (regular post types use rank_math_primary_category;
        // WooCommerce products use the dedicated rank_math_primary_product_cat key) ──
        $rm_primary = $this->extractMeta($all_meta, self::RM_META_PRIMARY);
        if ($rm_primary === '') {
            $rm_primary = $this->extractMeta($all_meta, self::RM_META_PRIMARY_PRODUCT);
        }
        if ($rm_primary !== '') {
            $imported += $this->importPrimaryCategory($post_id, (int) $rm_primary, $overwrite);
        }

        // Note: Redirections are NOT imported here. Unlike the rest of this method,
        // Rank Math redirects aren't post meta at all — they live in the dedicated
        // wp_rank_math_redirections table and are independent, URL-pattern-based
        // rules with no 1:1 relationship to a post. See importRedirections(), called
        // once from importGlobalOptions() instead of per post.

        return $imported;
    }

    /**
     * Imports redirection rules from Rank Math's dedicated {@see self::RM_REDIRECTIONS_TABLE} table
     * into SEOPulse's own dedicated {@see self::SEOPULSE_REDIRECTS_TABLE} table.
     *
     * Unlike Yoast, Rank Math's Redirections module (incl. the 404 Monitor) ships in the
     * FREE version, so this is not a legitimate Pro-only gap. Confirmed against a live
     * install: redirects aren't stored in post meta at all, they live in two dedicated
     * tables —
     *   wp_rank_math_redirections       rule definitions: id, sources (serialized array
     *                                    of source pattern(s) + comparison method —
     *                                    exact/contains/start/end/regex/...), url_to,
     *                                    header_code (301/302/307/410/451), hits,
     *                                    status (active/inactive), created, updated
     *   wp_rank_math_redirections_cache runtime from_url -> redirection_id lookup index,
     *                                    rebuilt by Rank Math itself — not migrated.
     *
     * Only 'active' rules are imported (inactive ones are dormant in Rank Math too, so
     * skipping them changes nothing observable on the site). A single Rank Math rule can
     * hold several source patterns; each one becomes its own row in wp_seopulse_redirects.
     *
     * Conflicts are resolved per source_url rather than by truncating the whole
     * seopulse_redirects table: if a row already exists for a given source_url, it's left
     * untouched unless $overwrite is true, in which case only that row's target/type/match
     * fields are updated in place — other existing, non-conflicting rows (and fields such
     * as group_name, category, description, hits or last_accessed on the updated row) are
     * always kept.
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importRedirections(array &$result, bool $overwrite): void
    {
        global $wpdb;

        $rm_table = $wpdb->prefix . self::RM_REDIRECTIONS_TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $rm_table)) !== $rm_table) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT sources, url_to, header_code FROM {$rm_table} WHERE status = 'active'",
            ARRAY_A,
        );

        if (empty($rows)) {
            return;
        }

        $seopulse_table = $wpdb->prefix . self::SEOPULSE_REDIRECTS_TABLE;

        // Index existing SEOPulse redirects by source_url for O(1) conflict lookups
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_results("SELECT id, source_url FROM {$seopulse_table}", ARRAY_A);

        $by_source = [];
        foreach ((array) $existing as $existing_row) {
            $by_source[$existing_row['source_url']] = (int) $existing_row['id'];
        }

        $imported_count = 0;
        $skipped_count  = 0;
        $now            = current_time('mysql');

        foreach ($rows as $row) {
            $sources = maybe_unserialize($row['sources']);
            if (!is_array($sources)) {
                continue;
            }

            $target = esc_url_raw((string) $row['url_to']);
            $type   = in_array((string) $row['header_code'], self::RM_REDIRECT_CODES, true)
                ? (int) $row['header_code']
                : 301;

            foreach ($sources as $source_entry) {
                $pattern = is_array($source_entry) ? trim((string) ($source_entry['pattern'] ?? '')) : '';
                if ($pattern === '') {
                    continue;
                }

                $match_type = is_array($source_entry) ? (string) ($source_entry['comparison'] ?? 'exact') : 'exact';
                $is_regex   = ($match_type === 'regex') ? 1 : 0;

                if (isset($by_source[$pattern])) {
                    if (!$overwrite) {
                        ++$skipped_count;
                        continue;
                    }

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->update(
                        $seopulse_table,
                        [
                            'target_url'    => $target,
                            'redirect_type' => $type,
                            'regex'         => $is_regex,
                            'match_type'    => $match_type,
                            'status'        => 'active',
                            'updated_at'    => $now,
                        ],
                        ['id' => $by_source[$pattern]],
                        ['%s', '%d', '%d', '%s', '%s', '%s'],
                        ['%d'],
                    );
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->insert(
                        $seopulse_table,
                        [
                            'source_url'         => $pattern,
                            'target_url'         => $target,
                            'redirect_type'      => $type,
                            'regex'              => $is_regex,
                            'match_type'         => $match_type,
                            'ignore_case'        => 1,
                            'group_name'         => '',
                            'category'           => '',
                            'pass_query_string'  => 1,
                            'status'             => 'active',
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ],
                        ['%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s'],
                    );

                    $by_source[$pattern] = (int) $wpdb->insert_id;
                }

                ++$imported_count;
            }
        }

        if ($imported_count === 0) {
            if ($skipped_count > 0) {
                $result['warnings'][] = sprintf(
                    /* translators: %d: number of redirection rules skipped */
                    __('%d Rank Math redirection(s) already exist in SEOPulse — skipped (overwrite disabled).', 'seopulse'),
                    $skipped_count,
                );
            }

            return;
        }

        $result['options_imported'][] = 'redirections';
        $result['warnings'][]         = sprintf(
            /* translators: %d: number of redirection rules imported */
            __('%d Rank Math redirection(s) imported.', 'seopulse'),
            $imported_count,
        );

        if ($skipped_count > 0) {
            $result['warnings'][] = sprintf(
                /* translators: %d: number of redirection rules skipped */
                __('%d Rank Math redirection(s) already exist in SEOPulse — skipped (overwrite disabled).', 'seopulse'),
                $skipped_count,
            );
        }
    }

    /**
     * Imports author/date/search/404 archive settings into Options::ARCHIVE_SETTINGS.
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importArchiveSettings(array &$result, bool $overwrite): void
    {
        $rm_titles = $this->getTitles();

        if (empty($rm_titles)) {
            return;
        }

        $current = (array) get_option(Options::ARCHIVE_SETTINGS, []);

        if (!empty($current) && !$overwrite) {
            $result['warnings'][] = __('Archive settings already configured — skipped (overwrite disabled).', 'seopulse');

            return;
        }

        $new = [];

        // ── Author archives ──
        $author_title = $this->translateTags((string) ($rm_titles['author_archive_title'] ?? ''), 'post');
        if ($author_title !== '') {
            $new['author']['title'] = $author_title;
        }

        $author_desc = $this->translateTags((string) ($rm_titles['author_archive_description'] ?? ''), 'post');
        if ($author_desc !== '') {
            $new['author']['description'] = $author_desc;
        }

        if (($rm_titles['disable_author_archives'] ?? '') === 'on') {
            $new['author']['disable_archives'] = true;
        }

        $author_robots = $rm_titles['author_robots'] ?? [];
        if (($rm_titles['author_custom_robots'] ?? '') === 'on' && is_array($author_robots)) {
            $new['author']['robots'] = in_array('noindex', $author_robots, true) ? 'noindex,follow' : 'index,follow';
        }

        // ── Date archives ──
        $date_title = $this->translateTags((string) ($rm_titles['date_archive_title'] ?? ''), 'post');
        if ($date_title !== '') {
            // Rank Math only has a single date archive title template (no year/month/day split)
            $new['date']['title_year']  = $date_title;
            $new['date']['title_month'] = $date_title;
            $new['date']['title_day']   = $date_title;
        }

        $date_desc = $this->translateTags((string) ($rm_titles['date_archive_description'] ?? ''), 'post');
        if ($date_desc !== '') {
            $new['date']['description'] = $date_desc;
        }

        if (($rm_titles['disable_date_archives'] ?? '') === 'on') {
            $new['date']['disable_archives'] = true;
        }

        $date_robots = $rm_titles['date_archive_robots'] ?? [];
        if (is_array($date_robots) && in_array('noindex', $date_robots, true)) {
            $new['date']['robots'] = 'noindex,follow';
        }

        // ── Search results ──
        $search_title = $this->translateTags((string) ($rm_titles['search_title'] ?? ''), 'post');
        if ($search_title !== '') {
            $new['search']['title'] = $search_title;
        }

        if (($rm_titles['noindex_search'] ?? '') === 'on') {
            $new['search']['robots'] = 'noindex,follow';
        }

        // ── 404 ──
        $title_404 = $this->translateTags((string) ($rm_titles['404_title'] ?? ''), 'post');
        if ($title_404 !== '') {
            $new['error_404']['title'] = $title_404;
        }

        if (empty($new)) {
            return;
        }

        update_option(Options::ARCHIVE_SETTINGS, $overwrite ? $new : array_replace_recursive($current, $new));
        $result['options_imported'][] = 'archive_settings';
    }

    /**
     * Imports the site Organization/Knowledge Graph (name, logo, social profiles)
     * into Options::LOCAL_SEO, going through LocalSeoValidator so the stored shape
     * always matches what the Local SEO settings UI itself produces.
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importOrganizationSettings(array &$result, bool $overwrite): void
    {
        $rm_titles = $this->getTitles();

        if (empty($rm_titles)) {
            return;
        }

        $current = (array) get_option(Options::LOCAL_SEO, []);

        if (!empty($current) && !$overwrite) {
            $result['warnings'][] = __('Local SEO / Organization settings already configured — skipped (overwrite disabled).', 'seopulse');

            return;
        }

        $raw = [];

        $type_map    = ['company' => 'Organization', 'person' => 'Person'];
        $raw['type'] = $type_map[(string) ($rm_titles['knowledgegraph_type'] ?? '')] ?? 'Organization';

        $name = (string) ($rm_titles['knowledgegraph_name'] ?? $rm_titles['website_name'] ?? '');
        if ($name !== '') {
            $raw['name'] = $this->translateTags($name, 'global');
        }

        $alt_name = (string) ($rm_titles['website_alternate_name'] ?? '');
        if ($alt_name !== '') {
            $raw['alternateName'] = $this->translateTags($alt_name, 'global');
        }

        $logo = (string) ($rm_titles['knowledgegraph_logo'] ?? $rm_titles['homepage_facebook_image'] ?? $rm_titles['open_graph_image'] ?? '');
        if ($logo !== '') {
            $raw['logo'] = $logo;
        }

        // Social profiles (sameAs)
        $same_as = [];

        $facebook_url = (string) ($rm_titles['social_url_facebook'] ?? '');
        if ($facebook_url !== '') {
            $same_as[] = $facebook_url;
        }

        $twitter_handle = (string) ($rm_titles['twitter_author_names'] ?? '');
        if ($twitter_handle !== '') {
            $same_as[] = 'https://twitter.com/' . ltrim($twitter_handle, '@');
        }

        if (!empty($same_as)) {
            $raw['sameAs'] = $same_as;
        }

        // Rank Math's Person/Organization distinction has no further SEOPulse equivalent beyond @type
        if (($rm_titles['knowledgegraph_type'] ?? '') === 'person') {
            $result['warnings'][] = __('Rank Math Knowledge Graph was set to "Person" — imported as Schema type Person.', 'seopulse');
        }

        if (count($raw) <= 1) { // only 'type' was set, nothing meaningful to import
            return;
        }

        $sanitized = LocalSeoValidator::sanitize_settings($raw);

        update_option(Options::LOCAL_SEO, $overwrite ? $sanitized : array_merge($current, $sanitized));
        $result['options_imported'][] = 'local_seo';
    }

    /**
     * Imports the IndexNow API key from Rank Math's Instant Indexing module
     * into Options::INDEXING.
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importIndexNowSettings(array &$result, bool $overwrite): void
    {
        $rm_instant = get_option(self::RM_INSTANT_INDEXING, []);
        $api_key    = is_array($rm_instant) ? (string) ($rm_instant['indexnow_api_key'] ?? '') : '';

        if ($api_key === '') {
            return;
        }

        $current = (array) get_option(Options::INDEXING, []);

        if (!empty($current['indexnow_key']) && !$overwrite) {
            $result['warnings'][] = __('IndexNow API key already configured — skipped (overwrite disabled).', 'seopulse');

            return;
        }

        $current['indexnow_key']     = sanitize_text_field($api_key);
        $current['indexnow_enabled'] = true;

        update_option(Options::INDEXING, $current);
        $result['options_imported'][] = 'indexing';
    }

    // ──────────────────────────────────────────────
    // CACHED OPTION GETTERS
    // ──────────────────────────────────────────────

    /**
     * Returns the rank-math-options-titles option, fetched once and cached
     * for the lifetime of this migrator instance.
     *
     * Used by both scanGlobalOptions() and importMetaSeoGlobal().
     *
     * @return array<string, mixed>
     */
    private function getTitles(): array
    {
        if ($this->titles === null) {
            $titles       = get_option(self::RM_TITLES, []);
            $this->titles = is_array($titles) ? $titles : [];
        }

        return $this->titles;
    }

    /**
     * Returns the rank-math-options-general option, fetched once and cached
     * for the lifetime of this migrator instance.
     *
     * Used by both scanGlobalOptions() and importRankMathVerificationCodes().
     *
     * @return array<string, mixed>
     */
    private function getGeneral(): array
    {
        if ($this->general === null) {
            $general       = get_option(self::RM_GENERAL, []);
            $this->general = is_array($general) ? $general : [];
        }

        return $this->general;
    }

    /**
     * Returns the rank-math-options-sitemap option, fetched once and cached
     * for the lifetime of this migrator instance.
     *
     * Used by both scanGlobalOptions() and importSitemapSettings().
     *
     * @return array<string, mixed>
     */
    private function getSitemap(): array
    {
        if ($this->sitemap === null) {
            $sitemap       = get_option(self::RM_SITEMAP, []);
            $this->sitemap = is_array($sitemap) ? $sitemap : [];
        }

        return $this->sitemap;
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
        $rm_titles = $this->getTitles();

        if (empty($rm_titles)) {
            return;
        }

        $mapped = [];

        // Home title & description (Rank Math stores these using its own %tag% placeholder syntax)
        $mapped['title']       = $this->translateTags((string) ($rm_titles['homepage_title'] ?? ''), 'global');
        $mapped['description'] = $this->translateTags((string) ($rm_titles['homepage_description'] ?? ''), 'global');

        // Open Graph
        $mapped['og_title']       = $this->translateTags((string) ($rm_titles['homepage_facebook_title'] ?? ''), 'global');
        $mapped['og_description'] = $this->translateTags((string) ($rm_titles['homepage_facebook_description'] ?? ''), 'global');
        $mapped['og_image']       = (string) ($rm_titles['homepage_facebook_image'] ?? $rm_titles['open_graph_image'] ?? '');
        $mapped['og_type']        = 'website';

        $site_name = (string) ($rm_titles['knowledgegraph_name'] ?? '');
        if ($site_name !== '') {
            $mapped['og_site_name'] = $site_name;
        }

        // Twitter
        $mapped['twitter_card']        = (string) ($rm_titles['twitter_card_type'] ?? 'summary_large_image');
        $mapped['twitter_title']       = $this->translateTags((string) ($rm_titles['homepage_twitter_title'] ?? ''), 'global');
        $mapped['twitter_description'] = $this->translateTags((string) ($rm_titles['homepage_twitter_description'] ?? ''), 'global');

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
        $rm_general = $this->getGeneral();

        if (empty($rm_general)) {
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
            $seo_data['title'] = $this->translateTags(sanitize_text_field($title), 'post');
        }

        $desc = $this->extractMeta($all_meta, self::RM_META_DESC);
        if ($desc !== '') {
            $seo_data['description'] = $this->translateTags(sanitize_text_field($desc), 'post');
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

        $og_img = $this->extractImageUrl($all_meta, self::RM_META_OG_IMG, self::RM_META_OG_IMG_ID);
        if ($og_img !== '') {
            $seo_data['og_image'] = esc_url_raw($og_img);
        }

        // Twitter — Rank Math's "Use Facebook data" default means the Twitter-specific
        // fields are often left blank on purpose; its own frontend then falls back to
        // the Facebook/OG fields when rendering the Twitter Card, so we mirror that
        // here rather than dropping the Twitter tags entirely.
        $tw_title = $this->extractMeta($all_meta, self::RM_META_TW_TITLE);
        if ($tw_title === '') {
            $tw_title = $og_title;
        }
        if ($tw_title !== '') {
            $seo_data['twitter_title'] = sanitize_text_field($tw_title);
        }

        $tw_desc = $this->extractMeta($all_meta, self::RM_META_TW_DESC);
        if ($tw_desc === '') {
            $tw_desc = $og_desc;
        }
        if ($tw_desc !== '') {
            $seo_data['twitter_description'] = sanitize_text_field($tw_desc);
        }

        $tw_img = $this->extractImageUrl($all_meta, self::RM_META_TW_IMG, self::RM_META_TW_IMG_ID);
        if ($tw_img === '') {
            $tw_img = $og_img;
        }
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

    /**
     * Translates Rank Math's %tag% placeholders into SEOPulse's {{namespace.tag}} syntax.
     *
     * Rank Math and SEOPulse use incompatible template placeholder formats. Without this
     * translation, imported titles/descriptions containing e.g. "%title% %sep% %sitename%"
     * would be stored and rendered verbatim instead of being resolved by the Meta SEO engine.
     *
     * @param string $value Raw Rank Math template string
     * @param string $context 'post', 'term', or 'global' (only common tags apply to 'global')
     * @return string Translated value, or '' if $value was empty
     */
    private function translateTags(string $value, string $context = 'post'): string
    {
        if ($value === '') {
            return '';
        }

        $map = self::TAG_MAP_COMMON + match ($context) {
            'post'  => self::TAG_MAP_POST,
            'term'  => self::TAG_MAP_TERM,
            default => [],
        };

        return strtr($value, $map);
    }

    /**
     * Resolves an image URL from meta, falling back to the companion
     * attachment-ID meta key when no direct URL was stored (e.g. an image
     * picked from the media library rather than pasted as a URL).
     *
     * @param array<string, array<int, mixed>> $all_meta
     * @param string $url_key Meta key holding the direct URL
     * @param string $id_key Meta key holding the attachment ID
     * @return string
     */
    private function extractImageUrl(array $all_meta, string $url_key, string $id_key): string
    {
        $url = $this->extractMeta($all_meta, $url_key);

        if ($url === '') {
            $attachment_id = (int) $this->extractMeta($all_meta, $id_key);
            if ($attachment_id > 0) {
                $url = (string) wp_get_attachment_url($attachment_id);
            }
        }

        return $url;
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
            self::RM_META_OG_IMG_ID,
            self::RM_META_TW_TITLE,
            self::RM_META_TW_DESC,
            self::RM_META_TW_IMG,
            self::RM_META_TW_IMG_ID,
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
            $data['title'] = $this->translateTags(sanitize_text_field($title), 'term');
        }

        $desc = $this->extractMeta($all_meta, self::RM_META_DESC);
        if ($desc !== '') {
            $data['description'] = $this->translateTags(sanitize_text_field($desc), 'term');
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

        $og_img = $this->extractImageUrl($all_meta, self::RM_META_OG_IMG, self::RM_META_OG_IMG_ID);
        if ($og_img !== '') {
            $data['og_image'] = esc_url_raw($og_img);
        }

        // Twitter — same "Use Facebook data" fallback as buildSeoData()
        $tw_title = $this->extractMeta($all_meta, self::RM_META_TW_TITLE);
        if ($tw_title === '') {
            $tw_title = $og_title;
        }
        if ($tw_title !== '') {
            $data['twitter_title'] = sanitize_text_field($tw_title);
        }

        $tw_desc = $this->extractMeta($all_meta, self::RM_META_TW_DESC);
        if ($tw_desc === '') {
            $tw_desc = $og_desc;
        }
        if ($tw_desc !== '') {
            $data['twitter_description'] = sanitize_text_field($tw_desc);
        }

        $tw_img = $this->extractImageUrl($all_meta, self::RM_META_TW_IMG, self::RM_META_TW_IMG_ID);
        if ($tw_img === '') {
            $tw_img = $og_img;
        }
        if ($tw_img !== '') {
            $data['twitter_image'] = esc_url_raw($tw_img);
        }

        return $data;
    }
}
