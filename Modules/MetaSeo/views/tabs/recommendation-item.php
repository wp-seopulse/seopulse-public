<?php
/**
 * Single recommendation item partial
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var array $seopulse_rec              Single recommendation item
 * @var array $seopulse_category_labels  Category label map
 * @var array $seopulse_difficulty_labels Difficulty label map
 */

if (!defined('ABSPATH')) {
    exit;
}

$seopulse_cat = $seopulse_rec['category'] ?? 'improvement';
?>
<div
	class="seopulse-recommendation-item seopulse-recommendation-item--<?php echo esc_attr($seopulse_cat); ?>">
	<div class="seopulse-recommendation-header">
		<span class="seopulse-recommendation-priority">
			<?php echo esc_html($seopulse_category_labels[ $seopulse_cat ] ?? ucfirst($seopulse_cat)); ?>
		</span>
		<?php if (!empty($seopulse_rec['estimated_impact'])) : ?>
		<span class="seopulse-recommendation-impact">
			+<?php echo esc_html($seopulse_rec['estimated_impact']); ?>
			pts
		</span>
		<?php endif; ?>
	</div>
	<p class="seopulse-recommendation-message">
		<?php echo esc_html($seopulse_rec['message'] ?? ''); ?>
	</p>
	<?php if (!empty($seopulse_rec['action'])) : ?>
	<p class="seopulse-recommendation-action">
		<span class="dashicons dashicons-lightbulb"></span>
		<?php echo esc_html($seopulse_rec['action']); ?>
	</p>
	<?php endif; ?>
	<?php if (!empty($seopulse_rec['difficulty'])) : ?>
	<span
		class="seopulse-recommendation-difficulty seopulse-difficulty--<?php echo esc_attr($seopulse_rec['difficulty']); ?>">
		<?php echo esc_html($seopulse_difficulty_labels[ $seopulse_rec['difficulty'] ] ?? ucfirst($seopulse_rec['difficulty'])); ?>
	</span>
	<?php endif; ?>
</div>