<?php

/**
 * Breadcrumb renderer for SEOPulse
 *
 * Generates visible breadcrumb trail HTML and JSON-LD BreadcrumbList
 * structured data for search engines.
 *
 * @package SEOPulse\Modules\MetaSeo
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Contracts\ExecuteHooks;

/**
 * BreadcrumbRenderer class
 *
 * Hooks into wp_head to output JSON-LD BreadcrumbList schema.
 * Provides a public render() method used by the seopulse_breadcrumbs() template tag.
 */
class BreadcrumbRenderer implements ExecuteHooks
{
    /**
     * Registers WordPress hooks.
     */
    public function hooks(): void
    {
        if (!self::is_enabled()) {
            return;
        }

        add_action('wp_head', [$this, 'render_json_ld'], 5);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_styles']);
        add_shortcode('seopulse_breadcrumbs', [$this, 'shortcode_handler']);

        // Auto-insert breadcrumbs before post content if configured
        if (self::is_auto_insert_enabled()) {
            add_filter('the_content', [$this, 'prepend_to_content'], 5);
        }
    }

    /**
     * Whether breadcrumbs are enabled in settings.
     */
    public static function is_enabled(): bool
    {
        $settings = (array) get_option(Options::META_SEO_GLOBAL, []);

        return !empty($settings['breadcrumbs_enabled']);
    }

    /**
     * Whether auto-insert into content is enabled.
     */
    public static function is_auto_insert_enabled(): bool
    {
        $settings = (array) get_option(Options::META_SEO_GLOBAL, []);

        return !empty($settings['breadcrumbs_auto_insert']);
    }

    /**
     * Returns the post types selected for auto-insert.
     *
     * @return string[]
     */
    private static function get_auto_insert_post_types(): array
    {
        $settings = (array) get_option(Options::META_SEO_GLOBAL, []);
        $types    = $settings['breadcrumbs_post_types'] ?? [];

        return is_array($types) ? $types : [];
    }

    /**
     * Prepends breadcrumb HTML to post content (the_content filter).
     */
    public function prepend_to_content(string $content): string
    {
        // Only on singular main query to avoid loops in excerpts/widgets
        if (!is_singular() || !is_main_query()) {
            return $content;
        }

        // Check post type allowlist
        $allowed = self::get_auto_insert_post_types();
        if (!empty($allowed) && !in_array(get_post_type(), $allowed, true)) {
            return $content;
        }

        $breadcrumbs = $this->render(false);

        if ($breadcrumbs === '') {
            return $content;
        }

        return $breadcrumbs . $content;
    }

    /**
     * Shortcode handler for [seopulse_breadcrumbs].
     *
     * @return string Breadcrumb HTML.
     */
    public function shortcode_handler(): string
    {
        return $this->render(false);
    }

    /**
     * Builds the breadcrumb trail for the current request.
     *
     * Applies breadcrumb_show_home and breadcrumb_show_last settings.
     *
     * @return list<array{name: string, url: string}>
     */
    public function build_trail(): array
    {
        $settings  = (array) get_option(Options::META_SEO_GLOBAL, []);
        $show_home = !isset($settings['breadcrumb_show_home']) || !empty($settings['breadcrumb_show_home']);
        $show_last = !isset($settings['breadcrumb_show_last']) || !empty($settings['breadcrumb_show_last']);

        $crumbs = $this->build_raw_trail($show_home);

        // Remove current page (last crumb) if disabled
        if (!$show_last && count($crumbs) > 1) {
            array_pop($crumbs);
        }

        return $crumbs;
    }

