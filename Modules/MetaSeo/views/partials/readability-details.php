<?php
/**
 * Readability details view
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var array $seopulse_category_data Readability data
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<?php if (isset($seopulse_category_data['flesch'])) : ?>
<div class="seopulse-data-row">
	<span
		class="seopulse-data-label"><?php esc_html_e('Flesch Score', 'seopulse'); ?></span>
	<span class="seopulse-data-value">
		<?php
        printf(
            '%d/100 - %s',
            intval($seopulse_category_data['flesch']['score'] ?? 0),
            esc_html($seopulse_category_data['flesch']['level'] ?? 'N/A'),
        );
    ?>
	</span>
</div>
<?php endif; ?>

<?php if (isset($seopulse_category_data['sentences'])) : ?>
<div class="seopulse-data-row">
	<span
		class="seopulse-data-label"><?php esc_html_e('Sentences', 'seopulse'); ?></span>
	<span class="seopulse-data-value">
		<?php
    printf(
        /* translators: 1: total number of sentences, 2: average words per sentence */
        esc_html__('%1$d total, avg %2$d words', 'seopulse'),
        intval($seopulse_category_data['sentences']['total_sentences'] ?? 0),
        intval($seopulse_category_data['sentences']['average_length'] ?? 0),
    );
    ?>
	</span>
</div>
<?php endif; ?>

<?php if (isset($seopulse_category_data['words'])) : ?>
<div class="seopulse-data-row">
	<span
		class="seopulse-data-label"><?php esc_html_e('Complexity', 'seopulse'); ?></span>
	<span class="seopulse-data-value">
		<?php
    printf(
        /* translators: 1: number of complex words, 2: complexity percentage */
        esc_html__('%1$d complex words (%2$d%%)', 'seopulse'),
        intval($seopulse_category_data['words']['complex_words_count'] ?? 0),
        intval($seopulse_category_data['words']['complexity_percentage'] ?? 0),
    );
    ?>
	</span>
</div>
<?php endif; ?>