<?php

/**
 * Yoast SEO to SEOPulse migration service
 *
 * Detects the presence of Yoast SEO, reads its options and post meta,
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
 * YoastMigrator class
 *
 * Extends AbstractMigrator to provide Yoast-specific mapping logic.
 * Common detect/scan/import/batch operations are inherited from the parent.
 */
class YoastMigrator extends AbstractMigrator
{
    // ── Yoast option keys ────────────────────────
    private const YO_MAIN     = 'wpseo';
    private const YO_TITLES   = 'wpseo_titles';
    private const YO_SOCIAL   = 'wpseo_social';
    private const YO_TAX_META = 'wpseo_taxonomy_meta';

    // ── Yoast post meta keys ─────────────────────
    private const YO_META_FOCUS_KW    = '_yoast_wpseo_focuskw';
    private const YO_META_TITLE       = '_yoast_wpseo_title';
    private const YO_META_DESC        = '_yoast_wpseo_metadesc';
    private const YO_META_CANONICAL   = '_yoast_wpseo_canonical';
    private const YO_META_NOINDEX     = '_yoast_wpseo_meta-robots-noindex';
    private const YO_META_NOFOLLOW    = '_yoast_wpseo_meta-robots-nofollow';
    private const YO_META_ROBOTS_ADV  = '_yoast_wpseo_meta-robots-adv';
    private const YO_META_OG_TITLE    = '_yoast_wpseo_opengraph-title';
    private const YO_META_OG_DESC     = '_yoast_wpseo_opengraph-description';
    private const YO_META_OG_IMG      = '_yoast_wpseo_opengraph-image';
    private const YO_META_TW_TITLE    = '_yoast_wpseo_twitter-title';
    private const YO_META_TW_DESC     = '_yoast_wpseo_twitter-description';
    private const YO_META_TW_IMG      = '_yoast_wpseo_twitter-image';
    private const YO_META_REDIRECT    = '_yoast_wpseo_redirect';
    private const YO_META_LINKDEX     = '_yoast_wpseo_linkdex';
    private const YO_META_FOCUS_KWS   = '_yoast_wpseo_focuskeywords';
    private const YO_META_PRIMARY_CAT = '_yoast_wpseo_primary_category';
    private const YO_META_OG_IMG_ID   = '_yoast_wpseo_opengraph-image-id';

    /**
     * Yoast robots noindex values
     *
     * Yoast stores: '' or '0' = default (inherit), '1' = noindex, '2' = index (force)
     */
    private const YO_ROBOTS_DEFAULT = ['', '0'];
    private const YO_ROBOTS_NOINDEX = '1';
    private const YO_ROBOTS_INDEX   = '2';

    /** Allowed advanced robots directives */
    private const ALLOWED_ADV_ROBOTS = ['noimageindex', 'noarchive', 'nosnippet'];

    // ──────────────────────────────────────────────
    // ABSTRACT IMPLEMENTATIONS
    // ──────────────────────────────────────────────

