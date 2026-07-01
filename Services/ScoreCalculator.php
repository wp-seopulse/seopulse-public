<?php

/**
 * Global SEO score calculation service
 *
 * Aggregates results from all modules and calculates the final score
 * Enhanced scoring system inspired by Yoast, Rank Math and SEOPress
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

use SEOPulse\Core\Logger;

use function SEOPulse\seopulse_get_service;

use WP_Post;

/**
 * ScoreCalculator class
 */
class ScoreCalculator
{
    /**
     * Analysis modules
     *
     * @var array
     */
    private array $modules = [];

    /**
     * Weight configuration by category (modifiable via filters)
     *
     * @var array
     */
    private array $weights = [
        'content'     => 0.35,      // 35% - Content analysis
        'meta'        => 0.25,         // 25% - Meta tags
        'readability' => 0.20,  // 20% - Readability
        'technical'   => 0.20,    // 20% - Technical aspects (images, links, etc.)
    ];

    /**
     * Score thresholds for levels
     *
     * @var array
     */
    private array $score_thresholds = [
        'excellent'         => 80, // 80-100
        'good'              => 60, // 60-79
        'needs_improvement' => 40, // 40-59
        'poor'              => 0,  // 0-39
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->load_modules();
        $this->apply_filters();
    }

    /**
     * Loads analysis modules
     *
     * @return void
     */
    private function load_modules(): void
    {
        // Retrieving modules from the main plugin
        // get_module() returns null if the module is disabled
        $plugin = \SEOPulse\seopulse();

        $candidates = [
            'content'     => $plugin->get_module('content_analyzer'),
            'meta'        => $plugin->get_module('meta_analyzer'),
            'readability' => $plugin->get_module('readability_analyzer'),
        ];

        // Exclude disabled modules (null)
        $this->modules = array_filter(
            $candidates,
            function ($module) {
                return $module !== null;
            },
        );

        /**
         * Filter to modify modules used for calculation
         *
         * @since 1.0.0
         * @param array $modules List of modules
         */
        $this->modules = apply_filters('seopulse_score_modules', $this->modules);
    }

    /**
     * Applies WordPress filters to the configuration
     *
     * @return void
     */
    private function apply_filters(): void
    {
        /**
         * Filters category weights
         *
         * @since 1.0.0
         * @param array $weights Category weights
         */
        $this->weights = apply_filters('seopulse_score_weights', $this->weights);

        /**
         * Filters score thresholds
         *
         * @since 1.0.0
         * @param array $thresholds Score thresholds
         */
        $this->score_thresholds = apply_filters('seopulse_score_thresholds', $this->score_thresholds);
    }

    /**
     * Calculates the complete SEO score for a post
     *
     * @param WP_Post $post WordPress post
     * @return array Analysis result
     */
    public function calculate(WP_Post $post): array
    {
        $start_time = microtime(true);

        $module_results      = [];
        $all_issues          = [];
        $all_recommendations = [];
        $weighted_scores     = [];
        $total_weight        = 0;
        $detailed_checks     = [];

        // Execute each module
        foreach ($this->modules as $module_name => $module) {
            if (!$module || !method_exists($module, 'analyze')) {
                continue;
            }

            try {
                $result = $module->analyze($post);
                $weight = method_exists($module, 'get_weight') ? $module->get_weight() : ($this->weights[ $module_name ] ?? 1.0);

                $module_results[ $module_name ] = [
                    'score'  => $result['score'],
                    'weight' => $weight,
                    'data'   => $result['data'] ?? [],
                    'level'  => $this->get_score_level($result['score']),
                ];

                $all_issues          = array_merge($all_issues, $result['issues'] ?? []);
                $all_recommendations = array_merge($all_recommendations, $result['recommendations'] ?? []);

                $weighted_scores[] = $result['score'] * $weight;
                $total_weight     += $weight;

                // Add detailed checks if available
                if (isset($result['checks'])) {
                    $detailed_checks[ $module_name ] = $result['checks'];
                }
            } catch (\Exception $e) {
                // Log the error but continue with other modules
                $logger = seopulse_get_service('Logger');
                if ($logger instanceof Logger) {
                    $logger->error(
                        'Error in analysis module',
                        [
                            'module' => $module_name,
                            'error'  => $e->getMessage(),
                        ],
                    );
                }

                // Module in error = score 0
                $module_results[ $module_name ] = [
                    'score'  => 0,
                    'weight' => $this->weights[ $module_name ] ?? 0,
                    'data'   => ['error' => $e->getMessage()],
                    'level'  => 'poor',
                ];
            }
        }

        // Weighted global score calculation
        $total_score = $total_weight > 0
            ? array_sum($weighted_scores) / $total_weight
            : 0;

        // Score rounding
        $total_score = round($total_score);

        // Recommendation prioritization
        $prioritizer                 = new RecommendationPrioritizer();
        $prioritized_recommendations = $prioritizer->prioritize($all_recommendations);

        // Global level determination
        $level = $this->get_score_level($total_score);

        // Performance statistics
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        // Detailed analysis by criteria
        $criteria_breakdown = $this->build_criteria_breakdown($module_results, $all_issues);

        // Building the final result
        $analysis = [
            'total_score'        => (int) $total_score,
            'level'              => $level,
            'scores'             => $module_results,
            'issues'             => $this->categorize_issues($all_issues),
            'recommendations'    => $prioritized_recommendations,
            'summary'            => $this->generate_summary($total_score, $module_results, $prioritized_recommendations),
            'criteria_breakdown' => $criteria_breakdown,
            'detailed_checks'    => $detailed_checks,
            'analyzed_at'        => current_time('mysql'),
            'post_modified'      => $post->post_modified,
            'execution_time_ms'  => $execution_time,
            'version'            => '1.1.0', // Calculator version
        ];

        /**
         * Filters the analysis result before returning it
         *
         * @since 1.0.0
         * @param array $analysis Analysis result
         * @param WP_Post $post Analyzed post
         */
        return apply_filters('seopulse_analysis_result', $analysis, $post);
    }

