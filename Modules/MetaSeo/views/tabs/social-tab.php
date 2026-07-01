<?php
/**
 * Social tab - Merged Open Graph (Facebook) + Twitter Cards
 *
 * Consolidates social sharing configuration into a single tab.
 *
 * @var array $meta
 * @var array $defaults
  * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<p class="seopulse-tab-intro">
	<?php esc_html_e('Configure how this page appears when shared on social media. Title and description default to your Meta Title and Meta Description if left empty.', 'seopulse'); ?>
</p>

<!-- ── Facebook / Open Graph ── -->
<div class="seopulse-social-section">
	<h4 class="seopulse-social-section__heading">
		<span class="dashicons dashicons-facebook-alt"></span>
		<?php esc_html_e('Facebook / Open Graph', 'seopulse'); ?>
	</h4>

	<div class="seopulse-meta-input-group">
		<label
			for="seopulse_meta_og_title"><?php esc_html_e('OG Title', 'seopulse'); ?></label>
		<input type="text" name="seopulse_meta_seo[og_title]" id="seopulse_meta_og_title"
			value="<?php echo esc_attr($meta['og_title'] ?? ''); ?>"
			class="widefat" data-seopulse-vars
			placeholder="<?php echo esc_attr($defaults['og_title']); ?>">
		<p class="description">
			<?php esc_html_e('Leave empty to use meta title.', 'seopulse'); ?>
		</p>
	</div>

	<div class="seopulse-meta-input-group">
		<label
			for="seopulse_meta_og_description"><?php esc_html_e('OG Description', 'seopulse'); ?></label>
		<textarea name="seopulse_meta_seo[og_description]" id="seopulse_meta_og_description" class="widefat" rows="3"
			data-seopulse-vars
			placeholder="<?php echo esc_attr($defaults['og_description']); ?>"><?php echo esc_textarea($meta['og_description'] ?? ''); ?></textarea>
		<p class="description">
			<?php esc_html_e('Leave empty to use meta description.', 'seopulse'); ?>
		</p>
	</div>

	<div class="seopulse-meta-input-group">
		<label
			for="seopulse_meta_og_image"><?php esc_html_e('OG Image', 'seopulse'); ?></label>
		<input type="url" name="seopulse_meta_seo[og_image]" id="seopulse_meta_og_image"
			value="<?php echo esc_attr($meta['og_image'] ?? ''); ?>"
			class="widefat" readonly>
		<div class="seopulse-image-upload">
			<button type="button" id="upload-og-image" class="button">
				<?php esc_html_e('Choose Image', 'seopulse'); ?>
			</button>
			<button type="button" id="remove-upload-og-image" class="button">
				<?php esc_html_e('Remove', 'seopulse'); ?>
			</button>
		</div>
		<p class="description">
			<?php esc_html_e('Recommended: 1200x630px', 'seopulse'); ?>
		</p>
		<?php if (!empty($meta['og_image'])) : ?>
		<div class="seopulse-image-preview">
			<img id="og-image-preview"
				src="<?php echo esc_url($meta['og_image']); ?>"
				alt="">
		</div>
		<?php else : ?>
		<div class="seopulse-image-preview" style="display: none;">
			<img id="og-image-preview" src="" alt="">
		</div>
		<?php endif; ?>
	</div>

	<div class="seopulse-meta-input-row">
		<div class="seopulse-meta-input-group">
			<label
				for="seopulse_meta_og_type"><?php esc_html_e('OG Type', 'seopulse'); ?></label>
			<select name="seopulse_meta_seo[og_type]" id="seopulse_meta_og_type" class="widefat">
				<?php
                $seopulse_og_types     = SEOPulse\Modules\MetaSeo\MetaSeoDefaults::get_og_type_options();
$seopulse_current_type = $meta['og_type'] ?? 'article';
foreach ($seopulse_og_types as $seopulse_value => $seopulse_label) {
    printf(
        '<option value="%s" %s>%s</option>',
        esc_attr($seopulse_value),
        selected($seopulse_current_type, $seopulse_value, false),
        esc_html($seopulse_label),
    );
}
?>
			</select>
		</div>
		<div class="seopulse-meta-input-group">
			<label
				for="seopulse_meta_og_site_name"><?php esc_html_e('Site Name', 'seopulse'); ?></label>
			<input type="text" name="seopulse_meta_seo[og_site_name]" id="seopulse_meta_og_site_name"
				value="<?php echo esc_attr($meta['og_site_name'] ?? ''); ?>"
				class="widefat"
				placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
		</div>
	</div>

	<div class="seopulse-meta-input-group">
		<label
			for="seopulse_meta_og_url"><?php esc_html_e('OG URL', 'seopulse'); ?>
			<span
				class="seopulse-field-badge"><?php esc_html_e('optional', 'seopulse'); ?></span>
		</label>
		<input type="url" name="seopulse_meta_seo[og_url]" id="seopulse_meta_og_url"
			value="<?php echo esc_attr($meta['og_url'] ?? ''); ?>"
			class="widefat"
			placeholder="<?php echo esc_attr($defaults['og_url']); ?>">
		<p class="description">
			<?php esc_html_e('Leave empty to use canonical URL.', 'seopulse'); ?>
		</p>
	</div>
</div>

<!-- ── Twitter / X Card ── -->
<div class="seopulse-social-section">
	<h4 class="seopulse-social-section__heading">
		<span class="dashicons dashicons-twitter"></span>
		<?php esc_html_e('X (Twitter) Card', 'seopulse'); ?>
	</h4>

	<div class="seopulse-meta-input-group">
		<label
			for="seopulse_meta_twitter_card"><?php esc_html_e('Card Type', 'seopulse'); ?></label>
		<select name="seopulse_meta_seo[twitter_card]" id="seopulse_meta_twitter_card" class="widefat">
			<?php
            $seopulse_card_types   = SEOPulse\Modules\MetaSeo\MetaSeoDefaults::get_twitter_card_options();
$seopulse_current_card = $meta['twitter_card'] ?? 'summary_large_image';
foreach ($seopulse_card_types as $seopulse_value => $seopulse_label) {
    printf(
        '<option value="%s" %s>%s</option>',
        esc_attr($seopulse_value),
        selected($seopulse_current_card, $seopulse_value, false),
        esc_html($seopulse_label),
    );
}
?>
		</select>
	</div>

	<div class="seopulse-meta-input-group">
		<label
			for="seopulse_meta_twitter_title"><?php esc_html_e('Title', 'seopulse'); ?></label>
		<input type="text" name="seopulse_meta_seo[twitter_title]" id="seopulse_meta_twitter_title"
			value="<?php echo esc_attr($meta['twitter_title'] ?? ''); ?>"
			class="widefat" data-seopulse-vars
			placeholder="<?php echo esc_attr($defaults['twitter_title']); ?>">
		<p class="description">
			<?php esc_html_e('Leave empty to use meta title.', 'seopulse'); ?>
		</p>
	</div>

	<div class="seopulse-meta-input-group">
		<label
			for="seopulse_meta_twitter_description"><?php esc_html_e('Description', 'seopulse'); ?></label>
		<textarea name="seopulse_meta_seo[twitter_description]" id="seopulse_meta_twitter_description" class="widefat"
			rows="3" data-seopulse-vars
			placeholder="<?php echo esc_attr($defaults['twitter_description']); ?>"><?php echo esc_textarea($meta['twitter_description'] ?? ''); ?></textarea>
		<p class="description">
			<?php esc_html_e('Leave empty to use meta description.', 'seopulse'); ?>
		</p>
	</div>

	<div class="seopulse-meta-input-group">
		<label
			for="seopulse_meta_twitter_image"><?php esc_html_e('Image', 'seopulse'); ?></label>
		<input type="url" name="seopulse_meta_seo[twitter_image]" id="seopulse_meta_twitter_image"
			value="<?php echo esc_attr($meta['twitter_image'] ?? ''); ?>"
			class="widefat" readonly>
		<div class="seopulse-image-upload">
			<button type="button" id="upload-twitter-image" class="button">
				<?php esc_html_e('Choose Image', 'seopulse'); ?>
			</button>
			<button type="button" id="remove-upload-twitter-image" class="button">
				<?php esc_html_e('Remove', 'seopulse'); ?>
			</button>
		</div>
		<p class="description">
			<?php esc_html_e('Recommended: 1200x675px. Falls back to OG Image if empty.', 'seopulse'); ?>
		</p>
		<?php if (!empty($meta['twitter_image'])) : ?>
		<div class="seopulse-image-preview">
			<img id="twitter-image-preview"
				src="<?php echo esc_url($meta['twitter_image']); ?>"
				alt="">
		</div>
		<?php else : ?>
		<div class="seopulse-image-preview" style="display: none;">
			<img id="twitter-image-preview" src="" alt="">
		</div>
		<?php endif; ?>
	</div>

	<div class="seopulse-meta-input-row">
		<div class="seopulse-meta-input-group">
			<label
				for="seopulse_meta_twitter_site"><?php esc_html_e('Site @handle', 'seopulse'); ?></label>
			<input type="text" name="seopulse_meta_seo[twitter_site]" id="seopulse_meta_twitter_site"
				value="<?php echo esc_attr($meta['twitter_site'] ?? ''); ?>"
				class="widefat" placeholder="@yoursite">
		</div>
		<div class="seopulse-meta-input-group">
			<label
				for="seopulse_meta_twitter_creator"><?php esc_html_e('Creator @handle', 'seopulse'); ?></label>
			<input type="text" name="seopulse_meta_seo[twitter_creator]" id="seopulse_meta_twitter_creator"
				value="<?php echo esc_attr($meta['twitter_creator'] ?? ''); ?>"
				class="widefat" placeholder="@author">
		</div>
	</div>
</div>