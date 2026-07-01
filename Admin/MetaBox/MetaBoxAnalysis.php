<?php

/**
 * SEOPulse meta box in the post editor - Full version
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\MetaBox;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Contracts\ExecuteHooksAdmin;

/**
 * MetaBox class
 */
class MetaBoxAnalysis implements ExecuteHooksAdmin
{
    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        // Analysis UI is handled by the React AnalysisPanel (admin.js → #seopulse-root)
        // on classic editor pages, and by the Gutenberg sidebar on block editor pages.
        // No PHP metabox registration is needed.
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        // Hooks moved to hooks() for the Kernel pattern
    }

    /**
     * Registers the meta box
     *
     * @return void
     */
    public function register_meta_box(): void
    {
        $post_types = $this->get_supported_post_types();

        add_meta_box(
            'seopulse-analysis',
            __('SEOPulse Analysis', 'seopulse'),
            [$this, 'render_meta_box'],
            $post_types,
            'normal',
            'high',
        );
    }

    /**
     * Returns supported post types
     *
     * @return array
     */
    private function get_supported_post_types(): array
    {
        $default_post_types = ['post', 'page'];

        return apply_filters('seopulse_supported_post_types', $default_post_types);
    }

    /**
     * Renders the meta box content
     *
     * @param \WP_Post $post Current post
     * @return void
     */
    public function render_meta_box(\WP_Post $post): void
    {
        $analysis = $this->get_full_analysis($post->ID);

        // Derive the score from the analysis rather than from separate post meta
        if ($analysis !== null && isset($analysis['total_score'])) {
            $score = (int) $analysis['total_score'];
        } else {
            $score = $this->get_score($post->ID);
        }

        $last_analysis = $this->get_last_analysis_time($post->ID);
        $post_id       = $post->ID;
        include SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/metabox/metabox-analysis.php';
    }

    /**
     * AJAX handler — returns re-rendered metabox HTML.
     *
     * Called by the Gutenberg sidebar after a successful analysis so
     * the server-rendered metabox stays in sync without a page reload.
     */
    public function ajax_refresh_metabox(): void
    {
        check_ajax_referer('wp_rest', '_nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'seopulse'), 403);
        }

        $post = get_post($post_id);

        if (!$post) {
            wp_send_json_error(__('Post not found.', 'seopulse'), 404);
        }

        ob_start();
        $this->render_meta_box($post);
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Retrieves the complete analysis
     */
    private function get_full_analysis(int $post_id): ?array
    {
        $cache  = \SEOPulse\seopulse()->cache();
        $cached = $cache->get_analysis($post_id);

        if ($cached !== null) {
            // Le cache contient déjà toute l'analyse
            return $cached;
        }

        // If no cache, check if we at least have a score in post meta
        $score = get_post_meta($post_id, '_seopulse_score', true);

        if ($score === '' || $score === false) {
            return null;
        }

        // Rebuild a basic analysis from post meta
        $scores     = get_post_meta($post_id, '_seopulse_scores', true);
        $recs_count = (int) get_post_meta($post_id, '_seopulse_recommendations_count', true);

        return [
            'total_score'     => (int) $score,
            'level'           => MetaBoxHelper::get_score_level((int) $score),
            'scores'          => is_array($scores) ? $scores : [],
            'issues'          => [
                'critical' => [],
                'high'     => [],
                'medium'   => [],
                'low'      => [],
            ],
            'recommendations' => [
                'blockers'   => [],
                'quick_wins' => [],
                'others'     => [],
                'top'        => [],
                'total'      => $recs_count,
            ],
            'summary'         => __('Analysis data partially available. Run a new analysis for full details.', 'seopulse'),
        ];
    }

    /**
     * Retrieves the score
     */
    private function get_score(int $post_id): int
    {
        $score = get_post_meta($post_id, '_seopulse_score', true);
        $score = is_numeric($score) ? (int) $score : 0;

        return max(0, min(100, $score));
    }

    /**
     * Retrieves the timestamp
     */
    private function get_last_analysis_time(int $post_id): int
    {
        $timestamp = get_post_meta($post_id, '_seopulse_last_analysis', true);

        return is_numeric($timestamp) ? (int) $timestamp : 0;
    }
}
