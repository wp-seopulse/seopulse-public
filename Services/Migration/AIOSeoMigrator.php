<?php

/**
 * All in One SEO (AIOSEO) to SEOPulse migration service
 *
 * SEO data in dedicated custom tables (wp_aioseo_posts, wp_aioseo_terms)
 * rather than wp_postmeta. Global settings are stored as a JSON-encoded blob
 * in the wp_options table under 'aioseo_options'.
 *
 * @package SEOPulse\Services
 * @since   1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services\Migration;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\AbstractMigrator;
use SEOPulse\Core\Constants\Options;

/**
 * AioseoMigrator class
 *
 * Extends AbstractMigrator to provide AIOSEO-specific mapping logic.
 * Common detect/scan/import/batch operations are inherited from the parent.
 */
class AIOSeoMigrator extends AbstractMigrator
{
    // ── AIOSEO wp_options keys ────────────────────
    private const AI_OPTIONS         = 'aioseo_options';
    private const AI_OPTIONS_DYNAMIC = 'aioseo_options_dynamic';

    // ── Custom table names (without $wpdb->prefix) ─
    private const AI_TABLE_POSTS     = 'aioseo_posts';
    private const AI_TABLE_TERMS     = 'aioseo_terms';
    private const AI_TABLE_REDIRECTS = 'aioseo_redirects'; // Pro only

    // ── AIOSEO image type slugs ───────────────────
    private const AI_IMG_CUSTOM = 'custom'; // URL entered manually
    private const AI_IMG_ATTACH = 'attach'; // Picked from media library

    /**
     * Decoded aioseo_options blob — cached to avoid repeated DB + json_decode.
     *
     * @var array<string, mixed>|null
     */
    private ?array $cachedOptions = null;

    // ──────────────────────────────────────────────
    // ABSTRACT IMPLEMENTATIONS
    // ──────────────────────────────────────────────

