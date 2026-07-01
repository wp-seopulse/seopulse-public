<?php

/**
 * REST API controller for recommendations
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\Content;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * RecommendationsController class
 */
class RecommendationsController extends RestController
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base = 'recommendations';
    }

    /**
     * Registers routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // GET /seopulse/v1/recommendations/{post_id}
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_recommendations'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id' => [
                            'required' => true,
                            'type'     => 'integer',
                        ],
                        'limit'   => [
                            'required'          => false,
                            'type'              => 'integer',
                            'default'           => 0,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ],
        );

        // POST /seopulse/v1/recommendations/{post_id}/dismiss
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)/dismiss',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'dismiss_recommendation'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id'           => [
                            'required' => true,
                            'type'     => 'integer',
                        ],
                        'recommendation_id' => [
                            'required' => true,
                            'type'     => 'string',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Retrieves recommendations for a post
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function get_recommendations(WP_REST_Request $request)
    {
        $post_id = $request->get_param('post_id');
        $limit   = (int) $request->get_param('limit');

        // Retrieve from cache
        $cache    = new \SEOPulse\Services\CacheManager();
        $analysis = $cache->get_analysis($post_id);

        if ($analysis === null) {
            return $this->error(
                __('No analysis found for this post.', 'seopulse'),
                404,
            );
        }

        $recommendations = $analysis['recommendations'] ?? [];

        // When limit is set, return only the top N recommendations
        if ($limit > 0 && isset($recommendations['top'])) {
            $recommendations['top'] = array_slice($recommendations['top'], 0, $limit);
        }

        return $this->success($recommendations);
    }

    /**
     * Marks a recommendation as dismissed
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function dismiss_recommendation(WP_REST_Request $request)
    {
        $post_id = $request->get_param('post_id');
        $rec_id  = $request->get_param('recommendation_id');

        // Retrieve dismissed recommendations
        $dismissed = get_post_meta($post_id, '_seopulse_dismissed_recommendations', true);
        if (!is_array($dismissed)) {
            $dismissed = [];
        }

        // Add the ID
        if (!in_array($rec_id, $dismissed, true)) {
            $dismissed[] = $rec_id;
            update_post_meta($post_id, '_seopulse_dismissed_recommendations', $dismissed);
        }

        return $this->success(
            [
                'message'         => __('Recommendation dismissed.', 'seopulse'),
                'dismissed_count' => count($dismissed),
            ],
        );
    }
}