    /**
     * Builds a detailed analysis by criteria
     *
     * @param array $module_results Results per module
     * @param array $all_issues All detected issues
     * @return array
     */
    private function build_criteria_breakdown(array $module_results, array $all_issues): array
    {
        $breakdown = [
            'total_checks'          => 0,
            'passed_checks'         => 0,
            'warnings'              => 0,
            'errors'                => 0,
            'critical_errors'       => 0,
            'completion_percentage' => 0,
        ];

        // Count issues by severity
        foreach ($all_issues as $issue) {
            $severity = $issue['severity'] ?? 'low';

            switch ($severity) {
                case 'critical':
                    ++$breakdown['critical_errors'];
                    ++$breakdown['errors'];
                    break;
                case 'high':
                    ++$breakdown['errors'];
                    break;
                case 'medium':
                    ++$breakdown['warnings'];
                    break;
                case 'low':
                    ++$breakdown['warnings'];
                    break;
            }

            ++$breakdown['total_checks'];
        }

        // Calculate the number of successful checks (estimate)
        // We assume approximately 10-15 checks per module
        $estimated_total_checks     = count($module_results) * 12;
        $breakdown['passed_checks'] = max(0, $estimated_total_checks - $breakdown['total_checks']);
        $breakdown['total_checks']  = $estimated_total_checks;

        // Completion percentage
        if ($breakdown['total_checks'] > 0) {
            $breakdown['completion_percentage'] = round(
                ($breakdown['passed_checks'] / $breakdown['total_checks']) * 100,
            );
        }

        return $breakdown;
    }

    /**
     * Determines the level based on the score
     *
     * @param float $score Score (0-100)
     * @return string Level (excellent, good, needs_improvement, poor)
     */
    private function get_score_level(float $score): string
    {
        if ($score >= $this->score_thresholds['excellent']) {
            return 'excellent';
        } elseif ($score >= $this->score_thresholds['good']) {
            return 'good';
        } elseif ($score >= $this->score_thresholds['needs_improvement']) {
            return 'needs_improvement';
        } else {
            return 'poor';
        }
    }

    /**
     * Categorizes issues by severity
     *
     * @param array $issues List of issues
     * @return array Categorized issues
     */
    private function categorize_issues(array $issues): array
    {
        $categorized = [
            'critical' => [],
            'high'     => [],
            'medium'   => [],
            'low'      => [],
        ];

        foreach ($issues as $issue) {
            $severity = $issue['severity'] ?? 'low';
            if (isset($categorized[ $severity ])) {
                $categorized[ $severity ][] = $issue;
            }
        }

        return $categorized;
    }