    /**
     * {@inheritDoc}
     */
    protected function getPluginSlugs(): array
    {
        return [
            'all-in-one-seo-pack/all_in_one_seo_pack.php',
            'all-in-one-seo-pack-pro/all_in_one_seo_pack.php',
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getSourceOptionKeys(): array
    {
        return [self::AI_OPTIONS, self::AI_OPTIONS_DYNAMIC];
    }

    /**
     * {@inheritDoc}
     */
    protected function getVersionConstant(): ?string
    {
        return 'AIOSEO_VERSION';
    }

    /**
     * {@inheritDoc}
     */
    protected function getPluginFilePath(): string
    {
        return '/all-in-one-seo-pack/all_in_one_seo_pack.php';
    }

    /**
     * {@inheritDoc}
     *
     * AIOSEO does not use wp_postmeta for SEO fields — the abstract scan
     * mechanism must skip standard meta scanning for this migrator.
     */
    protected function getScannableMetaKeys(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    protected function scanGlobalOptions(): array
    {
        $modules = [];
        $opts    = $this->getOptions();

        if (!empty($opts['searchAppearance']['global'])) {
            $modules['meta_seo_global'] = count($opts['searchAppearance']['global']);
        }

        if (!empty($opts['social'])) {
            $modules['social'] = count($opts['social']);
        }

        if (!empty($opts['sitemap']['general']['enable'])) {
            $modules['sitemap'] = 1;
        }

        $verif_count = count(array_filter(
            ['google', 'bing', 'baidu', 'yandex'],
            fn (string $k): bool => !empty($opts['webmasterTools'][$k]),
        ));

        if ($verif_count > 0) {
            $modules['verification'] = $verif_count;
        }

        return $modules;
    }

    /**
     * {@inheritDoc}
     */
    protected function importGlobalOptions(array &$result, bool $overwrite): void
    {
        $this->importMetaSeoGlobal($result, $overwrite);
        $this->importAioseoVerificationCodes($result, $overwrite);
    }

    /**
     * {@inheritDoc}
     *
     * AIOSEO sitemap config lives inside the JSON blob under sitemap.postTypes
     * and sitemap.taxonomies, each with an 'all' boolean flag and an optional
     * 'included' list for when 'all' is false.
     */
    protected function importSitemapSettings(array &$result, bool $overwrite): void
    {
        $opts = $this->getOptions();

        if (empty($opts['sitemap']['general']['enable'])) {
            return;
        }

        $current = get_option(Options::SITEMAP, []);

        if (!empty($current) && !$overwrite) {
            $result['warnings'][] = __('Sitemap settings already configured — skipped (overwrite disabled).', 'seopulse');

            return;
        }

        $mapped  = [];
        $sitemap = $opts['sitemap'] ?? [];

        // ── Post types ──────────────────────────────
        $cpt_cfg  = $sitemap['postTypes']['postTypes'] ?? [];
        $cpt_all  = !empty($cpt_cfg['all']);
        $cpt_list = array_flip((array) ($cpt_cfg['included'] ?? []));

        foreach (get_post_types(['public' => true], 'names') as $cpt) {
            $mapped['enable_' . $cpt] = ($cpt_all || isset($cpt_list[$cpt])) ? 1 : 0;
        }

        // ── Taxonomies ──────────────────────────────
        $tax_cfg  = $sitemap['taxonomies']['taxonomies'] ?? [];
        $tax_all  = !empty($tax_cfg['all']);
        $tax_list = array_flip((array) ($tax_cfg['included'] ?? []));

        foreach (get_taxonomies(['public' => true], 'names') as $tax) {
            $mapped['enable_' . $tax] = ($tax_all || isset($tax_list[$tax])) ? 1 : 0;
        }

        if (!empty($mapped)) {
            update_option(Options::SITEMAP, $overwrite ? $mapped : array_merge($current, $mapped));
            $result['options_imported'][] = 'sitemap';
        }
    }

    /**
     * {@inheritDoc}
     *
     * Queries wp_aioseo_posts in a single SELECT instead of per-field
     * get_post_meta() calls. Redirects are fetched from wp_aioseo_redirects
     * (available only in AIOSEO Pro — gracefully skipped otherwise).
     */
    protected function importSinglePostMeta(int $post_id, bool $overwrite): int
    {
        global $wpdb;

        $table = $wpdb->prefix . self::AI_TABLE_POSTS;

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id),
            ARRAY_A,
        );

        if (empty($row)) {
            return 0;
        }

        $imported = 0;

        // ── Focus keyword (primary keyphrase) ──
        $kw_primary = $this->extractPrimaryKeyphrase((string) ($row['keyphrases'] ?? ''));
        $imported  += $this->importFocusKeyword($post_id, $kw_primary, $overwrite);

        // ── Additional keyphrases (AIOSEO Pro) ──
        $secondary = $this->extractSecondaryKeyphrases((string) ($row['keyphrases'] ?? ''));
        if (!empty($secondary)) {
            $imported += $this->importFocusKeywords($post_id, $secondary, $overwrite);
        }

        // ── SEO score ──
        $score     = isset($row['seo_score']) ? (string) $row['seo_score'] : '';
        $imported += $this->importScore($post_id, $score, $overwrite);

        // ── SEO meta (title, desc, OG, Twitter, canonical, robots) ──
        $seo_data  = $this->buildSeoData($row);
        $imported += $this->importSeoMetaData($post_id, $seo_data, $overwrite);

        // ── Primary category ──
        $primary_term = $this->extractPrimaryCategory((string) ($row['primary_term'] ?? ''));
        if ($primary_term > 0) {
            $imported += $this->importPrimaryCategory($post_id, $primary_term, $overwrite);
        }

        // ── Redirect (Pro only — table existence is checked inside) ──
        $redirect = $this->fetchRedirect($post_id);
        if ($redirect !== null) {
            $imported += $this->importRedirection($post_id, $redirect['url'], $redirect['type'], $overwrite);
        }

        return $imported;
    }

    // ──────────────────────────────────────────────
    // AIOSEO-SPECIFIC PRIVATE METHODS
    // ──────────────────────────────────────────────

    /**
     * Returns the decoded aioseo_options array with a per-request cache.
     *
     * Older AIOSEO versions stored a plain PHP array; newer ones store JSON.
     * Both cases are handled gracefully.
     *
     * @return array<string, mixed>
     */
    private function getOptions(): array
    {
        if ($this->cachedOptions !== null) {
            return $this->cachedOptions;
        }

        $raw = get_option(self::AI_OPTIONS, '{}');

        if (is_array($raw)) {
            // Legacy format: already an array
            $this->cachedOptions = $raw;
        } else {
            $decoded             = json_decode((string) $raw, true);
            $this->cachedOptions = is_array($decoded) ? $decoded : [];
        }

        return $this->cachedOptions;
    }

    /**
     * Imports global SEO meta from AIOSEO's searchAppearance + social sections.
     *
     * AIOSEO JSON structure (simplified):
     *   searchAppearance.global.siteTitle
     *   searchAppearance.global.metaDescription
     *   searchAppearance.global.schema.organizationName
     *   social.facebook.general.{enable, defaultTitle, defaultDescription, defaultImageUrl}
     *   social.twitter.general.{defaultTwitterCard, twitterSite}
     *
     * @param array<string, mixed> &$result
     * @param bool $overwrite
     */
    private function importMetaSeoGlobal(array &$result, bool $overwrite): void
    {
        $opts   = $this->getOptions();
        $global = $opts['searchAppearance']['global'] ?? [];
        $social = $opts['social'] ?? [];
        $mapped = [];

        // ── Titles / description ────────────────────
        if (!empty($global)) {
            $mapped['title']       = (string) ($global['siteTitle'] ?? '');
            $mapped['description'] = (string) ($global['metaDescription'] ?? '');

            $sep = (string) ($global['separator'] ?? '');
            if ($sep !== '') {
                $result['warnings'][] = sprintf(
                    /* translators: %s: separator slug */
                    __('AIOSEO title separator "%s" noted but SEOPulse does not use a global separator.', 'seopulse'),
                    $sep,
                );
            }
        }

        // ── Open Graph (Facebook) ───────────────────
        $fb = $social['facebook']['general'] ?? [];
        if (!empty($fb)) {
            if (empty($fb['enable'])) {
                $result['warnings'][] = __('Open Graph (Facebook) was disabled in AIOSEO.', 'seopulse');
            }

            $mapped['og_title']       = (string) ($fb['defaultTitle'] ?? '');
            $mapped['og_description'] = (string) ($fb['defaultDescription'] ?? '');
            $mapped['og_image']       = (string) ($fb['defaultImageUrl'] ?? '');
            $mapped['og_type']        = 'website';

            // Organisation name from schema settings
            $org = (string) ($global['schema']['organizationName'] ?? '');
            if ($org !== '') {
                $mapped['og_site_name'] = $org;
            }
        }

        // ── Twitter ─────────────────────────────────
        $tw = $social['twitter']['general'] ?? [];
        if (!empty($tw)) {
            $mapped['twitter_card'] = (string) ($tw['defaultTwitterCard'] ?? 'summary_large_image');
            $mapped['twitter_site'] = (string) ($tw['twitterSite'] ?? '');
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
     * Imports search-engine verification codes from AIOSEO webmasterTools.
     *
     * @param array<string, mixed> &$result
     * @param bool $overwrite
     */
    private function importAioseoVerificationCodes(array &$result, bool $overwrite): void
    {
        $opts  = $this->getOptions();
        $tools = $opts['webmasterTools'] ?? [];

        if (empty($tools)) {
            return;
        }

        $codes_map = [
            'google'  => 'google_verification',
            'bing'    => 'bing_verification',
            'baidu'   => 'baidu_verification',
            'yandex'  => 'yandex_verification',
        ];

        $this->importVerificationCodes($codes_map, $tools, $overwrite, $result);
    }

    /**
     * Builds the SEO data array from a wp_aioseo_posts table row.
     *
     * @param array<string, mixed> $row Associative row from $wpdb->get_row()
     * @return array<string, string>
     */
    private function buildSeoData(array $row): array
    {
        $data = [];

        if (!empty($row['title'])) {
            $data['title'] = sanitize_text_field((string) $row['title']);
        }

        if (!empty($row['description'])) {
            $data['description'] = sanitize_text_field((string) $row['description']);
        }

        if (!empty($row['canonical_url'])) {
            $data['canonical'] = esc_url_raw((string) $row['canonical_url']);
        }

        // ── Robots ──────────────────────────────────
        $robots = $this->buildPostRobots($row);
        if ($robots !== '') {
            $data['robots'] = $robots;
        }

        // ── Open Graph ──────────────────────────────
        if (!empty($row['og_title'])) {
            $data['og_title'] = sanitize_text_field((string) $row['og_title']);
        }

        if (!empty($row['og_description'])) {
            $data['og_description'] = sanitize_text_field((string) $row['og_description']);
        }

        $og_img = $this->resolveImageUrl(
            (string) ($row['og_image_type'] ?? ''),
            (string) ($row['og_image_url'] ?? ''),
            (string) ($row['og_image_custom_url'] ?? ''),
        );
        if ($og_img !== '') {
            $data['og_image'] = esc_url_raw($og_img);
        }

        // ── Twitter ─────────────────────────────────
        if (!empty($row['twitter_title'])) {
            $data['twitter_title'] = sanitize_text_field((string) $row['twitter_title']);
        }

        if (!empty($row['twitter_description'])) {
            $data['twitter_description'] = sanitize_text_field((string) $row['twitter_description']);
        }

        $tw_img = $this->resolveImageUrl(
            (string) ($row['twitter_image_type'] ?? ''),
            (string) ($row['twitter_image_url'] ?? ''),
            (string) ($row['twitter_image_custom_url'] ?? ''),
        );
        if ($tw_img !== '') {
            $data['twitter_image'] = esc_url_raw($tw_img);
        }

        return $data;
    }

    /**
     * Builds a robots directive string from a wp_aioseo_posts row.
     *
     * AIOSEO stores each directive as a separate tinyint(1) column:
     *   robots_noindex, robots_nofollow, robots_noarchive,
     *   robots_nosnippet, robots_noimageindex
     *
     * When robots_default = 1, the post inherits global settings and nothing
     * should be stored at the post level.
     *
     * @param array<string, mixed> $row
     * @return string Comma-separated directives, or '' to skip storage
     */
    private function buildPostRobots(array $row): string
    {
        // Explicit "use site defaults" — nothing to store per-post
        if (!empty($row['robots_default'])) {
            return '';
        }

        $parts   = [];
        $parts[] = !empty($row['robots_noindex']) ? 'noindex' : 'index';
        $parts[] = !empty($row['robots_nofollow']) ? 'nofollow' : 'follow';

        $adv_map = [
            'robots_noarchive'    => 'noarchive',
            'robots_nosnippet'    => 'nosnippet',
            'robots_noimageindex' => 'noimageindex',
        ];

        foreach ($adv_map as $col => $directive) {
            if (!empty($row[$col])) {
                $parts[] = $directive;
            }
        }

        // Pure default (index, follow, no advanced) — avoid polluting the DB
        if ($parts === ['index', 'follow']) {
            return '';
        }

        return implode(',', $parts);
    }

    /**
     * Resolves the effective image URL from AIOSEO's image type + URL columns.
     *
     * AIOSEO uses a type discriminator alongside two URL columns:
     *   - og_image_url       : resolved URL for media-library images (type = 'attach')
     *   - og_image_custom_url: manually entered URL (type = 'custom')
     *
     * Other types ('default', 'content', 'author', …) are not user-defined
     * per-post URLs and cannot be reliably migrated — they are skipped.
     *
     * @param string $type Image type slug from the DB column
     * @param string $attach_url URL resolved from the media library
     * @param string $custom_url URL entered manually by the user
     * @return string Resolved URL or ''
     */
    private function resolveImageUrl(string $type, string $attach_url, string $custom_url): string
    {
        return match ($type) {
            self::AI_IMG_CUSTOM => $custom_url,
            self::AI_IMG_ATTACH => $attach_url,
            default             => '',
        };
    }

    /**
     * Extracts the primary (focus) keyphrase from AIOSEO's keyphrases JSON field.
     *
     * AIOSEO format:
     *   { "focus": { "keyphrase": "...", "score": 80, ... }, "extra": [...] }
     *
     * @param string $json Raw value from the DB column
     * @return string
     */
    private function extractPrimaryKeyphrase(string $json): string
    {
        if ($json === '') {
            return '';
        }

        $decoded = json_decode($json, true);

        return (string) ($decoded['focus']['keyphrase'] ?? '');
    }

    /**
     * Extracts secondary keyphrases from AIOSEO's keyphrases JSON (Pro feature).
     *
     * AIOSEO format for extra keyphrases:
     *   { "extra": [ { "keyphrase": "...", "score": 65 }, ... ] }
     *
     * @param string $json
     * @return list<string>
     */
    private function extractSecondaryKeyphrases(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        $extra   = $decoded['extra'] ?? [];

        if (!is_array($extra)) {
            return [];
        }

        return array_values(array_filter(
            array_column($extra, 'keyphrase'),
            static fn ($kw): bool => $kw !== '' && $kw !== null,
        ));
    }

    /**
     * Extracts the primary category term_id from AIOSEO's primary_term JSON field.
     *
     * AIOSEO stores a map of taxonomy → term_id for each post:
     *   { "category": 12, "product_cat": 5 }
     *
     * 'category' takes precedence; the first non-empty value is used as a fallback.
     *
     * @param string $json Raw value from the DB column
     * @return int term_id or 0 when absent
     */
    private function extractPrimaryCategory(string $json): int
    {
        if ($json === '') {
            return 0;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return 0;
        }

        // 'category' has explicit priority
        if (!empty($decoded['category'])) {
            return (int) $decoded['category'];
        }

        // Fallback: first non-zero value across any taxonomy
        foreach ($decoded as $term_id) {
            if ((int) $term_id > 0) {
                return (int) $term_id;
            }
        }

        return 0;
    }

    /**
     * Fetches a redirect entry from wp_aioseo_redirects for a given post.
     *
     * The redirects table only exists in AIOSEO Pro. Its presence is checked
     * before querying; null is returned gracefully for the free version.
     *
     * @param int $post_id
     * @return array{url: string, type: string}|null
     */
    private function fetchRedirect(int $post_id): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . self::AI_TABLE_REDIRECTS;

        // Use %s with LIKE only after escaping wildcards to avoid SQL issues
        $like = $wpdb->esc_like($table);
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) !== $table) {
            return null;
        }

        /** @var array<string, string>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT target, type FROM {$table}
                 WHERE source_post_id = %d AND enabled = 1
                 ORDER BY id DESC LIMIT 1",
                $post_id,
            ),
            ARRAY_A,
        );

        if (empty($row)) {
            return null;
        }

        return [
            'url'  => (string) ($row['target'] ?? ''),
            'type' => (string) ($row['type']   ?? '301'),
        ];
    }

    // ──────────────────────────────────────────────
    // TAXONOMY TERM META IMPORT
    // ──────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * AIOSEO stores term SEO data in wp_aioseo_terms (one row per term),
     * not in WordPress term_meta. Each row includes the taxonomy slug so
     * we can validate existence before importing.
     */
    protected function importTermsMeta(array &$result, bool $overwrite): void
    {
        global $wpdb;

        $table = $wpdb->prefix . self::AI_TABLE_TERMS;

        $like = $wpdb->esc_like($table);
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) !== $table) {
            $result['warnings'][] = __('AIOSEO terms table not found — term meta skipped.', 'seopulse');

            return;
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);

