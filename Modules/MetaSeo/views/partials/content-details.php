<?php
/**
 * Content details view
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var array $seopulse_category_data Content data
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<?php if (isset($seopulse_category_data['title'])) : ?>
<div class="seopulse-data-row">
	<span
		class="seopulse-data-label"><?php esc_html_e('Title', 'seopulse'); ?></span>
	<span class="seopulse-data-value">
		<?php
        printf(
            /* translators: 1: character count, 2: word count */
            esc_html__('%1$d chars, %2$d words', 'seopulse'),
            intval($seopulse_category_data['title']['length'] ?? 0),
            intval($seopulse_category_data['title']['word_count'] ?? 0),
        );
    ?>
	</span>
</div>
<?php endif; ?>

<?php if (isset($seopulse_category_data['headings'])) : ?>
<div class="seopulse-data-row">
	<span
		class="seopulse-data-label"><?php esc_html_e('Headings', 'seopulse'); ?></span>
	<span class="seopulse-data-value">
		H1:<?php echo esc_html($seopulse_category_data['headings']['h1_count'] ?? 0); ?>
		H2:<?php echo esc_html($seopulse_category_data['headings']['h2_count'] ?? 0); ?>
		H3:<?php echo esc_html($seopulse_category_data['headings']['h3_count'] ?? 0); ?>
	</span>
</div>
<?php endif; ?>

<?php if (isset($seopulse_category_data['length'])) : ?>
<div class="seopulse-data-row">
	<span
		class="seopulse-data-label"><?php esc_html_e('Content', 'seopulse'); ?></span>
	<span class="seopulse-data-value">
		<?php
    printf(
        /* translators: %d: word count */
        esc_html__('%d words', 'seopulse'),
        intval($seopulse_category_data['length']['word_count'] ?? 0),
    );
    ?>
	</span>
</div>
<?php endif; ?>