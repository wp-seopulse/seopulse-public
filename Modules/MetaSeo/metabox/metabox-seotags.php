<?php
/**
 * Main SEO meta box view
 *
 * @var WP_Post $post
 * @var array $meta
 * @var array $defaults
 * @var string $focus_keyword
  * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Build permalink for inline snippet
if (!function_exists('get_sample_permalink')) {
    require_once ABSPATH . 'wp-admin/includes/post.php';
}
$seopulse_sample    = get_sample_permalink($post->ID);
$seopulse_permalink = str_replace(['%postname%', '%pagename%'], $seopulse_sample[1], $seopulse_sample[0]);
$seopulse_parsed    = wp_parse_url($seopulse_permalink);
$seopulse_host      = $seopulse_parsed['host'] ?? '';
$seopulse_path      = $seopulse_parsed['path'] ?? '';
?>

<div class="seopulse-metabox-wrapper">
	<!-- Inline compact SERP snippet (always visible) -->
	<div class="seopulse-serp-snippet">
		<div class="seopulse-serp-snippet__label">
			<span class="dashicons dashicons-google"></span>
			<?php esc_html_e('Google Preview', 'seopulse'); ?>
		</div>
		<div class="seopulse-serp-snippet__box">
			<div class="seopulse-serp-snippet__url">
				<?php echo esc_html($seopulse_host . $seopulse_path); ?>
			</div>
			<div class="seopulse-serp-snippet__title" id="serp-snippet-title">
				<?php echo esc_html($defaults['title']); ?>
			</div>
			<div class="seopulse-serp-snippet__desc" id="serp-snippet-desc">
				<?php echo esc_html($defaults['description']); ?>
			</div>
		</div>
	</div>

	<!-- Tabs Navigation -->
	<div class="seopulse-tabs-nav">
		<button type="button" class="seopulse-tab-btn-tags active" data-tab="general">
			<span class="dashicons dashicons-search"></span>
			<?php esc_html_e('SEO', 'seopulse'); ?>
		</button>
		<button type="button" class="seopulse-tab-btn-tags" data-tab="social">
			<span class="dashicons dashicons-share"></span>
			<?php esc_html_e('Social', 'seopulse'); ?>
		</button>
		<button type="button" class="seopulse-tab-btn-tags" data-tab="preview">
			<span class="dashicons dashicons-visibility"></span>
			<?php esc_html_e('Preview', 'seopulse'); ?>
		</button>
	</div>

	<!-- Tab Contents -->
	<div class="seopulse-tabs-content">
		<!-- SEO Tab -->
		<div class="seopulse-tab-pane-tags active" id="seopulse-tab-general">
			<?php require SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/tabs/general-tab.php'; ?>
		</div>

		<!-- Social Tab (merged OG + Twitter) -->
		<div class="seopulse-tab-pane-tags" id="seopulse-tab-social">
			<?php require SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/tabs/social-tab.php'; ?>
		</div>

		<!-- Preview Tab -->
		<div class="seopulse-tab-pane-tags" id="seopulse-tab-preview">
			<?php require SEOPULSE_PLUGIN_DIR . 'Modules/MetaSeo/views/tabs/preview-tab.php'; ?>
		</div>
	</div>
</div>