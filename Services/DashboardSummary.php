<?php

/**
 * Dashboard summary data contract
 *
 * Aggregates existing plugin data into a single, stable structure
 * for the admin dashboard. Does not duplicate logic — reads from
 * existing options, post meta and managers.
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- DashboardSummary: direct DB access is intentional; caching is handled at the service/caller layer.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- All interpolated vars are safe prefixed table names ($wpdb->postmeta, $wpdb->posts).

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Kernel;
use SEOPulse\Modules\MetaSeo\Archives\ArchiveSettingsManager;
use SEOPulse\Modules\Monitor404\Monitor404Repository;

/**
 * DashboardSummary class
 */
final class DashboardSummary
{
    /** Transient key for cached summary data. */
    private const CACHE_KEY = 'seopulse_dashboard_summary_v1';

    /** Cache TTL in seconds (5 minutes). */
    private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * Deletes the cached dashboard summary, forcing a fresh rebuild on next load.
     * Call this after any action that changes dashboard-relevant data.
     *
     * @return void
     */
    public static function invalidate(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Returns the full dashboard summary contract.
     *
     * @return array{
     *   wizard_complete: bool,
     *   configuration_score: int,
     *   checklist: list<array{key: string, label: string, done: bool, action_url: string}>,
     *   sitemap_status: array{module_active: bool, configured: bool},
     *   indexation_status: array{author_noindex: bool, date_noindex: bool, search_noindex: bool},
     *   404_tracking_status: array{enabled: bool, logged_count: int},
     *   content_stats: array{analyzed_count: int, avg_score: int, needs_improvement: int, missing_meta: int, missing_featured_image: int, missing_alt: int},
     *   top_quick_wins: list<array{type: string, label: string, description: string, action_url: string, priority: string}>,
     *   modules_status: array<string, bool>,
     *   image_issues: list<array{id: int, title: string, edit_url: string, issue: string}>
     * }
     */
    public function get(): array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $wizard_complete   = (bool) get_option(Options::SETUP_COMPLETE, false);
        $wizard_profile    = (array) get_option(Options::WIZARD_PROFILE, []);
        $sitemap_status    = $this->get_sitemap_status();
        $indexation_status = $this->get_indexation_status();
        $tracking_status   = $this->get_404_tracking_status();
        $content_stats     = $this->get_content_stats();
        $modules_status    = $this->get_modules_status();
        $https_ok          = $this->is_https();
        $image_issues      = $this->get_image_issues();
        $image_seo         = $this->get_image_seo_block($content_stats, $image_issues);
        $instant_indexing  = $this->get_instant_indexing_block();

        $checklist = $this->build_checklist(
            $wizard_complete,
            $sitemap_status,
            $indexation_status,
            $tracking_status,
            $content_stats,
            $https_ok,
        );

        $configuration_score = $this->calculate_configuration_score($checklist);

        $quick_wins = $this->build_quick_wins(
            $wizard_complete,
            $content_stats,
            $https_ok,
            $sitemap_status,
            $tracking_status,
        );

        $health_checks = $this->build_health_checks(
            $https_ok,
            $sitemap_status,
            $indexation_status,
            $tracking_status,
        );

        $data = [
            'wizard_complete'     => $wizard_complete,
            'wizard_profile'      => $wizard_profile,
            'configuration_score' => $configuration_score,
            'checklist'           => $checklist,
            'sitemap_status'      => $sitemap_status,
            'indexation_status'   => $indexation_status,
            '404_tracking_status' => $tracking_status,
            'content_stats'       => $content_stats,
            'top_quick_wins'      => $quick_wins,
            'modules_status'      => $modules_status,
            'health_checks'       => $health_checks,
            'image_issues'        => $image_issues,
            'image_seo'           => $image_seo,
            'technical_audit'     => $this->get_technical_audit_block($health_checks),
            'instant_indexing'    => $instant_indexing,
            'score_history'       => $this->get_score_history((int) $content_stats['avg_score']),
        ];

        set_transient(self::CACHE_KEY, $data, self::CACHE_TTL);

        return $data;
    }