    /**
     * {@inheritDoc}
     */
    protected function getPluginSlugs(): array
    {
        return [
            'wordpress-seo/wp-seo.php',
            'wordpress-seo-premium/wp-seo-premium.php',
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getSourceOptionKeys(): array
    {
        return [self::YO_MAIN, self::YO_TITLES, self::YO_SOCIAL];
    }

    /**
     * {@inheritDoc}
     */
    protected function getVersionConstant(): ?string
    {
        return 'WPSEO_VERSION';
    }

    /**
     * {@inheritDoc}
     */
    protected function getPluginFilePath(): string
    {
        return '/wordpress-seo/wp-seo.php';
    }

    /**
     * {@inheritDoc}
     */
    protected function getScannableMetaKeys(): array
    {
        return [
            self::YO_META_TITLE,
            self::YO_META_DESC,
            self::YO_META_OG_TITLE,
            self::YO_META_OG_DESC,
            self::YO_META_OG_IMG,
            self::YO_META_OG_IMG_ID,
            self::YO_META_TW_TITLE,
            self::YO_META_TW_DESC,
            self::YO_META_TW_IMG,
            self::YO_META_FOCUS_KW,
            self::YO_META_CANONICAL,
            self::YO_META_NOINDEX,
            self::YO_META_NOFOLLOW,
            self::YO_META_ROBOTS_ADV,
            self::YO_META_REDIRECT,
            self::YO_META_LINKDEX,
            self::YO_META_FOCUS_KWS,
            self::YO_META_PRIMARY_CAT,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function scanGlobalOptions(): array
    {
        $modules = [];

        $titles = get_option(self::YO_TITLES, []);
        if (!empty($titles) && is_array($titles)) {
            $modules['meta_seo_global'] = count($titles);
        }

        $social = get_option(self::YO_SOCIAL, []);
        if (!empty($social) && is_array($social)) {
            $modules['social'] = count($social);
        }

        $main = get_option(self::YO_MAIN, []);
        if (!empty($main) && is_array($main)) {
            if (!empty($main['enable_xml_sitemap'])) {
                $modules['sitemap'] = 1;
            }

            $verif_count = count(array_filter(
                ['googleverify', 'msverify', 'baiduverify', 'yandexverify'],
                static fn (string $k): bool => !empty($main[$k]),
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
        $this->importYoastVerificationCodes($result, $overwrite);
    }

    /**
     * {@inheritDoc}
     */
    protected function importSitemapSettings(array &$result, bool $overwrite): void
    {
        $yo_main = get_option(self::YO_MAIN, []);

        if (empty($yo_main['enable_xml_sitemap'])) {
            return;
        }

        $current = get_option(Options::SITEMAP, []);

        if (!empty($current) && !$overwrite) {
            $result['warnings'][] = __('Sitemap settings already configured — skipped (overwrite disabled).', 'seopulse');

            return;
        }

        $yo_titles = get_option(self::YO_TITLES, []);
        $mapped    = [];

        if (is_array($yo_titles)) {
            foreach (get_post_types(['public' => true], 'names') as $cpt) {
                $mapped['enable_' . $cpt] = empty($yo_titles['noindex-' . $cpt]) ? 1 : 0;
            }

            foreach (get_taxonomies(['public' => true], 'names') as $tax) {
                $mapped['enable_' . $tax] = empty($yo_titles['noindex-tax-' . $tax]) ? 1 : 0;
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
        $all_meta = get_post_meta($post_id);

        $imported = 0;

        // ── Focus keyword (primary) ──
        $yo_kw    = $this->extractMeta($all_meta, self::YO_META_FOCUS_KW);
        $imported += $this->importFocusKeyword($post_id, $yo_kw, $overwrite);

        // ── Additional focus keywords (Yoast Premium — JSON array) ──
        $yo_kws_json = $this->extractMeta($all_meta, self::YO_META_FOCUS_KWS);
        if ($yo_kws_json !== '') {
            $decoded = json_decode($yo_kws_json, false);
            if (is_array($decoded)) {
                $secondary = array_filter(
                    array_column((array) $decoded, 'keyword'),
                    static fn ($kw): bool => $kw !== '' && $kw !== null,
                );
                if (!empty($secondary)) {
                    $imported += $this->importFocusKeywords($post_id, array_values($secondary), $overwrite);
                }
            }
        }

        // ── SEO score (linkdex) ──
        $yo_score  = $this->extractMeta($all_meta, self::YO_META_LINKDEX);
        $imported += $this->importScore($post_id, $yo_score, $overwrite);

        // ── Meta SEO (title, desc, OG, Twitter, canonical, robots) ──
        $seo_data  = $this->buildSeoData($all_meta);
        $imported += $this->importSeoMetaData($post_id, $seo_data, $overwrite);

        // ── Primary category ──
        $yo_primary = $this->extractMeta($all_meta, self::YO_META_PRIMARY_CAT);
        if ($yo_primary !== '') {
            $imported += $this->importPrimaryCategory($post_id, (int) $yo_primary, $overwrite);
        }

        // ── Redirection (Yoast free = 301 only) ──
        $yo_redirect = $this->extractMeta($all_meta, self::YO_META_REDIRECT);
        $imported   += $this->importRedirection($post_id, $yo_redirect, '301', $overwrite);

        return $imported;
    }

    // ──────────────────────────────────────────────
    // YOAST-SPECIFIC PRIVATE METHODS
    // ──────────────────────────────────────────────

    /**
     * Imports global SEO meta from Yoast (titles, OG, Twitter)
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importMetaSeoGlobal(array &$result, bool $overwrite): void
    {
        $yo_titles = get_option(self::YO_TITLES, []);
        $yo_social = get_option(self::YO_SOCIAL, []);

        if (empty($yo_titles) && empty($yo_social)) {
            return;
        }

        $mapped = [];

        if (is_array($yo_titles)) {
            $mapped['title']       = (string) ($yo_titles['title-home-wpseo'] ?? '');
            $mapped['description'] = (string) ($yo_titles['metadesc-home-wpseo'] ?? '');

            $mapped['og_title']       = (string) ($yo_titles['open_graph_frontpage_title'] ?? '');
            $mapped['og_description'] = (string) ($yo_titles['open_graph_frontpage_desc'] ?? '');
            $mapped['og_image']       = (string) ($yo_titles['open_graph_frontpage_image'] ?? '');

            // company_name takes priority over website_name (Yoast stores the organisation name there)
            $site_name = (string) ($yo_titles['company_name'] ?? $yo_titles['website_name'] ?? '');
            if ($site_name !== '') {
                $mapped['og_site_name'] = $site_name;
            }

            if (!empty($yo_titles['noindex-author-wpseo'])) {
                $result['warnings'][] = __('Yoast author archives set to noindex — noted.', 'seopulse');
            }
            if (!empty($yo_titles['noindex-archive-wpseo'])) {
                $result['warnings'][] = __('Yoast date archives set to noindex — noted.', 'seopulse');
            }

            $sep = (string) ($yo_titles['separator'] ?? '');
            if ($sep !== '') {
                $result['warnings'][] = sprintf(
                    /* translators: %s: separator slug */
                    __('Yoast title separator "%s" noted but SEOPulse does not use a global separator.', 'seopulse'),
                    $sep,
                );
            }
        }

        if (is_array($yo_social)) {
            if (empty($yo_social['opengraph'])) {
                $result['warnings'][] = __('Open Graph was disabled in Yoast SEO.', 'seopulse');
            }

            // Default OG image: fallback when the front page has none
            $og_default = (string) ($yo_social['og_default_image'] ?? '');
            if ($og_default !== '' && empty($mapped['og_image'])) {
                $mapped['og_image'] = $og_default;
            }

            $mapped['og_type']      = 'website';
            $mapped['twitter_card'] = (string) ($yo_social['twitter_card_type'] ?? 'summary_large_image');
            $mapped['twitter_site'] = (string) ($yo_social['twitter_site'] ?? '');
        }

        // Drop empty values to avoid overwriting existing data with blank strings
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
     * Imports verification codes from Yoast
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     */
    private function importYoastVerificationCodes(array &$result, bool $overwrite): void
    {
        $yo_main = get_option(self::YO_MAIN, []);

        if (empty($yo_main) || !is_array($yo_main)) {
            return;
        }

        $codes_map = [
            'googleverify' => 'google_verification',
            'msverify'     => 'bing_verification',
            'baiduverify'  => 'baidu_verification',
            'yandexverify' => 'yandex_verification',
        ];

        $this->importVerificationCodes($codes_map, $yo_main, $overwrite, $result);
    }

    /**
     * Builds the SEO data array from an already-fetched post meta map.
     *
     * Accepts the full meta array from a single get_post_meta() call
     * instead of issuing individual queries per field.
     *
     * @param array<string, array<int, mixed>> $all_meta Raw result of get_post_meta($post_id)
     * @return array<string, string>
     */
    private function buildSeoData(array $all_meta): array
    {
        $seo_data = [];

        $title = $this->extractMeta($all_meta, self::YO_META_TITLE);
        if ($title !== '') {
            $seo_data['title'] = sanitize_text_field($title);
        }

        $desc = $this->extractMeta($all_meta, self::YO_META_DESC);
        if ($desc !== '') {
            $seo_data['description'] = sanitize_text_field($desc);
        }

        $canonical = $this->extractMeta($all_meta, self::YO_META_CANONICAL);
        if ($canonical !== '') {
            $seo_data['canonical'] = esc_url_raw($canonical);
        }

        // ── Robots ──
        $robots = $this->buildPostRobots(
            $this->extractMeta($all_meta, self::YO_META_NOINDEX),
            $this->extractMeta($all_meta, self::YO_META_NOFOLLOW),
            $this->extractMeta($all_meta, self::YO_META_ROBOTS_ADV),
        );
        if ($robots !== '') {
            $seo_data['robots'] = $robots;
        }

        // ── Open Graph ──
        $og_title = $this->extractMeta($all_meta, self::YO_META_OG_TITLE);
        if ($og_title !== '') {
            $seo_data['og_title'] = sanitize_text_field($og_title);
        }

        $og_desc = $this->extractMeta($all_meta, self::YO_META_OG_DESC);
        if ($og_desc !== '') {
            $seo_data['og_description'] = sanitize_text_field($og_desc);
        }

        // Prefer the direct URL; fall back to the attachment ID if the URL is absent
        $og_img = $this->extractMeta($all_meta, self::YO_META_OG_IMG);
        if ($og_img === '') {
            $og_img_id = (int) $this->extractMeta($all_meta, self::YO_META_OG_IMG_ID);
            if ($og_img_id > 0) {
                $og_img = (string) wp_get_attachment_url($og_img_id);
            }
        }
        if ($og_img !== '') {
            $seo_data['og_image'] = esc_url_raw($og_img);
        }

        // ── Twitter ──
        $tw_title = $this->extractMeta($all_meta, self::YO_META_TW_TITLE);
        if ($tw_title !== '') {
            $seo_data['twitter_title'] = sanitize_text_field($tw_title);
        }

        $tw_desc = $this->extractMeta($all_meta, self::YO_META_TW_DESC);
        if ($tw_desc !== '') {
            $seo_data['twitter_description'] = sanitize_text_field($tw_desc);
        }

        $tw_img = $this->extractMeta($all_meta, self::YO_META_TW_IMG);
        if ($tw_img !== '') {
            $seo_data['twitter_image'] = esc_url_raw($tw_img);
        }

        return $seo_data;
    }

    /**
     * Builds a robots directive string from Yoast post meta values.
     *
     * Yoast semantics:
     *   noindex : '' or '0' = default (inherit), '1' = noindex, '2' = index (forced)
     *   nofollow: '' or '0' = follow, '1' = nofollow
     *
     * Returns an empty string when all values are at their Yoast defaults,
     * avoiding pollution of posts that were never explicitly configured.
     *
     * @param string $noindex Raw noindex meta value
     * @param string $nofollow Raw nofollow meta value
     * @param string $adv Advanced directives (comma-separated or 'none'/'-')
     * @return string Ready-to-store robots directive, or '' if all defaults
     */
    private function buildPostRobots(string $noindex, string $nofollow, string $adv): string
    {
        $adv_parts = $this->parseAdvancedRobots($adv);

        // Skip storing anything when all values are at their Yoast defaults
        $noindex_is_default  = in_array($noindex, self::YO_ROBOTS_DEFAULT, true);
        $nofollow_is_default = in_array($nofollow, self::YO_ROBOTS_DEFAULT, true);

        if ($noindex_is_default && $nofollow_is_default && empty($adv_parts)) {
            return '';
        }

        $parts = [];

        // Index/Noindex
        if ($noindex === self::YO_ROBOTS_NOINDEX) {
            $parts[] = 'noindex';
        } elseif ($noindex === self::YO_ROBOTS_INDEX || !$noindex_is_default) {
            // '2' = forcé index, ou tout autre cas non-default
            $parts[] = 'index';
        }

        // Follow/Nofollow
        $parts[] = ($nofollow === '1') ? 'nofollow' : 'follow';

        return implode(',', [...$parts, ...$adv_parts]);
    }

    /**
     * Parses Yoast advanced robots directives into a filtered array.
     *
     * [REFACTORING] Extrait de buildSeoData pour réutilisation dans buildTermSeoData.
     *
     * @param string $adv Valeur brute (ex: 'noarchive,noimageindex' ou 'none' ou '')
     * @return list<string>
     */
    private function parseAdvancedRobots(string $adv): array
    {
        if ($adv === '' || $adv === 'none' || $adv === '-') {
            return [];
        }

        return array_values(array_intersect(
            array_map('trim', explode(',', $adv)),
            self::ALLOWED_ADV_ROBOTS,
        ));
    }

    /**
     * Extracts a scalar string value from the raw get_post_meta() map.
     *
     * get_post_meta() sans clé renvoie [ 'key' => [ 0 => 'value' ] ].
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
     * Imports from the `wpseo_taxonomy_meta` option, which stores
     * per-term SEO data as a nested array:
     *   [ taxonomy => [ term_id => [ 'wpseo_title' => ..., ... ] ] ]
     */
    protected function importTermsMeta(array &$result, bool $overwrite): void
    {
        $yoast_terms = get_option(self::YO_TAX_META, []);

        if (empty($yoast_terms) || !is_array($yoast_terms)) {
            return;
        }

        // [PERF] Pré-valider les taxonomies une seule fois hors de la boucle interne
        $valid_taxonomies = array_filter(
            array_keys($yoast_terms),
            'taxonomy_exists',
        );

        $imported_count = 0;

        foreach ($valid_taxonomies as $taxonomy) {
            $terms = $yoast_terms[$taxonomy];
            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term_id => $meta) {
                $term_id = (int) $term_id;
                if ($term_id <= 0 || !is_array($meta)) {
                    continue;
                }

                // Vérifier l'existence du terme (suppression possible depuis la migration)
                if (!get_term($term_id, $taxonomy) instanceof \WP_Term) {
                    continue;
                }

                $seo_data = $this->buildTermSeoData($meta);
                if (!empty($seo_data)) {
                    $imported_count += $this->importTermSeoData($term_id, $seo_data, $overwrite);
                }
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
     * Converts Yoast taxonomy meta array to SEOPulse term SEO data.
     *
     * [FIX] La version originale ignorait nofollow et les directives avancées pour les terms.
     * Ils sont maintenant gérés via parseAdvancedRobots() et le même modèle que les posts.
     *
     * @param array<string, mixed> $meta Yoast term meta
     * @return array<string, string>
     */
    private function buildTermSeoData(array $meta): array
    {
        $data = [];

        if (!empty($meta['wpseo_title'])) {
            $data['title'] = sanitize_text_field((string) $meta['wpseo_title']);
        }

        if (!empty($meta['wpseo_desc'])) {
            $data['description'] = sanitize_text_field((string) $meta['wpseo_desc']);
        }

        if (!empty($meta['wpseo_canonical'])) {
            $data['canonical'] = esc_url_raw((string) $meta['wpseo_canonical']);
        }

        // [FIX] Robots : l'original ne gérait que noindex, sans nofollow ni directives avancées
        $noindex = $meta['wpseo_noindex'] ?? '';
        // Yoast stocke 'noindex' ou 'index' (string) pour les terms (différent des posts)
        $has_noindex = ($noindex === 'noindex');
        $adv_parts   = $this->parseAdvancedRobots((string) ($meta['wpseo_adv_robots'] ?? ''));

        if ($has_noindex || !empty($adv_parts)) {
            $robots_parts = [$has_noindex ? 'noindex' : 'index', 'follow'];
            $data['robots'] = implode(',', [...$robots_parts, ...$adv_parts]);
        }

        // ── Open Graph ──
        if (!empty($meta['wpseo_opengraph-title'])) {
            $data['og_title'] = sanitize_text_field((string) $meta['wpseo_opengraph-title']);
        }
        if (!empty($meta['wpseo_opengraph-description'])) {
            $data['og_description'] = sanitize_text_field((string) $meta['wpseo_opengraph-description']);
        }
        if (!empty($meta['wpseo_opengraph-image'])) {
            $data['og_image'] = esc_url_raw((string) $meta['wpseo_opengraph-image']);
        }

        // ── Twitter ──
        if (!empty($meta['wpseo_twitter-title'])) {
            $data['twitter_title'] = sanitize_text_field((string) $meta['wpseo_twitter-title']);
        }
        if (!empty($meta['wpseo_twitter-description'])) {
            $data['twitter_description'] = sanitize_text_field((string) $meta['wpseo_twitter-description']);
        }
        if (!empty($meta['wpseo_twitter-image'])) {
            $data['twitter_image'] = esc_url_raw((string) $meta['wpseo_twitter-image']);
        }

        return $data;
    }
}
