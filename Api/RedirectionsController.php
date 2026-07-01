<?php

/**
 * REST API controller for Redirections
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
use SEOPulse\Modules\Redirections\RedirectionsManager;
use SEOPulse\Modules\Redirections\RedirectRepository;
use SEOPulse\Modules\Redirections\RegexRedirectEngine;
use SEOPulse\Services\RedirectChainDetector;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * RedirectionsController class
 */
class RedirectionsController extends RestController
{
    /**
     * Manager
     *
     * @var RedirectionsManager
     */
    private RedirectionsManager $manager;

    /**
     * Redirect repository (SQL-based)
     *
     * @var RedirectRepository
     */
    private RedirectRepository $repository;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base  = 'redirections';
        $this->manager    = new RedirectionsManager();
        $this->repository = new RedirectRepository();
    }

    /**
     * Registers routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // GET /seopulse/v1/redirections
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_redirects'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'create_redirect'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // DELETE /seopulse/v1/redirections/bulk
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/bulk',
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_all_redirects'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // PUT|DELETE /seopulse/v1/redirections/{id}
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_]+)',
            [
                [
                    'methods'             => 'PUT',
                    'callback'            => [$this, 'update_redirect'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => 'DELETE',
                    'callback'            => [$this, 'delete_redirect'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // GET /seopulse/v1/redirections/export
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/export',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'export_redirects'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // POST /seopulse/v1/redirections/import
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/import',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'import_redirects'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // GET /seopulse/v1/redirections/chains
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/chains',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'detect_chains'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // ── ADVANCED ENDPOINTS (migrated from Pro) ───────────────────

        // GET /seopulse/v1/redirections/sql — Full SQL-based list with filters
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/sql',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_sql_redirects'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'page'       => [
                            'type'    => 'integer',
                            'default' => 1,
                        ],
                        'per_page'   => [
                            'type'    => 'integer',
                            'default' => 25,
                        ],
                        'status'     => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'group'      => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'category'   => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'match_type' => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'search'     => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'sort_by'    => [
                            'type'    => 'string',
                            'default' => 'created_at',
                        ],
                        'order'      => [
                            'type'    => 'string',
                            'default' => 'DESC',
                        ],
                    ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'create_sql_redirect'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'source_url'        => [
                            'type'     => 'string',
                            'required' => true,
                        ],
                        'target_url'        => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'redirect_type'     => [
                            'type'    => 'integer',
                            'default' => 301,
                        ],
                        'match_type'        => [
                            'type'    => 'string',
                            'default' => 'exact',
                        ],
                        'ignore_case'       => [
                            'type'    => 'integer',
                            'default' => 1,
                        ],
                        'maintenance_code'  => [
                            'type'    => 'integer',
                            'default' => 0,
                        ],
                        'regex'             => [
                            'type'    => 'boolean',
                            'default' => false,
                        ],
                        'group_name'        => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'category'          => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'description'       => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'pass_query_string' => [
                            'type'    => 'integer',
                            'default' => 1,
                        ],
                        'status'            => [
                            'type'    => 'string',
                            'default' => 'active',
                        ],
                    ],
                ],
            ],
        );

        // GET|PUT|DELETE /seopulse/v1/redirections/sql/{id}
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/sql/(?P<id>[\d]+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_sql_redirect'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => 'PUT,PATCH',
                    'callback'            => [$this, 'update_sql_redirect'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => 'DELETE',
                    'callback'            => [$this, 'delete_sql_redirect'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // GET /seopulse/v1/redirections/groups
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/groups',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_groups'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // GET /seopulse/v1/redirections/categories
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/categories',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_categories'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // GET /seopulse/v1/redirections/statistics
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/statistics',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_statistics'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // POST /seopulse/v1/redirections/bulk-action
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/bulk-action',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'bulk_action'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'ids'    => [
                        'type'     => 'array',
                        'required' => true,
                        'items'    => ['type' => 'integer'],
                    ],
                    'action' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ],
        );

        // POST /seopulse/v1/redirections/import-json
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/import-json',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'import_json'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // POST /seopulse/v1/redirections/import-csv
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/import-csv',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'import_csv_advanced'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // GET /seopulse/v1/redirections/export-csv
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/export-csv',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'export_csv_advanced'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // GET /seopulse/v1/redirections/export/htaccess
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/export/htaccess',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'export_htaccess'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // GET /seopulse/v1/redirections/export/nginx
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/export/nginx',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'export_nginx'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // GET /seopulse/v1/redirections/impact
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/impact',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_impact'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'page'     => [
                        'type'    => 'integer',
                        'default' => 1,
                    ],
                    'per_page' => [
                        'type'    => 'integer',
                        'default' => 25,
                    ],
                    'status'   => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                    'search'   => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                    'order'    => [
                        'type'    => 'string',
                        'default' => 'DESC',
                    ],
                ],
            ],
        );

        // PUT|GET /seopulse/v1/redirections/settings/debug
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings/debug',
            [
                [
                    'methods'             => 'PUT',
                    'callback'            => [$this, 'toggle_debug'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'enabled' => [
                            'type'     => 'boolean',
                            'required' => true,
                        ],
                    ],
                ],
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_debug'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );
    }

    /**
     * Retrieves all redirections
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function get_redirects(WP_REST_Request $request): WP_REST_Response
    {
        $redirects = $this->manager->get_all_redirects();

        return $this->success(
            [
                'redirects' => $redirects,
                'total'     => count($redirects),
            ],
        );
    }

    /**
     * Creates a redirection
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function create_redirect(WP_REST_Request $request)
    {
        $params   = $request->get_json_params();
        $redirect = $this->prepare_redirect_payload($params, true);

        if ($redirect instanceof WP_Error) {
            return $redirect;
        }

        // Chain / circular redirect check.
        $chain_warning = $this->check_chain($redirect['source'] ?? '', $redirect['destination'] ?? '');

        $success = $this->manager->add_redirect($redirect);

        if (!$success) {
            return $this->error(__('Failed to create redirect.', 'seopulse'), 500);
        }

        // Retrieve the full redirect with its generated ID
        $all     = $this->manager->get_all_redirects();
        $created = end($all);

        $response = [
            'message'  => __('Redirect created successfully.', 'seopulse'),
            'redirect' => $created,
        ];

        if ($chain_warning !== null) {
            $response['warning'] = $chain_warning;
        }

        return $this->success($response);
    }

    /**
     * Updates a redirection
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function update_redirect(WP_REST_Request $request)
    {
        $id     = $request->get_param('id');
        $params = $request->get_json_params();

        $redirect = $this->prepare_redirect_payload($params, false);

        if ($redirect instanceof WP_Error) {
            return $redirect;
        }

        if ($redirect === []) {
            return $this->error(__('No valid redirect fields were provided.', 'seopulse'), 400);
        }

        // Chain / circular redirect check.
        $chain_warning = null;
        if (isset($redirect['source'], $redirect['destination'])) {
            $chain_warning = $this->check_chain($redirect['source'], $redirect['destination']);
        }

        $success = $this->manager->update_redirect($id, $redirect);

        if (!$success) {
            return $this->error(__('Redirect not found.', 'seopulse'), 404);
        }

        $response = [
            'message' => __('Redirect updated successfully.', 'seopulse'),
        ];

        if ($chain_warning !== null) {
            $response['warning'] = $chain_warning;
        }

        return $this->success($response);
    }

    /**
     * Deletes a redirection
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function delete_redirect(WP_REST_Request $request)
    {
        $id = $request->get_param('id');

        $success = $this->manager->delete_redirect($id);

        if (!$success) {
            return $this->error(__('Redirect not found.', 'seopulse'), 404);
        }

        return $this->success(
            [
                'message' => __('Redirect deleted successfully.', 'seopulse'),
            ],
        );
    }

    /**
     * Deletes all redirections
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function delete_all_redirects(WP_REST_Request $request): WP_REST_Response
    {
        $this->manager->delete_all_redirects();

        return $this->success(
            [
                'message' => __('All redirects deleted successfully.', 'seopulse'),
            ],
        );
    }

    /**
     * Exports redirections
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function export_redirects(WP_REST_Request $request): WP_REST_Response
    {
        $csv = $this->manager->export_csv();

        return $this->success(
            [
                'csv'      => $csv,
                'filename' => 'seopulse-redirects-' . gmdate('Y-m-d') . '.csv',
            ],
        );
    }

    /**
     * Maximum rows allowed in a single CSV import.
     */
    private const CSV_MAX_ROWS = 500;

    /**
     * Imports redirections from CSV.
     *
     * Supports columns: source, target, type (301|302|regex).
     * Limited to 500 rows. Validates duplicates, malformed rows, and
     * circular chains before saving.
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function import_redirects(WP_REST_Request $request)
    {
        $params = $request->get_json_params();

        if (empty($params['csv'])) {
            return $this->error(__('CSV data is required.', 'seopulse'), 400);
        }

        $parsed = $this->parse_csv($params['csv']);

        if ($parsed instanceof WP_Error) {
            return $parsed;
        }

        // Build a chain detector with existing redirects + the new ones.
        $existing = $this->manager->get_all_redirects();
        $detector = new RedirectChainDetector($existing);
        $imported = 0;
        $skipped  = 0;
        $warnings = [];

        foreach ($parsed as $index => $row) {
            $line = $index + 2; // +1 header, +1 for 1-based.

            // Duplicate check against existing sources.
            foreach ($existing as $r) {
                if (strcasecmp($r['source'] ?? '', $row['source']) === 0) {
                    ++$skipped;
                    $warnings[] = sprintf(
                        /* translators: 1: line number, 2: source URL */
                        __('Line %1$d: duplicate source "%2$s" — skipped.', 'seopulse'),
                        $line,
                        $row['source'],
                    );
                    continue 2;
                }
            }

            // Chain / circular check.
            $check = $detector->check($row['source'], $row['target']);
            if ($check['circular']) {
                ++$skipped;
                $warnings[] = sprintf(
                    /* translators: 1: line number, 2: source URL */
                    __('Line %1$d: circular redirect detected for "%2$s" — skipped.', 'seopulse'),
                    $line,
                    $row['source'],
                );
                continue;
            }

            if (!$check['ok']) {
                $warnings[] = sprintf(
                    /* translators: 1: line number, 2: chain path */
                    __('Line %1$d: redirect chain detected (%2$s).', 'seopulse'),
                    $line,
                    implode(' → ', $check['chain']),
                );
            }

            // Regex type: validate pattern before importing.
            if ($row['type'] === 'regex') {
                $valid = RegexRedirectEngine::validate($row['source']);
                if ($valid instanceof WP_Error) {
                    ++$skipped;
                    $warnings[] = sprintf(
                        /* translators: 1: line number, 2: error message */
                        __('Line %1$d: invalid regex — %2$s', 'seopulse'),
                        $line,
                        $valid->get_error_message(),
                    );
                    continue;
                }
            }

            $redirect_type = $row['type'] === 'regex' ? 301 : (int) $row['type'];

            $success = $this->manager->add_redirect(
                [
                    'source'      => sanitize_text_field($row['source']),
                    'destination' => esc_url_raw($row['target']),
                    'type'        => $redirect_type,
                    'status'      => 'active',
                ],
            );

            if ($success) {
                ++$imported;
            } else {
                ++$skipped;
            }
        }

        return $this->success(
            [
                /* translators: %d: number of redirects imported */
                'message'  => sprintf(__('%d redirects imported successfully.', 'seopulse'), $imported),
                'count'    => $imported,
                'skipped'  => $skipped,
                'warnings' => $warnings,
            ],
        );
    }

    /**
     * Detect redirect chains and circular redirects.
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function detect_chains(WP_REST_Request $request): WP_REST_Response
    {
        $detector = RedirectChainDetector::fromSql();
        $result   = $detector->detectAll();

        $flattened = [];
        foreach ($result['chains'] as $chain) {
            $fix         = $detector->flatten($chain);
            $flattened[] = [
                'chain' => $chain,
                'fix'   => $fix,
            ];
        }

        return $this->success(
            [
                'chains' => $flattened,
                'loops'  => $result['loops'],
            ],
        );
    }

    /**
     * Normalizes a source URL to a path-only format.
     *
     * Users may enter a full URL (http://example.com/old-page) or just a path (/old-page).
     * The SQL matching always compares against REQUEST_URI (path only), so we must
     * store the source as a path to ensure redirects actually fire.
     *
     * @param string $source Raw source input
     * @return string Normalized path (e.g. /old-page)
     */
    private function normalize_source(string $source): string
    {
        $source = trim($source);

        // If it looks like a full URL, extract just the path.
        if (preg_match('#^https?://#i', $source)) {
            $path = wp_parse_url($source, PHP_URL_PATH);

            return $path ? '/' . ltrim($path, '/') : '/';
        }

        // Ensure leading slash for relative paths.
        return '/' . ltrim($source, '/');
    }

    /**
     * Validate and sanitize redirect payloads.
     *
     * @param array<string, mixed> $params
     * @param bool $creating Whether this payload is for a new redirect.
     * @return array<string, mixed>|WP_Error
     */
    private function prepare_redirect_payload(array $params, bool $creating)
    {
        if ($creating && (empty($params['source']) || empty($params['destination']))) {
            return $this->error(__('Source and destination are required.', 'seopulse'), 400);
        }

        $redirect = [];

        if (array_key_exists('source', $params)) {
            $source = sanitize_text_field((string) $params['source']);
            if ($source === '') {
                return $this->error(__('Source URL is required.', 'seopulse'), 400);
            }

            $redirect['source'] = $this->normalize_source($source);
        }

        if (array_key_exists('destination', $params)) {
            $destination = esc_url_raw((string) $params['destination']);
            if ($destination === '') {
                return $this->error(__('Destination URL is invalid.', 'seopulse'), 400);
            }

            $redirect['destination'] = $destination;
        }

        if (array_key_exists('type', $params)) {
            $type = (int) $params['type'];
            if (!in_array($type, [301, 302, 307, 308], true)) {
                return $this->error(__('Redirect type is invalid.', 'seopulse'), 400);
            }

            $redirect['type'] = $type;
        } elseif ($creating) {
            $redirect['type'] = 301;
        }

        if (array_key_exists('status', $params)) {
            $status = sanitize_key((string) $params['status']);
            if (!in_array($status, ['active', 'disabled'], true)) {
                return $this->error(__('Redirect status is invalid.', 'seopulse'), 400);
            }

            $redirect['status'] = $status;
        } elseif ($creating) {
            $redirect['status'] = 'active';
        }

        return $redirect;
    }

    /**
     * Check a source→destination pair for chain or circular redirect issues.
     *
     * @param string $source Source URL.
     * @param string $destination Destination URL.
     * @return array<string, mixed>|null Warning array or null when clean.
     */
    private function check_chain(string $source, string $destination): ?array
    {
        if ($source === '' || $destination === '') {
            return null;
        }

        $detector = RedirectChainDetector::fromSql();
        $result   = $detector->check($source, $destination);

        if ($result['ok']) {
            return null;
        }

        if ($result['circular']) {
            return [
                'type'    => 'circular',
                'message' => __('This redirect creates a circular loop.', 'seopulse'),
                'chain'   => $result['chain'],
            ];
        }

        return [
            'type'    => 'chain',
            'message' => sprintf(
                /* translators: %s: chain path like A → B → C */
                __('This redirect creates a chain: %s', 'seopulse'),
                implode(' → ', $result['chain']),
            ),
            'chain'   => $result['chain'],
            'fix'     => $detector->flatten($result['chain']),
        ];
    }

    /**
     * Parse raw CSV text into validated rows.
     *
     * Expected columns: source, target, type (301|302|regex).
     *
     * @param string $csv Raw CSV content.
     * @return array<int, array{source: string, target: string, type: string}>|WP_Error
     */
    private function parse_csv(string $csv)
    {
        $lines = preg_split('/\R/', trim($csv));

        if (empty($lines) || count($lines) < 2) {
            return $this->error(
                __('CSV must contain a header row and at least one data row.', 'seopulse'),
                400,
            );
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map(fn (string $h) => strtolower(trim($h)), $header);

        $source_idx = array_search('source', $header, true);
        $target_idx = array_search('target', $header, true);
        $type_idx   = array_search('type', $header, true);

        // Also accept destination as alias for target.
        if ($target_idx === false) {
            $target_idx = array_search('destination', $header, true);
        }

        if ($source_idx === false || $target_idx === false) {
            return $this->error(
                __('CSV header must contain "source" and "target" (or "destination") columns.', 'seopulse'),
                400,
            );
        }

        if (count($lines) > self::CSV_MAX_ROWS) {
            return $this->error(
                sprintf(
                    /* translators: %d: maximum number of CSV rows */
                    __('CSV exceeds the maximum of %d rows.', 'seopulse'),
                    self::CSV_MAX_ROWS,
                ),
                400,
            );
        }

        $allowed_types = ['301', '302', 'regex'];
        $rows          = [];

        foreach ($lines as $idx => $line) {
            if (trim($line) === '') {
                continue;
            }

            $cols = str_getcsv($line);

            $source = trim($cols[ $source_idx ] ?? '');
            $target = trim($cols[ $target_idx ] ?? '');
            $type   = $type_idx !== false ? strtolower(trim($cols[ $type_idx ] ?? '301')) : '301';

            if ($source === '' || $target === '') {
                continue; // Skip blank rows silently.
            }

            if (!in_array($type, $allowed_types, true)) {
                $type = '301';
            }

            $rows[] = [
                'source' => $source,
                'target' => $target,
                'type'   => $type,
            ];
        }

        if (empty($rows)) {
            return $this->error(
                __('No valid rows found in the CSV data.', 'seopulse'),
                400,
            );
        }

        return $rows;
    }

    // ── ADVANCED ENDPOINTS (migrated from Pro) ───────────────────

    /**
     * List SQL-based redirects with filters and pagination.
     */
    public function get_sql_redirects(WP_REST_Request $request): WP_REST_Response
    {
        $args = [
            'page'       => (int) $request->get_param('page'),
            'per_page'   => min((int) $request->get_param('per_page'), 100),
            'status'     => sanitize_text_field($request->get_param('status') ?? ''),
            'group'      => sanitize_text_field($request->get_param('group') ?? ''),
            'category'   => sanitize_text_field($request->get_param('category') ?? ''),
            'match_type' => sanitize_text_field($request->get_param('match_type') ?? ''),
            'search'     => sanitize_text_field($request->get_param('search') ?? ''),
            'sort_by'    => sanitize_key($request->get_param('sort_by') ?? 'created_at'),
            'order'      => strtoupper(sanitize_key($request->get_param('order') ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
        ];

        $result = $this->repository->getAll($args);

        return $this->success($result);
    }

    /**
     * Get a single redirect by ID via SQL repository.
     */
    public function get_sql_redirect(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id       = (int) $request->get_param('id');
        $redirect = $this->repository->getById($id);

        if (!$redirect) {
            return $this->error(__('Redirect not found.', 'seopulse'), 404);
        }

        return $this->success($redirect);
    }

    /**
     * Create a redirect via SQL repository.
     */
    public function create_sql_redirect(WP_REST_Request $request)
    {
        $data = [
            'source_url'        => sanitize_text_field($request->get_param('source_url')),
            'target_url'        => esc_url_raw($request->get_param('target_url') ?? ''),
            'redirect_type'     => (int) $request->get_param('redirect_type'),
            'match_type'        => sanitize_key($request->get_param('match_type') ?? 'exact'),
            'ignore_case'       => (int) $request->get_param('ignore_case'),
            'maintenance_code'  => (int) $request->get_param('maintenance_code'),
            'regex'             => (bool) $request->get_param('regex'),
            'group_name'        => sanitize_text_field($request->get_param('group_name') ?? ''),
            'category'          => sanitize_text_field($request->get_param('category') ?? ''),
            'description'       => sanitize_textarea_field($request->get_param('description') ?? ''),
            'pass_query_string' => (int) $request->get_param('pass_query_string'),
            'status'            => sanitize_key($request->get_param('status') ?? 'active'),
        ];

        $id = $this->repository->create($data);

        if ($id === false) {
            return $this->error(__('Failed to create redirect.', 'seopulse'), 500);
        }

        return $this->success(
            [
                'message'  => __('Redirect created successfully.', 'seopulse'),
                'redirect' => $this->repository->getById($id),
            ],
        );
    }

    /**
     * Update a redirect via SQL repository.
     */
    public function update_sql_redirect(WP_REST_Request $request)
    {
        $id   = (int) $request->get_param('id');
        $data = array_filter($request->get_json_params(), fn ($v) => $v !== null);

        // Sanitize writable fields.
        $sanitized   = [];
        $text_fields = ['source_url', 'target_url', 'group_name', 'category', 'status', 'match_type'];
        foreach ($text_fields as $field) {
            if (array_key_exists($field, $data)) {
                $sanitized[ $field ] = $field === 'target_url'
                    ? esc_url_raw((string) $data[ $field ])
                    : sanitize_text_field((string) $data[ $field ]);
            }
        }

        $int_fields = ['redirect_type', 'ignore_case', 'maintenance_code', 'pass_query_string'];
        foreach ($int_fields as $field) {
            if (array_key_exists($field, $data)) {
                $sanitized[ $field ] = (int) $data[ $field ];
            }
        }

        if (array_key_exists('regex', $data)) {
            $sanitized['regex'] = (bool) $data['regex'];
        }
        if (array_key_exists('description', $data)) {
            $sanitized['description'] = sanitize_textarea_field((string) $data['description']);
        }

        if (empty($sanitized)) {
            return $this->error(__('No valid fields provided.', 'seopulse'), 400);
        }

        $updated = $this->repository->update($id, $sanitized);

        if (!$updated) {
            return $this->error(__('Redirect not found.', 'seopulse'), 404);
        }

        return $this->success(
            [
                'message'  => __('Redirect updated successfully.', 'seopulse'),
                'redirect' => $this->repository->getById($id),
            ],
        );
    }

    /**
     * Delete a redirect via SQL repository.
     */
    public function delete_sql_redirect(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');

        $deleted = $this->repository->delete($id);

        if (!$deleted) {
            return $this->error(__('Redirect not found.', 'seopulse'), 404);
        }

        return $this->success(
            [
                'message' => __('Redirect deleted successfully.', 'seopulse'),
            ],
        );
    }

    /**
     * List distinct redirect groups.
     */
    public function get_groups(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success($this->repository->getGroups());
    }

    /**
     * List distinct redirect categories.
     */
    public function get_categories(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success($this->repository->getCategories());
    }

    /**
     * Get aggregate redirect statistics.
     */
    public function get_statistics(WP_REST_Request $request): WP_REST_Response
    {
        $redirect_stats = $this->repository->getStats();

        return $this->success(
            [
                'total_redirects'    => $redirect_stats['total'],
                'active_redirects'   => $redirect_stats['active'],
                'disabled_redirects' => $redirect_stats['disabled'],
                'total_hits'         => $redirect_stats['total_hits'],
            ],
        );
    }

    /**
     * Bulk action on redirects (activate, deactivate, delete).
     */
    public function bulk_action(WP_REST_Request $request)
    {
        $ids    = array_map('intval', (array) $request->get_param('ids'));
        $action = sanitize_key($request->get_param('action'));

        if (empty($ids)) {
            return $this->error(__('No IDs provided.', 'seopulse'), 400);
        }

        $affected = 0;

        switch ($action) {
            case 'activate':
                $affected = $this->repository->bulkUpdateStatus($ids, 'active');
                break;
            case 'deactivate':
                $affected = $this->repository->bulkUpdateStatus($ids, 'disabled');
                break;
            case 'delete':
                $affected = $this->repository->bulkDelete($ids);
                break;
            default:
                return $this->error(__('Invalid action.', 'seopulse'), 400);
        }

        return $this->success(
            [
                'message'  => sprintf(
                    /* translators: %d: number of affected redirects */
                    __('%d redirects updated.', 'seopulse'),
                    $affected,
                ),
                'affected' => $affected,
            ],
        );
    }

    /**
     * Import redirects from a JSON payload.
     */
    public function import_json(WP_REST_Request $request)
    {
        $rules = $request->get_json_params();

        if (!is_array($rules) || empty($rules)) {
            return $this->error(__('Invalid JSON payload.', 'seopulse'), 400);
        }

        $result = $this->repository->import($rules);

        return $this->success(
            [
                'message'  => sprintf(
                    /* translators: %d: number of imported redirects */
                    __('%d redirects imported.', 'seopulse'),
                    $result['imported'],
                ),
                'imported' => $result['imported'],
                'skipped'  => $result['skipped'],
                'errors'   => $result['errors'],
            ],
        );
    }

    /**
     * Advanced CSV import with full field support.
     */
    public function import_csv_advanced(WP_REST_Request $request)
    {
        $params = $request->get_json_params();

        if (empty($params['csv'])) {
            return $this->error(__('CSV data is required.', 'seopulse'), 400);
        }

        $result = $this->repository->importCsv($params['csv']);

        $imported = ($result['created'] ?? 0) + ($result['updated'] ?? 0);

        return $this->success(
            [
                'message'  => sprintf(
                    /* translators: 1: imported count, 2: updated count, 3: deleted count, 4: skipped count */
                    __('%1$d created, %2$d updated, %3$d deleted, %4$d skipped.', 'seopulse'),
                    $result['created'],
                    $result['updated'],
                    $result['deleted'],
                    $result['skipped'],
                ),
                'created'  => $result['created'],
                'updated'  => $result['updated'],
                'deleted'  => $result['deleted'],
                'skipped'  => $result['skipped'],
            ],
        );
    }

    /**
     * Export all redirects as CSV.
     */
    public function export_csv_advanced(WP_REST_Request $request): WP_REST_Response
    {
        $csv = $this->repository->exportCsv();

        return $this->success(
            [
                'csv'      => $csv,
                'filename' => 'seopulse-redirects-' . gmdate('Y-m-d') . '.csv',
            ],
        );
    }

    /**
     * Export active redirects as Apache .htaccess rules.
     */
    public function export_htaccess(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(
            [
                'content'  => $this->repository->exportHtaccess(),
                'filename' => 'seopulse-redirects.htaccess',
            ],
        );
    }

    /**
     * Export active redirects as Nginx rewrite rules.
     */
    public function export_nginx(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(
            [
                'content'  => $this->repository->exportNginx(),
                'filename' => 'seopulse-redirects-nginx.conf',
            ],
        );
    }

    /**
     * List redirects scored by impact.
     */
    public function get_impact(WP_REST_Request $request): WP_REST_Response
    {
        $args = [
            'page'     => (int) $request->get_param('page'),
            'per_page' => min((int) $request->get_param('per_page'), 100),
            'status'   => sanitize_text_field($request->get_param('status') ?? ''),
            'search'   => sanitize_text_field($request->get_param('search') ?? ''),
            'sort_by'  => 'impact_score',
            'order'    => strtoupper(sanitize_key($request->get_param('order') ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
        ];

        $result = $this->repository->getAllWithImpact($args);

        return $this->success($result);
    }

    /**
     * Toggle the redirect debug interstitial.
     */
    public function toggle_debug(WP_REST_Request $request): WP_REST_Response
    {
        $enabled = (bool) $request->get_param('enabled');

        update_option('seopulse_redirect_debug', $enabled ? '1' : '0');

        return $this->success(
            [
                'enabled' => $enabled,
                'message' => $enabled
                    ? __('Debug mode enabled.', 'seopulse')
                    : __('Debug mode disabled.', 'seopulse'),
            ],
        );
    }

    /**
     * Get the current debug mode setting.
     */
    public function get_debug(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(
            [
                'enabled' => get_option('seopulse_redirect_debug', '0') === '1',
            ],
        );
    }
}
