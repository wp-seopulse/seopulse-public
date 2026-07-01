<?php

/**
 * Sitemap Bulk Actions
 *
 * Allows bulk include/exclude of content from the sitemap
 *
 * @package SEOPulse\Modules\Sitemap
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Sitemap;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- SitemapBulkActions: direct DB access is intentional; caching is handled at the service/caller layer.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- All interpolated vars are safe prefixed table names ($wpdb->postmeta, $wpdb->posts).

use SEOPulse\Admin\Notifications\AdminNotification;

/**
 * SitemapBulkActions class
 */
class SitemapBulkActions
{
    /**
     * Initializes hooks
     *
     * @return void
     */
    public function init(): void
    {
        // Add bulk actions for posts and pages
        add_filter('bulk_actions-edit-post', [$this, 'add_bulk_actions']);
        add_filter('bulk_actions-edit-page', [$this, 'add_bulk_actions']);

        // Handle bulk actions
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk_actions'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handle_bulk_actions'], 10, 3);

        // Add for custom post types
        add_action('admin_init', [$this, 'add_cpt_bulk_actions']);

        // Add column in the list
        add_filter('manage_posts_columns', [$this, 'add_sitemap_column']);
        add_filter('manage_pages_columns', [$this, 'add_sitemap_column']);
        add_action('manage_posts_custom_column', [$this, 'render_sitemap_column'], 10, 2);
        add_action('manage_pages_custom_column', [$this, 'render_sitemap_column'], 10, 2);

        // Add for CPTs
        add_action('admin_init', [$this, 'add_cpt_columns']);

        // Add quick filter
        add_action('restrict_manage_posts', [$this, 'add_sitemap_filter']);
        add_filter('parse_query', [$this, 'filter_by_sitemap_status']);

        // Add styles
        add_action('admin_enqueue_scripts', [$this, 'add_admin_styles']);
    }

    /**
     * Adds bulk actions
     *
     * @param array $actions Existing actions
     * @return array Modified actions
     */
    public function add_bulk_actions(array $actions): array
    {
        $actions['seopulse_exclude_sitemap'] = '🚫 ' . __('Exclude from sitemap', 'seopulse');
        $actions['seopulse_include_sitemap'] = '✓ ' . __('Include in sitemap', 'seopulse');

        return $actions;
    }

    /**
     * Adds bulk actions for custom post types
     *
     * @return void
     */
    public function add_cpt_bulk_actions(): void
    {
        $post_types = get_post_types(
            [
                'public'   => true,
                '_builtin' => false,
            ],
            'names',
        );

        foreach ($post_types as $post_type) {
            add_filter("bulk_actions-edit-{$post_type}", [$this, 'add_bulk_actions']);
            add_filter("handle_bulk_actions-edit-{$post_type}", [$this, 'handle_bulk_actions'], 10, 3);
        }
    }

    /**
     * Handles bulk actions execution
     *
     * @param string $redirect_to Redirect URL
     * @param string $action Action to perform
     * @param array $post_ids Selected post IDs
     * @return string Modified redirect URL
     */
    public function handle_bulk_actions(string $redirect_to, string $action, array $post_ids): string
    {
        // Check permissions
        if (!current_user_can('edit_posts')) {
            return $redirect_to;
        }

        // Action: Exclude from sitemap
        if ($action === 'seopulse_exclude_sitemap') {
            $count = 0;
            foreach ($post_ids as $post_id) {
                // Check permissions for each post
                if (current_user_can('edit_post', $post_id)) {
                    update_post_meta($post_id, '_seopulse_exclude_sitemap', '1');
                    ++$count;
                }
            }

            // Clear cache
            do_action('seopulse_sitemap_clear_cache');

            // Notification via snackbar
            AdminNotification::success(
                sprintf(
                    /* translators: %d: Number of posts excluded from sitemap */
                    _n(
                        '%d item excluded from sitemap successfully.',
                        '%d items excluded from sitemap successfully.',
                        $count,
                        'seopulse',
                    ),
                    number_format_i18n($count),
                ),
            );
        }

        // Action: Include in sitemap
        if ($action === 'seopulse_include_sitemap') {
            $count = 0;
            foreach ($post_ids as $post_id) {
                // Check permissions for each post
                if (current_user_can('edit_post', $post_id)) {
                    delete_post_meta($post_id, '_seopulse_exclude_sitemap');
                    ++$count;
                }
            }

            // Clear cache
            do_action('seopulse_sitemap_clear_cache');

            // Notification via snackbar
            AdminNotification::success(
                sprintf(
                    /* translators: %d: Number of posts included in sitemap */
                    _n(
                        '%d item included in sitemap successfully.',
                        '%d items included in sitemap successfully.',
                        $count,
                        'seopulse',
                    ),
                    number_format_i18n($count),
                ),
            );
        }

        return $redirect_to;
    }

    /**
     * Adds a column for sitemap status
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_sitemap_column(array $columns): array
    {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[ $key ] = $value;

            // Add after title
            if ($key === 'title') {
                $new_columns['seopulse_sitemap'] = __('Sitemap', 'seopulse');
            }
        }

        return $new_columns;
    }

    /**
     * Adds columns for custom post types
     *
     * @return void
     */
    public function add_cpt_columns(): void
    {
        $post_types = get_post_types(
            [
                'public'   => true,
                '_builtin' => false,
            ],
            'names',
        );

        foreach ($post_types as $post_type) {
            add_filter("manage_{$post_type}_posts_columns", [$this, 'add_sitemap_column']);
            add_action("manage_{$post_type}_posts_custom_column", [$this, 'render_sitemap_column'], 10, 2);
        }
    }

    /**
     * Renders the sitemap column content
     *
     * @param string $column_name Column name
     * @param int $post_id Post ID
     * @return void
     */
    public function render_sitemap_column(string $column_name, int $post_id): void
    {
        if ($column_name === 'seopulse_sitemap') {
            $excluded        = get_post_meta($post_id, '_seopulse_exclude_sitemap', true);
            $custom_priority = get_post_meta($post_id, '_seopulse_sitemap_priority', true);

            if ($excluded) {
                echo '<span class="seopulse-sitemap-status seopulse-excluded" title="' . esc_attr__('Excluded from sitemap', 'seopulse') . '">✗</span>';
            } else {
                $title = __('Included in sitemap', 'seopulse');
                if ($custom_priority) {
                    /* translators: %s: Priority value */
                    $title .= ' (' . sprintf(__('Priority: %s', 'seopulse'), $custom_priority) . ')';
                }
                echo '<span class="seopulse-sitemap-status seopulse-included" title="' . esc_attr($title) . '">✓</span>';
            }
        }
    }

    /**
     * Adds a quick filter
     *
     * @return void
     */
    public function add_sitemap_filter(): void
    {
        global $typenow;

        // Check that we are on a post list page
        $post_types = array_merge(
            ['post', 'page'],
            get_post_types(
                [
                    'public'   => true,
                    '_builtin' => false,
                ],
                'names',
            ),
        );

        // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
        if (in_array($typenow, $post_types)) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $current = isset($_GET['seopulse_sitemap_filter']) ? sanitize_text_field(wp_unslash($_GET['seopulse_sitemap_filter'])) : '';
            ?>
<select name="seopulse_sitemap_filter" id="seopulse_sitemap_filter">
	<option value="">
		<?php esc_html_e('All sitemap statuses', 'seopulse'); ?>
	</option>
	<option value="included" <?php selected($current, 'included'); ?>>
		<?php echo '✓ ' . esc_html__('Included in sitemap', 'seopulse'); ?>
	</option>
	<option value="excluded" <?php selected($current, 'excluded'); ?>>
		<?php echo '✗ ' . esc_html__('Excluded from sitemap', 'seopulse'); ?>
	</option>
	<option value="custom_priority" <?php selected($current, 'custom_priority'); ?>>
		<?php echo '⭐ ' . esc_html__('With custom priority', 'seopulse'); ?>
	</option>
</select>
<?php
        }
    }

    /**
     * Filters posts by sitemap status
     *
     * @param \WP_Query $query WordPress Query
     * @return void
     */
    public function filter_by_sitemap_status(\WP_Query $query): void
    {
        global $pagenow;

        // Check that we are in admin on an edit page
        if (!is_admin() || $pagenow !== 'edit.php') {
            return;
        }

        // Check that a filter is applied
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['seopulse_sitemap_filter']) || empty($_GET['seopulse_sitemap_filter'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter = sanitize_text_field(wp_unslash($_GET['seopulse_sitemap_filter']));

        // Filter: Excluded from sitemap
        if ($filter === 'excluded') {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            $query->query_vars['meta_query'] = [
                [
                    'key'     => '_seopulse_exclude_sitemap',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ];
        }

        // Filter: Included in sitemap
        elseif ($filter === 'included') {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            $query->query_vars['meta_query'] = [
                'relation' => 'OR',
                [
                    'key'     => '_seopulse_exclude_sitemap',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_seopulse_exclude_sitemap',
                    'value'   => '1',
                    'compare' => '!=',
                ],
            ];
        }

        // Filter: With custom priority
        elseif ($filter === 'custom_priority') {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            $query->query_vars['meta_query'] = [
                [
                    'key'     => '_seopulse_sitemap_priority',
                    'compare' => 'EXISTS',
                ],
            ];
        }
    }

    /**
     * Adds CSS styles for admin
     *
     * @param string $hook_suffix The current admin page hook suffix.
     * @return void
     */
    public function add_admin_styles(string $hook_suffix): void
    {
        // Add only on edit pages.
        if ($hook_suffix !== 'edit.php') {
            return;
        }

        $css = '
			.seopulse-sitemap-status {
				display: inline-block;
				width: 20px;
				height: 20px;
				text-align: center;
				line-height: 20px;
				font-weight: bold;
				border-radius: 50%;
			}
			.seopulse-sitemap-status.seopulse-included {
				color: #46b450;
				background-color: #ecf7ed;
			}
			.seopulse-sitemap-status.seopulse-excluded {
				color: #dc3232;
			}
		';

        wp_register_style('seopulse-sitemap-bulk', false);
        wp_enqueue_style('seopulse-sitemap-bulk');
        wp_add_inline_style('seopulse-sitemap-bulk', $css);
    }

    /**
     * Retrieves statistics
     *
     * @param string $post_type Post type
     * @return array Statistics
     */
    public function get_stats(string $post_type = 'post'): array
    {
        global $wpdb;

        $total = wp_count_posts($post_type)->publish;

        // Exclude excluded posts
        $excluded = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.post_type = %s
            AND p.post_status = 'publish'
            AND pm.meta_key = '_seopulse_exclude_sitemap'
            AND pm.meta_value = '1'",
                $post_type,
            ),
        );

        $included = $total - $excluded;

        // Custom priority
        $custom_priority = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.post_type = %s
            AND p.post_status = 'publish'
            AND pm.meta_key = '_seopulse_sitemap_priority'",
                $post_type,
            ),
        );

        return [
            'total'           => $total,
            'included'        => $included,
            'excluded'        => $excluded,
            'custom_priority' => $custom_priority,
        ];
    }
}
?>