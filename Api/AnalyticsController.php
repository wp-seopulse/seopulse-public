<?php

/**
 * Analytics REST API Controller
 *
 * Handles CRUD operations for the Analytics & Cookie Consent settings
 * via the WordPress REST API.
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
use SEOPulse\Modules\Analytics\ConsentManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * AnalyticsController class
 *
 * REST endpoints for analytics/consent settings management.
 */
class AnalyticsController extends RestController
{
    /**
     * REST base path
     *
     * @var string
     */
    protected string $rest_base = 'analytics';

    /**
     * Consent manager instance
     *
     * @var ConsentManager
     */
    private ConsentManager $consentManager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->consentManager = new ConsentManager();
    }

    /**
     * Register REST routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // GET /seopulse/v1/analytics/settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // POST /seopulse/v1/analytics/settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'update_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );
    }

    /**
     * Get current analytics/consent settings
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response
    {
        $settings = $this->consentManager->getSettings();

        return $this->success($settings);
    }

    /**
     * Update analytics/consent settings
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params();

        if (empty($params)) {
            return $this->error(
                __('No settings data provided.', 'seopulse'),
                400,
            );
        }

        $this->consentManager->saveSettings($params);

        // Return the saved settings
        $settings = $this->consentManager->getSettings();

        return $this->success(
            [
                'settings' => $settings,
                'message'  => __('Settings saved successfully.', 'seopulse'),
            ],
        );
    }
}
