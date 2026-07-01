<?php

/**
 * REST API controller for migration from other SEO plugins
 *
 * Handles detection, scanning and importing data from
 * SEOPress, Yoast SEO and Rank Math SEO in a generic way.
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
use SEOPulse\Core\Traits\ExportableConfigTrait;
use SEOPulse\Services\Migration\AIOSeoMigrator;
use SEOPulse\Services\Migration\RankMathMigrator;
use SEOPulse\Services\Migration\SeoPressMigrator;
use SEOPulse\Services\Migration\YoastMigrator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * MigrationController class
 *
 * Exposes endpoints (per supported plugin):
 *  GET  /seopulse/v1/tools/{plugin}/detect — detects the source plugin
 *  GET  /seopulse/v1/tools/{plugin}/scan   — scans recoverable data
 *  POST /seopulse/v1/tools/{plugin}/import — imports the configuration
 *
 * Supported plugins: seopress, yoast, rankmath, aioseo
 */
class MigrationController extends RestController
{
    use ExportableConfigTrait;

    /**
     * Supported plugin slugs
     *
     * @var array<string>
     */
    private const SUPPORTED_PLUGINS = ['seopress', 'yoast', 'rankmath', 'aioseo'];

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
     * Uses a dynamic pattern to cover all supported plugins.
     *
     * @return void
     */
    public function register_routes(): void
    {
        $slug_pattern = implode('|', self::SUPPORTED_PLUGINS);

        // Detect
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<plugin>' . $slug_pattern . ')/detect',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handle_detect'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // Scan
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<plugin>' . $slug_pattern . ')/scan',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handle_scan'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // Import
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<plugin>' . $slug_pattern . ')/import',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_import'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );
    }

    // ──────────────────────────────────────────────
    // HANDLERS
    // ──────────────────────────────────────────────

    /**
     * Detects if the source plugin is installed/active and has data
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_detect(WP_REST_Request $request)
    {
        $plugin = $request->get_param('plugin');
        $label  = $this->get_label($plugin);

        try {
            $migrator  = $this->resolve_migrator($plugin);
            $detection = $migrator->detect();

            return $this->success($detection);
        } catch (\Throwable $e) {
            return $this->error(
                sprintf(
                    /* translators: %s: plugin name (e.g. SEOPress, Yoast SEO, Rank Math SEO) */
                    __('Failed to detect %s installation.', 'seopulse'),
                    $label,
                ),
                500,
            );
        }
    }

    /**
     * Scans recoverable data from the source plugin
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_scan(WP_REST_Request $request)
    {
        $plugin = $request->get_param('plugin');
        $label  = $this->get_label($plugin);

        try {
            $migrator  = $this->resolve_migrator($plugin);
            $detection = $migrator->detect();

            if (!$detection['has_data']) {
                return $this->error(
                    sprintf(
                        /* translators: %s: plugin name */
                        __('No %s data found in the database.', 'seopulse'),
                        $label,
                    ),
                    404,
                );
            }

            $scan              = $migrator->scan();
            $scan['detection'] = $detection;

            return $this->success($scan);
        } catch (\Throwable $e) {
            return $this->error(
                sprintf(
                    /* translators: %s: plugin name */
                    __('Failed to scan %s data.', 'seopulse'),
                    $label,
                ),
                500,
            );
        }
    }

    /**
     * Executes the import from the source plugin to SEOPulse
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_import(WP_REST_Request $request)
    {
        $plugin = $request->get_param('plugin');
        $label  = $this->get_label($plugin);

        try {
            $overwrite = (bool) $request->get_param('overwrite');

            $migrator  = $this->resolve_migrator($plugin);
            $detection = $migrator->detect();

            if (!$detection['has_data']) {
                return $this->error(
                    sprintf(
                        /* translators: %s: plugin name */
                        __('No %s data found in the database.', 'seopulse'),
                        $label,
                    ),
                    404,
                );
            }

            // Create a backup of the current configuration before import
            $backup_created = $this->createConfigBackup('pre_migration');

            // Execute the import
            $result = $migrator->import($overwrite);

            if (!empty($result['errors'])) {
                return $this->error(
                    implode(' ', $result['errors']),
                    500,
                    [
                        'partial_result' => $result,
                        'backup_created' => $backup_created,
                    ],
                );
            }

            return $this->success(
                [
                    'message'            => sprintf(
                        /* translators: %s: plugin name */
                        __('%s data imported successfully.', 'seopulse'),
                        $label,
                    ),
                    'backup_created'     => $backup_created,
                    'options_imported'   => $result['options_imported'],
                    'post_meta_imported' => $result['post_meta_imported'],
                    'posts_processed'    => $result['posts_processed'],
                    'warnings'           => $result['warnings'],
                ],
            );
        } catch (\Throwable $e) {
            return $this->error(
                sprintf(
                    /* translators: %s: plugin name */
                    __('Failed to import %s configuration. Please try again.', 'seopulse'),
                    $label,
                ),
                500,
            );
        }
    }

    // ──────────────────────────────────────────────
    // REGISTRY
    // ──────────────────────────────────────────────

    /**
     * Resolves the migrator service for the given plugin
     *
     * @param string $plugin Plugin slug
     * @return SeoPressMigrator|YoastMigrator|RankMathMigrator|AIOSeoMigrator
     */
    private function resolve_migrator(string $plugin): object
    {
        return match ($plugin) {
            'seopress' => new SeoPressMigrator(),
            'yoast'    => new YoastMigrator(),
            'rankmath' => new RankMathMigrator(),
            'aioseo'   => new AIOSeoMigrator(),
        };
    }

    /**
     * Returns the human-readable label for the plugin
     *
     * @param string $plugin Plugin slug
     * @return string
     */
    private function get_label(string $plugin): string
    {
        return match ($plugin) {
            'seopress' => 'SEOPress',
            'yoast'    => 'Yoast SEO',
            'rankmath' => 'Rank Math SEO',
            'aioseo'   => 'All In One SEO',
            default    => $plugin,
        };
    }
}
