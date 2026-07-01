<?php
/**
 * General tab - Core SEO fields
 *
 * Priority order: Focus Keyword → Title → Description → Robots → Canonical → Keywords
 *
 * @var array $meta
 * @var array $defaults
 * @var string $focus_keyword
  * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- Focus Keyword (highest priority) -->
<div class="seopulse-meta-input-group seopulse-meta-input-group--focus">
	<label for="seopulse_focus_keyword">
		<span class="dashicons dashicons-star-filled seopulse-focus-icon"></span>
		<?php esc_html_e('Focus Keyword', 'seopulse'); ?>
	</label>
	<input type="text" name="seopulse_focus_keyword" id="seopulse_focus_keyword"
		value="<?php echo esc_attr($focus_keyword); ?>"
		class="widefat"
		placeholder="<?php esc_attr_e('e.g. best running shoes', 'seopulse'); ?>">
	<p class="description">
		<?php esc_html_e('The main keyword you want this page to rank for. Used by the content analysis engine.', 'seopulse'); ?>
	</p>
</div>

<div class="seopulse-meta-input-group">
	<label
		for="seopulse_meta_title"><?php esc_html_e('Meta Title', 'seopulse'); ?></label>
	<input type="text" name="seopulse_meta_seo[title]" id="seopulse_meta_title"
		value="<?php echo esc_attr($meta['title'] ?? ''); ?>"
		class="widefat" maxlength="60" data-seopulse-vars
		placeholder="<?php echo esc_attr($defaults['title']); ?>">
	<p class="description">
		<?php esc_html_e('Recommended: 50-60 characters.', 'seopulse'); ?>
		<span class="seopulse-char-count" data-field="seopulse_meta_title">0/60</span>
	</p>
</div>

<div class="seopulse-meta-input-group">
	<label
		for="seopulse_meta_description"><?php esc_html_e('Meta Description', 'seopulse'); ?></label>
	<textarea name="seopulse_meta_seo[description]" id="seopulse_meta_description" class="widefat" rows="3"
		maxlength="160" data-seopulse-vars
		placeholder="<?php echo esc_attr($defaults['description']); ?>"><?php echo esc_textarea($meta['description'] ?? ''); ?></textarea>
	<p class="description">
		<?php esc_html_e('Recommended: 150-160 characters.', 'seopulse'); ?>
		<span class="seopulse-char-count" data-field="seopulse_meta_description">0/160</span>
	</p>
</div>

<div class="seopulse-meta-input-group">
	<label
		for="seopulse_meta_robots"><?php esc_html_e('Robots Directive', 'seopulse'); ?></label>
	<input type="text" name="seopulse_meta_seo[robots]" id="seopulse_meta_robots"
		value="<?php echo esc_attr($meta['robots'] ?? ''); ?>"
		class="widefat" placeholder="<?php esc_attr_e('index,follow', 'seopulse'); ?>">
	<div class="seopulse-robots-presets" style="margin-top:6px;">
		<?php
        $seopulse_robots_presets = [
            'index,follow'     => __('Index & Follow (default)', 'seopulse'),
            'noindex,follow'   => __('No Index, Follow', 'seopulse'),
            'index,nofollow'   => __('Index, No Follow', 'seopulse'),
            'noindex,nofollow' => __('No Index, No Follow', 'seopulse'),
        ];
foreach ($seopulse_robots_presets as $seopulse_value => $seopulse_label) :
    ?>
		<button type="button"
			class="seopulse-core__btn-small seopulse-core__btn-small--tpl-insert seopulse-robots-preset-btn"
			data-value="<?php echo esc_attr($seopulse_value); ?>"
			title="<?php echo esc_attr($seopulse_label); ?>">
			<?php echo esc_html($seopulse_value); ?>
		</button>
		<?php endforeach; ?>
	</div>
	<p class="description">
		<?php esc_html_e('Controls how search engines index this page. Default is index,follow.', 'seopulse'); ?>
	</p>
</div>

<div class="seopulse-meta-input-group">
	<label
		for="seopulse_meta_canonical"><?php esc_html_e('Canonical URL', 'seopulse'); ?></label>
	<input type="url" name="seopulse_meta_seo[canonical]" id="seopulse_meta_canonical"
		value="<?php echo esc_attr($meta['canonical'] ?? ''); ?>"
		class="widefat"
		placeholder="<?php echo esc_attr($defaults['canonical']); ?>">
	<p class="description">
		<?php esc_html_e('Leave empty to use the default permalink. Set this to avoid duplicate content issues.', 'seopulse'); ?>
	</p>
</div>

<div class="seopulse-meta-input-group">
	<label
		for="seopulse_meta_keywords"><?php esc_html_e('Meta Keywords', 'seopulse'); ?>
		<span
			class="seopulse-field-badge"><?php esc_html_e('optional', 'seopulse'); ?></span>
	</label>
	<input type="text" name="seopulse_meta_seo[keywords]" id="seopulse_meta_keywords"
		value="<?php echo esc_attr($meta['keywords'] ?? ''); ?>"
		class="widefat"
		placeholder="<?php echo esc_attr($defaults['keywords']); ?>">
	<p class="description">
		<?php esc_html_e('Comma-separated keywords. Most search engines ignore this tag, but it can help internal organization.', 'seopulse'); ?>
	</p>
</div>