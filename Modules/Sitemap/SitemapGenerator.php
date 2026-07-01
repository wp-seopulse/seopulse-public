<?php

/**
 * Sitemap Generator
 *
 * Handles XML sitemaps and robots.txt generation
 *
 * @package SEOPulse\Modules\Sitemap
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Sitemap;

/**
 * SitemapGenerator class
 */
class SitemapGenerator
{
    /**
     * Cache key
     */
    private const CACHE_KEY = 'seopulse_sitemap_cache';

    /**
     * Cache duration (24 hours)
     */
    private const CACHE_DURATION = 86400;

    /**
     * Static cache for options
     *
     * @var array|null
     */
    private static ?array $options = null;

    /**
     * Returns the XSL stylesheet URL
     *
     * @return string
     */
    private function get_xsl_url(): string
    {
        return SEOPULSE_PLUGIN_URL . 'Modules/Sitemap/assets/xsl/sitemap-style.xsl';
    }

    /**
     * Returns the xml-stylesheet processing instruction
     *
     * @return string
     */
    private function get_xsl_processing_instruction(): string
    {
        return '<?xml-stylesheet type="text/xsl" href="' . esc_url($this->get_xsl_url()) . '"?>' . "\n";
    }

    /**
     * Adds rewrite rules
     *
     * @return void
     */
    public function add_rewrite_rules(): void
    {
        $options        = $this->get_options();
        $disable_native = !empty($options['disable_wp_core_sitemaps']);

        if ($disable_native) {
            add_rewrite_rule('^sitemap\.xml$', 'index.php?seopulse_sitemap=index', 'top');
            add_rewrite_rule('^sitemap-([^/]+?)-([0-9]+)\.xml$', 'index.php?seopulse_sitemap=$matches[1]&paged=$matches[2]', 'top');
            add_rewrite_tag('%seopulse_sitemap%', '([^&]+)');
            add_rewrite_tag('%paged%', '([0-9]+)');
        }

        // Robots.txt rule (always active)
        add_rewrite_rule('^robots\.txt$', 'index.php?seopulse_robots=1', 'top');
        add_rewrite_tag('%seopulse_robots%', '([0-9]+)');
    }

