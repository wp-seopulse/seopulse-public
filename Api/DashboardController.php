<?php

/**
 * REST API Controller for the Dashboard Summary
 *
 * Exposes the DashboardSummary contract via a REST endpoint
 * so the admin UI (PHP or React) can consume it.
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
use SEOPulse\Services\DashboardSummary;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

class DashboardController extends RestController
{
    /** User meta key holding each user's dashboard layout. */
    public const LAYOUT_META_KEY = 'seopulse_dashboard_layout';

    /**
     * Draggable blocks and their default column.
     * Keep in sync with BLOCK_IDS in the React types (types/dashboard.ts).
     */
    private const LAYOUT_LEFT_BLOCKS = [
        'seo-health',
        'technical-checks',
        'quick-wins',
        'image-issues',
        'instant-indexing',
    ];

    private const LAYOUT_RIGHT_BLOCKS = [
        'setup-wizard',
        'seopulse-suite',
        'recent-analyses',
        'modules-panel',
    ];

    /** Upper bound for stored visibility keys (protects user meta from abuse). */
    private const LAYOUT_MAX_VISIBILITY_KEYS = 64;

    public function __construct()
    {
        $this->rest_base = 'dashboard';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/summary',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_summary'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/refresh',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'refresh_summary'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/recent-analyses',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_recent_analyses'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/layout',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_layout_response'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'save_layout'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'blockOrder'        => [
                            'required'          => false,
                            'validate_callback' => [self::class, 'validate_block_order'],
                        ],
                        'sectionVisibility' => [
                            'required'          => false,
                            'validate_callback' => [self::class, 'validate_visibility'],
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * GET /seopulse/v1/dashboard/summary
     *
     * Returns the full dashboard summary contract.
     */
    public function get_summary(WP_REST_Request $request): WP_REST_Response
    {
        $summary = new DashboardSummary();

        return $this->success($summary->get());
    }

    /**
     * POST /seopulse/v1/dashboard/refresh
     *
     * Invalidates the cache and returns a fresh summary.
     */
    public function refresh_summary(WP_REST_Request $request): WP_REST_Response
    {
        DashboardSummary::invalidate();
        $summary = new DashboardSummary();

        return $this->success($summary->get());
    }

    /**
     * GET /seopulse/v1/dashboard/recent-analyses
     *
     * Returns the 10 most recently analysed posts with their SEO score.
     * Shape: Array<{ id, title, score, analyzed_at, edit_url }>
     */
    public function get_recent_analyses(WP_REST_Request $request): WP_REST_Response
    {
        $query = new WP_Query(
            [
                'post_type'      => ['post', 'page'],
                'post_status'    => 'publish',
                'posts_per_page' => 10,
                'meta_key'       => '_seopulse_last_analysis',
                'orderby'        => 'meta_value_num',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ],
        );

        $posts = $query->posts;
        if (!empty($posts)) {
            update_meta_cache('post', wp_list_pluck($posts, 'ID'));
        }

        $results = [];
        foreach ($posts as $post) {
            $post_id     = (int) $post->ID;
            $score       = (int) get_post_meta($post_id, '_seopulse_score', true);
            $analyzed_at = (int) get_post_meta($post_id, '_seopulse_last_analysis', true);

            $results[] = [
                'id'          => $post_id,
                'title'       => get_the_title($post),
                'score'       => $score,
                'analyzed_at' => $analyzed_at,
                'edit_url'    => get_edit_post_link($post_id, 'raw') ?: '',
            ];
        }

        return $this->success($results);
    }

    /**
     * GET /seopulse/v1/dashboard/layout
     *
     * Returns the current user's saved dashboard layout.
     * Shape: { blockOrder: {left, right}|null, sectionVisibility: object|null }
     */
    public function get_layout_response(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(self::get_layout(get_current_user_id()));
    }

    /**
     * POST /seopulse/v1/dashboard/layout
     *
     * Partial update: only the supplied keys (blockOrder / sectionVisibility)
     * are replaced. Stored per user in user meta.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function save_layout(WP_REST_Request $request)
    {
        $has_order      = is_array($request->get_param('blockOrder'));
        $has_visibility = is_array($request->get_param('sectionVisibility'));

        if (!$has_order && !$has_visibility) {
            return new WP_Error(
                'seopulse_empty_layout',
                __('Nothing to save: provide blockOrder and/or sectionVisibility.', 'seopulse'),
                ['status' => 400],
            );
        }

        $user_id = get_current_user_id();
        $stored  = self::read_stored_layout($user_id);

        if ($has_order) {
            $stored['blockOrder'] = self::sanitize_block_order($request->get_param('blockOrder'));
        }
        if ($has_visibility) {
            $stored['sectionVisibility'] = self::sanitize_visibility($request->get_param('sectionVisibility'));
        }

        // update_user_meta() returns false when the value is unchanged: not an error.
        update_user_meta($user_id, self::LAYOUT_META_KEY, $stored);

        return $this->success(self::get_layout($user_id));
    }

    /**
     * Layout formatted for the client. Also used to seed the page:
     *
     *   wp_localize_script('seopulse-dashboard', 'seopulseDashboard', [
     *       // …existing keys…
     *       'layout' => \SEOPulse\Api\DashboardController::get_layout(get_current_user_id()),
     *   ]);
     *
     * Each part is null when the user has never saved it.
     *
     * @return array{blockOrder: array|null, sectionVisibility: object|null}
     */
    public static function get_layout(int $user_id): array
    {
        $stored = self::read_stored_layout($user_id);

        return [
            'blockOrder'        => $stored['blockOrder'],
            // (object) so an empty map is JSON-encoded as {} and not [].
            'sectionVisibility' => null === $stored['sectionVisibility']
                ? null
                : (object) $stored['sectionVisibility'],
        ];
    }

    /**
     * Reads and re-sanitises what is stored, so a corrupted or outdated meta
     * value can never reach the client.
     *
     * @return array{blockOrder: array|null, sectionVisibility: array|null}
     */
    private static function read_stored_layout(int $user_id): array
    {
        $meta = $user_id > 0 ? get_user_meta($user_id, self::LAYOUT_META_KEY, true) : [];
        $meta = is_array($meta) ? $meta : [];

        return [
            'blockOrder'        => isset($meta['blockOrder']) && is_array($meta['blockOrder'])
                ? self::sanitize_block_order($meta['blockOrder'])
                : null,
            'sectionVisibility' => isset($meta['sectionVisibility']) && is_array($meta['sectionVisibility'])
                ? self::sanitize_visibility($meta['sectionVisibility'])
                : null,
        ];
    }

    /**
     * Request validation for `blockOrder`.
     *
     * @param mixed $value Raw value.
     * @return bool|WP_Error
     */
    public static function validate_block_order($value)
    {
        if (!is_array($value)) {
            return new WP_Error(
                'rest_invalid_param',
                __('blockOrder must be an object with "left" and "right" lists.', 'seopulse'),
                ['status' => 400],
            );
        }

        foreach (['left', 'right'] as $column) {
            if (isset($value[$column]) && !is_array($value[$column])) {
                return new WP_Error(
                    'rest_invalid_param',
                    __('Each column of blockOrder must be a list.', 'seopulse'),
                    ['status' => 400],
                );
            }
        }

        return true;
    }

    /**
     * Request validation for `sectionVisibility`.
     *
     * @param mixed $value Raw value.
     * @return bool|WP_Error
     */
    public static function validate_visibility($value)
    {
        if (!is_array($value)) {
            return new WP_Error(
                'rest_invalid_param',
                __('sectionVisibility must be an object of booleans.', 'seopulse'),
                ['status' => 400],
            );
        }

        return true;
    }

    /**
     * Builds a valid block order: unknown IDs dropped, duplicates removed
     * (first occurrence wins), missing blocks appended to their default column.
     *
     * @param mixed $value Raw value.
     * @return array{left: string[], right: string[]}
     */
    public static function sanitize_block_order($value): array
    {
        $value = is_array($value) ? $value : [];
        $known = array_merge(self::LAYOUT_LEFT_BLOCKS, self::LAYOUT_RIGHT_BLOCKS);
        $seen  = [];

        $clean = static function ($ids) use ($known, &$seen): array {
            $out = [];
            if (!is_array($ids)) {
                return $out;
            }
            foreach ($ids as $id) {
                if (is_string($id) && in_array($id, $known, true) && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[]     = $id;
                }
            }

            return $out;
        };

        $left  = $clean($value['left'] ?? []);
        $right = $clean($value['right'] ?? []);

        foreach ($known as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            if (in_array($id, self::LAYOUT_LEFT_BLOCKS, true)) {
                $left[] = $id;
            } else {
                $right[] = $id;
            }
        }

        return ['left' => $left, 'right' => $right];
    }

    /**
     * Keeps only well-formed `key => bool` pairs, capped in size.
     *
     * @param mixed $value Raw value.
     * @return array<string, bool>
     */
    public static function sanitize_visibility($value): array
    {
        $out = [];
        if (!is_array($value)) {
            return $out;
        }

        foreach ($value as $key => $flag) {
            if (count($out) >= self::LAYOUT_MAX_VISIBILITY_KEYS) {
                break;
            }
            $key = (string) $key;
            if (1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) {
                continue;
            }
            $out[$key] = rest_sanitize_boolean($flag);
        }

        return $out;
    }
}
