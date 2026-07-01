<?php

/**
 * WP-CLI command: wp seopulse analyze
 *
 * Runs SEO analysis for one post or a filtered set of posts.
 *
 * @package SEOPulse\Core\CLI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Services\CacheManager;
use SEOPulse\Services\ScoreCalculator;

/**
 * Analyze SEO scores for posts.
 */
class AnalyzeCommand extends BaseCommand
{
    /**
     * Runs SEO analysis on one or more posts.
     *
     * ## OPTIONS
     *
     * [<post_id>]
     * : Analyze a single post by ID.
     *
     * [--post_type=<type>]
     * : Post type to analyze. Default: post
     *
     * [--post_status=<status>]
     * : Post status filter. Default: publish
     *
     * [--limit=<number>]
     * : Maximum number of posts to analyze. Default: all
     *
     * [--force]
     * : Force re-analysis even if cached.
     *
     * [--format=<format>]
     * : Output format. Options: table, json, csv. Default: table
     *
     * ## EXAMPLES
     *
     *     # Analyze a single post
     *     wp seopulse analyze 42
     *
     *     # Analyze all published posts
     *     wp seopulse analyze --post_type=post --post_status=publish
     *
     *     # Analyze pages, output as JSON
     *     wp seopulse analyze --post_type=page --format=json
     *
     * @param array<string> $args Positional arguments
     * @param array<string, mixed> $assoc Associative arguments
     * @return void
     */
    public function __invoke(array $args, array $assoc): void
    {
        $post_ids   = $this->resolve_post_ids($args, $assoc);
        $force      = isset($assoc['force']);
        $calculator = new ScoreCalculator();
        $cache      = new CacheManager();
        $results    = [];
        $count      = count($post_ids);

        if ($count > 1) {
            $progress = $this->make_progress('Analyzing posts', $count);
        }

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);

            if (!$post) {
                $this->warning("Post #{$post_id} not found, skipping.");
                if (isset($progress)) {
                    $progress->tick();
                }
                continue;
            }

            // Check cache unless forced
            if (!$force) {
                $cached = $cache->get_analysis($post_id);
                if ($cached !== null) {
                    $results[] = $this->format_row($post_id, $post->post_title, $cached, true);
                    if (isset($progress)) {
                        $progress->tick();
                    }
                    continue;
                }
            }

            try {
                $analysis = $calculator->calculate($post);
                $cache->set_analysis($post_id, $analysis);
                $this->save_analysis_meta($post_id, $analysis);

                $results[] = $this->format_row($post_id, $post->post_title, $analysis, false);
            } catch (\Throwable $e) {
                $this->warning("Failed to analyze #{$post_id}: {$e->getMessage()}");
                $results[] = [
                    'ID'     => $post_id,
                    'Title'  => mb_substr($post->post_title, 0, 50),
                    'Score'  => 'ERROR',
                    'Level'  => '-',
                    'Cached' => '-',
                ];
            }

            if (isset($progress)) {
                $progress->tick();
            }
        }

        if (isset($progress)) {
            $progress->finish();
        }

        if (empty($results)) {
            $this->error('No posts were analyzed.');
        }

        $this->format_items($results, ['ID', 'Title', 'Score', 'Level', 'Cached'], $assoc);

        $this->success(sprintf('%d post(s) analyzed.', count($results)));
    }

    /**
     * Formats one analysis result as a table row
     *
     * @param int $post_id Post ID
     * @param string $title Post title
     * @param array $analysis Analysis result
     * @param bool $cached Whether result came from cache
     * @return array<string, mixed>
     */
    private function format_row(int $post_id, string $title, array $analysis, bool $cached): array
    {
        $score = (int) ($analysis['total_score'] ?? 0);

        return [
            'ID'     => $post_id,
            'Title'  => mb_substr($title, 0, 50),
            'Score'  => $score,
            'Level'  => $this->score_level($score),
            'Cached' => $cached ? __('yes', 'seopulse') : __('no', 'seopulse'),
        ];
    }

    /**
     * Returns a human-readable score level
     *
     * @param int $score Score 0-100
     * @return string
     */
    private function score_level(int $score): string
    {
        if ($score >= 80) {
            return 'excellent';
        }
        if ($score >= 60) {
            return 'good';
        }
        if ($score >= 40) {
            return 'needs-work';
        }

        return 'poor';
    }

    /**
     * Persists analysis results to post meta
     *
     * @param int $post_id Post ID
     * @param array $analysis Analysis data
     * @return void
     */
    private function save_analysis_meta(int $post_id, array $analysis): void
    {
        update_post_meta($post_id, '_seopulse_score', $analysis['total_score']);
        update_post_meta($post_id, '_seopulse_last_analysis', time());

        $scores_for_metabox = [];
        foreach (($analysis['scores'] ?? []) as $module_name => $module_data) {
            $scores_for_metabox[ $module_name ] = [
                'score'  => $module_data['score'],
                'weight' => $module_data['weight'] ?? 1.0,
            ];
        }
        update_post_meta($post_id, '_seopulse_scores', $scores_for_metabox);

        $blockers   = count($analysis['recommendations']['blockers'] ?? []);
        $quick_wins = count($analysis['recommendations']['quick_wins'] ?? []);
        update_post_meta($post_id, '_seopulse_recommendations_count', $blockers + $quick_wins);

        $content_data       = $analysis['scores']['content']['data'] ?? [];
        $images_without_alt = (int) ($content_data['images']['images_without_alt'] ?? 0);
        update_post_meta($post_id, '_seopulse_images_without_alt', $images_without_alt);

        delete_transient('seopulse_dashboard_seo_overview');
    }
}
