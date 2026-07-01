<?php

/**
 * REST API controller for tools (configuration export / import)
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Admin\Columns\ColumnHandler;
use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Constants\PostMeta;
use SEOPulse\Core\Traits\ExportableConfigTrait;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * ToolsController class
 *
 * Exposes endpoints:
 *  GET  /seopulse/v1/tools/export — exports all configuration
 *  POST /seopulse/v1/tools/import — imports a JSON configuration
 *  POST /seopulse/v1/tools/reset  — resets all configuration
 *
 * SEO migration endpoints are handled by MigrationController.
 */
class ToolsController extends RestController
{
    use ExportableConfigTrait;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base = 'tools';
    }

    /**
     * Registers REST API routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // Export
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/export',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handle_export'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // Import
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/import',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_import'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // Reset
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/reset',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_reset'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // Admin columns settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/columns',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_column_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'save_column_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────
    // EXPORT
    // ──────────────────────────────────────────────

    /**
     * Handles configuration export
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_export(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $config = $this->buildExportPayload();

            return $this->success($config);
        } catch (\Throwable $e) {
            return $this->error(
                __('Failed to export configuration.', 'seopulse'),
                500,
            );
        }
    }

    // ──────────────────────────────────────────────
    // IMPORT
    // ──────────────────────────────────────────────

    /**
     * Handles configuration import
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_import(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = $request->get_json_params();

        if (empty($body) || !is_array($body)) {
            return $this->error(
                __('Invalid request body. A valid JSON configuration is required.', 'seopulse'),
                400,
            );
        }

        // 1. Validate the structure
        $validation = $this->validate_import_payload($body);

        if (is_wp_error($validation)) {
            return $validation;
        }

        // 2. Create a backup before import
        $backup_created = $this->createConfigBackup('pre_import');

        // 3. Apply the configuration
        $result = $this->apply_import($body['modules']);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->success(
            [
                'message'         => __('Configuration imported successfully.', 'seopulse'),
                'backup_created'  => $backup_created,
                'modules_updated' => $result,
            ],
        );
    }

    /**
     * Validates the import payload structure
     *
     * @param array<string, mixed> $payload JSON data
     * @return true|WP_Error
     */
    private function validate_import_payload(array $payload)
    {
        // Check required fields
        $required_fields = ['schema_version', 'plugin_version', 'modules'];

        foreach ($required_fields as $field) {
            if (!isset($payload[ $field ])) {
                return $this->error(
                    /* translators: %s: field name */
                    sprintf(__('Missing required field: %s', 'seopulse'), $field),
                    400,
                );
            }
        }

        // Check that modules is an array
        if (!is_array($payload['modules'])) {
            return $this->error(
                __('The "modules" field must be an object.', 'seopulse'),
                400,
            );
        }

        // Check that module keys are known
        $unknown_modules = array_diff(
            array_keys($payload['modules']),
            array_keys(self::getExportableOptions()),
        );

        if (!empty($unknown_modules)) {
            return $this->error(
                sprintf(
                    /* translators: %s: comma-separated list of unknown module keys */
                    __('Unknown module keys: %s', 'seopulse'),
                    implode(', ', $unknown_modules),
                ),
                400,
            );
        }

        // Check that each module is an array
        foreach ($payload['modules'] as $key => $data) {
            if (!is_array($data)) {
                return $this->error(
                    /* translators: %s: module key */
                    sprintf(__('Module "%s" must be an object.', 'seopulse'), $key),
                    400,
                );
            }
        }

        // Check schema version compatibility
        $import_version = $payload['schema_version'];

        if (version_compare($import_version, self::$configSchemaVersion, '>')) {
            return $this->error(
                sprintf(
                    /* translators: %1$s: import version, %2$s: current version */
                    __('Configuration schema version %1$s is newer than the current version %2$s. Please update the plugin first.', 'seopulse'),
                    $import_version,
                    self::$configSchemaVersion,
                ),
                400,
            );
        }

        return true;
    }

    /**
     * Applies the imported configuration
     *
     * @param array<string, array<string, mixed>> $modules Module data
     * @return array<string>|WP_Error List of updated modules
     */
    private function apply_import(array $modules)
    {
        $updated = [];
        $errors  = [];

        foreach ($modules as $key => $data) {
            $exportable = self::getExportableOptions();

            if (!isset($exportable[ $key ])) {
                continue;
            }

            $option_name = $exportable[ $key ];
            $result      = update_option($option_name, $data);

            if ($result) {
                $updated[] = $key;
            } else {
                // update_option returns false if the value hasn't changed (not an error)
                // We check if the option is identical
                $current = get_option($option_name, []);

                if ($current === $data) {
                    $updated[] = $key;
                } else {
                    $errors[] = $key;
                }
            }
        }

        if (!empty($errors)) {
            return $this->error(
                sprintf(
                    /* translators: %s: comma-separated list of module keys */
                    __('Failed to update the following modules: %s', 'seopulse'),
                    implode(', ', $errors),
                ),
                500,
            );
        }

        return $updated;
    }

    // ──────────────────────────────────────────────
    // RESET
    // ──────────────────────────────────────────────

    /**
     * Handles the complete configuration reset
     *
     * Creates a backup, deletes all module options,
     * SEOPulse post meta and cache transients.
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_reset(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            // 1. Create a backup before reset
            $backup_created = $this->createConfigBackup('pre_reset');

            // 2. Delete module options
            $options_deleted = $this->delete_module_options();

            // 3. Delete all SEOPulse post meta
            $meta_deleted = $this->delete_all_post_meta();

            // 4. Purge cache transients
            $transients_deleted = $this->delete_all_transients();

            return $this->success(
                [
                    'message'            => __('All settings have been reset to their defaults.', 'seopulse'),
                    'backup_created'     => $backup_created,
                    'options_deleted'    => $options_deleted,
                    'post_meta_deleted'  => $meta_deleted,
                    'transients_deleted' => $transients_deleted,
                ],
            );
        } catch (\Throwable $e) {
            return $this->error(
                __('Failed to reset configuration. Please try again.', 'seopulse'),
                500,
            );
        }
    }

    /**
     * Deletes options for all exportable modules
     *
     * @return int Number of deleted options
     */
    private function delete_module_options(): int
    {
        $deleted = 0;

        foreach (self::getExportableOptions() as $key => $option_name) {
            if (delete_option($option_name)) {
                ++$deleted;
            }
        }

        // Also delete 404 logs
        if (delete_option(Options::REDIRECTIONS_404)) {
            ++$deleted;
        }

        return $deleted;
    }

    /**
     * Deletes all SEOPulse post meta
     *
     * @return int Number of deleted rows
     */
    private function delete_all_post_meta(): int
    {
        global $wpdb;

        $meta_keys = [
            PostMeta::FOCUS_KEYWORD,
            PostMeta::SCORE,
            PostMeta::LAST_ANALYSIS,
            PostMeta::SCORES,
            PostMeta::RECOMMENDATIONS_COUNT,
            PostMeta::DISMISSED_RECOMMENDATIONS,
            PostMeta::META_SEO,
            PostMeta::REDIRECT_URL,
            PostMeta::REDIRECT_TYPE,
            PostMeta::EXCLUDE_SITEMAP,
            PostMeta::SITEMAP_PRIORITY,
            PostMeta::SITEMAP_CHANGEFREQ,
            PostMeta::NEWS_KEYWORDS,
            PostMeta::STOCK_TICKERS,
        ];

        $total = 0;

        foreach ($meta_keys as $meta_key) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            $rows = $wpdb->delete($wpdb->postmeta, ['meta_key' => $meta_key]);

            if ($rows !== false) {
                $total += $rows;
            }
        }

        // Clean WordPress object cache
        wp_cache_flush();

        return $total;
    }

    /**
     * Deletes all SEOPulse transients (analysis + sitemap)
     *
     * @return int Number of deleted rows
     */
    private function delete_all_transients(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 OR option_name LIKE %s",
                '%_transient_seopulse_%',
                '%_transient_timeout_seopulse_%',
            ),
        );

        return $deleted;
    }

    // ──────────────────────────────────────────────
    // ADMIN COLUMNS SETTINGS
    // ──────────────────────────────────────────────

    /**
     * GET /seopulse/v1/tools/columns
     *
     * @return WP_REST_Response
     */
    public function get_column_settings(): WP_REST_Response
    {
        return new WP_REST_Response(ColumnHandler::get_settings());
    }

    /**
     * POST /seopulse/v1/tools/columns
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function save_column_settings(WP_REST_Request $request): WP_REST_Response
    {
        $enabled    = (bool) $request->get_param('enabled');
        $post_types = $request->get_param('post_types');

        if (!is_array($post_types)) {
            $post_types = ['post', 'page'];
        }

        // Sanitize each post type slug.
        $post_types = array_map('sanitize_key', $post_types);

        ColumnHandler::save_settings($enabled, $post_types);

        return new WP_REST_Response(ColumnHandler::get_settings());
    }
}
