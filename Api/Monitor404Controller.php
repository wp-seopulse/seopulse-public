<?php

/**
 * 404 Monitor - REST API Controller
 *
 * Provides a comprehensive REST API for the 404 Monitor module:
 *
 *  GET    /seopulse/v1/404-monitor               - paginated log list
 *  GET    /seopulse/v1/404-monitor/summary        - counters/stats
 *  GET    /seopulse/v1/404-monitor/chart          - daily hits (charts)
 *  GET    /seopulse/v1/404-monitor/top            - top 404 URLs
 *  GET    /seopulse/v1/404-monitor/referrers      - top referrers
 *  GET    /seopulse/v1/404-monitor/suggest/{id}   - suggest redirect for entry
 *  POST   /seopulse/v1/404-monitor/{id}/redirect  - create redirect from entry
 *  POST   /seopulse/v1/404-monitor/{id}/ignore    - ignore an entry
 *  DELETE /seopulse/v1/404-monitor/{id}           - delete single entry
 *  POST   /seopulse/v1/404-monitor/bulk           - bulk actions (delete/ignore/redirect)
 *  GET    /seopulse/v1/404-monitor/export         - export (csv|json)
 *  GET    /seopulse/v1/404-monitor/settings       - get settings
 *  POST   /seopulse/v1/404-monitor/settings       - save settings
 *  POST   /seopulse/v1/404-monitor/truncate       - clear all logs
 *  POST   /seopulse/v1/404-monitor/test-email     - send test email report
 *  GET    /seopulse/v1/404-monitor/prioritized    - prioritized 404s by severity
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
use SEOPulse\Modules\Monitor404\Monitor404EmailReporter;
use SEOPulse\Modules\Monitor404\Monitor404Repository;
use SEOPulse\Modules\Monitor404\Monitor404SuggestionEngine;
use SEOPulse\Modules\Redirections\RedirectRepository;
use WP_REST_Request;
use WP_REST_Response;

class Monitor404Controller extends RestController
{
    protected string $rest_base = '404-monitor';

    // =========================================================================
    // Route registration
    // =========================================================================

    public function register_routes(): void
    {
        $ns = $this->namespace;
        $rb = '/' . $this->rest_base;

        // List
        register_rest_route(
            $ns,
            $rb,
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'getItems'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'page'      => [
                            'type'    => 'integer',
                            'default' => 1,
                            'minimum' => 1,
                        ],
                        'per_page'  => [
                            'type'    => 'integer',
                            'default' => 25,
                            'minimum' => 1,
                            'maximum' => 200,
                        ],
                        'status'    => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'search'    => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'sort_by'   => [
                            'type'    => 'string',
                            'default' => 'last_hit',
                        ],
                        'order'     => [
                            'type'    => 'string',
                            'default' => 'DESC',
                            'enum'    => ['ASC', 'DESC'],
                        ],
                        'is_bot'    => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'date_from' => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'date_to'   => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                    ],
                ],
            ],
        );

        // Summary counters
        register_rest_route(
            $ns,
            $rb . '/summary',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'getSummary'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // Daily chart data
        register_rest_route(
            $ns,
            $rb . '/chart',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'getChart'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'days' => [
                            'type'    => 'integer',
                            'default' => 30,
                            'minimum' => 7,
                            'maximum' => 365,
                        ],
                    ],
                ],
            ],
        );

        // Top URLs
        register_rest_route(
            $ns,
            $rb . '/top',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'getTop'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'limit'  => [
                            'type'    => 'integer',
                            'default' => 10,
                            'minimum' => 1,
                            'maximum' => 50,
                        ],
                        'is_bot' => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                    ],
                ],
            ],
        );

        // Top referrers
        register_rest_route(
            $ns,
            $rb . '/referrers',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'getReferrers'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'limit' => [
                            'type'    => 'integer',
                            'default' => 10,
                            'minimum' => 1,
                            'maximum' => 50,
                        ],
                    ],
                ],
            ],
        );

        // Redirect suggestion for a specific log entry
        register_rest_route(
            $ns,
            $rb . '/suggest/(?P<id>[\d]+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'getSuggestion'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // Create redirect from 404 entry
        register_rest_route(
            $ns,
            $rb . '/(?P<id>[\d]+)/redirect',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'createRedirect'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'target_url'    => [
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'esc_url_raw',
                        ],
                        'redirect_type' => [
                            'type'    => 'integer',
                            'default' => 301,
                            'enum'    => [301, 302, 307, 410],
                        ],
                    ],
                ],
            ],
        );

        // Ignore a single entry
        register_rest_route(
            $ns,
            $rb . '/(?P<id>[\d]+)/ignore',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'ignoreItem'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'reason' => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                    ],
                ],
            ],
        );

        // Delete single
        register_rest_route(
            $ns,
            $rb . '/(?P<id>[\d]+)',
            [
                [
                    'methods'             => 'DELETE',
                    'callback'            => [$this, 'deleteItem'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // Bulk actions
        register_rest_route(
            $ns,
            $rb . '/bulk',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'bulkAction'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'action'        => [
                            'type'     => 'string',
                            'required' => true,
                            'enum'     => ['delete', 'ignore', 'redirect'],
                        ],
                        'ids'           => [
                            'type'     => 'array',
                            'required' => true,
                            'items'    => ['type' => 'integer'],
                        ],
                        'target_url'    => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                        'redirect_type' => [
                            'type'    => 'integer',
                            'default' => 301,
                        ],
                    ],
                ],
            ],
        );

        // Export
        register_rest_route(
            $ns,
            $rb . '/export',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'export'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'format' => [
                            'type'    => 'string',
                            'default' => 'csv',
                            'enum'    => ['csv', 'json'],
                        ],
                    ],
                ],
            ],
        );

        // Settings
        register_rest_route(
            $ns,
            $rb . '/settings',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'getSettings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'saveSettings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // Truncate all logs
        register_rest_route(
            $ns,
            $rb . '/truncate',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'truncateLogs'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // Send test email
        register_rest_route(
            $ns,
            $rb . '/test-email',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'sendTestEmail'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // Prioritized 404s (sorted by severity)
        register_rest_route(
            $ns,
            $rb . '/prioritized',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'getPrioritized'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'limit'    => [
                            'type'    => 'integer',
                            'default' => 20,
                            'minimum' => 1,
                            'maximum' => 100,
                        ],
                        'category' => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                    ],
                ],
            ],
        );
    }

    // =========================================================================
    // Callbacks
    // =========================================================================

    public function getItems(WP_REST_Request $request): WP_REST_Response
    {
        $repo  = new Monitor404Repository();
        $isBot = $request->get_param('is_bot');

        $args = [
            'page'      => (int) $request->get_param('page'),
            'per_page'  => (int) $request->get_param('per_page'),
            'status'    => $request->get_param('status'),
            'search'    => $request->get_param('search'),
            'sort_by'   => $request->get_param('sort_by'),
            'order'     => $request->get_param('order'),
            'date_from' => $request->get_param('date_from'),
            'date_to'   => $request->get_param('date_to'),
        ];

        if ($isBot !== '') {
            $args['is_bot'] = (int) $isBot;
        }

        return new WP_REST_Response($repo->getAllWithSeverity($args));
    }

    public function getSummary(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response((new Monitor404Repository())->getSummary());
    }

    public function getChart(WP_REST_Request $request): WP_REST_Response
    {
        $days = (int) $request->get_param('days');
        $repo = new Monitor404Repository();

        $data = $repo->getDailyStatsFromAggregated($days);

        if (empty($data)) {
            $data = $repo->getDailyStats($days);
        }

        return new WP_REST_Response($data);
    }

    public function getTop(WP_REST_Request $request): WP_REST_Response
    {
        $repo  = new Monitor404Repository();
        $limit = (int) $request->get_param('limit');
        $isBot = $request->get_param('is_bot');

        $botsOnly   = $isBot === '1';
        $humansOnly = $isBot === '0';

        return new WP_REST_Response($repo->getTopUrls($limit, $botsOnly, $humansOnly));
    }

    public function getReferrers(WP_REST_Request $request): WP_REST_Response
    {
        $limit = (int) $request->get_param('limit');

        return new WP_REST_Response((new Monitor404Repository())->getTopReferrers($limit));
    }

    public function getSuggestion(WP_REST_Request $request): WP_REST_Response
    {
        $id   = (int) $request->get_param('id');
        $repo = new Monitor404Repository();
        $row  = $repo->getById($id);

        if (!$row) {
            return new WP_REST_Response(['message' => __('404 entry not found.', 'seopulse')], 404);
        }

        $engine      = new Monitor404SuggestionEngine();
        $suggestions = $engine->suggestMultiple($row['url'], 5);

        if (!empty($suggestions)) {
            $best = $suggestions[0];
            $repo->storeSuggestion($id, $best['url'], $best['score']);
        }

        return new WP_REST_Response($suggestions);
    }

    public function createRedirect(WP_REST_Request $request): WP_REST_Response
    {
        $logRepo   = new Monitor404Repository();
        $redirRepo = new RedirectRepository();

        $id  = (int) $request->get_param('id');
        $row = $logRepo->getById($id);

        if (!$row) {
            return new WP_REST_Response(['message' => __('404 entry not found.', 'seopulse')], 404);
        }

        $targetUrl    = (string) $request->get_param('target_url');
        $redirectType = (int) $request->get_param('redirect_type');

        $redirectId = $redirRepo->create(
            [
                'source_url'    => $row['url'],
                'target_url'    => $targetUrl,
                'redirect_type' => $redirectType,
                'group_name'    => '404-auto',
            ],
        );

        if ($redirectId === false) {
            return new WP_REST_Response(['message' => __('Failed to create redirect.', 'seopulse')], 500);
        }

        $logRepo->markRedirected($id, (int) $redirectId);

        return new WP_REST_Response(
            [
                'redirect_id' => $redirectId,
                'message'     => __('Redirect created successfully.', 'seopulse'),
            ],
            201,
        );
    }

    public function ignoreItem(WP_REST_Request $request): WP_REST_Response
    {
        $id     = (int) $request->get_param('id');
        $reason = sanitize_text_field((string) $request->get_param('reason'));

        (new Monitor404Repository())->markIgnored($id, $reason);

        return new WP_REST_Response(['ignored' => true]);
    }

    public function deleteItem(WP_REST_Request $request): WP_REST_Response
    {
        (new Monitor404Repository())->delete((int) $request->get_param('id'));

        return new WP_REST_Response(['deleted' => true]);
    }

    public function bulkAction(WP_REST_Request $request): WP_REST_Response
    {
        $action = $request->get_param('action');
        $ids    = array_map('intval', (array) $request->get_param('ids'));
        $repo   = new Monitor404Repository();

        switch ($action) {
            case 'delete':
                $count = $repo->bulkDelete($ids);

                return new WP_REST_Response(['deleted' => $count]);

            case 'ignore':
                $count = $repo->bulkIgnore($ids);

                return new WP_REST_Response(['ignored' => $count]);

            case 'redirect':
                $targetUrl    = esc_url_raw((string) $request->get_param('target_url'));
                $redirectType = (int) $request->get_param('redirect_type');

                if (empty($targetUrl)) {
                    return new WP_REST_Response(['message' => __('target_url is required for bulk redirect.', 'seopulse')], 400);
                }

                $redirRepo = new RedirectRepository();
                $created   = 0;

                foreach ($ids as $id) {
                    $row = $repo->getById($id);
                    if (!$row) {
                        continue;
                    }

                    $redirectId = $redirRepo->create(
                        [
                            'source_url'    => $row['url'],
                            'target_url'    => $targetUrl,
                            'redirect_type' => $redirectType,
                            'group_name'    => '404-bulk',
                        ],
                    );

                    if ($redirectId !== false) {
                        $repo->markRedirected($id, (int) $redirectId);
                        ++$created;
                    }
                }

                return new WP_REST_Response(['redirected' => $created]);

            default:
                return new WP_REST_Response(['message' => __('Unknown action.', 'seopulse')], 400);
        }
    }

    public function export(WP_REST_Request $request): WP_REST_Response
    {
        $format = $request->get_param('format');
        $repo   = new Monitor404Repository();

        if ($format === 'json') {
            return new WP_REST_Response(
                [
                    'data'     => $repo->exportJson(),
                    'filename' => 'seopulse-404-logs.json',
                    'mime'     => 'application/json',
                ],
            );
        }

        return new WP_REST_Response(
            [
                'data'     => $repo->exportCsv(),
                'filename' => 'seopulse-404-logs.csv',
                'mime'     => 'text/csv',
            ],
        );
    }

    public function getSettings(WP_REST_Request $request): WP_REST_Response
    {
        $opts = get_option('seopulse_404_settings', []);

        return new WP_REST_Response(is_array($opts) ? $opts : []);
    }

    public function saveSettings(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        if (!is_array($body)) {
            return new WP_REST_Response(['message' => __('Invalid payload.', 'seopulse')], 400);
        }

        $allowed = [
            'enable_tracking'               => 'bool',
            'track_bots'                    => 'bool',
            'track_logged_in'               => 'bool',
            'ignore_static'                 => 'bool',
            'ip_mode'                       => 'string',
            'retention_days'                => 'int',
            'global_redirect_enabled'       => 'bool',
            'global_redirect_url'           => 'url',
            'global_redirect_url_logged_in' => 'url',
            'disable_redirect_canonical'    => 'bool',
            'disable_slug_notifications'    => 'bool',
            'email_report_enabled'          => 'bool',
            'email_report_recipient'        => 'email',
            'auto_suggest_enabled'          => 'bool',
        ];

        $clean = [];
        foreach ($allowed as $key => $type) {
            if (!array_key_exists($key, $body)) {
                continue;
            }

            switch ($type) {
                case 'bool':
                    $clean[ $key ] = (bool) $body[ $key ];
                    break;
                case 'int':
                    $clean[ $key ] = (int) $body[ $key ];
                    break;
                case 'url':
                    $clean[ $key ] = esc_url_raw((string) $body[ $key ]);
                    break;
                case 'email':
                    $clean[ $key ] = sanitize_email((string) $body[ $key ]);
                    break;
                default:
                    $clean[ $key ] = sanitize_text_field((string) $body[ $key ]);
            }
        }

        $existing = get_option('seopulse_404_settings', []);
        $merged   = array_merge(is_array($existing) ? $existing : [], $clean);

        update_option('seopulse_404_settings', $merged);

        // Re-schedule email cron if toggled
        $reporter = new Monitor404EmailReporter();
        if (!empty($clean['email_report_enabled'])) {
            $reporter->scheduleCron();
        } else {
            $reporter->clearCron();
        }

        return new WP_REST_Response(
            [
                'saved'    => true,
                'settings' => $merged,
            ],
        );
    }

    public function truncateLogs(WP_REST_Request $request): WP_REST_Response
    {
        (new Monitor404Repository())->truncate();

        return new WP_REST_Response(['truncated' => true]);
    }

    public function sendTestEmail(WP_REST_Request $request): WP_REST_Response
    {
        $body      = $request->get_json_params();
        $recipient = sanitize_email((string) ($body['recipient'] ?? ''));

        $result = (new Monitor404EmailReporter())->sendTest($recipient);

        if (!$result['sent']) {
            return new WP_REST_Response(
                [
                    'sent'    => false,
                    'message' => $result['error'] ?? __('Email could not be sent. Check your WordPress mail configuration.', 'seopulse'),
                ],
                500,
            );
        }

        return new WP_REST_Response(['sent' => true]);
    }

    public function getPrioritized(WP_REST_Request $request): WP_REST_Response
    {
        $limit    = min(100, max(1, (int) $request->get_param('limit')));
        $category = sanitize_text_field((string) $request->get_param('category'));

        return new WP_REST_Response(
            (new Monitor404Repository())->getPrioritized($limit, $category),
        );
    }
}
