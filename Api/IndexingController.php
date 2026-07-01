<?php

/**
 * REST controller for Instant Indexing.
 *
 * Endpoints:
 * - GET    /seopulse/v1/indexing/settings          — get settings
 * - POST   /seopulse/v1/indexing/settings          — save settings
 * - POST   /seopulse/v1/indexing/submit            — manual URL submission
 * - POST   /seopulse/v1/indexing/test              — test provider connections
 * - GET    /seopulse/v1/indexing/logs               — get recent log entries
 * - POST   /seopulse/v1/indexing/upload-credentials — upload Google JSON key
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
use SEOPulse\Services\Indexing\GoogleIndexingSubmitter;
use SEOPulse\Services\IndexingLogger;
use SEOPulse\Services\IndexingServiceRegistry;
use WP_REST_Request;
use WP_REST_Response;

class IndexingController extends RestController
{
    protected string $rest_base = 'indexing';

    private IndexingServiceRegistry $registry;

    public function __construct()
    {
        $this->registry = new IndexingServiceRegistry();
    }

    public function register_routes(): void
    {
        // GET /indexing/settings
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

        // POST /indexing/settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'indexnow_enabled'        => [
                            'type'     => 'boolean',
                            'required' => false,
                        ],
                        'google_indexing_enabled' => [
                            'type'     => 'boolean',
                            'required' => false,
                        ],
                        'auto_submit_on_publish'  => [
                            'type'     => 'boolean',
                            'required' => false,
                        ],
                    ],
                ],
            ],
        );

        // POST /indexing/submit
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/submit',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'submit_url'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'url'    => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'esc_url_raw',
                            'validate_callback' => static fn ($v): bool => filter_var($v, FILTER_VALIDATE_URL) !== false,
                        ],
                        'action' => [
                            'required' => false,
                            'type'     => 'string',
                            'default'  => 'updated',
                            'enum'     => ['updated', 'deleted'],
                        ],
                    ],
                ],
            ],
        );

        // POST /indexing/test
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/test',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'test_connections'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // GET /indexing/logs
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/logs',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_logs'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'per_page' => [
                            'type'    => 'integer',
                            'default' => 50,
                            'minimum' => 1,
                            'maximum' => 100,
                        ],
                        'page'     => [
                            'type'    => 'integer',
                            'default' => 1,
                            'minimum' => 1,
                        ],
                        'service'  => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                    ],
                ],
            ],
        );

        // POST /indexing/upload-credentials
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/upload-credentials',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'upload_credentials'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );
    }

    /**
     * GET /indexing/settings
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response
    {
        $settings = (array) get_option(Options::INDEXING, []);

        return $this->success(
            [
                'indexnow_enabled'        => !empty($settings['indexnow_enabled']),
                'indexnow_key'            => $settings['indexnow_key'] ?? '',
                'google_indexing_enabled' => !empty($settings['google_indexing_enabled']),
                'google_credentials_set'  => !empty($settings['google_indexing_credentials']['client_email']),
                'auto_submit_on_publish'  => $settings['auto_submit_on_publish'] ?? true,
            ],
        );
    }

    /**
     * POST /indexing/settings
     */
    public function save_settings(WP_REST_Request $request): WP_REST_Response
    {
        $settings = (array) get_option(Options::INDEXING, []);
        $params   = $request->get_json_params();

        if (isset($params['indexnow_enabled'])) {
            $settings['indexnow_enabled'] = (bool) $params['indexnow_enabled'];

            // Auto-generate key if enabling for the first time.
            if ($settings['indexnow_enabled'] && empty($settings['indexnow_key'])) {
                $submitter = $this->registry->get('indexnow');
                if ($submitter instanceof \SEOPulse\Services\Indexing\IndexNowSubmitter) {
                    $submitter->getApiKey(); // This auto-generates and saves.
                    $settings = (array) get_option(Options::INDEXING, []);
                }
            }
        }

        if (isset($params['google_indexing_enabled'])) {
            $settings['google_indexing_enabled'] = (bool) $params['google_indexing_enabled'];
        }

        if (isset($params['auto_submit_on_publish'])) {
            $settings['auto_submit_on_publish'] = (bool) $params['auto_submit_on_publish'];
        }

        update_option(Options::INDEXING, $settings);

        return $this->get_settings($request);
    }

    /**
     * POST /indexing/submit
     */
    public function submit_url(WP_REST_Request $request): WP_REST_Response
    {
        $url    = (string) $request->get_param('url');
        $action = (string) $request->get_param('action');

        // Check cooldown.
        if (IndexingLogger::wasRecentlySubmitted($url)) {
            return $this->success(
                [
                    'results' => [],
                    'message' => 'URL was recently submitted. Skipped to prevent duplicates.',
                ],
            );
        }

        $results = $this->registry->submitAll($url, $action);

        // Log each result.
        foreach ($results as $serviceId => $result) {
            IndexingLogger::log(
                $url,
                $serviceId,
                $result['success'] ? 'success' : 'error',
                $result['message'],
            );
        }

        return $this->success(
            [
                'results' => $results,
            ],
        );
    }

    /**
     * POST /indexing/test
     */
    public function test_connections(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(
            [
                'results' => $this->registry->testAll(),
            ],
        );
    }

    /**
     * GET /indexing/logs
     */
    public function get_logs(WP_REST_Request $request): WP_REST_Response
    {
        $per_page = (int) $request->get_param('per_page');
        $page     = (int) $request->get_param('page');
        $service  = (string) $request->get_param('service');
        $offset   = ($page - 1) * $per_page;

        $entries = IndexingLogger::getRecent($per_page, $offset, $service);
        $total   = IndexingLogger::count($service);

        return $this->success(
            [
                'entries'     => $entries,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        );
    }

    /**
     * POST /indexing/upload-credentials
     *
     * Accepts a raw JSON body containing the Google service account key file contents.
     */
    public function upload_credentials(WP_REST_Request $request): WP_REST_Response
    {
        $json = $request->get_json_params();

        if (!is_array($json) || empty($json)) {
            return $this->error('Invalid JSON payload.', 400);
        }

        if (!GoogleIndexingSubmitter::validateCredentials($json)) {
            return $this->error('Invalid service account JSON. Must contain client_email and private_key.', 422);
        }

        $submitter = new GoogleIndexingSubmitter();
        $submitter->saveCredentials($json);

        // Test immediately.
        $test = $submitter->testConnection();

        return $this->success(
            [
                'credentials_set' => true,
                'test'            => $test,
            ],
        );
    }
}
