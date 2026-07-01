<?php

/**
 * REST API Controller — WordPress Dashboard Widget Overview
 *
 * Exposes the SeoOverviewWidget data as a REST endpoint so the
 * React component can fetch and refresh the data without a page reload.
 *
 * Auto-discovered by Kernel::buildClasses() from the Api/ directory.
 *
 * @package SEOPulse\Api
 * @since 1.1.2
 */

declare(strict_types=1);

namespace SEOPulse\Api;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Core\Constants\PostMeta;
use WP_REST_Request;
use WP_REST_Response;

class DashboardWidgetController extends RestController
{
    private const CACHE_KEY = 'seopulse_dashboard_widget_overview';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct()
    {
        $this->rest_base = 'dashboard/widget-overview';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_data'],
                    'permission_callback' => [$this, 'check_permissions'],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/refresh',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'refresh_data'],
                    'permission_callback' => [$this, 'check_permissions'],
                ],
            ],
        );
    }

    /**
     * GET /seopulse/v1/dashboard/widget-overview
     */
    public function get_data(WP_REST_Request $request): WP_REST_Response
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $this->success($cached);
        }

        $data = $this->compute_data();
        set_transient(self::CACHE_KEY, $data, self::CACHE_TTL);

        return $this->success($data);
    }

    /**
     * POST /seopulse/v1/dashboard/widget-overview/refresh
     */
    public function refresh_data(WP_REST_Request $request): WP_REST_Response
    {
        delete_transient(self::CACHE_KEY);
        $data = $this->compute_data();
        set_transient(self::CACHE_KEY, $data, self::CACHE_TTL);

        return $this->success($data);
    }

    /**
     * Computes all widget data from post meta.
     *
     * @return array{
     *   analyzed_count: int,
     *   avg_score: int,
     *   trend_delta: int,
     *   top_issues: list<array{module: string, label: string, count: int, quick_win: string}>,
     *   recent_posts: list<array{id: int, title: string, score: int, edit_url: string}>
     * }
     */
    private function compute_data(): array
    {
        global $wpdb;

        // Global stats: total count and average over ALL analyzed posts (matches dashboard)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $analyzed_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 WHERE p.post_status = 'publish'
                   AND p.post_type IN ('post', 'page')",
                PostMeta::SCORE,
            ),
        );

        if ($analyzed_count === 0) {
            return [
                'analyzed_count' => 0,
                'avg_score'      => 0,
                'trend_delta'    => 0,
                'top_issues'     => [],
                'recent_posts'   => [],
            ];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $avg_raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(CAST(pm.meta_value AS UNSIGNED))
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                     WHERE p.post_status = 'publish'
                       AND p.post_type IN ('post', 'page')",
                PostMeta::SCORE,
            ),
        );
        $avg_score = (int) round((float) $avg_raw);

        // Fetch the 10 most recently analyzed posts for issues and recent list
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $latest_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title,
                    pm_score.meta_value AS score,
                    pm_date.meta_value AS last_analysis,
                    pm_scores.meta_value AS detailed_scores
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_score
                 ON p.ID = pm_score.post_id AND pm_score.meta_key = %s
             INNER JOIN {$wpdb->postmeta} pm_date
                 ON p.ID = pm_date.post_id AND pm_date.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} pm_scores
                 ON p.ID = pm_scores.post_id AND pm_scores.meta_key = %s
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post', 'page')
             ORDER BY CAST(pm_date.meta_value AS UNSIGNED) DESC
             LIMIT 10",
                PostMeta::SCORE,
                PostMeta::LAST_ANALYSIS,
                PostMeta::SCORES,
            ),
            ARRAY_A,
        );

        $trend_delta = $this->compute_trend($wpdb);
        $top_issues  = $this->compute_top_issues($latest_posts ?? []);

        $seven_days_ago = time() - (7 * DAY_IN_SECONDS);
        $recent_posts   = [];
        foreach ($latest_posts ?? [] as $post) {
            if ((int) $post['last_analysis'] >= $seven_days_ago && count($recent_posts) < 5) {
                $recent_posts[] = [
                    'id'       => (int) $post['ID'],
                    'title'    => $post['post_title'],
                    'score'    => (int) $post['score'],
                    'edit_url' => (string) get_edit_post_link((int) $post['ID'], 'raw'),
                ];
            }
        }

        return [
            'analyzed_count' => $analyzed_count,
            'avg_score'      => $avg_score,
            'trend_delta'    => $trend_delta,
            'top_issues'     => $top_issues,
            'recent_posts'   => $recent_posts,
        ];
    }

    /**
     * Computes trend delta: current 30-day avg minus previous 30-day avg.
     */
    private function compute_trend(\wpdb $wpdb): int
    {
        $now            = time();
        $thirty_days    = 30 * DAY_IN_SECONDS;
        $current_start  = $now - $thirty_days;
        $previous_start = $now - (2 * $thirty_days);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $current_avg = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(CAST(pm_score.meta_value AS UNSIGNED))
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_score
                 ON p.ID = pm_score.post_id AND pm_score.meta_key = %s
             INNER JOIN {$wpdb->postmeta} pm_date
                 ON p.ID = pm_date.post_id AND pm_date.meta_key = %s
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post', 'page')
               AND CAST(pm_date.meta_value AS UNSIGNED) >= %d",
                PostMeta::SCORE,
                PostMeta::LAST_ANALYSIS,
                $current_start,
            ),
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $previous_avg = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(CAST(pm_score.meta_value AS UNSIGNED))
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_score
                 ON p.ID = pm_score.post_id AND pm_score.meta_key = %s
             INNER JOIN {$wpdb->postmeta} pm_date
                 ON p.ID = pm_date.post_id AND pm_date.meta_key = %s
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post', 'page')
               AND CAST(pm_date.meta_value AS UNSIGNED) >= %d
               AND CAST(pm_date.meta_value AS UNSIGNED) < %d",
                PostMeta::SCORE,
                PostMeta::LAST_ANALYSIS,
                $previous_start,
                $current_start,
            ),
        );

        return (int) round($current_avg - $previous_avg);
    }

    /**
     * Identifies the top 3 recurring issue categories from detailed scores.
     *
     * @param array<int, array<string, mixed>> $posts
     * @return list<array{module: string, label: string, count: int, quick_win: string}>
     */
    private function compute_top_issues(array $posts): array
    {
        $issue_counts = [];

        $labels = [
            'content'     => __('Content optimization', 'seopulse'),
            'meta'        => __('Meta tags (title & description)', 'seopulse'),
            'readability' => __('Readability', 'seopulse'),
            'technical'   => __('Technical SEO', 'seopulse'),
        ];

        $quick_wins = [
            'content'     => __('Add focus keywords and expand thin content.', 'seopulse'),
            'meta'        => __('Write unique meta titles and descriptions for each page.', 'seopulse'),
            'readability' => __('Shorten paragraphs and use subheadings, lists, and shorter sentences.', 'seopulse'),
            'technical'   => __('Fix heading hierarchy, add internal links, and add alt text to images.', 'seopulse'),
        ];

        foreach ($posts as $post) {
            $detailed = maybe_unserialize($post['detailed_scores'] ?? '');
            if (!is_array($detailed)) {
                continue;
            }

            foreach ($detailed as $module => $module_data) {
                $score = (int) ($module_data['score'] ?? 100);
                if ($score < 60 && isset($labels[ $module ])) {
                    $issue_counts[ $module ] = ($issue_counts[ $module ] ?? 0) + 1;
                }
            }
        }

        arsort($issue_counts);

        $top = [];
        $i   = 0;
        foreach ($issue_counts as $module => $count) {
            if ($i >= 3) {
                break;
            }
            $top[] = [
                'module'    => $module,
                'label'     => $labels[ $module ],
                'count'     => $count,
                'quick_win' => $quick_wins[ $module ],
            ];
            ++$i;
        }

        return $top;
    }
}
