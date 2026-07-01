<?php

/**
 * REST API controller for Archive Settings.
 *
 * Provides CRUD endpoints for archive SEO settings and
 * an analysis endpoint for intelligent recommendations.
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\MetaSeo;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Modules\MetaSeo\Archives\ArchiveAnalyzer;
use SEOPulse\Modules\MetaSeo\Archives\ArchiveSettingsManager;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ArchiveSettingsController — REST endpoints for archive SEO management.
 *
 * Routes:
 *  GET    /seopulse/v1/archives/settings           – Get all archive settings
 *  POST   /seopulse/v1/archives/settings           – Update archive settings
 *  POST   /seopulse/v1/archives/settings/reset     – Reset to defaults
 *  GET    /seopulse/v1/archives/analysis            – Get SEO analysis & recommendations
 *  GET    /seopulse/v1/archives/authors             – Get author data for UI
 *  GET    /seopulse/v1/archives/404-report          – Get 404 tracking report
 *
 * @since 1.0.0
 */
class ArchiveSettingsController extends RestController
{
    private ArchiveSettingsManager $manager;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->rest_base = 'archives';
        $this->manager   = new ArchiveSettingsManager();
    }

    /**
     * Register REST routes.
     */
    public function register_routes(): void
    {
        // GET/POST /settings — CRUD for archive settings
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
                    'args'                => [
                        'settings' => [
                            'required' => true,
                            'type'     => 'object',
                        ],
                        'type'     => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                    ],
                ],
            ],
        );

        // POST /settings/reset — Reset to defaults
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings/reset',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'reset_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'type' => [
                            'type'    => 'string',
                            'default' => '',
                        ],
                    ],
                ],
            ],
        );

        // GET /analysis — SEO recommendations
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/analysis',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_analysis'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // GET /authors — Author data for settings UI
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/authors',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_authors'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // GET /404-report — Frequent 404 URLs
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/404-report',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_404_report'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );
    }

    // ------------------------------------------------------------------
    // Endpoint handlers
    // ------------------------------------------------------------------

    /**
     * GET /settings — Return all archive settings merged with defaults.
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(
            [
                'settings' => $this->manager->getAll(),
                'defaults' => ArchiveSettingsManager::getDefaults(),
            ],
        );
    }

    /**
     * POST /settings — Save archive settings.
     *
     * Accepts either full settings or per-type:
     * - `{ settings: { author: {...}, date: {...} } }` — partial update
     * - `{ settings: {...}, type: "author" }` — single-type update
     */
    public function save_settings(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $settings = $request->get_param('settings');
            $type     = $request->get_param('type');

            if (!is_array($settings)) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => __('Invalid settings format.', 'seopulse'),
                    ],
                    400,
                );
            }

            if (is_string($type) && $type !== '') {
                // Single-type update
                $this->manager->update($type, $settings);
            } else {
                // Merge partial update with existing
                $existing = $this->manager->getAll();

                foreach ($settings as $key => $values) {
                    if (is_array($values) && isset($existing[ $key ])) {
                        $existing[ $key ] = array_merge($existing[ $key ], $values);
                    }
                }

                $this->manager->updateAll($existing);
            }

            // Flush meta engine cache after settings change
            try {
                $engine = new \SEOPulse\Modules\MetaSeo\Engine\MetaEngine();
                $engine->flushCache();
            } catch (\Throwable $e) {
                // Non-critical
            }

            return $this->success(
                [
                    'saved'    => true,
                    'message'  => __('Archive settings saved successfully.', 'seopulse'),
                    'settings' => $this->manager->getAll(),
                ],
            );
        } catch (\Throwable $e) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                ],
                500,
            );
        }
    }

    /**
     * POST /settings/reset — Reset archive settings to defaults.
     */
    public function reset_settings(WP_REST_Request $request): WP_REST_Response
    {
        $type = $request->get_param('type');

        if (is_string($type) && $type !== '') {
            $this->manager->reset($type);
        } else {
            $this->manager->resetAll();
        }

        return $this->success(
            [
                'reset'    => true,
                'message'  => __('Archive settings reset to defaults.', 'seopulse'),
                'settings' => $this->manager->getAll(),
            ],
        );
    }

    /**
     * GET /analysis — Return SEO analysis and recommendations.
     */
    public function get_analysis(WP_REST_Request $request): WP_REST_Response
    {
        $analyzer = new ArchiveAnalyzer($this->manager);

        return $this->success($analyzer->analyze());
    }

    /**
     * GET /authors — Author data for the settings UI.
     */
    public function get_authors(WP_REST_Request $request): WP_REST_Response
    {
        $analyzer     = new ArchiveAnalyzer($this->manager);
        $activeCount  = $analyzer->getActiveAuthorCount();
        $emptyAuthors = $analyzer->getAuthorsWithoutContent();

        // Get all authors with their post counts
        $authors = get_users(
            [
                'role__in' => ['author', 'editor', 'administrator', 'contributor'],
                'orderby'  => 'post_count',
                'order'    => 'DESC',
            ],
        );

        $authorData = [];

        foreach ($authors as $user) {
            $authorData[] = [
                'id'         => $user->ID,
                'name'       => $user->display_name,
                'email'      => $user->user_email,
                'post_count' => (int) count_user_posts($user->ID, 'post', true),
                'has_bio'    => !empty($user->description),
                'url'        => get_author_posts_url($user->ID),
            ];
        }

        return $this->success(
            [
                'authors'          => $authorData,
                'active_count'     => $activeCount,
                'empty_authors'    => $emptyAuthors,
                'is_single_author' => $activeCount <= 1,
            ],
        );
    }

    /**
     * GET /404-report — Frequent 404 URLs with redirect suggestions.
     */
    public function get_404_report(WP_REST_Request $request): WP_REST_Response
    {
        $logs = get_option('seopulse_404_logs', []);

        if (!is_array($logs)) {
            $logs = [];
        }

        // Sort by count descending
        usort($logs, static fn ($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

        $report = [];

        foreach ($logs as $log) {
            $url = $log['url'] ?? '';

            if ($url === '') {
                continue;
            }

            // Try to find a similar post for redirect suggestion
            $suggestion = $this->findRedirectSuggestion($url);

            $report[] = [
                'url'         => $url,
                'hits'        => (int) ($log['count'] ?? 0),
                'last_access' => $log['last_access'] ?? '',
                'referrer'    => $log['referrer'] ?? '',
                'suggestion'  => $suggestion,
            ];
        }

        return $this->success(
            [
                'total'  => count($report),
                'report' => array_slice($report, 0, 50),
            ],
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Find a redirect suggestion for a 404 URL based on slug similarity.
     *
     * @param string $url The 404 URL.
     * @return array{url: string, title: string, similarity: float}|null
     */
    private function findRedirectSuggestion(string $url): ?array
    {
        // Extract the slug from the URL
        $path = wp_parse_url($url, PHP_URL_PATH);

        if (!is_string($path)) {
            return null;
        }

        $slug = trim($path, '/');
        $slug = basename($slug);

        if ($slug === '') {
            return null;
        }

        // Search for posts with similar slugs
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_name
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                 AND post_type IN ('post', 'page')
                 AND post_name LIKE %s
                 LIMIT 5",
                '%' . $wpdb->esc_like($slug) . '%',
            ),
        );

        if (empty($results)) {
            return null;
        }

        // Find best match by similarity
        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $post) {
            similar_text($slug, $post->post_name, $similarity);

            if ($similarity > $bestScore) {
                $bestScore = $similarity;
                $bestMatch = $post;
            }
        }

        if ($bestMatch === null || $bestScore < 30) {
            return null;
        }

        $permalink = get_permalink($bestMatch->ID);

        return [
            'url'        => is_string($permalink) ? $permalink : '',
            'title'      => $bestMatch->post_title,
            'similarity' => round($bestScore, 1),
        ];
    }
}
