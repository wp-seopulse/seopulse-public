<?php
/**
 * Generic details view
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var array $seopulse_category_data Generic data
 */

if (!defined('ABSPATH')) {
    exit;
}

foreach ($seopulse_category_data as $seopulse_key => $seopulse_value) {
    if (is_scalar($seopulse_value)) {
        ?>
<div class="seopulse-data-row">
	<span
		class="seopulse-data-label"><?php echo esc_html(ucfirst(str_replace('_', ' ', $seopulse_key))); ?></span>
	<span
		class="seopulse-data-value"><?php echo esc_html($seopulse_value); ?></span>
</div>
<?php
    }
}
?>