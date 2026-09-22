<?php

/**
 * REST API controller — Page Builders Integration sub-toggles
 *
 * GET  /seopulse/v1/page-builders/settings
 * POST /seopulse/v1/page-builders/settings
 *
 * @package SEOPulse\Api\PageBuilders
 * @since 1.7.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\PageBuilders;

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Core\Constants\Options;
use SEOPulse\Modules\PageBuilders\BuilderDetector;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PageBuildersController class
 */
class PageBuildersController extends RestController
{
    /**
     * Builders exposed by this controller.
     *
     * @var array<string>
     */
    private const BUILDERS = ['elementor', 'divi', 'beaver_builder', 'oxygen'];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base = 'page-builders';
    }

    /**
     * Registers routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );
    }

    /**
     * GET /seopulse/v1/page-builders/settings
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(
            [
                'detected' => BuilderDetector::detectAll(),
                'enabled'  => $this->get_enabled_states(),
            ],
        );
    }

    /**
     * POST /seopulse/v1/page-builders/settings
     *
     * Body: { "enabled": { "elementor": true, "divi": false, ... } }
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function save_settings(WP_REST_Request $request): WP_REST_Response
    {
        $json      = $request->get_json_params();
        $submitted = isset($json['enabled']) && is_array($json['enabled']) ? $json['enabled'] : [];

        $current = $this->get_enabled_states();

        foreach (self::BUILDERS as $builder) {
            if (isset($submitted[ $builder ])) {
                $current[ $builder ] = (bool) $submitted[ $builder ];
            }
        }

        update_option(Options::PAGE_BUILDERS_SETTINGS, $current, false);

        return $this->success(
            [
                'detected' => BuilderDetector::detectAll(),
                'enabled'  => $current,
            ],
        );
    }

    /**
     * Merges stored sub-toggle state with defaults (enabled unless explicitly disabled).
     *
     * @return array<string, bool>
     */
    private function get_enabled_states(): array
    {
        $stored = get_option(Options::PAGE_BUILDERS_SETTINGS, []);
        $stored = is_array($stored) ? $stored : [];

        $states = [];
        foreach (self::BUILDERS as $builder) {
            $states[ $builder ] = isset($stored[ $builder ]) ? (bool) $stored[ $builder ] : true;
        }

        return $states;
    }
}
