<?php

/**
 * Abstract base class for SEO plugin migrators
 *
 * Provides the common template for detect/scan/import operations.
 * Concrete migrators need only define their source plugin mapping
 * and any plugin-specific import logic.
 *
 * @package SEOPulse\Core\Abstracts
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Abstracts;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- AbstractMigrator: direct DB access is intentional; caching is handled at the service/caller layer.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- All interpolated vars are safe prefixed table names or allowlisted SQL fragments.
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- Dynamic placeholder counts via array_fill() and spread params.

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Constants\PostMeta;

/**
 * AbstractMigrator class — Template Method pattern
 *
 * Concrete migrators must implement the abstract methods to define
 * their source plugin's constants, option keys, and mapping logic.
 */
abstract class AbstractMigrator
{
    /**
     * Batch size for post meta import
     */
    protected const BATCH_SIZE = 100;

    // ──────────────────────────────────────────────
    // ABSTRACT: Concrete migrators must implement
    // ──────────────────────────────────────────────

    /**
     * Returns the plugin slug(s) to check via is_plugin_active()
     *
     * @return array<string> Plugin file slugs (e.g. ['wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php'])
     */
    abstract protected function getPluginSlugs(): array;

    /**
     * Returns the option keys to check for source data existence
     *
     * @return array<string> Option key names
     */
    abstract protected function getSourceOptionKeys(): array;

    /**
     * Returns the version constant name (e.g. 'WPSEO_VERSION') or null
     *
     * @return string|null
     */
    abstract protected function getVersionConstant(): ?string;

    /**
     * Returns the path to the main plugin file for get_plugin_data()
     *
     * @return string Relative path from the plugins directory (e.g. '/wordpress-seo/wp-seo.php')
     */
    abstract protected function getPluginFilePath(): string;

    /**
     * Returns the meta keys to scan in the source plugin's data
     *
     * @return array<string>
     */
    abstract protected function getScannableMetaKeys(): array;

    /**
     * Scans source global options and returns module counts
     *
     * @return array<string, int>
     */
    abstract protected function scanGlobalOptions(): array;

    /**
     * Imports global options for this specific source plugin
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     * @return void
     */
    abstract protected function importGlobalOptions(array &$result, bool $overwrite): void;

    /**
     * Imports sitemap settings for this specific source plugin
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     * @return void
     */
    abstract protected function importSitemapSettings(array &$result, bool $overwrite): void;

    /**
     * Imports meta for a single post
     *
     * @param int $post_id Post ID
     * @param bool $overwrite Overwrite existing data
     * @return int Number of imported meta fields
     */
    abstract protected function importSinglePostMeta(int $post_id, bool $overwrite): int;

    /**
     * Imports taxonomy term meta (title, desc, OG, Twitter, etc.)
     *
     * Concrete migrators should override this to handle their
     * plugin-specific term meta storage format.
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     * @return void
     */
    protected function importTermsMeta(array &$result, bool $overwrite): void
    {
        // Default: no term meta import — override in subclass
    }

    // ──────────────────────────────────────────────
    // PUBLIC API: detect / scan / import
    // ──────────────────────────────────────────────

    /**
     * Detects if the source plugin is installed (data in database)
     *
     * @return array{installed: bool, active: bool, has_data: bool, version: string}
     */
    public function detect(): array
    {
        $active   = $this->isPluginActive();
        $has_data = $this->hasData();
        $version  = $this->getVersion();

        return [
            'installed' => $has_data || $active,
            'active'    => $active,
            'has_data'  => $has_data,
            'version'   => $version,
        ];
    }

