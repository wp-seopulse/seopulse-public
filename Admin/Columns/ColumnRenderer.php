<?php

/**
 * Renders individual admin column cells.
 *
 * Each public method renders one column type using data
 * from the primed meta cache (ColumnQuery).
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\Columns;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ColumnRenderer — HTML output for each custom column.
 */
final class ColumnRenderer
{
    /**
     * Render the SEO Score badge.
     *
     * Colour rules: green >= 80, yellow >= 60, red < 60.
     *
     * @param int $post_id Post ID.
     *
     * @return void
     */
    public static function score(int $post_id): void
    {
        $score = ColumnQuery::get_score($post_id);

        if ($score === null) {
            echo '<span class="seopulse-col-score seopulse-col-score--na" aria-label="'
                . esc_attr__('Not analyzed', 'seopulse') . '">&mdash;</span>';

            return;
        }

        if ($score >= 80) {
            $color_class = 'green';
        } elseif ($score >= 60) {
            $color_class = 'yellow';
        } else {
            $color_class = 'red';
        }

        $edit_url = get_edit_post_link($post_id, 'raw');

        printf(
            '<a href="%s" class="seopulse-col-score seopulse-col-score--%s" title="%s">%d%%</a>',
            esc_url((string) $edit_url),
            esc_attr($color_class),
            /* translators: %d: SEO score percentage */
            esc_attr(sprintf(__('SEO Score: %d%%', 'seopulse'), $score)),
            (int) $score,
        );
    }

    /**
     * Render the Title / Meta Description preview.
     *
     * @param int $post_id Post ID.
     *
     * @return void
     */
    public static function meta(int $post_id): void
    {
        $title = ColumnQuery::get_meta_title($post_id);
        $desc  = ColumnQuery::get_meta_description($post_id);

        if ($title === '' && $desc === '') {
            echo '<span class="seopulse-col-meta seopulse-col-meta--empty">'
                . esc_html__('No meta data', 'seopulse') . '</span>';

            return;
        }

        echo '<div class="seopulse-col-meta">';

        if ($title !== '') {
            printf(
                '<div class="seopulse-col-meta__title" title="%s">%s</div>',
                esc_attr($title),
                esc_html(mb_strimwidth($title, 0, 75, '…')),
            );
        }

        if ($desc !== '') {
            printf(
                '<div class="seopulse-col-meta__desc" title="%s">%s</div>',
                esc_attr($desc),
                esc_html(mb_strimwidth($desc, 0, 110, '…')),
            );
        }

        echo '</div>';
    }

    /**
     * Render the analysis status indicator.
     *
     * @param int $post_id Post ID.
     *
     * @return void
     */
    public static function status(int $post_id): void
    {
        $status = ColumnQuery::get_status($post_id);

        $labels = [
            'up-to-date'     => __('Up to date', 'seopulse'),
            'needs-analysis' => __('Needs analysis', 'seopulse'),
            'not-analyzed'   => __('Not analyzed', 'seopulse'),
        ];

        $label = $labels[ $status ] ?? $labels['not-analyzed'];

        printf(
            '<span class="seopulse-col-status seopulse-col-status--%s">%s</span>',
            esc_attr($status),
            esc_html($label),
        );

        if ($status === 'up-to-date') {
            $last = ColumnQuery::get_last_analysis($post_id);
            if ($last !== null) {
                printf(
                    '<br><small class="seopulse-col-status__time">%s</small>',
                    esc_html(human_time_diff($last) . ' ' . __('ago', 'seopulse')),
                );
            }
        }
    }
}
