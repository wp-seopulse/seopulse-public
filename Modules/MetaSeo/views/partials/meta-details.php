<?php
/**
 * Meta details view
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var array $seopulse_category_data Meta data
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<?php if (isset($seopulse_category_data['meta_title'])) : ?>
<div class="seopulse-data-row">
	<span
		class="seopulse-data-label"><?php esc_html_e('Title', 'seopulse'); ?></span>
	<span class="seopulse-data-value">
		<?php
        printf(
            /* translators: 1: character count, 2: estimated pixel width */
            esc_html__('%1$d chars ≈ %2$dpx', 'seopulse'),
            intval($seopulse_category_data['meta_title']['length'] ?? 0),
            intval($seopulse_category_data['meta_title']['pixel_width'] ?? 0),
        );
    ?>
	</span>
</div>
<?php endif; ?>

<?php if (isset($seopulse_category_data['meta_description'])) : ?>
<div class="seopulse-data-row">
	<span
		class="seopulse-data-label"><?php esc_html_e('Description', 'seopulse'); ?></span>
	<span class="seopulse-data-value">
		<?php
    printf(
        /* translators: 1: character count, 2: word count */
        esc_html__('%1$d chars, %2$d words', 'seopulse'),
        intval($seopulse_category_data['meta_description']['length'] ?? 0),
        intval($seopulse_category_data['meta_description']['word_count'] ?? 0),
    );
    ?>
	</span>
</div>
<?php endif; ?>