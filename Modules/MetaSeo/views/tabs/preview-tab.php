<?php
/**
 * Preview tab - Google, Facebook, Twitter previews
 *
 * @var array $meta
 * @var array $defaults
 * @var WP_Post $post
  * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Build pretty permalink even for drafts
if (!function_exists('get_sample_permalink')) {
    require_once ABSPATH . 'wp-admin/includes/post.php';
}
$seopulse_sample       = get_sample_permalink($post->ID);
$seopulse_permalink    = str_replace(['%postname%', '%pagename%'], $seopulse_sample[1], $seopulse_sample[0]);
$seopulse_parsed       = wp_parse_url($seopulse_permalink);
$seopulse_display_url  = ($seopulse_parsed['host'] ?? '') . ($seopulse_parsed['path'] ?? '');
$seopulse_display_host = $seopulse_parsed['host'] ?? '';
?>

<!-- Google Preview -->
<div class="seopulse-google-preview">
	<h4><?php esc_html_e('Google Search Preview', 'seopulse'); ?>
	</h4>
	<p class="seopulse-preview-hint">
		<?php esc_html_e('Based on your Meta Title and Meta Description from the SEO tab.', 'seopulse'); ?>
	</p>
	<div class="seopulse-google-preview-box">
		<div class="seopulse-google-favicon">
			<?php
            $seopulse_site_icon_url = get_site_icon_url(32);
if ($seopulse_site_icon_url) :
    ?>
			<img src="<?php echo esc_url($seopulse_site_icon_url); ?>"
				alt="">
			<?php else : ?>
			<span class="dashicons dashicons-wordpress-alt"></span>
			<?php endif; ?>
		</div>
		<div class="seopulse-google-site-name">
			<?php echo esc_html(get_bloginfo('name')); ?>
		</div>
		<div class="seopulse-google-breadcrumb">
			<?php echo esc_html($seopulse_display_url); ?>
		</div>
		<div class="seopulse-google-title" id="google-preview-title">
			<?php echo esc_html($defaults['title']); ?>
		</div>
		<div class="seopulse-google-description" id="google-preview-description">
			<?php echo esc_html($defaults['description']); ?>
		</div>
	</div>
</div>

<!-- Facebook Preview -->
<div class="seopulse-facebook-preview">
	<h4><?php esc_html_e('Facebook Preview', 'seopulse'); ?>
	</h4>
	<p class="seopulse-preview-hint">
		<?php esc_html_e('Based on OG Title, OG Description, and OG Image from the Social tab.', 'seopulse'); ?>
	</p>
	<div class="seopulse-facebook-card">
		<?php if (!empty($defaults['og_image'])) : ?>
		<div class="seopulse-facebook-image">
			<img id="facebook-preview-image"
				src="<?php echo esc_url($defaults['og_image']); ?>"
				alt="">
		</div>
		<?php else : ?>
		<div class="seopulse-facebook-image" style="display: none;">
			<img id="facebook-preview-image" src="" alt="">
		</div>
		<?php endif; ?>
		<div class="seopulse-facebook-content">
			<div class="seopulse-facebook-domain">
				<?php echo esc_html($seopulse_display_host); ?>
			</div>
			<div class="seopulse-facebook-title" id="facebook-preview-title">
				<?php echo esc_html($defaults['og_title']); ?>
			</div>
			<div class="seopulse-facebook-description" id="facebook-preview-description">
				<?php echo esc_html($defaults['og_description']); ?>
			</div>
		</div>
	</div>
</div>

<!-- Twitter Preview -->
<div class="seopulse-twitter-preview">
	<h4><?php esc_html_e('X (Twitter) Preview', 'seopulse'); ?>
	</h4>
	<p class="seopulse-preview-hint">
		<?php esc_html_e('Based on X-Card fields from the Social tab. Falls back to OG data if empty.', 'seopulse'); ?>
	</p>
	<div class="seopulse-twitter-card">
		<?php if (!empty($defaults['twitter_image'])) : ?>
		<div class="seopulse-twitter-image">
			<img id="twitter-preview-image"
				src="<?php echo esc_url($defaults['twitter_image']); ?>"
				alt="">
		</div>
		<?php else : ?>
		<div class="seopulse-twitter-image" style="display: none;">
			<img id="twitter-preview-image" src="" alt="">
		</div>
		<?php endif; ?>
		<div class="seopulse-twitter-content">
			<div class="seopulse-twitter-title" id="twitter-preview-title">
				<?php echo esc_html($defaults['twitter_title']); ?>
			</div>
			<div class="seopulse-twitter-description" id="twitter-preview-description">
				<?php echo esc_html($defaults['twitter_description']); ?>
			</div>
			<div class="seopulse-twitter-domain">
				<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
					<path
						d="M11.96 14.945c-.067 0-.136-.01-.203-.027-1.13-.318-2.097-.986-2.795-1.932-.832-1.125-1.176-2.508-.968-3.893s.942-2.605 2.068-3.438l3.53-2.608c2.322-1.716 5.61-1.224 7.33 1.1.83 1.127 1.175 2.51.967 3.895s-.943 2.605-2.07 3.438l-1.48 1.094c-.333.246-.804.175-1.05-.158-.246-.334-.176-.804.158-1.05l1.48-1.095c.803-.592 1.327-1.463 1.476-2.45.148-.988-.098-1.975-.69-2.778-1.225-1.656-3.572-2.01-5.23-.784l-3.53 2.608c-.802.593-1.326 1.464-1.475 2.45-.15.99.097 1.975.69 2.778.498.675 1.187 1.15 1.992 1.377.4.114.633.528.52.928-.092.33-.39.547-.722.547z">
					</path>
					<path
						d="M7.27 22.054c-1.61 0-3.197-.735-4.225-2.125-.832-1.127-1.176-2.51-.968-3.894s.943-2.605 2.07-3.438l1.478-1.094c.333-.246.804-.175 1.05.158s.177.804-.157 1.05l-1.48 1.095c-.803.593-1.326 1.464-1.475 2.45-.148.99.097 1.975.69 2.778 1.225 1.657 3.57 2.01 5.23.785l3.528-2.608c1.658-1.225 2.01-3.57.785-5.23-.498-.674-1.187-1.15-1.992-1.376-.4-.113-.633-.527-.52-.927.112-.4.528-.63.926-.522 1.13.318 2.096.986 2.794 1.932 1.717 2.324 1.224 5.612-1.1 7.33l-3.53 2.608c-.933.693-2.023 1.026-3.105 1.026z">
					</path>
				</svg>
				<?php echo esc_html($seopulse_display_host); ?>
			</div>
		</div>
	</div>
</div>