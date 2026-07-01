<?php

/**
 * Registers custom SEO columns in post/page list tables.
 *
 * - Adds Score, Title/Meta, and Status columns.
 * - Primes the post meta cache in one batch (no N+1).
 * - Score column is sortable.
 * - Columns are configurable per-post-type via options.
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\Columns;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Constants\PostMeta;
use SEOPulse\Core\Contracts\ExecuteHooksAdmin;

/**
 * ColumnHandler — Admin list table SEO columns.
 */
class ColumnHandler implements ExecuteHooksAdmin
{
    /** Column key prefix to avoid collisions. */
    private const PREFIX = 'seopulse_';

    /**
     * Register hooks for all configured post types.
     *
     * @return void
     */
    public function hooks(): void
    {
        // Column settings toggle.
        if (!$this->is_globally_enabled()) {
            return;
        }

        $post_types = $this->get_enabled_post_types();

        foreach ($post_types as $pt) {
            add_filter("manage_{$pt}_posts_columns", [$this, 'add_columns']);
            add_action("manage_{$pt}_posts_custom_column", [$this, 'render_column'], 10, 2);
            add_filter("manage_edit-{$pt}_sortable_columns", [$this, 'sortable_columns']);
        }

        add_action('pre_get_posts', [$this, 'handle_sort']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    /**
     * Add SEO columns to the list table.
     *
     * Inserts after 'title' for a natural reading order.
     *
     * @param array $columns Existing columns.
     *
     * @return array Modified columns.
     */
    public function add_columns(array $columns): array
    {
        $new = [];

        foreach ($columns as $key => $label) {
            $new[ $key ] = $label;

            if ($key === 'title') {
                $new[ self::PREFIX . 'score' ]  = __('SEO Score', 'seopulse');
                $new[ self::PREFIX . 'meta' ]   = __('Title / Meta', 'seopulse');
                $new[ self::PREFIX . 'status' ] = __('SEO Status', 'seopulse');
            }
        }

        return $new;
    }

    /**
     * Render a custom column cell.
     *
     * @param string $column Column key.
     * @param int $post_id Post ID.
     *
     * @return void
     */
    public function render_column(string $column, int $post_id): void
    {
        // Prime once per screen load.
        ColumnQuery::prime();

        switch ($column) {
            case self::PREFIX . 'score':
                ColumnRenderer::score($post_id);
                break;
            case self::PREFIX . 'meta':
                ColumnRenderer::meta($post_id);
                break;
            case self::PREFIX . 'status':
                ColumnRenderer::status($post_id);
                break;
        }
    }

    /**
     * Declare sortable columns.
     *
     * @param array $columns Existing sortable columns.
     *
     * @return array Modified sortable columns.
     */
    public function sortable_columns(array $columns): array
    {
        $columns[ self::PREFIX . 'score' ] = self::PREFIX . 'score';

        return $columns;
    }

    /**
     * Handle sorting by SEO score on list table.
     *
     * @param \WP_Query $query Main admin query.
     *
     * @return void
     */
    public function handle_sort(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('orderby') !== self::PREFIX . 'score') {
            return;
        }

        $query->set('meta_key', PostMeta::SCORE);
        $query->set('orderby', 'meta_value_num');

        // Include posts without a score (show them last).
        $query->set(
            'meta_query',
            [
                'relation' => 'OR',
                [
                    'key'     => PostMeta::SCORE,
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => PostMeta::SCORE,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        );
    }

    /**
     * Enqueue column styles on list table screens.
     *
     * @param string $hook Current admin page.
     *
     * @return void
     */
    public function enqueue_styles(string $hook): void
    {
        if ($hook !== 'edit.php') {
            return;
        }

        wp_enqueue_style(
            'seopulse-admin-columns',
            SEOPULSE_PLUGIN_URL . 'assets/css/admin-columns.css',
            [],
            SEOPULSE_VERSION,
        );
    }

    /**
     * Get post types where SEO columns are enabled.
     *
     * Reads the Options::ADMIN_COLUMNS option.
     * Defaults to post + page.
     *
     * @return string[]
     */
    private function get_enabled_post_types(): array
    {
        $settings = get_option(Options::ADMIN_COLUMNS, []);

        if (!is_array($settings) || empty($settings['post_types'])) {
            return apply_filters('seopulse_supported_post_types', ['post', 'page']);
        }

        return array_filter(
            (array) $settings['post_types'],
            static fn (string $pt): bool => post_type_exists($pt),
        );
    }

    /**
     * Check whether the global toggle is on.
     *
     * Defaults to true (enabled) if the option has never been saved.
     *
     * @return bool
     */
    private function is_globally_enabled(): bool
    {
        $settings = get_option(Options::ADMIN_COLUMNS, []);

        if (!is_array($settings) || !isset($settings['enabled'])) {
            return true;
        }

        return (bool) $settings['enabled'];
    }

    /**
     * Return the current column settings (for REST/React consumption).
     *
     * @return array{enabled: bool, post_types: string[]}
     */
    public static function get_settings(): array
    {
        $settings = get_option(Options::ADMIN_COLUMNS, []);

        $defaults = [
            'enabled'    => true,
            'post_types' => ['post', 'page'],
        ];

        if (!is_array($settings)) {
            return $defaults;
        }

        return [
            'enabled'    => isset($settings['enabled']) ? (bool) $settings['enabled'] : $defaults['enabled'],
            'post_types' => !empty($settings['post_types']) ? array_values((array) $settings['post_types']) : $defaults['post_types'],
        ];
    }

    /**
     * Save column settings.
     *
     * @param bool $enabled Global toggle.
     * @param string[] $post_types Enabled post types.
     *
     * @return void
     */
    public static function save_settings(bool $enabled, array $post_types): void
    {
        // Sanitize post types to only allow registered types.
        $clean_types = array_values(
            array_filter(
                $post_types,
                static fn (string $pt): bool => post_type_exists($pt),
            ),
        );

        update_option(
            Options::ADMIN_COLUMNS,
            [
                'enabled'    => $enabled,
                'post_types' => $clean_types,
            ],
        );
    }
}
