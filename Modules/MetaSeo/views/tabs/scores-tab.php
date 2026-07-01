<?php
/**
 * Scores tab view
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var array $seopulse_scores Scores by category
 * @var int $post_id Post ID
 */

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Admin\MetaBox\MetaBoxHelper;

if (empty($seopulse_scores)) {
    echo '<p class="seopulse-no-data">' . esc_html__('No score data available.', 'seopulse') . '</p>';

    return;
}
?>
<div class="seopulse-score-breakdown">
	<?php foreach ($seopulse_scores as $seopulse_category => $seopulse_data) : ?>
	<?php
        $seopulse_category_score  = is_array($seopulse_data) && isset($seopulse_data['score']) ? (int) $seopulse_data['score'] : 0;
	    $seopulse_category_weight = is_array($seopulse_data) && isset($seopulse_data['weight']) ? (float) $seopulse_data['weight'] : 0;
	    $seopulse_category_data   = is_array($seopulse_data) && isset($seopulse_data['data']) ? $seopulse_data['data'] : [];
	    ?>
	<div class="seopulse-score-item">
		<div class="seopulse-score-header">
			<span class="seopulse-score-name">
				<?php echo esc_html(MetaBoxHelper::get_category_label($seopulse_category)); ?>
			</span>
			<span class="seopulse-score-value-text">
				<?php echo esc_html($seopulse_category_score); ?>/100
			</span>
		</div>
		<div class="seopulse-progress-bar">
			<div class="seopulse-progress-fill seopulse-progress-fill--<?php echo esc_attr(MetaBoxHelper::get_score_level($seopulse_category_score)); ?>"
				style="width: <?php echo esc_attr($seopulse_category_score); ?>%">
			</div>
		</div>
		<div class="seopulse-score-details">
			<span class="seopulse-score-weight">
				<?php
	            /* translators: %d: category weight percentage */
	            printf(esc_html__('Weight: %d%%', 'seopulse'), (int) ($seopulse_category_weight * 100));
	    ?>
			</span>
			<?php if (!empty($seopulse_category_data)) : ?>
			<button type="button" class="seopulse-toggle-details-analysis"
				data-category="<?php echo esc_attr($seopulse_category); ?>">
				<span class="dashicons dashicons-arrow-down-alt2"></span>
			</button>
			<?php endif; ?>
		</div>

		<?php if (!empty($seopulse_category_data)) : ?>
		<div class="seopulse-category-data"
			data-category="<?php echo esc_attr($seopulse_category); ?>"
			style="display: none;">
			<?php
	        // Include category details
	        include SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/partials/category-details.php';
		    ?>
		</div>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>
</div>