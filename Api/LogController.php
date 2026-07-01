<?php

/**
 * REST API controller for log management
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
use SEOPulse\Core\Logger;

use function SEOPulse\seopulse_get_service;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * LogController class
 *
 * Exposes endpoints:
 *  GET  /seopulse/v1/logs            — read log entries
 *  POST /seopulse/v1/logs/clear      — clear the log file
 *  GET  /seopulse/v1/logs/export     — export raw log contents
 */
class LogController extends RestController
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base = 'logs';
    }

    /**
     * Registers REST API routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // Read entries (enhanced with search, date, source, pagination)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_entries'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'level'     => [
                        'type'              => 'string',
                        'enum'              => ['debug', 'info', 'warning', 'error'],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'lines'     => [
                        'type'              => 'integer',
                        'default'           => 500,
                        'minimum'           => 1,
                        'maximum'           => 2000,
                        'sanitize_callback' => 'absint',
                    ],
                    'search'    => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'date_from' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'date_to'   => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'source'    => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'page'      => [
                        'type'              => 'integer',
                        'default'           => 1,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page'  => [
                        'type'              => 'integer',
                        'default'           => 50,
                        'minimum'           => 10,
                        'maximum'           => 200,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        );

        // Clear
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/clear',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'clear_logs'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // Export (enhanced with filter params)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/export',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'export_logs'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'format'    => [
                        'type'              => 'string',
                        'enum'              => ['json', 'csv'],
                        'default'           => 'json',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'level'     => [
                        'type'              => 'string',
                        'enum'              => ['debug', 'info', 'warning', 'error'],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'search'    => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'date_from' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'date_to'   => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        );

        // Daily stats for sparkline
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/stats',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_stats'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'days' => [
                        'type'              => 'integer',
                        'default'           => 7,
                        'minimum'           => 1,
                        'maximum'           => 30,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        );

        // Distinct sources
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/sources',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_sources'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // Bulk delete by timestamps
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/delete-entries',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'delete_entries'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'timestamps' => [
                        'type'     => 'array',
                        'required' => true,
                        'items'    => ['type' => 'string'],
                    ],
                ],
            ],
        );

        // Retention settings
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
                    'args'                => [
                        'max_file_size' => [
                            'type'    => 'integer',
                            'minimum' => 1048576,   // 1 MB min
                            'maximum' => 104857600, // 100 MB max
                        ],
                        'max_files'     => [
                            'type'    => 'integer',
                            'minimum' => 1,
                            'maximum' => 20,
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Returns recent log entries with search, date, source filtering and pagination
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_entries(WP_REST_Request $request)
    {
        $logger = seopulse_get_service('Logger');
        if (!$logger instanceof Logger) {
            return $this->error(__('Logger service unavailable.', 'seopulse'), 500);
        }

        $level    = $request->get_param('level');
        $lines    = (int) $request->get_param('lines');
        $search   = $request->get_param('search');
        $dateFrom = $request->get_param('date_from');
        $dateTo   = $request->get_param('date_to');
        $source   = $request->get_param('source');
        $page     = (int) $request->get_param('page');
        $perPage  = (int) $request->get_param('per_page');

        $entries = $logger->readLastLines($lines, $level);

        // Apply additional filters
        $entries = $this->filter_entries($entries, $search, $dateFrom, $dateTo, $source);

        // Reverse chronological
        $entries = array_reverse($entries);

        // Pagination
        $total      = count($entries);
        $totalPages = (int) ceil($total / $perPage);
        $offset     = ($page - 1) * $perPage;
        $paged      = array_slice($entries, $offset, $perPage);

        return $this->success(
            [
                'entries'     => array_values($paged),
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
                'file_size'   => $logger->getFileSize(),
                'counts'      => $logger->countByLevel($lines),
            ],
        );
    }

    /**
     * Clears the log file
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function clear_logs(WP_REST_Request $request)
    {
        $logger = seopulse_get_service('Logger');
        if (!$logger instanceof Logger) {
            return $this->error(__('Logger service unavailable.', 'seopulse'), 500);
        }

        $cleared = $logger->clear();

        if (!$cleared) {
            return $this->error(__('Failed to clear log file.', 'seopulse'), 500);
        }

        return $this->success(['cleared' => true]);
    }

    /**
     * Returns filtered log contents for download (JSON or CSV)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function export_logs(WP_REST_Request $request)
    {
        $logger = seopulse_get_service('Logger');
        if (!$logger instanceof Logger) {
            return $this->error(__('Logger service unavailable.', 'seopulse'), 500);
        }

        $format   = $request->get_param('format') ?: 'json';
        $level    = $request->get_param('level');
        $search   = $request->get_param('search');
        $dateFrom = $request->get_param('date_from');
        $dateTo   = $request->get_param('date_to');

        $entries = $logger->readLastLines(2000, $level);
        $entries = $this->filter_entries($entries, $search, $dateFrom, $dateTo);

        if ($format === 'csv') {
            $csvLines   = [];
            $csvLines[] = 'Timestamp,Level,Message,Context';
            foreach (array_reverse($entries) as $entry) {
                $csvLines[] = sprintf(
                    '%s,%s,"%s","%s"',
                    $entry['timestamp'] ?? '',
                    $entry['level'] ?? '',
                    str_replace('"', '""', $entry['message'] ?? ''),
                    str_replace('"', '""', wp_json_encode($entry['context'] ?? [], JSON_UNESCAPED_SLASHES) ?: ''),
                );
            }
            $contents = implode("\n", $csvLines);
            $filename = 'seopulse-logs-' . gmdate('Y-m-d') . '.csv';
        } else {
            $contents = $logger->getContents();
            $filename = 'seopulse-logs-' . gmdate('Y-m-d') . '.log';
        }

        return $this->success(
            [
                'contents'  => $contents,
                'file_size' => strlen($contents),
                'filename'  => $filename,
                'format'    => $format,
            ],
        );
    }

    /**
     * Returns daily statistics for trend chart
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_stats(WP_REST_Request $request)
    {
        $logger = seopulse_get_service('Logger');
        if (!$logger instanceof Logger) {
            return $this->error(__('Logger service unavailable.', 'seopulse'), 500);
        }

        $days = (int) $request->get_param('days');

        return $this->success(
            [
                'daily'  => $logger->getDailyCounts($days),
                'counts' => $logger->countByLevel(),
            ],
        );
    }

    /**
     * Returns distinct source values found in log context
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_sources(WP_REST_Request $request)
    {
        $logger = seopulse_get_service('Logger');
        if (!$logger instanceof Logger) {
            return $this->error(__('Logger service unavailable.', 'seopulse'), 500);
        }

        return $this->success(
            [
                'sources' => $logger->getDistinctSources(),
            ],
        );
    }

    /**
     * Deletes specific log entries by timestamp
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function delete_entries(WP_REST_Request $request)
    {
        $logger = seopulse_get_service('Logger');
        if (!$logger instanceof Logger) {
            return $this->error(__('Logger service unavailable.', 'seopulse'), 500);
        }

        $timestamps = $request->get_json_params()['timestamps'] ?? [];
        if (empty($timestamps) || !is_array($timestamps)) {
            return $this->error(__('No timestamps provided.', 'seopulse'), 400);
        }

        // Sanitize timestamps
        $timestamps = array_map('sanitize_text_field', array_slice($timestamps, 0, 500));

        $deleted = $logger->deleteEntries($timestamps);

        return $this->success(
            [
                'deleted' => $deleted,
            ],
        );
    }

    /**
     * Returns current retention settings
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_settings(WP_REST_Request $request)
    {
        $logger = seopulse_get_service('Logger');
        if (!$logger instanceof Logger) {
            return $this->error(__('Logger service unavailable.', 'seopulse'), 500);
        }

        return $this->success($logger->getRetentionSettings());
    }

    /**
     * Saves retention settings
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function save_settings(WP_REST_Request $request)
    {
        $params  = $request->get_json_params();
        $current = get_option('seopulse_log_settings', []);

        if (isset($params['max_file_size'])) {
            $current['max_file_size'] = max(1048576, min(104857600, (int) $params['max_file_size']));
        }
        if (isset($params['max_files'])) {
            $current['max_files'] = max(1, min(20, (int) $params['max_files']));
        }

        update_option('seopulse_log_settings', $current);

        return $this->success($current);
    }

    // ──────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────

    /**
     * Apply search, date, and source filters to entries
     *
     * @param array $entries
     * @param string|null $search
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param string|null $source
     * @return array
     */
    private function filter_entries(array $entries, ?string $search = null, ?string $dateFrom = null, ?string $dateTo = null, ?string $source = null): array
    {
        if ($search !== null && $search !== '') {
            $searchLower = mb_strtolower($search);
            $entries     = array_filter(
                $entries,
                static function ($entry) use ($searchLower) {
                    $message = mb_strtolower($entry['message'] ?? '');
                    $context = mb_strtolower(wp_json_encode($entry['context'] ?? [], JSON_UNESCAPED_SLASHES) ?: '');

                    return str_contains($message, $searchLower) || str_contains($context, $searchLower);
                },
            );
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $entries = array_filter($entries, static fn ($entry) => ($entry['timestamp'] ?? '') >= $dateFrom);
        }

        if ($dateTo !== null && $dateTo !== '') {
            $toEnd   = $dateTo . 'T23:59:59Z';
            $entries = array_filter($entries, static fn ($entry) => ($entry['timestamp'] ?? '') <= $toEnd);
        }

        if ($source !== null && $source !== '') {
            $entries = array_filter($entries, static fn ($entry) => ($entry['context']['source'] ?? '') === $source);
        }

        return array_values($entries);
    }
}