        if (empty($rows)) {
            return;
        }

        // Pre-validate taxonomies once, outside the inner loop
        $seen_taxonomies = array_unique(array_column($rows, 'taxonomy'));
        $valid_taxonomies = array_flip(array_filter($seen_taxonomies, 'taxonomy_exists'));

        $imported_count = 0;

        foreach ($rows as $row) {
            $term_id  = (int) ($row['term_id']  ?? 0);
            $taxonomy = (string) ($row['taxonomy']  ?? '');

            if ($term_id <= 0 || $taxonomy === '') {
                continue;
            }

            if (!isset($valid_taxonomies[$taxonomy])) {
                continue;
            }

            // Verify the term still exists (could have been deleted since AIOSEO stored it)
            if (!get_term($term_id, $taxonomy) instanceof \WP_Term) {
                continue;
            }

            $seo_data = $this->buildTermSeoData($row);
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
     * Converts a wp_aioseo_terms row to a SEOPulse term SEO data array.
     *
     * Notable difference from posts: terms do not have a robots_default flag
     * in AIOSEO — directives are always stored explicitly.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function buildTermSeoData(array $row): array
    {
        $data = [];

        if (!empty($row['title'])) {
            $data['title'] = sanitize_text_field((string) $row['title']);
        }

        if (!empty($row['description'])) {
            $data['description'] = sanitize_text_field((string) $row['description']);
        }

        if (!empty($row['canonical_url'])) {
            $data['canonical'] = esc_url_raw((string) $row['canonical_url']);
        }

        // ── Robots ──────────────────────────────────
        $has_noindex  = !empty($row['robots_noindex']);
        $has_nofollow = !empty($row['robots_nofollow']);
        $adv          = array_filter([
            'noarchive'    => !empty($row['robots_noarchive']),
            'nosnippet'    => !empty($row['robots_nosnippet']),
            'noimageindex' => !empty($row['robots_noimageindex']),
        ]);

        if ($has_noindex || $has_nofollow || !empty($adv)) {
            $parts   = [];
            $parts[] = $has_noindex ? 'noindex' : 'index';
            $parts[] = $has_nofollow ? 'nofollow' : 'follow';

            $data['robots'] = implode(',', array_merge($parts, array_keys($adv)));
        }

        // ── Open Graph ──────────────────────────────
        if (!empty($row['og_title'])) {
            $data['og_title'] = sanitize_text_field((string) $row['og_title']);
        }

        if (!empty($row['og_description'])) {
            $data['og_description'] = sanitize_text_field((string) $row['og_description']);
        }

        $og_img = $this->resolveImageUrl(
            (string) ($row['og_image_type']       ?? ''),
            (string) ($row['og_image_url']         ?? ''),
            (string) ($row['og_image_custom_url']  ?? ''),
        );
        if ($og_img !== '') {
            $data['og_image'] = esc_url_raw($og_img);
        }

        // ── Twitter ─────────────────────────────────
        if (!empty($row['twitter_title'])) {
            $data['twitter_title'] = sanitize_text_field((string) $row['twitter_title']);
        }

        if (!empty($row['twitter_description'])) {
            $data['twitter_description'] = sanitize_text_field((string) $row['twitter_description']);
        }

        // AIOSEO terms may not have a distinct Twitter image column;
        // fall back to the OG image if it was resolved above.
        if (!empty($row['twitter_image_url'])) {
            $data['twitter_image'] = esc_url_raw((string) $row['twitter_image_url']);
        } elseif (isset($data['og_image'])) {
            $data['twitter_image'] = $data['og_image'];
        }

        return $data;
    }
}
