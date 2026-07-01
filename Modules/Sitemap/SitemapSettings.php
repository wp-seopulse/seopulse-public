<?php

/**
 * Sitemap Settings Management
 *
 * Handles meta boxes and sitemap settings registration
 *
 * @package SEOPulse\Modules\Sitemap
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Sitemap;

use WP_Post;

/**
 * SitemapSettings class
 */
class SitemapSettings
{
    /**
     * Registers settings
     *
     * @return void
     */
    public function register_settings(): void
    {
        register_setting(
            'seopulse_sitemap_settings',
            'seopulse_sitemap_settings',
            [
                'sanitize_callback' => [$this, 'sanitize_settings'],
            ],
        );
    }

    /**
     * Registers meta boxes
     *
     * @return void
     */
    public function register_meta_boxes(): void
    {
        $post_types = get_post_types(['public' => true], 'names');

        // Filter to allow customization
        $post_types = apply_filters('seopulse_sitemap_metabox_post_types', $post_types);

        foreach ($post_types as $post_type) {
            // Skip attachments
            if ($post_type === 'attachment') {
                continue;
            }

            add_meta_box(
                'seopulse_sitemap_settings',
                __('SEOPulse Sitemap', 'seopulse'),
                [$this, 'render_meta_box'],
                $post_type,
                'side',
                'high',
            );
        }
    }

    /**
     * Renders the meta box content
     *
     * @param WP_Post $post Current post
     * @return void
     */
    public function render_meta_box(WP_Post $post): void
    {
        wp_nonce_field('seopulse_sitemap_metabox', 'seopulse_sitemap_metabox_nonce');

        // Get meta values with defaults
        $exclude    = get_post_meta($post->ID, '_seopulse_exclude_sitemap', true);
        $priority   = get_post_meta($post->ID, '_seopulse_sitemap_priority', true);
        $changefreq = get_post_meta($post->ID, '_seopulse_sitemap_changefreq', true);
        ?>
<div class="seopulse-sitemap-metabox">
	<div class="seopulse-field">
		<label>
			<input type="checkbox" name="seopulse_exclude_sitemap" value="1"
				<?php checked($exclude, '1'); ?>
			/>
			<?php esc_html_e('Exclude from sitemap', 'seopulse'); ?>
		</label>
		<p class="description">
			<?php esc_html_e('Check this box to exclude this content from the sitemap.', 'seopulse'); ?>
		</p>
	</div>

	<div class="seopulse-field">
		<label for="seopulse_sitemap_priority">
			<?php esc_html_e('Priority', 'seopulse'); ?>
		</label>
		<select name="seopulse_sitemap_priority" id="seopulse_sitemap_priority">
			<option value="">
				<?php esc_html_e('Default', 'seopulse'); ?>
			</option>
			<?php
                    $priorities = ['0.1', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1.0'];
        foreach ($priorities as $p) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($p),
                selected($priority, $p, false),
                esc_html($p),
            );
        }
        ?>
		</select>
		<p class="description">
			<?php esc_html_e('The priority of this URL relative to other URLs on your site (0.1 to 1.0).', 'seopulse'); ?>
		</p>
	</div>

	<div class="seopulse-field">
		<label for="seopulse_sitemap_changefreq">
			<?php esc_html_e('Change Frequency', 'seopulse'); ?>
		</label>
		<select name="seopulse_sitemap_changefreq" id="seopulse_sitemap_changefreq">
			<option value="">
				<?php esc_html_e('Default', 'seopulse'); ?>
			</option>
			<?php
        $frequencies = [
            'always'  => __('Always', 'seopulse'),
            'hourly'  => __('Hourly', 'seopulse'),
            'daily'   => __('Daily', 'seopulse'),
            'weekly'  => __('Weekly', 'seopulse'),
            'monthly' => __('Monthly', 'seopulse'),
            'yearly'  => __('Yearly', 'seopulse'),
            'never'   => __('Never', 'seopulse'),
        ];
        foreach ($frequencies as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($changefreq, $value, false),
                esc_html($label),
            );
        }
        ?>
		</select>
		<p class="description">
			<?php esc_html_e('How frequently the page is likely to change.', 'seopulse'); ?>
		</p>
	</div>


