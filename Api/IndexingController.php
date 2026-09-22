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
use SEOPulse\Services\Indexing\IndexNowSubmitter;
use SEOPulse\Services\IndexingLogger;
use SEOPulse\Services\IndexingServiceRegistry;
use WP_Error;
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
                            // Only accept http(s) explicitly, rather than
                            // relying solely on FILTER_VALIDATE_URL (which
                            // happily validates javascript:/file:/ftp: URLs
                            // too) or on esc_url_raw's protocol stripping.
                            'validate_callback' => static fn ($v): bool => is_string($v)
                                && (bool) preg_match('#^https?://#i', $v)
                                && filter_var($v, FILTER_VALIDATE_URL) !== false,
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
     *
     * @return WP_REST_Response|WP_Error error() (inherited) returns WP_Error, not WP_REST_Response.
     */
    public function save_settings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params();

        if (!is_array($params)) {
            return $this->error('Invalid or missing JSON body.', 400);
        }

        $settings = (array) get_option(Options::INDEXING, []);

        if (isset($params['indexnow_enabled'])) {
            $settings['indexnow_enabled'] = (bool) $params['indexnow_enabled'];

            // Auto-generate key if enabling for the first time.
            if ($settings['indexnow_enabled'] && empty($settings['indexnow_key'])) {
                $submitter = $this->registry->get('indexnow');
                if ($submitter instanceof IndexNowSubmitter) {
                    // IMPORTANT: capture the returned key directly instead
                    // of re-reading get_option() afterwards. getApiKey()
                    // persists its own (slightly stale) copy of $settings
                    // internally; re-reading it here would discard the
                    // `indexnow_enabled = true` we just set above (which
                    // hasn't been saved to the DB yet at this point),
                    // silently reverting the toggle to its previous value.
                    $settings['indexnow_key'] = $submitter->getApiKey();
                }
            }
        }

        if (isset($params['google_indexing_enabled'])) {
            $settings['google_indexing_enabled'] = (bool) $params['google_indexing_enabled'];
        }

        if (isset($params['auto_submit_on_publish'])) {
            $settings['auto_submit_on_publish'] = (bool) $params['auto_submit_on_publish'];
        }

        // This option group can hold Google's service-account private key —
        // never autoload it on every page request.
        update_option(Options::INDEXING, $settings, false);

        return $this->get_settings($request);
    }

    /**
     * POST /indexing/submit
     */
    public function submit_url(WP_REST_Request $request): WP_REST_Response
    {
        $url    = (string) $request->get_param('url');
        $action = (string) $request->get_param('action');

        // Cooldown is now checked per-provider inside the registry, so a
        // provider that has never successfully submitted this URL (e.g.
        // Google, if IndexNow already succeeded) still gets a chance.
        $results = $this->registry->submitAllRespectingCooldown($url, $action);

        foreach ($results as $serviceId => $result) {
            $status = 'error';

            if (!empty($result['skipped'])) {
                $status = 'skipped';
            } elseif ($result['success']) {
                $status = 'success';
            }

            IndexingLogger::log($url, $serviceId, $status, $result['message']);
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
     *
     * @return WP_REST_Response|WP_Error error() (inherited) returns WP_Error, not WP_REST_Response.
     */
    public function upload_credentials(WP_REST_Request $request): WP_REST_Response|WP_Error
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