    /**
     * Builds the raw breadcrumb trail without applying show_last filter.
     *
     * @return list<array{name: string, url: string}>
     */
    private function build_raw_trail(bool $show_home): array
    {
        $crumbs = [];

        // Optionally add Home as first crumb
        if ($show_home) {
            $crumbs[] = [
                'name' => $this->get_home_label(),
                'url'  => home_url('/'),
            ];
        }

        if (is_front_page() || (is_home() && is_front_page())) {
            // Front page: only Home crumb, no extra trail
            return $crumbs;
        }

        if (is_home()) {
            // Blog page (static front page + separate posts page)
            $blog_id = (int) get_option('page_for_posts');
            if ($blog_id > 0) {
                $crumbs[] = [
                    'name' => get_the_title($blog_id),
                    'url'  => (string) get_permalink($blog_id),
                ];
            }

            return $crumbs;
        }

        if (is_singular()) {
            $crumbs = $this->build_singular_trail($crumbs);

            return $crumbs;
        }

        if (is_category()) {
            $crumbs = $this->build_category_trail($crumbs);

            return $crumbs;
        }

        if (is_tag()) {
            $tag = get_queried_object();
            if ($tag instanceof \WP_Term) {
                $crumbs[] = [
                    'name' => $tag->name,
                    'url'  => (string) get_tag_link($tag->term_id),
                ];
            }

            return $crumbs;
        }

        if (is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $crumbs   = $this->build_term_ancestors($crumbs, $term);
                $crumbs[] = [
                    'name' => $term->name,
                    'url'  => (string) get_term_link($term),
                ];
            }

            return $crumbs;
        }

        if (is_post_type_archive()) {
            $pta = get_queried_object();
            if ($pta instanceof \WP_Post_Type) {
                $crumbs[] = [
                    'name' => $pta->labels->name,
                    'url'  => (string) get_post_type_archive_link($pta->name),
                ];
            }

            return $crumbs;
        }

        if (is_author()) {
            $author = get_queried_object();
            if ($author instanceof \WP_User) {
                $crumbs[] = [
                    'name' => $author->display_name,
                    'url'  => (string) get_author_posts_url($author->ID),
                ];
            }

            return $crumbs;
        }

        if (is_date()) {
            $crumbs = $this->build_date_trail($crumbs);

            return $crumbs;
        }

        if (is_search()) {
            $crumbs[] = [
                'name' => sprintf(
                    /* translators: %s: search query */
                    __('Search: %s', 'seopulse'),
                    get_search_query(),
                ),
                'url'  => (string) get_search_link(),
            ];

            return $crumbs;
        }

        if (is_404()) {
            $crumbs[] = [
                'name' => __('Page not found', 'seopulse'),
                'url'  => '',
            ];

            return $crumbs;
        }

