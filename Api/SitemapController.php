<?php

/**
 * REST API controller for the Sitemap module
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
use SEOPulse\Modules\Sitemap\SitemapModule;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * SitemapController class
 */
class SitemapController extends RestController
{
    public function __construct()
    {
        $this->rest_base = 'sitemap';
    }

    /**
     * Registers REST API routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/sitemap/stats',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_stats'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/sitemap/settings',
            [
                'methods'             => ['GET', 'POST'],
                'callback'            => [$this, 'manage_settings'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'settings' => [
                        'type'              => 'object',
                        'required'          => false,
                        'validate_callback' => [$this, 'validate_settings'],
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/sitemap/bulk-action',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'bulk_action'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'action'   => [
                        'type'     => 'string',
                        'required' => true,
                        'enum'     => ['exclude', 'include'],
                    ],
                    'post_ids' => [
                        'type'     => 'array',
                        'required' => true,
                        'items'    => ['type' => 'integer'],
                        'minItems' => 1,
                        'maxItems' => 100,
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/sitemap/clear-cache',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'clear_cache'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/sitemap/posts',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_posts'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'post_type' => [
                        'type'    => 'string',
                        'default' => 'post',
                        'enum'    => ['post', 'page'],
                    ],
                    'page'      => [
                        'type'    => 'integer',
                        'default' => 1,
                        'minimum' => 1,
                    ],
                    'search'    => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/sitemap/news-stats',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_news_stats'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/sitemap/test-urls',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'test_urls'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/sitemap/seo-analysis',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'seo_analysis'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/sitemap/robots-content',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_robots_content'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );
    }

    /**
     * Validates settings parameter
     *
     * @param mixed $value Value to validate
     * @param WP_REST_Request $request Request
     * @param string $param Parameter name
     * @return bool
     */
    public function validate_settings($value, WP_REST_Request $request, string $param): bool
    {
        return is_array($value);
    }

    /**
     * GET /sitemap/stats
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function get_stats(WP_REST_Request $request): WP_REST_Response
    {
        $module = \SEOPulse\seopulse()->get_module('sitemap');

        if (!$module || !method_exists($module, 'get_generator')) {
            return rest_ensure_response(['error' => __('Sitemap module not found', 'seopulse')]);
        }

        /** @var SitemapModule $module */
        $generator = $module->get_generator();
        $stats     = $generator->get_sitemap_stats();

