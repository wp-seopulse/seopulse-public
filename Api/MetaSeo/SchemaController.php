<?php

/**
 * REST API controller for the advanced Schema.org editor
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\MetaSeo;

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Modules\MetaSeo\Engine\Providers\AdvancedSchemaProvider;
use SEOPulse\Modules\MetaSeo\Engine\Providers\SchemaCatalog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SchemaController — type definitions and per-post Schema.org configuration
 */
class SchemaController extends RestController
{
    /**
     * Registers routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/schema/types',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_types'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/posts/(?P<post_id>\d+)/schema',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_schema'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id' => ['type' => 'integer', 'required' => true],
                    ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'update_schema'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id' => ['type' => 'integer', 'required' => true],
                        'type'    => ['type' => 'string', 'required' => true],
                        'data'    => ['type' => 'object', 'required' => false],
                    ],
                ],
            ],
        );
    }

    /**
     * List editable types and their fields
     *
     * GET /seopulse/v1/schema/types
     *
     * @param WP_REST_Request $request Request
     *
     * @return WP_REST_Response
     */
    public function get_types(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(['types' => SchemaCatalog::definitions()]);
    }

    /**
     * Read the configuration of a post
     *
     * GET /seopulse/v1/posts/{post_id}/schema
     *
     * @param WP_REST_Request $request Request
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_schema(WP_REST_Request $request)
    {
        $post = $this->resolve_post($request);

        if ($post instanceof WP_Error) {
            return $post;
        }

        return $this->success($this->payload($post, SchemaCatalog::get($post->ID)));
    }

    /**
     * Save the configuration of a post (empty type removes it)
     *
     * POST /seopulse/v1/posts/{post_id}/schema
     *
     * @param WP_REST_Request $request Request
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_schema(WP_REST_Request $request)
    {
        $post = $this->resolve_post($request);

        if ($post instanceof WP_Error) {
            return $post;
        }

        $type = sanitize_text_field((string) $request->get_param('type'));
        $data = $request->get_param('data');

        if ($type !== '' && !SchemaCatalog::has_type($type)) {
            return $this->error(__('Unknown schema type.', 'seopulse'), 400);
        }

        $saved = SchemaCatalog::save($post->ID, $type, is_array($data) ? $data : []);

        return $this->success($this->payload($post, $saved));
    }

    /**
     * Resolve the post and check edit capability
     *
     * @param WP_REST_Request $request Request
     *
     * @return \WP_Post|WP_Error
     */
    private function resolve_post(WP_REST_Request $request)
    {
        $post_id = (int) $request->get_param('post_id');
        $post    = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return $this->error(__('Post not found.', 'seopulse'), 404);
        }

        if (!current_user_can('edit_post', $post_id)) {
            return $this->error(__('You do not have permission to edit this post.', 'seopulse'), 403);
        }

        return $post;
    }

    /**
     * Response payload with the generated JSON-LD preview
     *
     * @param \WP_Post $post Post.
     * @param array{type: string, data: array<string, mixed>}|null $config Configuration.
     *
     * @return array<string, mixed>
     */
    private function payload(\WP_Post $post, ?array $config): array
    {
        $preview = $config === null
            ? []
            : (new AdvancedSchemaProvider())->build_for($post, $config['type'], $config['data']);

        return [
            'post_id' => $post->ID,
            'type'    => $config['type'] ?? '',
            'data'    => $config['data'] ?? new \stdClass(),
            'preview' => $preview === [] ? null : $preview,
        ];
    }
}