        return $crumbs;
    }

    /**
     * Renders visible breadcrumb HTML.
     *
     * @param bool $echo Whether to echo or return.
     * @return string HTML output.
     */
    public function render(bool $echo = true): string
    {
        if (!self::is_enabled()) {
            return '';
        }

        $crumbs = $this->build_trail();

        if (count($crumbs) < 2) {
            // Don't render breadcrumbs on front page (only Home crumb)
            return '';
        }

        $last_index = count($crumbs) - 1;

        $html  = '<nav class="seopulse-breadcrumbs" aria-label="' . esc_attr__('Breadcrumb', 'seopulse') . '">';
        $html .= '<ol class="seopulse-breadcrumbs__list">';

        foreach ($crumbs as $i => $crumb) {
            $is_last = ($i === $last_index);
            $html   .= '<li class="seopulse-breadcrumbs__item">';

            if (!$is_last && $crumb['url'] !== '') {
                $html .= '<a class="seopulse-breadcrumbs__link" href="' . esc_url($crumb['url']) . '">';
                $html .= esc_html($crumb['name']);
                $html .= '</a>';
            } else {
                $html .= '<span class="seopulse-breadcrumbs__current" aria-current="page">';
                $html .= esc_html($crumb['name']);
                $html .= '</span>';
            }

            if (!$is_last) {
                $html .= '<span class="seopulse-breadcrumbs__separator" aria-hidden="true">';
                /**
                 * Filters the breadcrumb separator character.
                 *
                 * @since 1.0.0
                 * @param string $separator Default separator.
                 */
                $html .= esc_html((string) apply_filters('seopulse_breadcrumb_separator', '›'));
                $html .= '</span>';
            }

            $html .= '</li>';
        }

        $html .= '</ol>';
        $html .= '</nav>';

        if ($echo) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
        }

        return $html;
    }

    /**
     * Enqueues the minimal breadcrumb stylesheet on the frontend.
     */
    public function enqueue_frontend_styles(): void
    {
        wp_enqueue_style(
            'seopulse-breadcrumbs',
            SEOPULSE_PLUGIN_URL . 'Modules/MetaSeo/assets/css/breadcrumbs.css',
            [],
            SEOPULSE_VERSION,
        );
    }

    /**
     * Outputs JSON-LD BreadcrumbList in wp_head.
     */
    public function render_json_ld(): void
    {
        $crumbs = $this->build_trail();

        if (count($crumbs) < 2) {
            return;
        }

        $items = [];
        foreach ($crumbs as $i => $crumb) {
            if ($crumb['url'] === '') {
                continue;
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['name'],
                'item'     => $crumb['url'],
            ];
        }

        if (empty($items)) {
            return;
        }

        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode applies JSON_HEX_TAG|JSON_HEX_AMP; esc_html would break JSON-LD.
        echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
    }

    // ──────────────────────────────────────────────────
    // Private trail builders
    // ──────────────────────────────────────────────────

    /**
     * @return list<array{name: string, url: string}>
     */
    private function build_singular_trail(array $crumbs): array
    {
        global $post;

        if (!$post instanceof \WP_Post) {
            return $crumbs;
        }

        $post_type = get_post_type($post);

        // For hierarchical post types (pages), add ancestors
        if (is_post_type_hierarchical($post_type)) {
            $ancestors = get_post_ancestors($post);
            $ancestors = array_reverse($ancestors);

            foreach ($ancestors as $ancestor_id) {
                $crumbs[] = [
                    'name' => get_the_title($ancestor_id),
                    'url'  => (string) get_permalink($ancestor_id),
                ];
            }
        } else {
            // For posts, add primary category
            if ($post_type === 'post') {
                $categories = get_the_category($post->ID);
                if (!empty($categories)) {
                    $primary  = $this->get_primary_category($categories, $post->ID);
                    $crumbs   = $this->build_category_ancestors($crumbs, $primary);
                    $crumbs[] = [
                        'name' => $primary->name,
                        'url'  => (string) get_category_link($primary->term_id),
                    ];
                }
            } else {
                // Custom post type: add archive link
                $pta = get_post_type_object($post_type);
                if ($pta && $pta->has_archive) {
                    $archive_link = get_post_type_archive_link($post_type);
                    if ($archive_link) {
                        $crumbs[] = [
                            'name' => $pta->labels->name,
                            'url'  => (string) $archive_link,
                        ];
                    }
                }
            }
        }

        // Current page/post (last crumb)
        $crumbs[] = [
            'name' => get_the_title($post),
            'url'  => (string) get_permalink($post),
        ];

        return $crumbs;
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function build_category_trail(array $crumbs): array
    {
        $cat = get_queried_object();
        if (!$cat instanceof \WP_Term) {
            return $crumbs;
        }

        $crumbs   = $this->build_category_ancestors($crumbs, $cat);
        $crumbs[] = [
            'name' => $cat->name,
            'url'  => (string) get_category_link($cat->term_id),
        ];

        return $crumbs;
    }

    /**
     * Adds ancestor categories to the trail.
     *
     * @return list<array{name: string, url: string}>
     */
    private function build_category_ancestors(array $crumbs, \WP_Term $cat): array
    {
        if ($cat->parent === 0) {
            return $crumbs;
        }

        $ancestors = get_ancestors($cat->term_id, 'category', 'taxonomy');
        $ancestors = array_reverse($ancestors);

        foreach ($ancestors as $ancestor_id) {
            $ancestor = get_term($ancestor_id, 'category');
            if ($ancestor instanceof \WP_Term) {
                $crumbs[] = [
                    'name' => $ancestor->name,
                    'url'  => (string) get_category_link($ancestor->term_id),
                ];
            }
        }

        return $crumbs;
    }

    /**
     * Adds ancestor terms for custom taxonomies.
     *
     * @return list<array{name: string, url: string}>
     */
    private function build_term_ancestors(array $crumbs, \WP_Term $term): array
    {
        if ($term->parent === 0) {
            return $crumbs;
        }

        $ancestors = get_ancestors($term->term_id, $term->taxonomy, 'taxonomy');
        $ancestors = array_reverse($ancestors);

        foreach ($ancestors as $ancestor_id) {
            $ancestor = get_term($ancestor_id, $term->taxonomy);
            if ($ancestor instanceof \WP_Term) {
                $crumbs[] = [
                    'name' => $ancestor->name,
                    'url'  => (string) get_term_link($ancestor),
                ];
            }
        }

        return $crumbs;
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function build_date_trail(array $crumbs): array
    {
        $year = (string) get_query_var('year');

        if (is_year()) {
            $crumbs[] = [
                'name' => $year,
                'url'  => (string) get_year_link((int) $year),
            ];

            return $crumbs;
        }

        // Month or day: always add year
        $crumbs[] = [
            'name' => $year,
            'url'  => (string) get_year_link((int) $year),
        ];

        $month      = (string) get_query_var('monthnum');
        $month_name = '';
        if ($month !== '' && $month !== '0') {
            $timestamp = mktime(0, 0, 0, (int) $month, 1, (int) $year);
            if ($timestamp !== false) {
                $month_name = wp_date('F', $timestamp) ?: $month;
            }
        }

        if (is_month()) {
            $crumbs[] = [
                'name' => $month_name,
                'url'  => (string) get_month_link((int) $year, (int) $month),
            ];

            return $crumbs;
        }

        if (is_day()) {
            $day      = (string) get_query_var('day');
            $crumbs[] = [
                'name' => $month_name,
                'url'  => (string) get_month_link((int) $year, (int) $month),
            ];
            $crumbs[] = [
                'name' => $day,
                'url'  => (string) get_day_link((int) $year, (int) $month, (int) $day),
            ];
        }

        return $crumbs;
    }

    /**
     * Returns the "Home" label (filterable).
     */
    private function get_home_label(): string
    {
        /**
         * Filters the breadcrumb Home label.
         *
         * @since 1.0.0
         * @param string $label Default "Home" label.
         */
        return (string) apply_filters(
            'seopulse_breadcrumb_home_label',
            __('Home', 'seopulse'),
        );
    }

    /**
     * Picks the primary category for a post.
     *
     * Respects Yoast/RankMath primary category meta if present,
     * otherwise returns the first category.
     *
     * @param \WP_Term[] $categories
     */
    private function get_primary_category(array $categories, int $post_id): \WP_Term
    {
        // Check SEOPulse own primary category meta (future-proof)
        $primary_id = (int) get_post_meta($post_id, '_seopulse_primary_category', true);

        // Fallback to Yoast primary category
        if ($primary_id === 0) {
            $primary_id = (int) get_post_meta($post_id, '_yoast_wpseo_primary_category', true);
        }

        // Fallback to RankMath primary category
        if ($primary_id === 0) {
            $primary_id = (int) get_post_meta($post_id, 'rank_math_primary_category', true);
        }

        if ($primary_id > 0) {
            foreach ($categories as $cat) {
                if ($cat->term_id === $primary_id) {
                    return $cat;
                }
            }
        }

        return $categories[0];
    }
}