    /**
     * Scans recoverable data before import
     *
     * @return array{modules: array<string, int>, post_meta: int, total_posts: int}
     */
    public function scan(): array
    {
        global $wpdb;

        $modules = $this->scanGlobalOptions();

        $meta_keys    = $this->getScannableMetaKeys();
        $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a hardcoded set of %s tokens; values go through prepare().
        $meta_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})",
                ...$meta_keys,
            ),
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a hardcoded set of %s tokens; values go through prepare().
        $total_posts = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})",
                ...$meta_keys,
            ),
        );

        return [
            'modules'     => $modules,
            'post_meta'   => $meta_count,
            'total_posts' => $total_posts,
        ];
    }

    /**
     * Executes the complete import from source plugin to SEOPulse
     *
     * @param bool $overwrite If true, overwrites existing data
     * @return array{
     *   options_imported: array<string>,
     *   post_meta_imported: int,
     *   posts_processed: int,
     *   warnings: array<string>,
     *   errors: array<string>
     * }
     */
    public function import(bool $overwrite = false): array
    {
        $result = [
            'options_imported'   => [],
            'post_meta_imported' => 0,
            'posts_processed'    => 0,
            'warnings'           => [],
            'errors'             => [],
        ];

        $this->importGlobalOptions($result, $overwrite);
        $this->importSitemapSettings($result, $overwrite);
        $this->importPostMeta($result, $overwrite);
        $this->importTermsMeta($result, $overwrite);

        return $result;
    }

    // ──────────────────────────────────────────────
    // SHARED HELPERS available to all migrators
    // ──────────────────────────────────────────────

    /**
     * Checks if the source plugin is active
     *
     * @return bool
     */
    protected function isPluginActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ($this->getPluginSlugs() as $slug) {
            if (is_plugin_active($slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if source data exists in the database
     *
     * @return bool
     */
    protected function hasData(): bool
    {
        foreach ($this->getSourceOptionKeys() as $key) {
            if (get_option($key, false) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieves the source plugin version
     *
     * @return string
     */
    protected function getVersion(): string
    {
        $constant = $this->getVersionConstant();

        if ($constant !== null && defined($constant)) {
            return (string) constant($constant);
        }

        $plugin_data_path = dirname(SEOPULSE_PLUGIN_DIR) . $this->getPluginFilePath();

        if (file_exists($plugin_data_path)) {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $data = get_plugin_data($plugin_data_path, false, false);

            return $data['Version'] ?? '';
        }

        return '';
    }

    /**
     * Imports post meta in batches (common logic)
     *
     * @param array<string, mixed> &$result Accumulated result
     * @param bool $overwrite Overwrite existing data
     * @return void
     */
    protected function importPostMeta(array &$result, bool $overwrite): void
    {
        global $wpdb;

        $meta_keys    = $this->getScannableMetaKeys();
        $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a hardcoded set of %s tokens; values go through prepare().
        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) ORDER BY post_id",
                ...$meta_keys,
            ),
        );

        if (empty($post_ids)) {
            return;
        }

        $total_meta  = 0;
        $total_posts = 0;

        foreach (array_chunk($post_ids, static::BATCH_SIZE) as $batch) {
            foreach ($batch as $post_id) {
                $post_id     = (int) $post_id;
                $imported    = $this->importSinglePostMeta($post_id, $overwrite);
                $total_meta += $imported;

                if ($imported > 0) {
                    ++$total_posts;
                }
            }

            // Avoid timeouts
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }

        $result['post_meta_imported'] = $total_meta;
        $result['posts_processed']    = $total_posts;

        if ($total_posts > 0) {
            $result['options_imported'][] = 'post_meta';
        }
    }

    /**
     * Imports a focus keyword from source to SEOPulse
     *
     * @param int $post_id Post ID
     * @param string $source_value Source keyword value
     * @param bool $overwrite Overwrite existing data
     * @return int Number of imported fields (0 or 1)
     */
    protected function importFocusKeyword(int $post_id, string $source_value, bool $overwrite): int
    {
        if (empty($source_value)) {
            return 0;
        }

        $existing = get_post_meta($post_id, PostMeta::FOCUS_KEYWORD, true);

        if (empty($existing) || $overwrite) {
            update_post_meta($post_id, PostMeta::FOCUS_KEYWORD, sanitize_text_field($source_value));

            return 1;
        }

        return 0;
    }

    /**
     * Imports secondary focus keywords into the multi-keyword field
     *
     * @param int $post_id Post ID
     * @param array<string> $keywords Array of keywords (duplicates of primary excluded)
     * @param bool $overwrite Overwrite existing data
     * @return int Number of imported fields (0 or 1)
     */
    protected function importFocusKeywords(int $post_id, array $keywords, bool $overwrite): int
    {
        $keywords = array_filter(array_map('trim', $keywords));

        if (empty($keywords)) {
            return 0;
        }

        $existing = get_post_meta($post_id, PostMeta::FOCUS_KEYWORDS, true);

        if (empty($existing) || $overwrite) {
            update_post_meta(
                $post_id,
                PostMeta::FOCUS_KEYWORDS,
                array_map('sanitize_text_field', array_values($keywords)),
            );

            return 1;
        }

        return 0;
    }

    /**
     * Imports a primary category for a post
     *
     * @param int $post_id Post ID
     * @param int $term_id Primary category term ID
     * @param bool $overwrite Overwrite existing data
     * @return int Number of imported fields (0 or 1)
     */
    protected function importPrimaryCategory(int $post_id, int $term_id, bool $overwrite): int
    {
        if ($term_id <= 0) {
            return 0;
        }

        // Verify term exists
        $term = get_term($term_id);
        if (!$term instanceof \WP_Term) {
            return 0;
        }

        $existing = get_post_meta($post_id, '_seopulse_primary_category', true);

        if (empty($existing) || $overwrite) {
            update_post_meta($post_id, '_seopulse_primary_category', $term_id);

            return 1;
        }

        return 0;
    }

    /**
     * Imports SEO data for a single taxonomy term
     *
     * @param int $term_id Term ID
     * @param array<string, string> $seo_data Mapped SEO data
     * @param bool $overwrite Overwrite existing data
     * @return int Number of imported fields
     */
    protected function importTermSeoData(int $term_id, array $seo_data, bool $overwrite): int
    {
        if (empty($seo_data)) {
            return 0;
        }

        $existing = get_term_meta($term_id, '_seopulse_term_meta_seo', true);
        $existing = is_array($existing) ? $existing : [];

        $final = $overwrite ? $seo_data : array_merge($existing, $seo_data);
        update_term_meta($term_id, '_seopulse_term_meta_seo', $final);

        return count($seo_data);
    }

    /**
     * Imports an SEO score from source to SEOPulse
     *
     * @param int $post_id Post ID
     * @param string|int $source_score Source score value
     * @param bool $overwrite Overwrite existing data
     * @return int Number of imported fields (0 or 1)
     */
    protected function importScore(int $post_id, string|int $source_score, bool $overwrite): int
    {
        if ($source_score === '' || $source_score === false) {
            return 0;
        }

        $existing_score = get_post_meta($post_id, PostMeta::SCORE, true);

        if (($existing_score === '' || $existing_score === false) || $overwrite) {
            $score_val = max(0, min(100, (int) $source_score));
            update_post_meta($post_id, PostMeta::SCORE, $score_val);

            return 1;
        }

        return 0;
    }

    /**
     * Imports SEO meta data (title, desc, OG, Twitter, canonical, robots)
     * into the _seopulse_meta_seo post meta
     *
     * @param int $post_id Post ID
     * @param array<string, string> $seo_data Mapped SEO data fields
     * @param bool $overwrite Overwrite existing data
     * @return int Number of imported fields
     */
    protected function importSeoMetaData(int $post_id, array $seo_data, bool $overwrite): int
    {
        if (empty($seo_data)) {
            return 0;
        }

        $existing_seo = get_post_meta($post_id, PostMeta::META_SEO, true);
        $existing_seo = is_array($existing_seo) ? $existing_seo : [];

        $final_seo = $overwrite ? $seo_data : array_merge($existing_seo, $seo_data);
        update_post_meta($post_id, PostMeta::META_SEO, $final_seo);

        return count($seo_data);
    }

    /**
     * Imports a redirection from source to SEOPulse
     *
     * @param int $post_id Post ID
     * @param string $redirect_url Redirect URL
     * @param string $redirect_type Redirect type (301, 302)
     * @param bool $overwrite Overwrite existing data
     * @return int Number of imported fields (0 or 2)
     */
    protected function importRedirection(int $post_id, string $redirect_url, string $redirect_type, bool $overwrite): int
    {
        if (empty($redirect_url)) {
            return 0;
        }

        $existing_url = get_post_meta($post_id, PostMeta::REDIRECT_URL, true);

        if (empty($existing_url) || $overwrite) {
            update_post_meta($post_id, PostMeta::REDIRECT_URL, esc_url_raw($redirect_url));
            update_post_meta(
                $post_id,
                PostMeta::REDIRECT_TYPE,
                in_array($redirect_type, ['301', '302'], true) ? $redirect_type : '301',
            );

            return 2;
        }

        return 0;
    }

    /**
     * Merges mapped data into a SEOPulse option with overwrite control
     *
     * @param string $option_key SEOPulse option key (e.g. Options::META_SEO_GLOBAL)
     * @param array<string, mixed> $mapped Mapped data to merge
     * @param bool $overwrite Overwrite existing data
     * @param array<string, mixed> &$result Accumulated result
     * @param string $import_label Label for options_imported tracking
     * @param string $skip_warning Warning message when skipping
     * @return bool True if data was imported
     */
    protected function mergeIntoOption(
        string $option_key,
        array $mapped,
        bool $overwrite,
        array &$result,
        string $import_label,
        string $skip_warning = '',
    ): bool {
        $current = get_option($option_key, []);

        if (!empty($current) && !$overwrite) {
            if (!empty($skip_warning)) {
                $result['warnings'][] = $skip_warning;
            }

            return false;
        }

        // Filter empty values
        $mapped = array_filter($mapped, fn ($v) => $v !== '' && $v !== null);

        if (empty($mapped)) {
            return false;
        }

        $final = $overwrite ? $mapped : array_merge($current, $mapped);
        update_option($option_key, $final);
        $result['options_imported'][] = $import_label;

        return true;
    }

    /**
     * Imports verification codes into META_SEO_GLOBAL
     *
     * @param array<string, string> $codes_map Source key => SEOPulse key mapping
     * @param array<string, mixed> $source_data Source option data
     * @param bool $overwrite Overwrite existing data
     * @param array<string, mixed> &$result Accumulated result
     * @return void
     */
    protected function importVerificationCodes(
        array $codes_map,
        array $source_data,
        bool $overwrite,
        array &$result,
    ): void {
        $current      = get_option(Options::META_SEO_GLOBAL, []);
        $imported_any = false;

        foreach ($codes_map as $source_key => $seopulse_key) {
            $value = $source_data[ $source_key ] ?? '';
            if (empty($value)) {
                continue;
            }

            if (!empty($current[ $seopulse_key ]) && !$overwrite) {
                $result['warnings'][] = sprintf(
                    /* translators: %s: verification service name */
                    __('%s verification already configured — skipped.', 'seopulse'),
                    ucfirst(str_replace(['verify', '_verify', '_'], ['', '', ' '], $source_key)),
                );
                continue;
            }

            $current[ $seopulse_key ] = sanitize_text_field($value);
            $imported_any             = true;
        }

        if ($imported_any) {
            update_option(Options::META_SEO_GLOBAL, $current);

            if (!in_array('meta_seo_global', $result['options_imported'], true)) {
                $result['options_imported'][] = 'verification';
            }
        }
    }
}