    /**
     * Generates a text summary of the analysis
     *
     * @param float $total_score Total score
     * @param array $module_results Results per module
     * @param array $recommendations Recommendations
     * @return string Summary
     */
    private function generate_summary(float $total_score, array $module_results, array $recommendations): string
    {
        $level = $this->get_score_level($total_score);

        $summary_parts = [];

        // Main message based on score with emoji
        switch ($level) {
            case 'excellent':
                $summary_parts[] = '🎉 ' . __('Excellent work! Your content is highly optimized for SEO.', 'seopulse');
                break;
            case 'good':
                $summary_parts[] = '👍 ' . __('Good job! Your content is well-optimized, but there are some improvements you can make.', 'seopulse');
                break;
            case 'needs_improvement':
                $summary_parts[] = '⚠️ ' . __('Your content needs significant SEO improvements to perform well.', 'seopulse');
                break;
            case 'poor':
                $summary_parts[] = '❌ ' . __('Your content requires major SEO work. Let\'s start with the priority recommendations.', 'seopulse');
                break;
        }

        // Identification of the weakest module
        $weakest_module   = null;
        $weakest_score    = 100;
        $strongest_module = null;
        $strongest_score  = 0;

        foreach ($module_results as $module_name => $result) {
            if ($result['score'] < $weakest_score) {
                $weakest_score  = $result['score'];
                $weakest_module = $module_name;
            }
            if ($result['score'] > $strongest_score) {
                $strongest_score  = $result['score'];
                $strongest_module = $module_name;
            }
        }

        // Strength if score > 80
        if ($strongest_module && $strongest_score >= 80) {
            $module_labels = [
                'content'     => __('content structure', 'seopulse'),
                'meta'        => __('meta tags', 'seopulse'),
                'readability' => __('readability', 'seopulse'),
            ];

            $summary_parts[] = sprintf(
                /* translators: %s: module name */
                __('Your %s is excellent.', 'seopulse'),
                $module_labels[ $strongest_module ] ?? $strongest_module,
            );
        }

        // Weakness if score < 70
        if ($weakest_module && $weakest_score < 70) {
            $module_labels = [
                'content'     => __('content structure', 'seopulse'),
                'meta'        => __('meta tags', 'seopulse'),
                'readability' => __('readability', 'seopulse'),
            ];

            $summary_parts[] = sprintf(
                /* translators: %s: module name */
                __('Focus on improving your %s first.', 'seopulse'),
                $module_labels[ $weakest_module ] ?? $weakest_module,
            );
        }

        // Number of priority recommendations
        $blockers      = $recommendations['blockers'] ?? [];
        $quick_wins    = $recommendations['quick_wins'] ?? [];
        $high_priority = array_merge($blockers, $quick_wins);

        if (count($high_priority) > 0) {
            $summary_parts[] = sprintf(
                /* translators: %d: number of high-priority recommendations */
                _n(
                    'You have %d high-priority recommendation to address.',
                    'You have %d high-priority recommendations to address.',
                    count($high_priority),
                    'seopulse',
                ),
                count($high_priority),
            );
        }

        // Encouragement message if score is high but not excellent
        if ($level === 'good' && $total_score >= 70) {
            $points_to_excellent = $this->score_thresholds['excellent'] - $total_score;
            $summary_parts[]     = sprintf(
                /* translators: %d: points needed */
                __('You\'re just %d points away from an excellent score!', 'seopulse'),
                (int) $points_to_excellent,
            );
        }

        return implode(' ', $summary_parts);
    }

    /**
     * Calculates the potential impact of implementing all recommendations
     *
     * @param array $recommendations Recommendations
     * @return int Maximum potential score
     */
    private function calculate_potential_score(array $recommendations): int
    {
        $total_impact = 0;

        $actionable = array_merge(
            $recommendations['blockers'] ?? [],
            $recommendations['quick_wins'] ?? [],
        );

        foreach ($actionable as $rec) {
            $total_impact += $rec['estimated_impact'] ?? 0;
        }

        return $total_impact;
    }

    /**
     * Gets the weight of a category
     *
     * @param string $category Category
     * @return float Weight
     */
    public function get_category_weight(string $category): float
    {
        return $this->weights[ $category ] ?? 0.0;
    }

    /**
     * Gets all weights
     *
     * @return array Weights
     */
    public function get_all_weights(): array
    {
        return $this->weights;
    }

    /**
     * Gets the score thresholds
     *
     * @return array Thresholds
     */
    public function get_score_thresholds(): array
    {
        return $this->score_thresholds;
    }
}
