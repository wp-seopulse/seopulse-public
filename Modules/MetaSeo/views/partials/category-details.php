<?php
/**
 * Category details view
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var string $seopulse_category Category name
 * @var array $seopulse_category_data Category data
 * @var int $post_id Post ID
 */

if (!defined('ABSPATH')) {
    exit;
}

// Retrieve detailed checks from the already loaded analysis ($analysis variable from parent scope)
// Do NOT re-fetch cache here to avoid overwriting the $analysis variable from parent scope
$seopulse_checks = [];

if (isset($analysis['detailed_checks'][ $seopulse_category ])) {
    $seopulse_checks = $analysis['detailed_checks'][ $seopulse_category ];
}
?>
<div class="seopulse-data-grid">
	<?php
    // Display detailed checks if available
    if (!empty($seopulse_checks)) {
        include SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/partials/checks-list.php';
    }

// Then display data according to the category type
switch ($seopulse_category) {
    case 'content':
    case 'content_analyzer':
        include SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/partials/content-details.php';
        break;
    case 'meta':
    case 'meta_analyzer':
        include SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/partials/meta-details.php';
        break;
    case 'readability':
    case 'readability_analyzer':
        include SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/partials/readability-details.php';
        break;
    default:
        include SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/partials/generic-details.php';
        break;
}
?>
</div>