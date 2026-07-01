<?php

/**
 * REST API controller for Local SEO
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
use SEOPulse\Core\Constants\Options;
use SEOPulse\Modules\LocalSeo\LocalSeoValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * LocalSeoController class
 */
class LocalSeoController extends RestController
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base = 'local-seo';
    }

    /**
     * Registers routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // GET/POST /seopulse/v1/local-seo/settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_permissions'],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'update_settings'],
                    'permission_callback' => [$this, 'check_permissions'],
                ],
            ],
        );

        // POST /seopulse/v1/local-seo/test-jsonld
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/test-jsonld',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'test_jsonld'],
                    'permission_callback' => [$this, 'check_permissions'],
                ],
            ],
        );
    }

    /**
     * Retrieves settings
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response
    {
        $settings = get_option(Options::LOCAL_SEO, []);

        return $this->success(
            [
                'settings' => $settings,
            ],
        );
    }

    /**
     * Updates settings
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function update_settings(WP_REST_Request $request)
    {
        $params = $request->get_json_params();

        if (!is_array($params)) {
            return $this->error(__('Invalid data format.', 'seopulse'), 400);
        }

        $sanitized = LocalSeoValidator::sanitize_settings($params);
        update_option(Options::LOCAL_SEO, $sanitized);

        return $this->success(
            [
                'message'  => __('Settings saved successfully.', 'seopulse'),
                'settings' => $sanitized,
            ],
        );
    }

    /**
     * Tests for JSON-LD presence on a URL
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function test_jsonld(WP_REST_Request $request)
    {
        $url = $request->get_param('url');
        $url = is_string($url) ? esc_url_raw(trim($url)) : '';

        if (empty($url)) {
            return $this->error(__('URL is required.', 'seopulse'), 400);
        }

        // Local test
        if (strpos($url, home_url()) === 0) {
            $settings   = get_option(Options::LOCAL_SEO, []);
            $has_jsonld = !empty($settings) && isset($settings['@context']);

            return $this->success(
                [
                    'has_jsonld' => $has_jsonld,
                    'message'    => $has_jsonld
                        ? __('JSON-LD detected on this page.', 'seopulse')
                        : __('No JSON-LD detected.', 'seopulse'),
                ],
            );
        }

        // External test
        $response = wp_remote_get(
            $url,
            [
                'timeout'    => 10,
                'user-agent' => 'SEOPulse/1.0',
            ],
        );

        if (is_wp_error($response)) {
            return $this->error(__('Failed to check URL.', 'seopulse'), 500);
        }

        $body       = wp_remote_retrieve_body($response);
        $has_jsonld = strpos($body, 'application/ld+json') !== false;

        return $this->success(
            [
                'has_jsonld' => $has_jsonld,
                'message'    => $has_jsonld
                    ? __('JSON-LD detected on this page.', 'seopulse')
                    : __('No JSON-LD detected.', 'seopulse'),
            ],
        );
    }
}
