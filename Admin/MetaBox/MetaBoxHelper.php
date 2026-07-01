<?php

/**
 * Helper for the analysis Meta Box
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\MetaBox;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Utility class for the meta box
 */
class MetaBoxHelper
{
    /**
     * Score level
     *
     * @param int $score Score entre 0 et 100
     * @return string excellent|good|needs_improvement|poor
     */
    public static function get_score_level(int $score): string
    {
        if ($score >= 80) {
            return 'excellent';
        }
        if ($score >= 60) {
            return 'good';
        }
        if ($score >= 40) {
            return 'needs_improvement';
        }

        return 'poor';
    }

    /**
     * Formats elapsed time
     *
     * @param int $timestamp Unix timestamp
     * @return string Formatted time
     */
    public static function get_time_ago(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return __('Never', 'seopulse');
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return __('just now', 'seopulse');
        }

        return human_time_diff($timestamp, time());
    }

    /**
     * Category label
     *
     * @param string $category Category key
     * @return string Readable label
     */
    public static function get_category_label(string $category): string
    {
        $labels = [
            'content'              => __('Content', 'seopulse'),
            'content_analyzer'     => __('Content', 'seopulse'),
            'meta'                 => __('Meta Tags', 'seopulse'),
            'meta_analyzer'        => __('Meta Tags', 'seopulse'),
            'readability'          => __('Readability', 'seopulse'),
            'readability_analyzer' => __('Readability', 'seopulse'),
        ];

        return $labels[ $category ] ?? ucfirst(str_replace(['_', '-'], ' ', $category));
    }
}
