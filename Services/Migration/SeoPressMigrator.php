<?php

/**
 * SEOPress to SEOPulse migration service
 *
 * Detects the presence of SEOPress, reads its options and post meta,
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
 * SeoPressMigrator class
 *
 * Extends AbstractMigrator to provide SEOPress-specific mapping logic.
 * Common detect/scan/import/batch operations are inherited from the parent.
 */
class SeoPressMigrator extends AbstractMigrator
{
    // ── SEOPress option keys ─────────────────────
    private const SP_TITLES    = 'seopress_titles_option_name';
    private const SP_SOCIAL    = 'seopress_social_option_name';
    private const SP_ANALYTICS = 'seopress_google_analytics_option_name';
    private const SP_ADVANCED  = 'seopress_advanced_option_name';
    private const SP_SITEMAP   = 'seopress_xml_sitemap_option_name';
    private const SP_TOGGLE    = 'seopress_toggle';

    // ── SEOPress post meta keys ──────────────────
    private const SP_META_TITLE         = '_seopress_titles_title';
    private const SP_META_DESC          = '_seopress_titles_desc';
    private const SP_META_CANONICAL     = '_seopress_robots_canonical';
    private const SP_META_NOINDEX       = '_seopress_robots_index';
    private const SP_META_NOFOLLOW      = '_seopress_robots_follow';
    private const SP_META_OG_TITLE      = '_seopress_social_fb_title';
    private const SP_META_OG_DESC       = '_seopress_social_fb_desc';
    private const SP_META_OG_IMG        = '_seopress_social_fb_img';
    private const SP_META_TW_TITLE      = '_seopress_social_twitter_title';
    private const SP_META_TW_DESC       = '_seopress_social_twitter_desc';
    private const SP_META_TW_IMG        = '_seopress_social_twitter_img';
    private const SP_META_FOCUS_KW      = '_seopress_analysis_target_kw';
    private const SP_META_REDIRECT_ON   = '_seopress_redirections_enabled';
    private const SP_META_REDIRECT_URL  = '_seopress_redirections_value';
    private const SP_META_REDIRECT_TYPE = '_seopress_redirections_type';
    private const SP_META_NOIMAGE       = '_seopress_robots_imageindex';
    private const SP_META_NOSNIPPET     = '_seopress_robots_snippet';
    private const SP_META_NOARCHIVE     = '_seopress_robots_archive';
    private const SP_META_PRIMARY_CAT   = '_seopress_robots_primary_cat';

    // ──────────────────────────────────────────────
    // ABSTRACT IMPLEMENTATIONS
    // ──────────────────────────────────────────────