</div>
<?php
        // Add Google News fields
        require_once __DIR__ . '/view/view-sitemap-news-metabox.php';
        if (function_exists('seopulse_sitemap_render_news_fields')) {
            seopulse_sitemap_render_news_fields($post);
        }
    }

    /**
     * Enqueues metabox styles on post edit screens
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_metabox_styles(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $css = '.seopulse-sitemap-metabox .seopulse-field { margin-bottom: 15px; }'
            . '.seopulse-sitemap-metabox label { display: block; font-weight: 600; margin-bottom: 5px; }'
            . '.seopulse-sitemap-metabox .description { color: #666; font-size: 13px; font-style: italic; margin-top: 5px; }';

        wp_register_style('seopulse-sitemap-metabox', false, [], SEOPULSE_VERSION);
        wp_enqueue_style('seopulse-sitemap-metabox');
        wp_add_inline_style('seopulse-sitemap-metabox', $css);
    }

    /**
     * Saves meta box data
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     * @return void
     */
    public function save_post_meta(int $post_id, WP_Post $post): void
    {
        // Nonce verification
        if (!isset($_POST['seopulse_sitemap_metabox_nonce'])) {
            return;
        }

        // Security: Properly sanitize nonce before verification
        $nonce = sanitize_text_field(wp_unslash($_POST['seopulse_sitemap_metabox_nonce']));
        if (!wp_verify_nonce($nonce, 'seopulse_sitemap_metabox')) {
            return;
        }

        // Autosave check
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Permission check
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Exclusion from sitemap
        if (isset($_POST['seopulse_exclude_sitemap'])) {
            update_post_meta($post_id, '_seopulse_exclude_sitemap', '1');
        } else {
            delete_post_meta($post_id, '_seopulse_exclude_sitemap');
        }

        // Custom priority
        if (isset($_POST['seopulse_sitemap_priority']) && !empty($_POST['seopulse_sitemap_priority'])) {
            $priority = sanitize_text_field(wp_unslash($_POST['seopulse_sitemap_priority']));

            // Validate priority value
            if ($this->validate_priority($priority)) {
                update_post_meta($post_id, '_seopulse_sitemap_priority', $priority);
            }
        } else {
            delete_post_meta($post_id, '_seopulse_sitemap_priority');
        }

        // Change frequency
        if (isset($_POST['seopulse_sitemap_changefreq']) && !empty($_POST['seopulse_sitemap_changefreq'])) {
            $changefreq = sanitize_text_field(wp_unslash($_POST['seopulse_sitemap_changefreq']));

            // Validate changefreq value
            if ($this->validate_changefreq($changefreq)) {
                update_post_meta($post_id, '_seopulse_sitemap_changefreq', $changefreq);
            }
        } else {
            delete_post_meta($post_id, '_seopulse_sitemap_changefreq');
        }

        // Clear sitemap cache
        do_action('seopulse_sitemap_clear_cache');
    }

    /**
     * Sanitize settings
     *
     * @param array $input Input data
     * @return array Sanitized data
     */
    public function sanitize_settings(array $input): array
    {
        $sanitized = [];

        // Boolean fields
        $bool_fields = [
            'enable_post',
            'enable_page',
            'enable_category',
            'enable_post_tag',
            'enable_images',
            'include_images',
            'disable_wp_core_sitemaps',
            'enable_news_sitemap',
            'create_physical_robots',
        ];

        foreach ($bool_fields as $field) {
            if (array_key_exists($field, $input)) {
                $sanitized[ $field ] = !empty($input[ $field ]) ? 1 : 0;
            }
        }

        // Text fields with validation
        $text_fields = [
            'priority_post'   => ['0.1', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1.0'],
            'priority_page'   => ['0.1', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1.0'],
            'changefreq_post' => ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'],
            'changefreq_page' => ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'],
        ];

        foreach ($text_fields as $field => $allowed_values) {
            if (array_key_exists($field, $input)) {
                $value               = sanitize_text_field($input[ $field ]);
                $sanitized[ $field ] = in_array($value, $allowed_values, true) ? $value : $allowed_values[0];
            }
        }

        // Textarea fields
        if (isset($input['excluded_urls'])) {
            $sanitized['excluded_urls'] = sanitize_textarea_field($input['excluded_urls']);
        }

        if (isset($input['custom_robots'])) {
            $sanitized['custom_robots'] = sanitize_textarea_field($input['custom_robots']);
        }

        if (isset($input['news_publication_name'])) {
            $sanitized['news_publication_name'] = sanitize_text_field($input['news_publication_name']);
        }

        if (isset($input['news_sitemap_days'])) {
            $days                           = absint($input['news_sitemap_days']);
            $allowed_days                   = [1, 2, 3, 7, 14, 30];
            $sanitized['news_sitemap_days'] = in_array($days, $allowed_days, true) ? $days : 2;
        }

        // Custom post types
        $post_types = get_post_types(
            [
                'public'   => true,
                '_builtin' => false,
            ],
        );
        foreach ($post_types as $pt) {
            $pt_key = sanitize_key($pt);

            $sanitized[ "enable_{$pt_key}" ] = !empty($input[ "enable_{$pt_key}" ]) ? 1 : 0;

            if (isset($input[ "priority_{$pt_key}" ])) {
                $priority                          = sanitize_text_field($input[ "priority_{$pt_key}" ]);
                $valid_priorities                  = ['0.1', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1.0'];
                $sanitized[ "priority_{$pt_key}" ] = in_array($priority, $valid_priorities, true) ? $priority : '0.5';
            }

            if (isset($input[ "changefreq_{$pt_key}" ])) {
                $changefreq                          = sanitize_text_field($input[ "changefreq_{$pt_key}" ]);
                $valid_freqs                         = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
                $sanitized[ "changefreq_{$pt_key}" ] = in_array($changefreq, $valid_freqs, true) ? $changefreq : 'weekly';
            }
        }

        return $sanitized;
    }

    /**
     * Validates a priority value
     *
     * @param string $priority Priority value
     * @return bool
     */
    private function validate_priority(string $priority): bool
    {
        $valid_priorities = ['0.1', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1.0'];

        return in_array($priority, $valid_priorities, true);
    }

    /**
     * Validates a change frequency value
     *
     * @param string $changefreq Frequency value
     * @return bool
     */
    private function validate_changefreq(string $changefreq): bool
    {
        $valid_freqs = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

        return in_array($changefreq, $valid_freqs, true);
    }

    /**
     * Retrieves post metadata
     *
     * @param int $post_id Post ID
     * @return array Metadata
     */
    public function get_post_meta(int $post_id): array
    {
        return [
            'exclude'    => get_post_meta($post_id, '_seopulse_exclude_sitemap', true),
            'priority'   => get_post_meta($post_id, '_seopulse_sitemap_priority', true),
            'changefreq' => get_post_meta($post_id, '_seopulse_sitemap_changefreq', true),
        ];
    }
}
?>