<?php

/**
 * REST API controller for Image ALT auto-fill
 *
 * Provides endpoints for settings, diagnostics, and batch operations.
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\Images;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Services\ImageAltFiller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * ImageAltController class
 *
 * Endpoints:
 *  GET    /seopulse/v1/image-alt/settings    — get settings
 *  POST   /seopulse/v1/image-alt/settings    — save settings
 *  GET    /seopulse/v1/image-alt/diagnostics  — get image alt diagnostics
 *  POST   /seopulse/v1/image-alt/batch-fill   — run batch fill
 */
class ImageAltController extends RestController
{
    /**
     * @var ImageAltFiller
     */
    private ImageAltFiller $filler;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base = 'image-alt';
        $this->filler    = new ImageAltFiller();
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // Get settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'save_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // Diagnostics
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/diagnostics',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_diagnostics'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // Batch fill
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/batch-fill',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'batch_fill'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'page' => [
                        'type'              => 'integer',
                        'default'           => 1,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────
    // SETTINGS
    // ──────────────────────────────────────────────

    /**
     * Get image alt settings
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success($this->filler->get_settings());
    }

    /**
     * Save image alt settings
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function save_settings(WP_REST_Request $request)
    {
        $params = $request->get_json_params();

        if (empty($params) || !is_array($params)) {
            return $this->error(
                __('Invalid request body.', 'seopulse'),
                400,
            );
        }

        $this->filler->save_settings($params);

        return $this->success($this->filler->get_settings());
    }

    // ──────────────────────────────────────────────
    // DIAGNOSTICS
    // ──────────────────────────────────────────────

    /**
     * Get diagnostics summary
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function get_diagnostics(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success($this->filler->get_diagnostics());
    }

    // ──────────────────────────────────────────────
    // BATCH FILL
    // ──────────────────────────────────────────────

    /**
     * Run batch fill operation
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function batch_fill(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');

        $result = $this->filler->batch_fill($page);

        return $this->success($result);
    }
}