    /**
     * {@inheritDoc}
     */
    protected function getPluginSlugs(): array
    {
        return [
            'wp-seopress/seopress.php',
            'wp-seopress-pro/seopress-pro.php',
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getSourceOptionKeys(): array
    {
        return [self::SP_TITLES, self::SP_SOCIAL, self::SP_ANALYTICS];
    }

    /**
     * {@inheritDoc}
     */
    protected function getVersionConstant(): ?string
    {
        return 'SEOPRESS_VERSION';
    }

    /**
     * {@inheritDoc}
     */
    protected function getPluginFilePath(): string
    {
        return '/wp-seopress/seopress.php';
    }

    /**
     * {@inheritDoc}
     */
    protected function getScannableMetaKeys(): array
    {
        return [
            self::SP_META_TITLE,
            self::SP_META_DESC,
            self::SP_META_OG_TITLE,
            self::SP_META_OG_DESC,
            self::SP_META_OG_IMG,
            self::SP_META_TW_TITLE,
            self::SP_META_TW_DESC,
            self::SP_META_TW_IMG,
            self::SP_META_FOCUS_KW,
            self::SP_META_CANONICAL,
            self::SP_META_NOINDEX,
            self::SP_META_NOFOLLOW,
            self::SP_META_REDIRECT_ON,
            self::SP_META_REDIRECT_URL,
            self::SP_META_REDIRECT_TYPE,
            self::SP_META_NOIMAGE,
            self::SP_META_NOSNIPPET,
            self::SP_META_NOARCHIVE,
            self::SP_META_PRIMARY_CAT,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function scanGlobalOptions(): array
    {
        $modules = [];

        $titles = get_option(self::SP_TITLES, []);
        if (!empty($titles) && is_array($titles)) {
            $modules['meta_seo_global'] = count($titles);
        }

        $social = get_option(self::SP_SOCIAL, []);
        if (!empty($social) && is_array($social)) {
            $modules['social'] = count($social);
        }

        $analytics = get_option(self::SP_ANALYTICS, []);
        if (!empty($analytics) && is_array($analytics)) {
            $modules['tracking'] = count($analytics);
        }

        $sitemap = get_option(self::SP_SITEMAP, []);
        if (!empty($sitemap) && is_array($sitemap)) {
            $modules['sitemap'] = count($sitemap);
        }

        $advanced = get_option(self::SP_ADVANCED, []);
        if (!empty($advanced) && is_array($advanced)) {
            $verif_count = count(array_filter(
                [
                    'seopress_advanced_advanced_google',
                    'seopress_advanced_advanced_bing',
                    'seopress_advanced_advanced_baidu',
                    'seopress_advanced_advanced_yandex',
                ],
                static fn (string $k): bool => !empty($advanced[ $k ]),
            ));

            if ($verif_count > 0) {
                $modules['verification'] = $verif_count;
            }
        }

        return $modules;
    }

    /**
     * {@inheritDoc}
     */
    protected function importGlobalOptions(array &$result, bool $overwrite): void
    {
        $this->importMetaSeoGlobal($result, $overwrite);
        $this->importTracking($result, $overwrite);
        $this->importSeopressVerificationCodes($result, $overwrite);
    }

    /**
     * {@inheritDoc}
     */
    protected function importSitemapSettings(array &$result, bool $overwrite): void
    {
        $sp_sitemap = get_option(self::SP_SITEMAP, []);

        if (empty($sp_sitemap) || !is_array($sp_sitemap)) {
            return;
        }

        $current = get_option(Options::SITEMAP, []);

        if (!empty($current) && !$overwrite) {
            $result['warnings'][] = __('Sitemap settings already configured — skipped (overwrite disabled).', 'seopulse');

            return;
        }

        $mapped = [];

        $mapped['enable_images'] = ($sp_sitemap['seopress_xml_sitemap_img_enable'] ?? '') === '1' ? 1 : 0;

        $post_types_list = $sp_sitemap['seopress_xml_sitemap_post_types_list'] ?? [];
        if (is_array($post_types_list)) {
            foreach ($post_types_list as $cpt_key => $cpt_data) {
                $mapped[ 'enable_' . $cpt_key ] = (isset($cpt_data['include']) && $cpt_data['include'] === '1') ? 1 : 0;
            }
        }

        $tax_list = $sp_sitemap['seopress_xml_sitemap_taxonomies_list'] ?? [];
        if (is_array($tax_list)) {
            foreach ($tax_list as $tax_key => $tax_data) {
                $mapped[ 'enable_' . $tax_key ] = (isset($tax_data['include']) && $tax_data['include'] === '1') ? 1 : 0;
            }
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

        // ── Focus keyword ──
        $sp_kw = $this->extractMeta($all_meta, self::SP_META_FOCUS_KW);
        if ($sp_kw !== '') {
            // SEOPress may store comma-separated keywords
            $keywords  = array_map('trim', explode(',', $sp_kw));
            $primary   = $keywords[0] ?? '';
            $imported += $this->importFocusKeyword($post_id, $primary, $overwrite);

            $secondary = array_slice($keywords, 1);
            if (!empty($secondary)) {
                $imported += $this->importFocusKeywords($post_id, $secondary, $overwrite);
            }
        }

        // ── Meta SEO (title, desc, OG, Twitter, canonical, robots) ──
        $seo_data  = $this->buildSeoData($all_meta);
        $imported += $this->importSeoMetaData($post_id, $seo_data, $overwrite);

        // ── Primary category ──
        $sp_primary = $this->extractMeta($all_meta, self::SP_META_PRIMARY_CAT);
        if ($sp_primary !== '') {
            $imported += $this->importPrimaryCategory($post_id, (int) $sp_primary, $overwrite);
        }

        // ── Redirections ──
        if ($this->extractMeta($all_meta, self::SP_META_REDIRECT_ON) === 'yes') {
            $redir_url  = $this->extractMeta($all_meta, self::SP_META_REDIRECT_URL);
            $redir_type = $this->extractMeta($all_meta, self::SP_META_REDIRECT_TYPE);
            $imported  += $this->importRedirection($post_id, $redir_url, $redir_type, $overwrite);
        }

        return $imported;
    }

    // ──────────────────────────────────────────────
    // SEOPRESS-SPECIFIC PRIVATE METHODS
    // ──────────────────────────────────────────────

    /**
     * Imports global SEO meta from SEOPress (titles, OG, Twitter, robots)
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importMetaSeoGlobal(array &$result, bool $overwrite): void
    {
        $sp_titles = get_option(self::SP_TITLES, []);
        $sp_social = get_option(self::SP_SOCIAL, []);

        if (empty($sp_titles) && empty($sp_social)) {
            return;
        }

        $mapped = [];

        // Titles & description
        if (is_array($sp_titles)) {
            $mapped['title']       = (string) ($sp_titles['seopress_titles_home_site_title'] ?? '');
            $mapped['description'] = (string) ($sp_titles['seopress_titles_home_site_desc'] ?? '');
        }

        // Open Graph & Twitter
        if (is_array($sp_social)) {
            $mapped['og_title']       = (string) ($sp_social['seopress_social_facebook_og_title'] ?? '');
            $mapped['og_description'] = (string) ($sp_social['seopress_social_facebook_og_desc'] ?? '');
            $mapped['og_url']         = (string) ($sp_social['seopress_social_facebook_og_url'] ?? '');
            $mapped['og_image']       = (string) ($sp_social['seopress_social_facebook_og_img'] ?? '');
            $mapped['og_type']        = 'website';
            $mapped['og_site_name']   = (string) ($sp_social['seopress_social_knowledge_name'] ?? get_bloginfo('name'));

            if (($sp_social['seopress_social_facebook_og'] ?? '') !== '1') {
                $result['warnings'][] = __('Open Graph was disabled in SEOPress.', 'seopulse');
            }

            $mapped['twitter_card']        = $this->mapTwitterCardType((string) ($sp_social['seopress_social_twitter_card'] ?? ''));
            $mapped['twitter_title']       = (string) ($sp_social['seopress_social_twitter_card_title'] ?? '');
            $mapped['twitter_description'] = (string) ($sp_social['seopress_social_twitter_card_desc'] ?? '');
            $mapped['twitter_image']       = (string) ($sp_social['seopress_social_twitter_card_img'] ?? '');
            $mapped['twitter_site']        = (string) ($sp_social['seopress_social_accounts_twitter'] ?? '');
        }

        // Robots — only write when at least one directive is explicitly set
        if (is_array($sp_titles)) {
            $noindex  = !empty($sp_titles['seopress_titles_noindex']);
            $nofollow = !empty($sp_titles['seopress_titles_nofollow']);

            if ($noindex || $nofollow) {
                $mapped['robots'] = implode(',', [
                    $noindex ? 'noindex' : 'index',
                    $nofollow ? 'nofollow' : 'follow',
                ]);
            }

            $sep = (string) ($sp_titles['seopress_titles_sep'] ?? '');
            if ($sep !== '') {
                $result['warnings'][] = sprintf(
                    /* translators: %s: separator character */
                    __('SEOPress title separator "%s" noted but SEOPulse does not use a global separator.', 'seopulse'),
                    $sep,
                );
            }
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
     * Imports tracking codes (GA4 / GTM) from SEOPress
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importTracking(array &$result, bool $overwrite): void
    {
        $sp_ga = get_option(self::SP_ANALYTICS, []);

        if (empty($sp_ga) || !is_array($sp_ga)) {
            return;
        }

        $ga4_id = (string) ($sp_ga['seopress_google_analytics_ga4'] ?? '');

        if ($ga4_id === '') {
            return;
        }

        $current = get_option(Options::ANALYTICS, []);

        if (!empty($current['ga4_id']) && !$overwrite) {
            $result['warnings'][] = __('GA4 tracking ID already configured — skipped.', 'seopulse');

            return;
        }

        $current['ga4_id']      = sanitize_text_field($ga4_id);
        $current['ga4_enabled'] = true;

        update_option(Options::ANALYTICS, $current);

        if (!in_array('tracking', $result['options_imported'], true)) {
            $result['options_imported'][] = 'tracking';
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

        $title = $this->extractMeta($all_meta, self::SP_META_TITLE);
        if ($title !== '') {
            $seo_data['title'] = sanitize_text_field($title);
        }

        $desc = $this->extractMeta($all_meta, self::SP_META_DESC);
        if ($desc !== '') {
            $seo_data['description'] = sanitize_text_field($desc);
        }

        $canonical = $this->extractMeta($all_meta, self::SP_META_CANONICAL);
        if ($canonical !== '') {
            $seo_data['canonical'] = esc_url_raw($canonical);
        }

        // Robots — SEOPress uses 'yes' string values
        $sp_noindex  = $this->extractMeta($all_meta, self::SP_META_NOINDEX);
        $sp_nofollow = $this->extractMeta($all_meta, self::SP_META_NOFOLLOW);
        $sp_noimage  = $this->extractMeta($all_meta, self::SP_META_NOIMAGE);
        $sp_nosnip   = $this->extractMeta($all_meta, self::SP_META_NOSNIPPET);
        $sp_noarch   = $this->extractMeta($all_meta, self::SP_META_NOARCHIVE);

        if ($sp_noindex === 'yes' || $sp_nofollow === 'yes' || $sp_noimage === 'yes' || $sp_nosnip === 'yes' || $sp_noarch === 'yes') {
            $parts   = [];
            $parts[] = $sp_noindex === 'yes' ? 'noindex' : 'index';
            $parts[] = $sp_nofollow === 'yes' ? 'nofollow' : 'follow';

            if ($sp_noimage === 'yes') {
                $parts[] = 'noimageindex';
            }
            if ($sp_nosnip === 'yes') {
                $parts[] = 'nosnippet';
            }
            if ($sp_noarch === 'yes') {
                $parts[] = 'noarchive';
            }

            $seo_data['robots'] = implode(',', $parts);
        }

        // Open Graph
        $og_title = $this->extractMeta($all_meta, self::SP_META_OG_TITLE);
        if ($og_title !== '') {
            $seo_data['og_title'] = sanitize_text_field($og_title);
        }

        $og_desc = $this->extractMeta($all_meta, self::SP_META_OG_DESC);
        if ($og_desc !== '') {
            $seo_data['og_description'] = sanitize_text_field($og_desc);
        }

        $og_img = $this->extractMeta($all_meta, self::SP_META_OG_IMG);
        if ($og_img !== '') {
            $seo_data['og_image'] = esc_url_raw($og_img);
        }

        // Twitter
        $tw_title = $this->extractMeta($all_meta, self::SP_META_TW_TITLE);
        if ($tw_title !== '') {
            $seo_data['twitter_title'] = sanitize_text_field($tw_title);
        }

        $tw_desc = $this->extractMeta($all_meta, self::SP_META_TW_DESC);
        if ($tw_desc !== '') {
            $seo_data['twitter_description'] = sanitize_text_field($tw_desc);
        }

        $tw_img = $this->extractMeta($all_meta, self::SP_META_TW_IMG);
        if ($tw_img !== '') {
            $seo_data['twitter_image'] = esc_url_raw($tw_img);
        }

        return $seo_data;
    }

    /**
     * Maps the Twitter card type from SEOPress to SEOPulse
     *
     * @param string $sp_type SEOPress type
     * @return string SEOPulse type
     */
    private function mapTwitterCardType(string $sp_type): string
    {
        $map = [
            '1'                   => 'summary_large_image',
            '0'                   => 'summary',
            'summary'             => 'summary',
            'summary_large_image' => 'summary_large_image',
            'app'                 => 'app',
            'player'              => 'player',
        ];

        return $map[ $sp_type ] ?? 'summary_large_image';
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
        return isset($all_meta[ $key ][0]) ? (string) $all_meta[ $key ][0] : '';
    }

    // ──────────────────────────────────────────────
    // VERIFICATION CODES
    // ──────────────────────────────────────────────

    /**
     * Imports verification codes from SEOPress advanced option
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importSeopressVerificationCodes(array &$result, bool $overwrite): void
    {
        $sp_advanced = get_option(self::SP_ADVANCED, []);

        if (empty($sp_advanced) || !is_array($sp_advanced)) {
            return;
        }

        $codes_map = [
            'seopress_advanced_advanced_google' => 'google_verification',
            'seopress_advanced_advanced_bing'   => 'bing_verification',
            'seopress_advanced_advanced_baidu'  => 'baidu_verification',
            'seopress_advanced_advanced_yandex' => 'yandex_verification',
        ];

        $this->importVerificationCodes($codes_map, $sp_advanced, $overwrite, $result);
    }

    // ──────────────────────────────────────────────
    // TAXONOMY TERM META IMPORT
    // ──────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * SEOPress stores term meta with the same keys as post meta
     * in the termmeta table: _seopress_titles_title, _seopress_titles_desc,
     * _seopress_social_fb_*, _seopress_social_twitter_*, _seopress_robots_*, etc.
     */
    protected function importTermsMeta(array &$result, bool $overwrite): void
    {
        global $wpdb;

        $term_meta_keys = [
            self::SP_META_TITLE,
            self::SP_META_DESC,
            self::SP_META_CANONICAL,
            self::SP_META_NOINDEX,
            self::SP_META_NOFOLLOW,
            self::SP_META_OG_TITLE,
            self::SP_META_OG_DESC,
            self::SP_META_OG_IMG,
            self::SP_META_TW_TITLE,
            self::SP_META_TW_DESC,
            self::SP_META_TW_IMG,
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
     * Builds SEO data for a term from SEOPress term meta.
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

        $title = $this->extractMeta($all_meta, self::SP_META_TITLE);
        if ($title !== '') {
            $data['title'] = sanitize_text_field($title);
        }

        $desc = $this->extractMeta($all_meta, self::SP_META_DESC);
        if ($desc !== '') {
            $data['description'] = sanitize_text_field($desc);
        }

        $canonical = $this->extractMeta($all_meta, self::SP_META_CANONICAL);
        if ($canonical !== '') {
            $data['canonical'] = esc_url_raw($canonical);
        }

        // Robots
        $noindex  = $this->extractMeta($all_meta, self::SP_META_NOINDEX);
        $nofollow = $this->extractMeta($all_meta, self::SP_META_NOFOLLOW);
        if ($noindex === 'yes' || $nofollow === 'yes') {
            $data['robots'] = implode(',', [
                $noindex === 'yes' ? 'noindex' : 'index',
                $nofollow === 'yes' ? 'nofollow' : 'follow',
            ]);
        }

        // Open Graph
        $og_title = $this->extractMeta($all_meta, self::SP_META_OG_TITLE);
        if ($og_title !== '') {
            $data['og_title'] = sanitize_text_field($og_title);
        }

        $og_desc = $this->extractMeta($all_meta, self::SP_META_OG_DESC);
        if ($og_desc !== '') {
            $data['og_description'] = sanitize_text_field($og_desc);
        }

        $og_img = $this->extractMeta($all_meta, self::SP_META_OG_IMG);
        if ($og_img !== '') {
            $data['og_image'] = esc_url_raw($og_img);
        }

        // Twitter
        $tw_title = $this->extractMeta($all_meta, self::SP_META_TW_TITLE);
        if ($tw_title !== '') {
            $data['twitter_title'] = sanitize_text_field($tw_title);
        }

        $tw_desc = $this->extractMeta($all_meta, self::SP_META_TW_DESC);
        if ($tw_desc !== '') {
            $data['twitter_description'] = sanitize_text_field($tw_desc);
        }

        $tw_img = $this->extractMeta($all_meta, self::SP_META_TW_IMG);
        if ($tw_img !== '') {
            $data['twitter_image'] = esc_url_raw($tw_img);
        }

        return $data;
    }
}
