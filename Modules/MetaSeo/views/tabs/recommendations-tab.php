<?php
/**
 * Recommendations tab view — top 3 visible, rest behind "Show more"
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var array $seopulse_recommendations Recommendations
 */

if (!defined('ABSPATH')) {
    exit;
}

$seopulse_blockers   = $seopulse_recommendations['blockers'] ?? [];
$seopulse_quick_wins = $seopulse_recommendations['quick_wins'] ?? [];
$seopulse_others     = $seopulse_recommendations['others'] ?? [];
$seopulse_actionable = array_merge($seopulse_blockers, $seopulse_quick_wins, $seopulse_others);

if (empty($seopulse_actionable)) {
    ?>
<div class="seopulse-no-recommendations">
	<span class="dashicons dashicons-thumbs-up"></span>
	<p><?php esc_html_e('Your content is well optimized!', 'seopulse'); ?>
	</p>
</div>
<?php
    return;
}

$seopulse_category_labels = [
    'blocker'     => __('Blocker', 'seopulse'),
    'quick_win'   => __('Quick Win', 'seopulse'),
    'improvement' => __('Improvement', 'seopulse'),
];

$seopulse_difficulty_labels = [
    'easy'   => __('Easy', 'seopulse'),
    'medium' => __('Medium', 'seopulse'),
    'hard'   => __('Hard', 'seopulse'),
];

$seopulse_visible   = array_slice($seopulse_actionable, 0, 3);
$seopulse_remaining = array_slice($seopulse_actionable, 3);
?>
<div class="seopulse-recommendations-list">
	<?php foreach ($seopulse_visible as $seopulse_rec) : ?>
	<?php include __DIR__ . '/recommendation-item.php'; ?>
	<?php endforeach; ?>

	<?php if (!empty($seopulse_remaining)) : ?>
	<div class="seopulse-recommendations-more" id="seopulse-more-recs" style="display: none;">
		<?php foreach ($seopulse_remaining as $seopulse_rec) : ?>
		<?php include __DIR__ . '/recommendation-item.php'; ?>
		<?php endforeach; ?>
	</div>
	<button type="button" class="seopulse-show-more-btn" id="seopulse-toggle-recs" data-show="
		<?php
        echo esc_attr(
            sprintf(
                /* translators: %d: number of remaining recommendations */
                __('Show %d more', 'seopulse'),
                count($seopulse_remaining),
            ),
        );
	    ?>
	" data-hide="<?php esc_attr_e('Show less', 'seopulse'); ?>">
		<?php
	        printf(
	            /* translators: %d: number of remaining recommendations */
	            esc_html__('Show %d more', 'seopulse'),
	            count($seopulse_remaining),
	        );
	    ?>
	</button>
	<?php endif; ?>
</div>