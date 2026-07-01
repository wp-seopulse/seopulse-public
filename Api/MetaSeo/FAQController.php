<?php

/**
 * REST API controller for FAQ management
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\MetaSeo;

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Services\FAQParser;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FAQController — REST API endpoints for FAQ management
 */
class FAQController extends RestController
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base = 'posts/(?P<post_id>\d+)/faqs';
    }

    /**
     * Registers routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // GET /seopulse/v1/posts/{post_id}/faqs
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_faqs'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'post_id' => [
                        'type'        => 'integer',
                        'required'    => true,
                        'description' => 'The post ID',
                    ],
                ],
            ],
        );

        // POST /seopulse/v1/posts/{post_id}/faqs
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'update_faqs'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'post_id' => [
                        'type'        => 'integer',
                        'required'    => true,
                        'description' => 'The post ID',
                    ],
                    'faqs'    => [
                        'type'        => 'array',
                        'required'    => true,
                        'description' => 'Array of FAQ items',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'question' => ['type' => 'string'],
                                'answer'   => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        );

        // DELETE /seopulse/v1/posts/{post_id}/faqs/{faq_index}
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<faq_index>\d+)',
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_faq'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'post_id'   => [
                        'type'        => 'integer',
                        'required'    => true,
                        'description' => 'The post ID',
                    ],
                    'faq_index' => [
                        'type'        => 'integer',
                        'required'    => true,
                        'description' => 'The FAQ item index',
                    ],
                ],
            ],
        );
    }

    /**
     * Retrieve FAQs for a post
     *
     * GET /seopulse/v1/posts/{post_id}/faqs
     *
     * @param WP_REST_Request $request Request
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_faqs(WP_REST_Request $request)
    {
        $post_id = (int) $request->get_param('post_id');

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            return $this->error(__('Post not found.', 'seopulse'), 404);
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return $this->error(__('You do not have permission to edit this post.', 'seopulse'), 403);
        }

        // Retrieve custom FAQs
        $faqs = get_post_meta($post_id, '_seopulse_faqs', true);
        if (!is_array($faqs)) {
            $faqs = [];
        }

        // Sanitize for output
        $faqs = FAQParser::parse_custom_faqs($faqs);

        return $this->success(
            [
                'post_id' => $post_id,
                'faqs'    => $faqs,
                'count'   => count($faqs),
            ],
        );
    }

    /**
     * Add or update FAQs for a post
     *
     * POST /seopulse/v1/posts/{post_id}/faqs
     * Body: { "faqs": [{"question": "...", "answer": "..."}, ...] }
     *
     * @param WP_REST_Request $request Request
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_faqs(WP_REST_Request $request)
    {
        $post_id = (int) $request->get_param('post_id');
        $faqs    = $request->get_param('faqs');

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            return $this->error(__('Post not found.', 'seopulse'), 404);
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return $this->error(__('You do not have permission to edit this post.', 'seopulse'), 403);
        }

        // Validate input
        if (!is_array($faqs) || empty($faqs)) {
            return $this->error(__('FAQs must be a non-empty array.', 'seopulse'), 400);
        }

        // Parse and sanitize FAQs
        $parsed_faqs = FAQParser::parse_custom_faqs($faqs);

        if (empty($parsed_faqs)) {
            return $this->error(__('No valid FAQs provided.', 'seopulse'), 400);
        }

        // Save to post_meta
        update_post_meta($post_id, '_seopulse_faqs', $parsed_faqs);

        return $this->success(
            [
                'message' => __('FAQs updated successfully.', 'seopulse'),
                'post_id' => $post_id,
                'faqs'    => $parsed_faqs,
                'count'   => count($parsed_faqs),
            ],
        );
    }

    /**
     * Delete a specific FAQ
     *
     * DELETE /seopulse/v1/posts/{post_id}/faqs/{faq_index}
     *
     * @param WP_REST_Request $request Request
     *
     * @return WP_REST_Response|WP_Error
     */
    public function delete_faq(WP_REST_Request $request)
    {
        $post_id   = (int) $request->get_param('post_id');
        $faq_index = (int) $request->get_param('faq_index');

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            return $this->error(__('Post not found.', 'seopulse'), 404);
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return $this->error(__('You do not have permission to edit this post.', 'seopulse'), 403);
        }

        // Retrieve current FAQs
        $faqs = get_post_meta($post_id, '_seopulse_faqs', true);
        if (!is_array($faqs)) {
            return $this->error(__('No FAQs found for this post.', 'seopulse'), 404);
        }

        // Validate index
        if (!isset($faqs[ $faq_index ])) {
            return $this->error(__('FAQ item not found.', 'seopulse'), 404);
        }

        // Remove the FAQ at the specified index
        unset($faqs[ $faq_index ]);

        // Re-index array
        $faqs = array_values($faqs);

        // Update or delete meta
        if (empty($faqs)) {
            delete_post_meta($post_id, '_seopulse_faqs');
        } else {
            update_post_meta($post_id, '_seopulse_faqs', $faqs);
        }

        return $this->success(
            [
                'message' => __('FAQ deleted successfully.', 'seopulse'),
                'post_id' => $post_id,
                'faqs'    => $faqs,
                'count'   => count($faqs),
            ],
        );
    }
}
