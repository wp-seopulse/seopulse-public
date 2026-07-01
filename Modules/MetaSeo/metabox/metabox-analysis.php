<?php
/**
 * Analysis meta box view
 *
 * Prioritized editorial view: score + top actions above the fold,
 * then detailed scores and full issues list.
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var array|null $analysis Analysis data
 * @var int $score Global score
 * @var int $last_analysis Timestamp of last analysis
 * @var int $post_id Post ID
 * @var WP_Post $post Current post
 */

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Admin\MetaBox\MetaBoxHelper;

?>
<div class="seopulse-metabox-wrapper" id="seopulse-metabox">
	<?php
    if ($analysis !== null) :
        $seopulse_level          = $analysis['level'] ?? MetaBoxHelper::get_score_level($score);
        $seopulse_blockers       = $analysis['recommendations']['blockers'] ?? [];
        $seopulse_quick_wins     = $analysis['recommendations']['quick_wins'] ?? [];
        $seopulse_top_actions    = array_slice(array_merge($seopulse_blockers, $seopulse_quick_wins), 0, 3);
        $seopulse_total_critical = count($analysis['issues']['critical'] ?? []);
        $seopulse_total_high     = count($analysis['issues']['high'] ?? []);
        ?>

	<!-- Score header -->
	<div class="seopulse-analysis-header">
		<div
			class="seopulse-score-circle seopulse-score-circle--<?php echo esc_attr($seopulse_level); ?>">
			<span
				class="seopulse-score-value"><?php echo esc_html($score); ?></span>
		</div>
		<div class="seopulse-analysis-header__info">
			<p class="seopulse-score-summary">
				<?php echo esc_html($analysis['summary'] ?? ''); ?>
			</p>
			<?php if ($last_analysis > 0) : ?>
			<p class="seopulse-last-analysis">
				<?php
                    printf(
                        /* translators: %s: human-readable time difference */
                        esc_html__('Analyzed %s ago', 'seopulse'),
                        esc_html(MetaBoxHelper::get_time_ago($last_analysis)),
                    );
			    ?>
			</p>
			<?php endif; ?>
		</div>
	</div>

	<!-- Priority actions (top 3 — above the fold) -->
	<?php if (!empty($seopulse_top_actions)) : ?>
	<div class="seopulse-priority-actions">
		<h4 class="seopulse-priority-actions__title">
			<span class="dashicons dashicons-flag"></span>
			<?php esc_html_e('Next Steps', 'seopulse'); ?>
		</h4>
		<?php
            $seopulse_category_labels = [
			    'blocker'   => __('Blocker', 'seopulse'),
			    'quick_win' => __('Quick Win', 'seopulse'),
            ];
	    foreach ($seopulse_top_actions as $seopulse_action) :
	        $seopulse_cat = $seopulse_action['category'] ?? 'improvement';
	        ?>
		<div
			class="seopulse-priority-action seopulse-priority-action--<?php echo esc_attr($seopulse_cat); ?>">
			<span class="seopulse-priority-action__badge">
				<?php echo esc_html($seopulse_category_labels[ $seopulse_cat ] ?? ucfirst($seopulse_cat)); ?>
			</span>
			<span class="seopulse-priority-action__text">
				<?php echo esc_html($seopulse_action['message'] ?? ''); ?>
			</span>
			<?php if (!empty($seopulse_action['estimated_impact'])) : ?>
			<span class="seopulse-priority-action__impact">
				+<?php echo esc_html($seopulse_action['estimated_impact']); ?>
				pts
			</span>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<!-- Tabs: Scores | Issues | All Tips -->
	<div class="seopulse-tabs-nav">
		<button type="button" class="seopulse-tab-btn-analysis active" data-tab="scores">
			<span class="dashicons dashicons-chart-bar"></span>
			<?php esc_html_e('Scores', 'seopulse'); ?>
		</button>
		<button type="button" class="seopulse-tab-btn-analysis" data-tab="issues">
			<span class="dashicons dashicons-warning"></span>
			<?php esc_html_e('Issues', 'seopulse'); ?>
			<?php if (($seopulse_total_critical + $seopulse_total_high) > 0) : ?>
			<span
				class="seopulse-badge seopulse-badge--error"><?php echo esc_html($seopulse_total_critical + $seopulse_total_high); ?></span>
			<?php endif; ?>
		</button>
		<button type="button" class="seopulse-tab-btn-analysis" data-tab="recommendations">
			<span class="dashicons dashicons-lightbulb"></span>
			<?php esc_html_e('All Tips', 'seopulse'); ?>
			<?php if (!empty($analysis['recommendations']['total'])) : ?>
			<span
				class="seopulse-badge"><?php echo esc_html($analysis['recommendations']['total']); ?></span>
			<?php endif; ?>
		</button>
	</div>

	<div class="seopulse-tab-pane-analysis active" id="seopulse-tab-scores">
		<?php
	        $seopulse_scores = $analysis['scores'] ?? [];
        include SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/tabs/scores-tab.php';
        ?>
	</div>

	<div class="seopulse-tab-pane-analysis" id="seopulse-tab-issues">
		<?php
        $seopulse_issues = $analysis['issues'] ?? [];
        include SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/tabs/issues-tab.php';
        ?>
	</div>

	<div class="seopulse-tab-pane-analysis" id="seopulse-tab-recommendations">
		<?php
        $seopulse_recommendations = $analysis['recommendations'] ?? [];
        include SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/tabs/recommendations-tab.php';
        ?>
	</div>

	<div class="seopulse-metabox-actions">
		<button type="button" class="seopulse-core__btn seopulse-core__btn--primary seopulse-run-analysis"
			data-post-id="<?php echo esc_attr($post->ID); ?>">
			<span class="dashicons dashicons-update"></span>
			<?php esc_html_e('Refresh Analysis', 'seopulse'); ?>
		</button>
	</div>

	<?php else : ?>
	<div class="seopulse-metabox-empty">
		<div class="seopulse-empty-icon">
			<span class="dashicons dashicons-chart-line"></span>
		</div>
		<h4><?php esc_html_e('No SEO analysis yet', 'seopulse'); ?>
		</h4>
		<p><?php esc_html_e('Get insights to improve your content for search engines.', 'seopulse'); ?>
		</p>
		<button type="button" class="seopulse-core__btn seopulse-core__btn--primary seopulse-run-analysis"
			data-post-id="<?php echo esc_attr($post->ID); ?>">
			<span class="dashicons dashicons-search"></span>
			<?php esc_html_e('Run Analysis', 'seopulse'); ?>
		</button>
	</div>

	<?php endif; ?>

	<p class="seopulse-metabox-footer">
		<a
			href="<?php echo esc_url(admin_url('admin.php?page=seopulse')); ?>">
			<?php esc_html_e('View all analyses', 'seopulse'); ?>
		</a>
	</p>
</div>