    /**
     * Returns and persists a 14-day rolling score history.
     *
     * Each entry: ['date' => 'YYYY-MM-DD', 'score' => int]
     *
     * @param int $today_score Current avg score to record for today.
     * @return list<array{date: string, score: int}>
     */
    private function get_score_history(int $today_score): array
    {
        $option_key = 'seopulse_score_history';
        $history    = (array) get_option($option_key, []);
        $today      = gmdate('Y-m-d');

        // Replace or append today's entry; track whether a DB write is needed.
        $needs_save = false;
        $found      = false;
        foreach ($history as &$entry) {
            if (is_array($entry) && ($entry['date'] ?? '') === $today) {
                if ((int) ($entry['score'] ?? -1) !== $today_score) {
                    $entry['score'] = $today_score;
                    $needs_save     = true;
                }
                $found = true;
                break;
            }
        }
        unset($entry);

        if (!$found) {
            $history[]  = ['date' => $today, 'score' => $today_score];
            $needs_save = true;
        }

        if ($needs_save) {
            // Keep only the last 14 days, sorted ascending by date
            usort($history, static fn ($a, $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
            $history = array_slice($history, -14);
            update_option($option_key, $history, false);
        }

        return $history;
    }

    /**
     * Sitemap module status from existing option.
     *
     * @return array{module_active: bool, configured: bool}
     */
    private function get_sitemap_status(): array
    {
        $module_active = Kernel::isModuleEnabled('sitemap');
        $settings      = (array) get_option(Options::SITEMAP, []);

        // Consider configured if the module is active and settings have been saved
        $configured = $module_active && !empty($settings);

        return [
            'module_active' => $module_active,
            'configured'    => $configured,
        ];
    }

    /**
     * Indexation status from archive settings.
     *
     * @return array{author_noindex: bool, date_noindex: bool, search_noindex: bool}
     */
    private function get_indexation_status(): array
    {
        $manager  = new ArchiveSettingsManager();
        $settings = $manager->getAll();

        $author = $settings['author'] ?? [];
        $date   = $settings['date'] ?? [];
        $search = $settings['search'] ?? [];

        return [
            'author_noindex' => !empty($author['noindex_single_author']) || !empty($author['noindex_empty_authors']),
            'date_noindex'   => str_contains($date['robots'] ?? '', 'noindex'),
            'search_noindex' => str_contains($search['robots'] ?? '', 'noindex'),
        ];
    }

    /**
     * 404 tracking status from Monitor404 module.
     *
     * @return array{enabled: bool, logged_count: int, total_hits: int, recurring_count: int}
     */
    private function get_404_tracking_status(): array
    {
        $enabled = Kernel::isModuleEnabled('monitor_404');

        if (!$enabled) {
            return [
                'enabled'         => false,
                'logged_count'    => 0,
                'total_hits'      => 0,
                'recurring_count' => 0,
            ];
        }

        $repo    = new Monitor404Repository();
        $summary = $repo->getSummary();

        return [
            'enabled'         => true,
            'logged_count'    => $summary['unique_urls'],
            'total_hits'      => $summary['total_hits'],
            'recurring_count' => $summary['active'],
        ];
    }

    /**
     * Content analysis statistics from post meta.
     *
     * @return array{analyzed_count: int, avg_score: int, needs_improvement: int, missing_meta: int, missing_featured_image: int, missing_alt: int}
     */
    private function get_content_stats(): array
    {
        global $wpdb;

        $analyzed_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 WHERE p.post_status = 'publish'
                 AND p.post_type IN ('post', 'page')",
                '_seopulse_score',
            ),
        );

        $avg_score = 0;
        if ($analyzed_count > 0) {
            $avg_score = (int) round(
                (float) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT AVG(CAST(pm.meta_value AS UNSIGNED))
                         FROM {$wpdb->posts} p
                         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                         WHERE p.post_status = 'publish'
                         AND p.post_type IN ('post', 'page')",
                        '_seopulse_score',
                    ),
                ),
            );
        }

        $needs_improvement = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 WHERE p.post_status = 'publish'
                 AND p.post_type IN ('post', 'page')
                 AND CAST(pm.meta_value AS UNSIGNED) < 60",
                '_seopulse_score',
            ),
        );

        $missing_meta = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 LEFT JOIN {$wpdb->postmeta} md ON p.ID = md.post_id AND md.meta_key = %s
                 WHERE p.post_status = %s
                 AND p.post_type IN (%s, %s)
                 AND (md.meta_value IS NULL OR md.meta_value = %s)",
                '_seopulse_score',
                '_seopulse_meta_description',
                'publish',
                'post',
                'page',
                '',
            ),
        );

        // Image SEO: published posts without a featured image
        $missing_featured_image = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(p.ID)
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} t ON p.ID = t.post_id AND t.meta_key = %s
                 WHERE p.post_status = %s
                 AND p.post_type IN (%s, %s)
                 AND (t.meta_value IS NULL OR t.meta_value = %s)",
                '_thumbnail_id',
                'publish',
                'post',
                'page',
                '',
            ),
        );

        // Image SEO: analyzed posts flagged with missing alt
        $missing_alt = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 WHERE p.post_status = 'publish'
                 AND p.post_type IN ('post', 'page')
                 AND CAST(pm.meta_value AS UNSIGNED) > 0",
                '_seopulse_images_without_alt',
            ),
        );

        return [
            'analyzed_count'         => $analyzed_count,
            'avg_score'              => $avg_score,
            'needs_improvement'      => $needs_improvement,
            'missing_meta'           => $missing_meta,
            'missing_featured_image' => $missing_featured_image,
            'missing_alt'            => $missing_alt,
        ];
    }

    /**
     * Module enabled/disabled status.
     *
     * @return array<string, bool>
     */
    private function get_modules_status(): array
    {
        $definitions = Kernel::getModulesDefinition();
        $status      = [];

        foreach (array_keys($definitions) as $key) {
            $status[ $key ] = Kernel::isModuleEnabled($key);
        }

        return $status;
    }

    /**
     * Whether the site uses HTTPS.
     */
    private function is_https(): bool
    {
        return is_ssl() || str_starts_with(get_site_url(), 'https://');
    }

    /**
     * Returns posts with image SEO issues (missing featured image or missing alt).
     *
     * Lightweight query limited to 20 rows to avoid admin overhead.
     *
     * @return list<array{id: int, title: string, edit_url: string, issue: string}>
     */
    public function get_image_issues(): array
    {
        global $wpdb;

        // Posts without featured image
        $no_thumb = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} t ON p.ID = t.post_id AND t.meta_key = %s
                 WHERE p.post_status = %s
                 AND p.post_type IN (%s, %s)
                 AND (t.meta_value IS NULL OR t.meta_value = %s)
                 ORDER BY p.post_modified DESC
                 LIMIT 10",
                '_thumbnail_id',
                'publish',
                'post',
                'page',
                '',
            ),
        );

        // Posts flagged with missing alt (from analysis)
        $no_alt = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    AND pm.meta_key = %s
                    AND CAST(pm.meta_value AS UNSIGNED) > 0
                 WHERE p.post_status = %s
                 AND p.post_type IN (%s, %s)
                 ORDER BY p.post_modified DESC
                 LIMIT 10",
                '_seopulse_images_without_alt',
                'publish',
                'post',
                'page',
            ),
        );

        $items = [];

        foreach ($no_thumb as $row) {
            $id      = (int) $row->ID;
            $items[] = [
                'id'       => $id,
                'title'    => $row->post_title,
                'edit_url' => get_edit_post_link($id, 'raw') ?: '',
                'issue'    => 'no_featured_image',
            ];
        }

        foreach ($no_alt as $row) {
            $id      = (int) $row->ID;
            $items[] = [
                'id'       => $id,
                'title'    => $row->post_title,
                'edit_url' => get_edit_post_link($id, 'raw') ?: '',
                'issue'    => 'missing_alt',
            ];
        }

        // Sort by issue priority (missing featured first) then limit
        return array_slice($items, 0, 20);
    }

    /**
     * Builds the configuration checklist.
     *
     * Each item carries a weight used to compute the configuration score.
     *
     * @return list<array{key: string, label: string, done: bool, action_url: string, weight: int}>
     */
    private function build_checklist(
        bool $wizard_complete,
        array $sitemap_status,
        array $indexation_status,
        array $tracking_status,
        array $content_stats,
        bool $https_ok,
    ): array {
        return [
            [
                'key'        => 'wizard',
                'label'      => __('Complete the setup wizard', 'seopulse'),
                'done'       => $wizard_complete,
                'action_url' => admin_url('admin.php?page=seopulse-setup-wizard'),
                'weight'     => 25,
            ],
            [
                'key'        => 'https',
                'label'      => __('Enable HTTPS on your site', 'seopulse'),
                'done'       => $https_ok,
                'action_url' => '',
                'weight'     => 15,
            ],
            [
                'key'        => 'sitemap',
                'label'      => __('Activate and configure the sitemap', 'seopulse'),
                'done'       => $sitemap_status['configured'],
                'action_url' => admin_url('admin.php?page=seopulse-sitemap'),
                'weight'     => 15,
            ],
            [
                'key'        => 'indexation',
                'label'      => __('Configure archive search visibility', 'seopulse'),
                'done'       => $indexation_status['search_noindex'] && $indexation_status['date_noindex'],
                'action_url' => admin_url('admin.php?page=seopulse-meta-seo#tab=archives'),
                'weight'     => 15,
            ],
            [
                'key'        => '404_tracking',
                'label'      => __('Enable 404 error tracking', 'seopulse'),
                'done'       => $tracking_status['enabled'],
                'action_url' => admin_url('admin.php?page=seopulse-404-monitor'),
                'weight'     => 10,
            ],
            [
                'key'        => 'first_analysis',
                'label'      => __('Analyze at least one post', 'seopulse'),
                'done'       => $content_stats['analyzed_count'] > 0,
                'action_url' => admin_url('edit.php'),
                'weight'     => 20,
            ],
        ];
    }

    /**
     * Calculates the configuration score (0-100) from checklist weights.
     *
     * @param list<array{done: bool, weight: int}> $checklist
     * @return int
     */
    private function calculate_configuration_score(array $checklist): int
    {
        $score = 0;

        foreach ($checklist as $item) {
            if ($item['done']) {
                $score += $item['weight'];
            }
        }

        return min(100, $score);
    }

    /**
     * Builds the top quick wins list (max 5).
     *
     * Uses the same field shape as RecommendationPrioritizer so that
     * dashboard quick wins and editorial recommendations share a
     * unified contract.
     *
     * @return list<array{type: string, label: string, description: string, action_url: string, priority: string, category: string, estimated_impact: int, difficulty: string, icon: string, pro_only: bool}>
     */
    private function build_quick_wins(
        bool $wizard_complete,
        array $content_stats,
        bool $https_ok,
        array $sitemap_status,
        array $tracking_status = [],
    ): array {
        $wins = [];

        if (!$wizard_complete) {
            $wins[] = [
                'type'             => 'setup',
                'label'            => __('Complete the setup wizard', 'seopulse'),
                'description'      => __('Configure SEOPulse to unlock its full potential.', 'seopulse'),
                'action_url'       => admin_url('admin.php?page=seopulse-setup-wizard'),
                'priority'         => 'critical',
                'category'         => RecommendationPrioritizer::CATEGORY_BLOCKER,
                'estimated_impact' => 25,
                'difficulty'       => 'easy',
                'icon'             => 'admin-generic',
                'pro_only'         => false,
            ];
        }

        if (!$https_ok) {
            $wins[] = [
                'type'             => 'technical',
                'label'            => __('Switch your site to HTTPS', 'seopulse'),
                'description'      => __('Search engines penalize non-secure sites.', 'seopulse'),
                'action_url'       => '',
                'priority'         => 'critical',
                'category'         => RecommendationPrioritizer::CATEGORY_BLOCKER,
                'estimated_impact' => 15,
                'difficulty'       => 'hard',
                'icon'             => 'lock',
                'pro_only'         => false,
            ];
        }

        if (!$sitemap_status['configured']) {
            $wins[] = [
                'type'             => 'technical',
                'label'            => __('Configure your XML sitemap', 'seopulse'),
                'description'      => __('Help search engines discover all your pages.', 'seopulse'),
                'action_url'       => admin_url('admin.php?page=seopulse-sitemap'),
                'priority'         => 'high',
                'category'         => RecommendationPrioritizer::CATEGORY_QUICK_WIN,
                'estimated_impact' => 15,
                'difficulty'       => 'easy',
                'icon'             => 'sitemap',
                'pro_only'         => false,
            ];
        }

        if ($content_stats['missing_meta'] > 0) {
            $wins[] = [
                'type'             => 'content',
                'label'            => sprintf(
                    /* translators: %d: number of posts missing meta descriptions */
                    _n(
                        'Add a meta description to %d post',
                        'Add meta descriptions to %d posts',
                        $content_stats['missing_meta'],
                        'seopulse',
                    ),
                    $content_stats['missing_meta'],
                ),
                'description'      => __('Meta descriptions improve click-through rates from search results.', 'seopulse'),
                'action_url'       => admin_url('edit.php'),
                'priority'         => 'high',
                'category'         => RecommendationPrioritizer::CATEGORY_QUICK_WIN,
                'estimated_impact' => 20,
                'difficulty'       => 'easy',
                'icon'             => 'media-document',
                'pro_only'         => false,
            ];
        }

        if ($content_stats['needs_improvement'] > 0) {
            $wins[] = [
                'type'             => 'content',
                'label'            => sprintf(
                    /* translators: %d: number of posts with low SEO scores */
                    _n(
                        'Improve the SEO score of %d post',
                        'Improve the SEO scores of %d posts',
                        $content_stats['needs_improvement'],
                        'seopulse',
                    ),
                    $content_stats['needs_improvement'],
                ),
                'description'      => __('Posts scoring below 60 need attention to rank well.', 'seopulse'),
                'action_url'       => admin_url('edit.php'),
                'priority'         => 'medium',
                'category'         => RecommendationPrioritizer::CATEGORY_IMPROVEMENT,
                'estimated_impact' => 10,
                'difficulty'       => 'medium',
                'icon'             => 'chart-line',
                'pro_only'         => false,
            ];
        }

        if ($content_stats['analyzed_count'] === 0) {
            $wins[] = [
                'type'             => 'content',
                'label'            => __('Run your first SEO analysis', 'seopulse'),
                'description'      => __('Edit a post and click "Analyze" to see optimization tips.', 'seopulse'),
                'action_url'       => admin_url('edit.php'),
                'priority'         => 'high',
                'category'         => RecommendationPrioritizer::CATEGORY_QUICK_WIN,
                'estimated_impact' => 20,
                'difficulty'       => 'easy',
                'icon'             => 'chart-bar',
                'pro_only'         => false,
            ];
        }

        // Image SEO quick wins
        if ($content_stats['missing_alt'] > 0) {
            $wins[] = [
                'type'             => 'image_seo',
                'label'            => sprintf(
                    /* translators: %d: number of posts with images missing alt text */
                    _n(
                        'Add alt text to images in %d post',
                        'Add alt text to images in %d posts',
                        $content_stats['missing_alt'],
                        'seopulse',
                    ),
                    $content_stats['missing_alt'],
                ),
                'description'      => __('Alt text improves accessibility and helps search engines understand your images.', 'seopulse'),
                'action_url'       => admin_url('admin.php?page=seopulse-image-diagnostic'),
                'priority'         => 'high',
                'category'         => RecommendationPrioritizer::CATEGORY_QUICK_WIN,
                'estimated_impact' => 8,
                'difficulty'       => 'easy',
                'icon'             => 'format-image',
                'pro_only'         => false,
            ];
        }

        if ($content_stats['missing_featured_image'] > 0) {
            $wins[] = [
                'type'             => 'image_seo',
                'label'            => sprintf(
                    /* translators: %d: number of posts missing a featured image */
                    _n(
                        'Add a featured image to %d post',
                        'Add featured images to %d posts',
                        $content_stats['missing_featured_image'],
                        'seopulse',
                    ),
                    $content_stats['missing_featured_image'],
                ),
                'description'      => __('Featured images increase click-through rates from search results and social sharing.', 'seopulse'),
                'action_url'       => admin_url('edit.php'),
                'priority'         => 'medium',
                'category'         => RecommendationPrioritizer::CATEGORY_IMPROVEMENT,
                'estimated_impact' => 10,
                'difficulty'       => 'easy',
                'icon'             => 'images-alt2',
                'pro_only'         => false,
            ];
        }

        $recurring_404s = $tracking_status['recurring_count'] ?? 0;
        if ($recurring_404s > 0) {
            $wins[] = [
                'type'             => 'technical',
                'label'            => sprintf(
                    /* translators: %d: number of recurring 404 URLs */
                    _n(
                        'Fix %d recurring 404 error',
                        'Fix %d recurring 404 errors',
                        $recurring_404s,
                        'seopulse',
                    ),
                    $recurring_404s,
                ),
                'description'      => __('Recurring 404 errors waste crawl budget and hurt user experience. Create redirections for broken URLs.', 'seopulse'),
                'action_url'       => admin_url('admin.php?page=seopulse-404-monitor'),
                'priority'         => 'high',
                'category'         => RecommendationPrioritizer::CATEGORY_QUICK_WIN,
                'estimated_impact' => 15,
                'difficulty'       => 'easy',
                'icon'             => 'warning',
                'pro_only'         => false,
            ];
        }

        return array_slice($wins, 0, 5);
    }

    /**
     * Builds the technical health checks array.
     *
     * Each check produces a simple, actionable diagnostic signal.
     *
     * @return list<array{key: string, label: string, status: string, detail: string, action_url: string, icon: string}>
     */
    private function build_health_checks(
        bool $https_ok,
        array $sitemap_status,
        array $indexation_status,
        array $tracking_status,
    ): array {
        $checks = [];

        // 1. HTTPS
        $checks[] = [
            'key'        => 'https',
            'label'      => __('HTTPS', 'seopulse'),
            'status'     => $https_ok ? 'pass' : 'fail',
            'detail'     => $https_ok
                ? __('Your site uses a secure connection.', 'seopulse')
                : __('Your site is not served over HTTPS.', 'seopulse'),
            'action_url' => '',
            'icon'       => 'dashicons-lock',
        ];

        // 2. Search engine visibility (blog_public)
        $blog_public = (string) get_option('blog_public', '1');
        $checks[]    = [
            'key'        => 'search_visibility',
            'label'      => __('Search engine visibility', 'seopulse'),
            'status'     => $blog_public !== '0' ? 'pass' : 'fail',
            'detail'     => $blog_public !== '0'
                ? __('Search engines are allowed to index your site.', 'seopulse')
                : __('Search engines are blocked from indexing your site.', 'seopulse'),
            'action_url' => admin_url('options-reading.php'),
            'icon'       => 'dashicons-visibility',
        ];

        // 3. Permalink structure
        $permalink_structure = (string) get_option('permalink_structure', '');
        $checks[]            = [
            'key'        => 'permalinks',
            'label'      => __('Permalink structure', 'seopulse'),
            'status'     => $permalink_structure !== '' ? 'pass' : 'warn',
            'detail'     => $permalink_structure !== ''
                ? __('SEO-friendly permalinks are configured.', 'seopulse')
                : __('Plain permalinks are not optimal for SEO.', 'seopulse'),
            'action_url' => admin_url('options-permalink.php'),
            'icon'       => 'dashicons-admin-links',
        ];

        // 4. Robots.txt
        $has_physical_robots = file_exists(ABSPATH . 'robots.txt');
        $has_virtual_robots  = Kernel::isModuleEnabled('sitemap');
        $robots_ok           = $has_physical_robots || $has_virtual_robots;
        $checks[]            = [
            'key'        => 'robots_txt',
            'label'      => __('Robots.txt', 'seopulse'),
            'status'     => $robots_ok ? 'pass' : 'warn',
            'detail'     => $robots_ok
                ? ($has_physical_robots
                    ? __('Physical robots.txt file found.', 'seopulse')
                    : __('Virtual robots.txt served by SEOPulse.', 'seopulse'))
                : __('No robots.txt detected. Enable the sitemap module to generate one.', 'seopulse'),
            'action_url' => admin_url('admin.php?page=seopulse-sitemap'),
            'icon'       => 'dashicons-media-text',
        ];

        // 5. Sitemap
        $checks[] = [
            'key'        => 'sitemap',
            'label'      => __('XML Sitemap', 'seopulse'),
            'status'     => $sitemap_status['configured'] ? 'pass' : ($sitemap_status['module_active'] ? 'warn' : 'fail'),
            'detail'     => $sitemap_status['configured']
                ? __('Sitemap is active and configured.', 'seopulse')
                : ($sitemap_status['module_active']
                    ? __('Sitemap module is active but not yet configured.', 'seopulse')
                    : __('Sitemap module is disabled.', 'seopulse')),
            'action_url' => admin_url('admin.php?page=seopulse-sitemap'),
            'icon'       => 'dashicons-networking',
        ];

        // 6. Archive indexation
        $archives_ok = $indexation_status['search_noindex'] && $indexation_status['date_noindex'];
        $checks[]    = [
            'key'        => 'archive_indexation',
            'label'      => __('Archive indexation', 'seopulse'),
            'status'     => $archives_ok ? 'pass' : 'warn',
            'detail'     => $archives_ok
                ? __('Search and date archives are noindexed.', 'seopulse')
                : __('Some archives may be indexed by search engines.', 'seopulse'),
            'action_url' => admin_url('admin.php?page=seopulse-meta-seo#tab=archives'),
            'icon'       => 'dashicons-archive',
        ];

        // 7. 404 tracking
        $tracking_ok = $tracking_status['enabled'] && ($tracking_status['recurring_count'] ?? 0) === 0;
        if ($tracking_status['enabled']) {
            $tracking_detail = ($tracking_status['recurring_count'] ?? 0) > 0
                ? sprintf(
                    /* translators: %d: number of recurring 404 errors */
                    _n('%d recurring 404 needs attention', '%d recurring 404s need attention', $tracking_status['recurring_count'], 'seopulse'),
                    $tracking_status['recurring_count'],
                )
                : __('404 tracking is active, no recurring errors.', 'seopulse');
        } else {
            $tracking_detail = __('404 tracking is disabled.', 'seopulse');
        }
        $checks[] = [
            'key'        => '404_tracking',
            'label'      => __('404 Tracking', 'seopulse'),
            'status'     => $tracking_ok ? 'pass' : ($tracking_status['enabled'] ? 'warn' : 'fail'),
            'detail'     => $tracking_detail,
            'action_url' => admin_url('admin.php?page=seopulse-404-monitor'),
            'icon'       => 'dashicons-warning',
        ];

        // 8. Active redirections
        $redirections     = (array) get_option(Options::REDIRECTIONS, []);
        $active_redirects = 0;
        foreach ($redirections as $r) {
            if (!empty($r['enabled'])) {
                ++$active_redirects;
            }
        }
        $has_redirections = $active_redirects > 0;
        $checks[]         = [
            'key'        => 'redirections',
            'label'      => __('Redirects Manager', 'seopulse'),
            'status'     => $has_redirections ? 'pass' : 'info',
            'detail'     => $has_redirections
                ? sprintf(
                    /* translators: %d: number of active redirections */
                    _n('%d active redirection', '%d active redirections', $active_redirects, 'seopulse'),
                    $active_redirects,
                )
                : __('No redirections configured yet.', 'seopulse'),
            'action_url' => admin_url('admin.php?page=seopulse-redirections'),
            'icon'       => 'dashicons-randomize',
        ];

        return $checks;
    }

    /**
     * Returns the Image SEO aggregate block for the dashboard.
     *
     * Built from already-computed $content_stats and $image_issues to avoid
     * duplicate queries.
     *
     * @param array $content_stats Output of get_content_stats().
     * @param list<array{id: int, title: string, edit_url: string, issue: string}> $image_issues
     * @return array{missing_alt_count: int, missing_featured_count: int, has_issues: bool, top_offenders: list<array>}
     */
    private function get_image_seo_block(array $content_stats, array $image_issues): array
    {
        $missing_alt_count      = (int) ($content_stats['missing_alt'] ?? 0);
        $missing_featured_count = (int) ($content_stats['missing_featured_image'] ?? 0);

        // Media-library level diagnostics
        $filler      = new ImageAltFiller();
        $diagnostics = $filler->get_diagnostics();

        // Count images with non-SEO-friendly filenames
        $poor_filename_count = $this->count_poor_filenames($filler);

        return [
            'missing_alt_count'      => $missing_alt_count,
            'missing_featured_count' => $missing_featured_count,
            'total_images'           => $diagnostics['total_images'],
            'media_missing_alt'      => $diagnostics['missing_alt'],
            'poor_filename_count'    => $poor_filename_count,
            'has_issues'             => $missing_alt_count > 0 || $missing_featured_count > 0 || $diagnostics['missing_alt'] > 0 || $poor_filename_count > 0,
            'top_offenders'          => array_slice($image_issues, 0, 5),
        ];
    }

    /**
     * Count media-library images with non-SEO-friendly filenames.
     *
     * Queries attachment slugs directly via SQL for performance,
     * then filters using ImageAltFiller::is_seo_friendly().
     *
     * @param ImageAltFiller $filler
     * @return int
     */
    private function count_poor_filenames(ImageAltFiller $filler): int
    {
        global $wpdb;

        $slugs = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_name FROM {$wpdb->posts}
                 WHERE post_type = %s
                 AND post_mime_type IN ('image/jpeg','image/png','image/webp','image/gif')
                 AND post_status = %s
                 LIMIT %d",
                'attachment',
                'inherit',
                500,
            ),
        );

        $count = 0;
        foreach ($slugs as $slug) {
            if (!$filler->is_seo_friendly($slug)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Returns the Technical Audit block — aggregated view of health_checks
     * with issue/pass counters and normalized severity for dashboard display.
     *
     * Severity mapping (shared vocabulary with AdminNotificationPanel):
     *   fail → blocker   (must fix)
     *   warn → action    (recommended action)
     *   pass → ok        (healthy)
     *   info → info      (informational)
     *
     * @param list<array{key: string, label: string, status: string, detail: string, action_url: string, icon: string}> $health_checks
     * @return array{checks: list<array>, issues_count: int, pass_count: int, total: int}
     */
    private function get_technical_audit_block(array $health_checks): array
    {
        $status_to_severity = [
            'fail' => 'blocker',
            'warn' => 'action',
            'pass' => 'ok',
            'info' => 'info',
        ];

        $checks = [];
        foreach ($health_checks as $check) {
            $check['severity'] = $status_to_severity[ $check['status'] ] ?? 'info';
            $checks[]          = $check;
        }

        $issues_count = count(
            array_filter(
                $checks,
                static fn (array $c): bool => in_array($c['status'], ['fail', 'warn'], true),
            ),
        );

        $pass_count = count(
            array_filter(
                $checks,
                static fn (array $c): bool => $c['status'] === 'pass',
            ),
        );

        return [
            'checks'       => $checks,
            'issues_count' => $issues_count,
            'pass_count'   => $pass_count,
            'total'        => count($checks),
        ];
    }

    /**
     * Returns the Instant Indexing status block.
     *
     * Reads from the seopulse_indexing_log table (populated by Q4D-08
     * Instant Indexing). Returns safe defaults when no submissions exist.
     *
     * @return array{last_submitted_url: string, last_submitted_at: int|null, submissions_today: int, available: bool}
     */
    private function get_instant_indexing_block(): array
    {
        $recent = IndexingLogger::getRecent(1);

        if (empty($recent)) {
            return [
                'last_submitted_url' => '',
                'last_submitted_at'  => null,
                'submissions_today'  => 0,
                'available'          => true,
            ];
        }

        $last = $recent[0];

        return [
            'last_submitted_url' => (string) ($last->url ?? ''),
            'last_submitted_at'  => isset($last->timestamp) ? strtotime($last->timestamp) : null,
            'submissions_today'  => IndexingLogger::count(),
            'available'          => true,
        ];
    }
}
