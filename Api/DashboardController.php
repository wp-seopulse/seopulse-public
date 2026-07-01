<?php

/**
 * REST API Controller for the Dashboard Summary
 *
 * Exposes the DashboardSummary contract via a REST endpoint
 * so the admin UI (PHP or React) can consume it.
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Services\DashboardSummary;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

class DashboardController extends RestController
{
    public function __construct()
    {
        $this->rest_base = 'dashboard';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/summary',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_summary'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/refresh',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'refresh_summary'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/recent-analyses',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_recent_analyses'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );
    }

    /**
     * GET /seopulse/v1/dashboard/summary
     *
     * Returns the full dashboard summary contract.
     */
    public function get_summary(WP_REST_Request $request): WP_REST_Response
    {
        $summary = new DashboardSummary();

        return $this->success($summary->get());
    }

    /**
     * POST /seopulse/v1/dashboard/refresh
     *
     * Invalidates the cache and returns a fresh summary.
     */
    public function refresh_summary(WP_REST_Request $request): WP_REST_Response
    {
        DashboardSummary::invalidate();
        $summary = new DashboardSummary();

        return $this->success($summary->get());
    }

    /**
     * GET /seopulse/v1/dashboard/recent-analyses
     *
     * Returns the 10 most recently analysed posts with their SEO score.
     * Shape: Array<{ id, title, score, analyzed_at, edit_url }>
     */
    public function get_recent_analyses(WP_REST_Request $request): WP_REST_Response
    {
        $query = new WP_Query(
            [
                'post_type'      => ['post', 'page'],
                'post_status'    => 'publish',
                'posts_per_page' => 10,
                'meta_key'       => '_seopulse_last_analysis',
                'orderby'        => 'meta_value_num',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ],
        );

        $posts = $query->posts;
        if (!empty($posts)) {
            update_meta_cache('post', wp_list_pluck($posts, 'ID'));
        }

        $results = [];
        foreach ($posts as $post) {
            $post_id     = (int) $post->ID;
            $score       = (int) get_post_meta($post_id, '_seopulse_score', true);
            $analyzed_at = (int) get_post_meta($post_id, '_seopulse_last_analysis', true);

            $results[] = [
                'id'          => $post_id,
                'title'       => get_the_title($post),
                'score'       => $score,
                'analyzed_at' => $analyzed_at,
                'edit_url'    => get_edit_post_link($post_id, 'raw') ?: '',
            ];
        }

        return $this->success($results);
    }
}
