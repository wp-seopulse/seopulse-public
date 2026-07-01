<?php

/**
 * REST controller — module management
 *
 * GET  /seopulse/v1/modules
 * POST /seopulse/v1/modules/{key}/toggle
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api;

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Core\Module\ModuleManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ModulesController class
 */
class ModulesController extends RestController
{
    public function __construct()
    {
        $this->rest_base = 'modules';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_modules'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<key>[a-z0-9_]+)/toggle',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'toggle_module'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'key' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => static fn (mixed $v): bool => is_string($v) && $v !== '',
                    ],
                    'enabled' => [
                        'required'          => true,
                        'type'              => 'boolean',
                        'sanitize_callback' => static fn (mixed $v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                    ],
                ],
            ],
        );
    }

    /**
     * GET /seopulse/v1/modules
     *
     * Returns all module definitions as used by the UI.
     */
    public function get_modules(WP_REST_Request $request): WP_REST_Response
    {
        $definitions = ModuleManager::instance()->getDefinitionsForUI();

        return $this->success($definitions);
    }

    /**
     * POST /seopulse/v1/modules/{key}/toggle
     *
     * Body (JSON): { "enabled": true|false }
     *
     * Response: { module, enabled, cascaded[], autoEnabled[], message }
     *
     * Replicates the exact logic of AdminPage::ajax_toggle_module() so
     * the legacy AJAX handler can be removed in Phase 11.
     */
    public function toggle_module(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $module_key = sanitize_key((string) $request->get_param('key'));
        $new_state  = (bool) $request->get_param('enabled');

        $manager    = ModuleManager::instance();
        $definition = $manager->getDefinition($module_key);

        if ($definition === null) {
            return $this->error(__('Unknown module.', 'seopulse'), 400);
        }

        $auto_enabled = [];

        if ($new_state) {
            // Auto-enable dependencies that are currently disabled
            $ui = $manager->getDefinitionsForUI();
            foreach ($definition->requires as $req) {
                if (empty($ui[$req]['enabled'])) {
                    $manager->enable($req);
                    $auto_enabled[] = $req;
                    $req_instance   = $manager->instantiateModule($req);
                    if ($req_instance !== null) {
                        $req_instance->onActivate();
                    }
                }
            }
            $manager->enable($module_key);
        } else {
            $manager->disable($module_key);
        }

        // Fire lifecycle hook on the toggled module
        $instance = $manager->instantiateModule($module_key);
        if ($instance !== null) {
            if ($new_state) {
                $instance->onActivate();
            } else {
                $instance->onDeactivate();
            }
        }

        // Cascade-disable modules that depend on the just-disabled module
        $cascaded = [];
        if (!$new_state) {
            foreach ($manager->getDefinitions() as $dep_key => $dep_def) {
                if ($dep_key !== $module_key && in_array($module_key, $dep_def->requires, true)) {
                    $cascaded[]   = $dep_key;
                    $dep_instance = $manager->instantiateModule($dep_key);
                    if ($dep_instance !== null) {
                        $dep_instance->onDeactivate();
                    }
                }
            }
        }

        return $this->success(
            [
                'module'      => $module_key,
                'enabled'     => $new_state,
                'cascaded'    => $cascaded,
                'autoEnabled' => $auto_enabled,
                'message'     => $new_state
                    ? sprintf(
                        /* translators: %s: Module name */
                        esc_html__('%s has been enabled.', 'seopulse'),
                        $definition->label,
                    )
                    : sprintf(
                        /* translators: %s: Module name */
                        esc_html__('%s has been disabled.', 'seopulse'),
                        $definition->label,
                    ),
            ],
        );
    }
}