        return rest_ensure_response($stats);
    }

    /**
     * GET|POST /sitemap/settings
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function manage_settings(WP_REST_Request $request)
    {
        $method = $request->get_method();

        if ($method === 'GET') {
            $settings = get_option('seopulse_sitemap_settings', []);

            return rest_ensure_response($settings);
        }

        if ($method === 'POST') {
            $params = $request->get_json_params();

            if (!is_array($params)) {
                return new WP_Error(
                    'invalid_data',
                    __('Invalid settings data', 'seopulse'),
                    ['status' => 400],
                );
            }

            $module = \SEOPulse\seopulse()->get_module('sitemap');

            if (!$module || !method_exists($module, 'get_settings')) {
                return new WP_Error(
                    'module_not_found',
                    __('Sitemap module not found', 'seopulse'),
                    ['status' => 500],
                );
            }

            /** @var SitemapModule $module */
            $settings     = $module->get_settings();
            $current      = get_option('seopulse_sitemap_settings', []);
            $sanitized    = $settings->sanitize_settings($params);
            $new_settings = array_merge($current, $sanitized);
            update_option('seopulse_sitemap_settings', $new_settings);

            return rest_ensure_response(
                [
                    'success' => true,
                    'message' => __('Settings saved successfully', 'seopulse'),
                ],
            );
        }

        return new WP_Error(
            'invalid_method',
            __('Invalid request method', 'seopulse'),
            ['status' => 405],
        );
    }

    /**
     * POST /sitemap/bulk-action
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function bulk_action(WP_REST_Request $request)
    {
        $params = $request->get_json_params();

        if (!is_array($params)) {
            return new WP_Error(
                'invalid_request',
                __('Invalid request data', 'seopulse'),
                ['status' => 400],
            );
        }

        $action   = sanitize_text_field($params['action'] ?? '');
        $post_ids = isset($params['post_ids']) && is_array($params['post_ids'])
            ? array_map('absint', $params['post_ids'])
            : [];

        if (!in_array($action, ['exclude', 'include'], true)) {
            return new WP_Error(
                'invalid_action',
                __('Invalid action specified', 'seopulse'),
                ['status' => 400],
            );
        }

        if (empty($post_ids)) {
            return new WP_Error(
                'invalid_request',
                __('No post IDs provided', 'seopulse'),
                ['status' => 400],
            );
        }

        if (count($post_ids) > 100) {
            return new WP_Error(
                'too_many_items',
                __('Too many items. Maximum 100 allowed.', 'seopulse'),
                ['status' => 400],
            );
        }

        $count = 0;

        foreach ($post_ids as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }

            if ($action === 'exclude') {
                update_post_meta($post_id, '_seopulse_exclude_sitemap', '1');
                ++$count;
            } elseif ($action === 'include') {
                delete_post_meta($post_id, '_seopulse_exclude_sitemap');
                ++$count;
            }
        }

        do_action('seopulse_sitemap_clear_cache');

        return rest_ensure_response(
            [
                'success' => true,
                'count'   => $count,
                'message' => sprintf(
                    /* translators: %d: number of items processed */
                    __('%d items processed successfully', 'seopulse'),
                    $count,
                ),
            ],
        );
    }

    /**
     * POST /sitemap/clear-cache
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function clear_cache(WP_REST_Request $request): WP_REST_Response
    {
        do_action('seopulse_sitemap_clear_cache');

        return rest_ensure_response(
            [
                'success' => true,
                'message' => __('Cache cleared successfully', 'seopulse'),
            ],
        );
    }

    /**
     * GET /sitemap/posts
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function get_posts(WP_REST_Request $request)
    {
        $post_type = sanitize_text_field($request->get_param('post_type'));
        $page      = absint($request->get_param('page'));
        $search    = sanitize_text_field($request->get_param('search'));

        if (!in_array($post_type, ['post', 'page'], true)) {
            return new WP_Error(
                'invalid_post_type',
                __('Invalid post type', 'seopulse'),
                ['status' => 400],
            );
        }

        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'paged'          => max(1, $page),
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $posts = $query->posts;
        $total = $query->found_posts;

        $formatted_posts = array_map(
            function ($post) {
                $excluded = get_post_meta($post->ID, '_seopulse_exclude_sitemap', true);
                $priority = get_post_meta($post->ID, '_seopulse_sitemap_priority', true);

                return [
                    'id'       => $post->ID,
                    'title'    => get_the_title($post),
                    'link'     => get_permalink($post),
                    'excluded' => (bool) $excluded,
                    'priority' => $priority ?: 'default',
                    'date'     => get_the_date('c', $post),
                ];
            },
            $posts,
        );

        return rest_ensure_response(
            [
                'posts' => $formatted_posts,
                'total' => $total,
                'page'  => $page,
            ],
        );
    }

    /**
     * GET /sitemap/news-stats
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function get_news_stats(WP_REST_Request $request): WP_REST_Response
    {
        $module = \SEOPulse\seopulse()->get_module('sitemap');

        if (!$module) {
            return rest_ensure_response(['available' => false]);
        }

        if (!property_exists($module, 'news')) {
            return rest_ensure_response(['available' => false]);
        }

        return rest_ensure_response(
            [
                'available' => true,
                'stats'     => [],
            ],
        );
    }

    /**
     * POST /sitemap/test-urls
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function test_urls(WP_REST_Request $request): WP_REST_Response
    {
        $sitemap_urls = [
            home_url('/sitemap.xml'),
            home_url('/sitemap-posts-1.xml'),
            home_url('/sitemap-pages-1.xml'),
        ];

        $results = [];

        foreach ($sitemap_urls as $url) {
            $response = wp_remote_head(
                $url,
                [
                    'timeout'   => 10,
                    'sslverify' => false,
                ],
            );

            $status = is_wp_error($response)
                ? 'error'
                : wp_remote_retrieve_response_code($response);

            $results[] = [
                'url'     => $url,
                'status'  => $status,
                'message' => is_wp_error($response) ? $response->get_error_message() : '',
            ];
        }

        return rest_ensure_response(
            [
                'success' => true,
                'results' => $results,
                'message' => __('URL testing completed', 'seopulse'),
            ],
        );
    }

    /**
     * POST /sitemap/seo-analysis
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function seo_analysis(WP_REST_Request $request)
    {
        $module = \SEOPulse\seopulse()->get_module('sitemap');

        if (!$module || !method_exists($module, 'get_generator')) {
            return new WP_Error(
                'module_not_found',
                __('Sitemap module not found', 'seopulse'),
                ['status' => 500],
            );
        }

        /** @var SitemapModule $module */
        $generator = $module->get_generator();
        $stats     = $generator->get_sitemap_stats();

        $analysis = [
            'total_urls'      => $stats['total_urls'] ?? 0,
            'recommendations' => [],
        ];

        if ($analysis['total_urls'] < 10) {
            $analysis['recommendations'][] = __('Consider adding more content to improve indexation', 'seopulse');
        }

        if (isset($stats['images']) && $stats['images'] === 0) {
            $analysis['recommendations'][] = __('Add images to your posts to enrich the sitemap', 'seopulse');
        }

        return rest_ensure_response(
            [
                'success'  => true,
                'analysis' => $analysis,
                'message'  => __('SEO analysis completed', 'seopulse'),
            ],
        );
    }

    /**
     * GET /sitemap/robots-content
     *
     * Returns the current robots.txt content (physical file or generated).
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function get_robots_content(WP_REST_Request $request): WP_REST_Response
    {
        $module = \SEOPulse\seopulse()->get_module('sitemap');

        if (!$module || !method_exists($module, 'get_generator')) {
            return rest_ensure_response(['content' => '']);
        }

        /** @var SitemapModule $module */
        $generator = $module->get_generator();

        $robots_file = ABSPATH . 'robots.txt';
        if (file_exists($robots_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $content = file_get_contents($robots_file);
        } else {
            $content = $generator->generate_robots_txt();
        }

        return rest_ensure_response(['content' => $content !== false ? $content : '']);
    }
}