    /**
     * Handles sitemap requests
     *
     * @return void
     */
    public function handle_sitemap_request(): void
    {
        $options        = $this->get_options();
        $disable_native = !empty($options['disable_wp_core_sitemaps']);

        if (!$disable_native) {
            return;
        }

        $sitemap_type = get_query_var('seopulse_sitemap');

        if (empty($sitemap_type)) {
            return;
        }

        // Security: Validate sitemap type against whitelist
        $allowed_types   = $this->get_allowed_sitemap_types();
        $allowed_types[] = 'index';

        if (!in_array($sitemap_type, $allowed_types, true)) {
            return;
        }

        // Set appropriate headers
        status_header(200);
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex, follow');

        if ($sitemap_type === 'index') {
            echo $this->generate_sitemap_index(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            $paged = absint(get_query_var('paged', 1));
            echo $this->generate_sitemap($sitemap_type, $paged); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        exit;
    }

    /**
     * Handles robots.txt requests
     *
     * @return void
     */
    public function handle_robots_request(): void
    {
        $robots = get_query_var('seopulse_robots');

        if (empty($robots)) {
            return;
        }

        // Check if physical file exists
        $robots_file = ABSPATH . 'robots.txt';
        if (file_exists($robots_file)) {
            // Serve physical file
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html(file_get_contents($robots_file)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            exit;
        }

        // Generate virtual robots.txt
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo $this->generate_robots_txt(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Generates the sitemap index
     *
     * @return string XML content
     */
    public function generate_sitemap_index(): string
    {
        $cache_key = self::CACHE_KEY . '_index';
        $cached    = get_transient($cache_key);

        if ($cached && !$this->is_debug_mode()) {
            return $cached;
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= $this->get_xsl_processing_instruction();
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $sitemaps = $this->get_sitemap_list();

        foreach ($sitemaps as $sitemap) {
            $xml .= '<sitemap>' . "\n";
            $xml .= '  <loc>' . esc_url($sitemap['loc']) . '</loc>' . "\n";
            if (isset($sitemap['lastmod'])) {
                $xml .= '  <lastmod>' . esc_xml($sitemap['lastmod']) . '</lastmod>' . "\n";
            }
            $xml .= '</sitemap>' . "\n";
        }

        $xml .= '</sitemapindex>';

        set_transient($cache_key, $xml, self::CACHE_DURATION);

        return $xml;
    }

    /**
     * Generates an individual sitemap
     *
     * @param string $type Sitemap type
     * @param int $paged Page number
     * @return string XML content
     */
    public function generate_sitemap(string $type, int $paged = 1): string
    {
        $cache_key = self::CACHE_KEY . "_{$type}_{$paged}";
        $cached    = get_transient($cache_key);

        if ($cached && !$this->is_debug_mode()) {
            return $cached;
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= $this->get_xsl_processing_instruction();
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

        $options = $this->get_options();
        if (!empty($options['include_images'])) {
            $xml .= "\n" . '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
        }

        $xml .= '>' . "\n";

        $urls = $this->get_urls_by_type($type, $paged);

        foreach ($urls as $url_data) {
            $xml .= '<url>' . "\n";
            $xml .= '  <loc>' . esc_url($url_data['loc']) . '</loc>' . "\n";

            if (isset($url_data['lastmod'])) {
                $xml .= '  <lastmod>' . esc_xml($url_data['lastmod']) . '</lastmod>' . "\n";
            }

            if (isset($url_data['changefreq'])) {
                $xml .= '  <changefreq>' . esc_xml($url_data['changefreq']) . '</changefreq>' . "\n";
            }

            if (isset($url_data['priority'])) {
                $xml .= '  <priority>' . esc_xml($url_data['priority']) . '</priority>' . "\n";
            }

            // Images
            if (!empty($url_data['images']) && !empty($options['include_images'])) {
                foreach ($url_data['images'] as $image) {
                    $xml .= '  <image:image>' . "\n";
                    $xml .= '    <image:loc>' . esc_url($image['loc']) . '</image:loc>' . "\n";

                    if (!empty($image['title'])) {
                        $xml .= '    <image:title>' . esc_xml($image['title']) . '</image:title>' . "\n";
                    }

                    if (!empty($image['caption'])) {
                        $xml .= '    <image:caption>' . esc_xml($image['caption']) . '</image:caption>' . "\n";
                    }

                    $xml .= '  </image:image>' . "\n";
                }
            }

            $xml .= '</url>' . "\n";
        }

        $xml .= '</urlset>';

        set_transient($cache_key, $xml, self::CACHE_DURATION);

        return $xml;
    }

    /**
     * Generates the robots.txt content
     *
     * @return string robots.txt content
     */
    public function generate_robots_txt(): string
    {
        $options = $this->get_options();

        // If custom_robots is set, it holds the full robots.txt content — use it verbatim.
        if (!empty($options['custom_robots'])) {
            $content = trim($options['custom_robots']) . "\n";

            return apply_filters('seopulse_robots_txt', $content);
        }

        // No custom content: build the default auto-generated robots.txt.
        $content  = "User-agent: *\n";
        $content .= "Disallow: /wp-admin/\n";
        $content .= "Disallow: /wp-includes/\n";
        $content .= "Allow: /wp-admin/admin-ajax.php\n";
        $content .= "\n";
        $content .= 'Sitemap: ' . $this->get_sitemap_url() . "\n";

        return apply_filters('seopulse_robots_txt', $content);
    }

    /**
     * Adds entries to robots.txt
     *
     * @param string $output Current content
     * @param string $public Public flag
     * @return string Modified content
     */
    public function add_robots_entries(string $output, string $public): string
    {
        if ('1' !== $public) {
            return $output;
        }

        $output .= "\nSitemap: " . $this->get_sitemap_url() . "\n";

        $options = $this->get_options();
        if (!empty($options['custom_robots'])) {
            $output .= "\n" . $options['custom_robots'] . "\n";
        }

        return $output;
    }

    /**
     * Clears the cache
     *
     * @return void
     */
    public function clear_cache(): void
    {
        global $wpdb;

        // Clear all sitemap-related transients
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 OR option_name LIKE %s",
                '_transient_seopulse_sitemap_cache%',
                '_transient_timeout_seopulse_sitemap_cache%',
            ),
        );

        // Reset static cache
        self::$options = null;
    }

    /**
     * Retrieves options
     *
     * @return array Options
     */
    private function get_options(): array
    {
        if (self::$options === null) {
            self::$options = get_option('seopulse_sitemap_settings', []);
        }

        return self::$options;
    }

    /**
     * Retrieves the sitemap URL
     *
     * @return string Sitemap URL
     */
    private function get_sitemap_url(): string
    {
        $options        = $this->get_options();
        $disable_native = !empty($options['disable_wp_core_sitemaps']);

        return $disable_native ? home_url('/sitemap.xml') : home_url('/wp-sitemap.xml');
    }

    /**
     * Retrieves allowed sitemap types
     *
     * @return array Allowed types
     */
    private function get_allowed_sitemap_types(): array
    {
        $allowed = ['posts', 'pages', 'categories', 'tags', 'images'];

        // Add custom post types
        $post_types = get_post_types(
            [
                'public'   => true,
                '_builtin' => false,
            ],
        );
        foreach ($post_types as $pt) {
            $allowed[] = sanitize_key($pt);
        }

        return apply_filters('seopulse_allowed_sitemap_types', $allowed);
    }

    /**
     * Retrieves the sitemap list for the index
     *
     * @return array Sitemap list
     */
    private function get_sitemap_list(): array
    {
        $options  = $this->get_options();
        $sitemaps = [];
        $home_url = home_url();

        // Posts sitemap
        if ($this->is_enabled('post', $options)) {
            $sitemaps[] = [
                'loc'     => $home_url . '/sitemap-posts-1.xml',
                'lastmod' => $this->get_last_modified('post'),
            ];
        }

        // Pages sitemap
        if ($this->is_enabled('page', $options)) {
            $sitemaps[] = [
                'loc'     => $home_url . '/sitemap-pages-1.xml',
                'lastmod' => $this->get_last_modified('page'),
            ];
        }

        // Custom post types
        $post_types = get_post_types(
            [
                'public'   => true,
                '_builtin' => false,
            ],
        );
        foreach ($post_types as $pt) {
            if ($this->is_enabled($pt, $options)) {
                $sitemaps[] = [
                    'loc'     => $home_url . '/sitemap-' . sanitize_key($pt) . '-1.xml',
                    'lastmod' => $this->get_last_modified($pt),
                ];
            }
        }

        // Categories
        if ($this->is_enabled('category', $options)) {
            $sitemaps[] = [
                'loc'     => $home_url . '/sitemap-categories-1.xml',
                'lastmod' => gmdate('Y-m-d'),
            ];
        }

        // Tags
        if ($this->is_enabled('post_tag', $options)) {
            $sitemaps[] = [
                'loc'     => $home_url . '/sitemap-tags-1.xml',
                'lastmod' => gmdate('Y-m-d'),
            ];
        }

        // Images
        if ($this->is_enabled('images', $options)) {
            $sitemaps[] = [
                'loc'     => $home_url . '/sitemap-images-1.xml',
                'lastmod' => gmdate('Y-m-d'),
            ];
        }

        return apply_filters('seopulse_sitemap_list', $sitemaps);
    }

    /**
     * Retrieves URLs by type
     *
     * @param string $type Content type
     * @param int $paged Page number
     * @return array URLs
     */
    private function get_urls_by_type(string $type, int $paged): array
    {
        $options = $this->get_options();

        switch ($type) {
            case 'posts':
                return $this->get_post_urls('post', $paged, $options);
            case 'pages':
                return $this->get_post_urls('page', $paged, $options);
            case 'categories':
                return $this->get_taxonomy_urls('category', $paged, $options);
            case 'tags':
                return $this->get_taxonomy_urls('post_tag', $paged, $options);
            case 'images':
                return $this->get_image_urls($paged, $options);
            default:
                // Custom post types
                if (post_type_exists($type)) {
                    return $this->get_post_urls($type, $paged, $options);
                }

                return [];
        }
    }

    /**
     * Retrieves post URLs
     *
     * @param string $post_type Post type
     * @param int $paged Page number
     * @param array $options Settings
     * @return array URLs
     */
    private function get_post_urls(string $post_type, int $paged, array $options): array
    {
        $urls           = [];
        $posts_per_page = 500;

        $args = [
            'post_type'              => $post_type,
            'post_status'            => 'publish',
            'posts_per_page'         => $posts_per_page,
            'paged'                  => $paged,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ];

        $posts = get_posts($args);

        foreach ($posts as $post) {
            $permalink = get_permalink($post);

            // Skip if excluded
            if ($this->is_excluded($permalink, $options)) {
                continue;
            }

            // Check post-specific exclusion
            if (get_post_meta($post->ID, '_seopulse_exclude_sitemap', true)) {
                continue;
            }

            // Get custom values or defaults
            $custom_priority   = get_post_meta($post->ID, '_seopulse_sitemap_priority', true);
            $custom_changefreq = get_post_meta($post->ID, '_seopulse_sitemap_changefreq', true);

            $priority   = $custom_priority ?: ($options[ "priority_{$post_type}" ] ?? '0.6');
            $changefreq = $custom_changefreq ?: ($options[ "changefreq_{$post_type}" ] ?? 'weekly');

            $url_data = [
                'loc'        => $permalink,
                'lastmod'    => get_the_modified_time('Y-m-d', $post),
                'changefreq' => $changefreq,
                'priority'   => $priority,
            ];

            // Add images if enabled
            if (!empty($options['include_images'])) {
                $images = $this->get_post_images($post->ID);
                if (!empty($images)) {
                    $url_data['images'] = $images;
                }
            }

            $urls[] = $url_data;
        }

        return $urls;
    }

    /**
     * Retrieves taxonomy URLs
     *
     * @param string $taxonomy Taxonomy name
     * @param int $paged Page number
     * @param array $options Settings
     * @return array URLs
     */
    private function get_taxonomy_urls(string $taxonomy, int $paged, array $options): array
    {
        $urls   = [];
        $number = 500;
        $offset = ($paged - 1) * $number;

        $terms = get_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'number'     => $number,
                'offset'     => $offset,
            ],
        );

        if (is_wp_error($terms)) {
            return [];
        }

        foreach ($terms as $term) {
            $term_link = get_term_link($term);

            if (is_wp_error($term_link)) {
                continue;
            }

            if ($this->is_excluded($term_link, $options)) {
                continue;
            }

            $urls[] = [
                'loc'        => $term_link,
                'lastmod'    => gmdate('Y-m-d'),
                'changefreq' => 'weekly',
                'priority'   => '0.4',
            ];
        }

        return $urls;
    }

    /**
     * Retrieves image URLs
     *
     * @param int $paged Page number
     * @param array $options Settings
     * @return array URLs
     */
    private function get_image_urls(int $paged, array $options): array
    {
        $urls           = [];
        $posts_per_page = 500;

        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $posts_per_page,
            'paged'          => $paged,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ];

        $images = get_posts($args);

        foreach ($images as $image) {
            $image_url = wp_get_attachment_url($image->ID);

            if (!$image_url || $this->is_excluded($image_url, $options)) {
                continue;
            }

            $urls[] = [
                'loc'        => $image_url,
                'lastmod'    => get_the_modified_time('Y-m-d', $image),
                'changefreq' => 'monthly',
                'priority'   => '0.3',
            ];
        }

        return $urls;
    }

    /**
     * Retrieves images from a post
     *
     * @param int $post_id Post ID
     * @return array Images
     */
    private function get_post_images(int $post_id): array
    {
        $images = [];

        // Featured image
        if (has_post_thumbnail($post_id)) {
            $thumb_id  = get_post_thumbnail_id($post_id);
            $thumb_url = wp_get_attachment_url($thumb_id);

            if ($thumb_url) {
                $images[] = [
                    'loc'     => $thumb_url,
                    'title'   => get_the_title($thumb_id),
                    'caption' => wp_get_attachment_caption($thumb_id),
                ];
            }
        }

        // Images in content
        $post = get_post($post_id);
        if ($post && !empty($post->post_content)) {
            preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $post->post_content, $matches);

            if (!empty($matches[1])) {
                foreach ($matches[1] as $img_url) {
                    // Validate URL
                    if (!filter_var($img_url, FILTER_VALIDATE_URL)) {
                        continue;
                    }

                    $attachment_id = attachment_url_to_postid($img_url);

                    $images[] = [
                        'loc'     => $img_url,
                        'title'   => $attachment_id ? get_the_title($attachment_id) : '',
                        'caption' => $attachment_id ? wp_get_attachment_caption($attachment_id) : '',
                    ];
                }
            }
        }

        // Limit to 10 images per post (Google recommendation)
        return array_slice($images, 0, 10);
    }

    /**
     * Checks if a type is enabled
     *
     * @param string $type Content type
     * @param array $options Settings
     * @return bool
     */
    private function is_enabled(string $type, array $options): bool
    {
        $key = "enable_{$type}";

        return isset($options[ $key ]) ? (bool) $options[ $key ] : true;
    }

    /**
     * Checks if a URL is excluded
     *
     * @param string $url URL to check
     * @param array $options Settings
     * @return bool
     */
    private function is_excluded(string $url, array $options): bool
    {
        if (empty($options['excluded_urls'])) {
            return false;
        }

        $excluded = explode("\n", $options['excluded_urls']);

        foreach ($excluded as $pattern) {
            $pattern = trim($pattern);
            if (empty($pattern)) {
                continue;
            }

            if (false !== strpos($url, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieves the last modified date for a post type
     *
     * @param string $post_type Post type
     * @return string Date
     */
    private function get_last_modified(string $post_type): string
    {
        $posts = get_posts(
            [
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ],
        );

        if (!empty($posts)) {
            return get_the_modified_time('Y-m-d', $posts[0]);
        }

        return gmdate('Y-m-d');
    }

    /**
     * Checks if debug mode is active
     *
     * @return bool
     */
    private function is_debug_mode(): bool
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }

        if (!current_user_can('manage_options') || !is_admin()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['debug_sitemap'])) {
            return false;
        }

        // Validate debug parameter
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $debug = sanitize_text_field(wp_unslash($_GET['debug_sitemap']));

        return '1' === $debug;
    }

    /**
     * Retrieves sitemap statistics
     *
     * @return array Statistics
     */
    public function get_sitemap_stats(): array
    {
        $stats = [
            'posts'      => 0,
            'pages'      => 0,
            'images'     => 0,
            'total_urls' => 0,
        ];

        // Posts
        $post_count     = wp_count_posts('post');
        $stats['posts'] = isset($post_count->publish) ? $post_count->publish : 0;

        // Pages
        $page_count     = wp_count_posts('page');
        $stats['pages'] = isset($page_count->publish) ? $page_count->publish : 0;

        // Images
        $image_count     = wp_count_posts('attachment');
        $stats['images'] = isset($image_count->inherit) ? $image_count->inherit : 0;

        $stats['total_urls'] = $stats['posts'] + $stats['pages'] + 1; // +1 for homepage

        // Custom post types
        $post_types = get_post_types(
            [
                'public'   => true,
                '_builtin' => false,
            ],
        );
        foreach ($post_types as $pt) {
            $count = wp_count_posts($pt);
            if (isset($count->publish)) {
                $stats['total_urls'] += $count->publish;
            }
        }

        // Taxonomies
        $options = $this->get_options();

        if ($this->is_enabled('category', $options)) {
            $cat_count            = wp_count_terms(
                [
                    'taxonomy'   => 'category',
                    'hide_empty' => true,
                ],
            );
            $stats['total_urls'] += is_numeric($cat_count) ? $cat_count : 0;
        }

        if ($this->is_enabled('post_tag', $options)) {
            $tag_count            = wp_count_terms(
                [
                    'taxonomy'   => 'post_tag',
                    'hide_empty' => true,
                ],
            );
            $stats['total_urls'] += is_numeric($tag_count) ? $tag_count : 0;
        }

        return $stats;
    }
}
