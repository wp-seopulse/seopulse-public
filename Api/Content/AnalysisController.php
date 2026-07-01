<?php

/**
 * REST API controller for SEO analysis
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\Content;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Core\Constants\PostMeta;
use SEOPulse\Modules\I18n\FocusKeywordLocalization;
use SEOPulse\Modules\I18n\LanguageDetector;
use SEOPulse\Services\CacheManager;
use SEOPulse\Services\DashboardSummary;
use SEOPulse\Services\ScoreCalculator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * AnalysisController class
 */
class AnalysisController extends RestController
{
    /**
     * Score calculator
     *
     * @var ScoreCalculator
     */
    private ScoreCalculator $score_calculator;

    /**
     * Cache manager
     *
     * @var CacheManager
     */
    private CacheManager $cache;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base        = 'analyze';
        $this->score_calculator = new ScoreCalculator();
        $this->cache            = new CacheManager();
    }

    /**
     * Registers routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // POST /seopulse/v1/analyze
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'analyze_post'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => $this->get_analyze_args(),
                ],
            ],
        );

        // GET /seopulse/v1/analyze/{post_id}
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_cached_analysis'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'validate_callback' => function ($param) {
                                return is_numeric($param) && $param > 0;
                            },
                        ],
                    ],
                ],
            ],
        );

        // GET /seopulse/v1/analyze/{post_id}/internal-links
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)/internal-links',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_internal_link_suggestions'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'validate_callback' => function ($param) {
                                return is_numeric($param) && $param > 0;
                            },
                        ],
                        'keyword' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'limit'   => [
                            'required'          => false,
                            'type'              => 'integer',
                            'default'           => 5,
                            'validate_callback' => function ($param) {
                                return is_numeric($param) && $param > 0 && $param <= 20;
                            },
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Arguments for the analyze endpoint
     *
     * @return array
     */
    private function get_analyze_args(): array
    {
        return [
            'post_id'          => [
                'required'          => true,
                'type'              => 'integer',
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
            ],
            'force_refresh'    => [
                'required' => false,
                'type'     => 'boolean',
                'default'  => false,
            ],
            'focus_keyword'    => [
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Deprecated: use focus_keywords array instead (single keyword only, kept for backwards compatibility)',
            ],
            'focus_keywords'   => [
                'required'    => false,
                'type'        => 'array',
                'default'     => [],
                'description' => 'Multi-keyword support (v3.0+): array of keywords to analyze',
                'items'       => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
            'content'          => [
                'required' => false,
                'type'     => 'string',
                'default'  => '',
            ],
            'title'            => [
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'meta_description' => [
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Analyzes a post
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function analyze_post(WP_REST_Request $request)
    {
        $post_id               = $request->get_param('post_id');
        $force_refresh         = $request->get_param('force_refresh');
        $focus_keyword         = $request->get_param('focus_keyword');  // Legacy single keyword
        $focus_keywords        = $request->get_param('focus_keywords');  // NEW: array of keywords
        $live_content          = $request->get_param('content');
        $live_title            = $request->get_param('title');
        $live_meta_description = $request->get_param('meta_description');

        // Retrieve the post
        $post = get_post($post_id);
        if (!$post) {
            return $this->error(
                __('Post not found.', 'seopulse'),
                404,
            );
        }

        // Check post-specific permissions
        if (!current_user_can('edit_post', $post_id)) {
            return $this->error(
                __('You do not have permission to analyze this post.', 'seopulse'),
                403,
            );
        }

        // Handle multi-keyword support (v3.0+) with backwards compatibility
        if (!empty($focus_keywords) && is_array($focus_keywords)) {
            // NEW: focus_keywords array provided
            $keywords_to_save = array_filter(array_map('sanitize_text_field', $focus_keywords));

            // Validate keyword format (min 2 chars, max 50 chars)
            foreach ($keywords_to_save as $kw) {
                $len = mb_strlen($kw);
                if ($len < 2 || $len > 50) {
                    return $this->error(
                        __('Each focus keyword must be between 2 and 50 characters.', 'seopulse'),
                        400,
                    );
                }
            }

            // Remove duplicates (case-insensitive)
            $seen   = [];
            $unique = [];
            foreach ($keywords_to_save as $kw) {
                $lower = mb_strtolower($kw);
                if (!isset($seen[ $lower ])) {
                    $seen[ $lower ] = true;
                    $unique[]       = $kw;
                }
            }
            $keywords_to_save = $unique;

            if (!empty($keywords_to_save)) {
                FocusKeywordLocalization::save($post_id, array_values($keywords_to_save));
            }
        } elseif ($focus_keyword !== '' && $focus_keyword !== null) {
            // LEGACY: single keyword provided - convert to array
            $keywords_to_save = [sanitize_text_field($focus_keyword)];
            FocusKeywordLocalization::save($post_id, $keywords_to_save);
        }

        // Override post data with live editor content so analyzers
        // see the unsaved Gutenberg state instead of stale DB data.
        if (!empty($live_content)) {
            $post->post_content = wp_kses_post($live_content);
        }
        if (!empty($live_title)) {
            $post->post_title = $live_title;
        }

        // Persist live meta description so MetaAnalyzer picks it up.
        if ($live_meta_description !== '' && $live_meta_description !== null) {
            $meta_seo = get_post_meta($post_id, PostMeta::META_SEO, true);
            if (!is_array($meta_seo)) {
                $meta_seo = [];
            }
            $meta_seo['description'] = $live_meta_description;
            update_post_meta($post_id, PostMeta::META_SEO, $meta_seo);
        }

        // Check cache (unless force_refresh)
        if (!$force_refresh) {
            $cached = $this->cache->get_analysis($post_id);
            if ($cached !== null) {
                return $this->success(
                    [
                        'post_id'   => $post_id,
                        'analysis'  => $cached,
                        'cached'    => true,
                        'timestamp' => time(),
                        'language'  => LanguageDetector::for_post($post_id),
                    ],
                );
            }
        }

        // Run the analysis
        try {
            $analysis = $this->score_calculator->calculate($post);

            // Cache the result
            $this->cache->set_analysis($post_id, $analysis);

            // Save in post meta for history
            $this->save_analysis_meta($post_id, $analysis);

            return $this->success(
                [
                    'post_id'   => $post_id,
                    'analysis'  => $analysis,
                    'cached'    => false,
                    'timestamp' => time(),
                    'language'  => LanguageDetector::for_post($post_id),
                ],
            );
        } catch (\Exception $e) {
            return $this->error(
                sprintf(
                    /* translators: %s: error message */
                    __('Analysis failed: %s', 'seopulse'),
                    $e->getMessage(),
                ),
                500,
            );
        }
    }

    /**
     * Retrieves cached analysis
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function get_cached_analysis(WP_REST_Request $request)
    {
        $post_id = $request->get_param('post_id');

        $post = get_post($post_id);
        if (!$post) {
            return $this->error(__('Post not found.', 'seopulse'), 404);
        }

        $cached = $this->cache->get_analysis($post_id);

        if ($cached === null) {
            return $this->error(
                __('No cached analysis found. Please run an analysis first.', 'seopulse'),
                404,
            );
        }

        return $this->success(
            [
                'post_id'  => $post_id,
                'analysis' => $cached,
                'cached'   => true,
            ],
        );
    }

    /**
     * Returns internal link suggestions based on the focus keyword.
     *
     * Searches published posts/pages (excluding the current post) whose
     * title or content contain the keyword, ordered by relevance.
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function get_internal_link_suggestions(WP_REST_Request $request)
    {
        $post_id = (int) $request->get_param('post_id');
        $keyword = $request->get_param('keyword');
        $limit   = (int) $request->get_param('limit');

        // If no keyword provided, try reading from post meta.
        if (empty($keyword)) {
            $keyword = (string) get_post_meta($post_id, '_seopulse_focus_keyword', true);
        }

        if (empty($keyword)) {
            return $this->success(
                [
                    'suggestions' => [],
                    'keyword'     => '',
                ],
            );
        }

        global $wpdb;

        $like_keyword = '%' . $wpdb->esc_like($keyword) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_type, post_name
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type IN ('post', 'page')
                   AND ID != %d
                   AND (post_title LIKE %s OR post_content LIKE %s)
                 ORDER BY
                   CASE WHEN post_title LIKE %s THEN 0 ELSE 1 END,
                   post_date DESC
                 LIMIT %d",
                $post_id,
                $like_keyword,
                $like_keyword,
                $like_keyword,
                $limit,
            ),
        );

        $suggestions = [];

        foreach ($results as $row) {
            $suggestions[] = [
                'id'        => (int) $row->ID,
                'title'     => $row->post_title,
                'url'       => get_permalink((int) $row->ID),
                'post_type' => $row->post_type,
                'edit_url'  => get_edit_post_link((int) $row->ID, 'raw'),
            ];
        }

        return $this->success(
            [
                'suggestions' => $suggestions,
                'keyword'     => $keyword,
            ],
        );
    }

    /**
     * Saves the analysis in post meta
     *
     * @param int $post_id Post ID
     * @param array $analysis Analysis result
     * @return void
     */
    private function save_analysis_meta(int $post_id, array $analysis): void
    {
        // Save the main score
        update_post_meta($post_id, '_seopulse_score', $analysis['total_score']);

        // Save the last analysis date
        update_post_meta($post_id, '_seopulse_last_analysis', time());

        // Save scores by category (MetaBox-compatible format)
        $scores_for_metabox = [];
        foreach ($analysis['scores'] as $module_name => $module_data) {
            // Store only the score (not the whole object)
            $scores_for_metabox[ $module_name ] = [
                'score'  => $module_data['score'],
                'weight' => $module_data['weight'] ?? 1.0,
            ];
        }
        update_post_meta($post_id, '_seopulse_scores', $scores_for_metabox);

        // Save the number of actionable recommendations (blockers + quick wins)
        $blockers_count   = count($analysis['recommendations']['blockers'] ?? []);
        $quick_wins_count = count($analysis['recommendations']['quick_wins'] ?? []);
        update_post_meta($post_id, '_seopulse_recommendations_count', $blockers_count + $quick_wins_count);

        // Save Image SEO signal for dashboard aggregation
        $content_data       = $analysis['scores']['content']['data'] ?? [];
        $images_without_alt = (int) ($content_data['images']['images_without_alt'] ?? 0);
        update_post_meta($post_id, '_seopulse_images_without_alt', $images_without_alt);

        // Invalidate dashboard caches (React dashboard + PHP WP dashboard widget)
        DashboardSummary::invalidate();
        delete_transient('seopulse_dashboard_seo_overview');
    }
}
