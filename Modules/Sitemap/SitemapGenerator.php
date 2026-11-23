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

use SEOPulse\Core\Cache\ObjectCache;
use SEOPulse\Modules\I18n\Multilingual;
use SEOPulse\Modules\MetaSeo\Headless\AlternatesProvider;

/**
 * SitemapGenerator class
 */
class SitemapGenerator
{
    /**
     * Cache key
     */
    private const CACHE_KEY = 'sitemap_cache';

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
     * Media extractor (images and videos)
     *
     * @var SitemapMedia|null
     */
    private ?SitemapMedia $media = null;

    /**
     * Returns the media extractor
     *
     * @return SitemapMedia
     */
    private function media(): SitemapMedia
    {
        return $this->media ??= new SitemapMedia();
    }

    /**
     * Language alternates provider
     *
     * @var AlternatesProvider|null
     */
    private ?AlternatesProvider $alternates = null;

    /**
     * Returns the language alternates provider
     *
     * @return AlternatesProvider
     */
    private function alternates(): AlternatesProvider
    {
        return $this->alternates ??= new AlternatesProvider();
    }

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

        if (!in_array($this->split_language((string) $sitemap_type)[0], $allowed_types, true)) {
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
     * Disable the native WordPress sitemap module if the option is enabled.
     *
     * Must execute before wp_sitemaps_get_server() runs
     * on 'init' (priority 10 by default), otherwise WP_Sitemaps::init() will still
     * redirect /sitemap.xml -> /wp-sitemap.xml on template_redirect.
     *
     * @return void
     */
    public function maybe_disable_core_sitemaps(): void
    {
        $options = $this->get_options();

        if (!empty($options['disable_wp_core_sitemaps'])) {
            add_filter('wp_sitemaps_enabled', '__return_false');
        }
    }

    /**
     * Generates the sitemap index
     *
     * @return string XML content
     */
    public function generate_sitemap_index(): string
    {
        $cache_key = self::CACHE_KEY . '_index';
        $cached    = ObjectCache::get($cache_key, 'sitemap');

        if (is_string($cached) && $cached !== '' && !$this->is_debug_mode()) {
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

        ObjectCache::set($cache_key, $xml, 'sitemap', self::CACHE_DURATION);

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
        $cached    = ObjectCache::get($cache_key, 'sitemap');

        if (is_string($cached) && $cached !== '' && !$this->is_debug_mode()) {
            return $cached;
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= $this->get_xsl_processing_instruction();
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

        $options = $this->get_options();
        if (!empty($options['include_images'])) {
            $xml .= "\n" . '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
        }

        if ($type === 'videos') {
            $xml .= "\n" . '        xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"';
        }

        if (Multilingual::is_active()) {
            $xml .= "\n" . '        xmlns:xhtml="http://www.w3.org/1999/xhtml"';
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

            foreach ($url_data['alternates'] ?? [] as $alternate) {
                $xml .= '  <xhtml:link rel="alternate" hreflang="' . esc_attr($alternate['hreflang']) . '" href="' . esc_url($alternate['url']) . '" />' . "\n";
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

            // Videos
            foreach ($url_data['videos'] ?? [] as $video) {
                $xml .= $this->render_video($video);
            }

            $xml .= '</url>' . "\n";
        }

        $xml .= '</urlset>';

        ObjectCache::set($cache_key, $xml, 'sitemap', self::CACHE_DURATION);

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

        // If custom_robots is set, it holds the full robots.txt content â€” use it verbatim.
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
        ObjectCache::flush_group('sitemap');

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
        $allowed = ['posts', 'pages', 'categories', 'tags', 'images', 'videos'];

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

        // One entry per language on multilingual sites, so each sitemap only lists one language.
        $add = static function (string $slug, string $lastmod) use (&$sitemaps, $home_url): void {
            $languages = Multilingual::is_active() ? array_keys(Multilingual::languages()) : [''];

            foreach ($languages as $lang) {
                $sitemaps[] = [
                    'loc'     => $home_url . '/sitemap-' . $slug . ('' !== $lang ? '-' . $lang : '') . '-1.xml',
                    'lastmod' => $lastmod,
                ];
            }
        };

        // Posts sitemap
        if ($this->is_enabled('post', $options)) {
            $add('posts', $this->get_last_modified('post'));
        }

        // Pages sitemap
        if ($this->is_enabled('page', $options)) {
            $add('pages', $this->get_last_modified('page'));
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
                $add(sanitize_key($pt), $this->get_last_modified($pt));
            }
        }

        // Categories
        if ($this->is_enabled('category', $options)) {
            $add('categories', gmdate('Y-m-d'));
        }

        // Tags
        if ($this->is_enabled('post_tag', $options)) {
            $add('tags', gmdate('Y-m-d'));
        }

        // Images
        if ($this->is_enabled('images', $options)) {
            $sitemaps[] = [
                'loc'     => $home_url . '/sitemap-images-1.xml',
                'lastmod' => gmdate('Y-m-d'),
            ];
        }

        // Videos: one entry per page of posts, only when some post embeds a video
        if ($this->is_enabled('videos', $options)) {
            $video_pages = (int) ceil($this->media()->countVideoPosts($this->get_video_post_types($options)) / SitemapMedia::VIDEO_POSTS_PER_PAGE);

            for ($page = 1; $page <= $video_pages; $page++) {
                $sitemaps[] = [
                    'loc'     => $home_url . '/sitemap-videos-' . $page . '.xml',
                    'lastmod' => gmdate('Y-m-d'),
                ];
            }
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

        [$type, $lang] = $this->split_language($type);

        switch ($type) {
            case 'posts':
                return $this->get_post_urls('post', $paged, $options, $lang);
            case 'pages':
                return $this->get_post_urls('page', $paged, $options, $lang);
            case 'categories':
                return $this->get_taxonomy_urls('category', $paged, $options, $lang);
            case 'tags':
                return $this->get_taxonomy_urls('post_tag', $paged, $options, $lang);
            case 'images':
                return $this->get_image_urls($paged, $options);
            case 'videos':
                return $this->get_video_urls($paged, $options);
            default:
                // Custom post types
                if (post_type_exists($type)) {
                    return $this->get_post_urls($type, $paged, $options, $lang);
                }

                return [];
        }
    }

    /**
     * Splits a sitemap type such as "posts-fr" into its base type and language.
     *
     * @param string $type Sitemap type
     * @return array{0: string, 1: string} Base type, then language slug (empty when none)
     */
    private function split_language(string $type): array
    {
        if (Multilingual::is_active()) {
            foreach (array_keys(Multilingual::languages()) as $slug) {
                $suffix = '-' . $slug;

                if (str_ends_with($type, $suffix)) {
                    return [substr($type, 0, -strlen($suffix)), (string) $slug];
                }
            }
        }

        return [$type, ''];
    }

    /**
     * Retrieves post URLs
     *
     * @param string $post_type Post type
     * @param int $paged Page number
     * @param array $options Settings
     * @param string $lang Language slug, empty for all
     * @return array URLs
     */
    private function get_post_urls(string $post_type, int $paged, array $options, string $lang = ''): array
    {
        return Multilingual::with_language($lang, fn (): array => $this->collect_post_urls($post_type, $paged, $options, $lang));
    }

    /**
     * Collects post URLs (runs with the sitemap language active)
     *
     * @param string $post_type Post type
     * @param int $paged Page number
     * @param array $options Settings
     * @param string $lang Language slug, empty for all
     * @return array URLs
     */
    private function collect_post_urls(string $post_type, int $paged, array $options, string $lang): array
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
            'post__not_in'           => \SEOPulse\Services\WooCommerceSystemPages::ids(),
        ];

        if ('' !== $lang) {
            $args = array_merge($args, Multilingual::query_args($lang));
        }

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

            $alternates = $this->alternates()->for_post($post);
            if ([] !== $alternates) {
                $url_data['alternates'] = $alternates;
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
     * @param string $lang Language slug, empty for all
     * @return array URLs
     */
    private function get_taxonomy_urls(string $taxonomy, int $paged, array $options, string $lang = ''): array
    {
        return Multilingual::with_language($lang, fn (): array => $this->collect_taxonomy_urls($taxonomy, $paged, $options, $lang));
    }

    /**
     * Collects taxonomy URLs (runs with the sitemap language active)
     *
     * @param string $taxonomy Taxonomy name
     * @param int $paged Page number
     * @param array $options Settings
     * @param string $lang Language slug, empty for all
     * @return array URLs
     */
    private function collect_taxonomy_urls(string $taxonomy, int $paged, array $options, string $lang): array
    {
        $urls   = [];
        $number = 500;
        $offset = ($paged - 1) * $number;

        $terms = get_terms(
            array_merge(
                [
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => true,
                    'number'     => $number,
                    'offset'     => $offset,
                ],
                '' !== $lang ? Multilingual::query_args($lang) : [],
            ),
        );

        if (is_wp_error($terms)) {
            return [];
        }

        foreach ($terms as $term) {
            $term_link = get_term_link($term);

            if (is_wp_error($term_link)) {
                continue;
            }

            if ('' !== $lang) {
                $term_link = Multilingual::localize_url($term_link, $lang);
            }

            if ($this->is_excluded($term_link, $options)) {
                continue;
            }

            $url_data = [
                'loc'        => $term_link,
                'lastmod'    => gmdate('Y-m-d'),
                'changefreq' => 'weekly',
                'priority'   => '0.4',
            ];

            $alternates = $this->alternates()->for_term($term);
            if ([] !== $alternates) {
                $url_data['alternates'] = $alternates;
            }

            $urls[] = $url_data;
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
     * Post types scanned for videos (enabled in the sitemap settings)
     *
     * @param array $options Settings
     * @return string[] Post types
     */
    private function get_video_post_types(array $options): array
    {
        $types = get_post_types(['public' => true], 'names');
        unset($types['attachment']);

        return array_values(array_filter($types, fn (string $pt): bool => $this->is_enabled($pt, $options)));
    }

    /**
     * Retrieves URLs of posts embedding videos
     *
     * @param int $paged Page number
     * @param array $options Settings
     * @return array URLs
     */
    private function get_video_urls(int $paged, array $options): array
    {
        $types = $this->get_video_post_types($options);
        $ids   = $this->media()->videoPostIds($types, $paged);

        if ($ids === []) {
            return [];
        }

        $posts = get_posts(
            [
                'post__in'       => $ids,
                'post_type'      => $types,
                'post_status'    => 'publish',
                'orderby'        => 'post__in',
                'posts_per_page' => count($ids),
                'no_found_rows'  => true,
            ],
        );

        $urls = [];

        foreach ($posts as $post) {
            $permalink = get_permalink($post);

            if ($this->is_excluded($permalink, $options) || get_post_meta($post->ID, '_seopulse_exclude_sitemap', true) || \SEOPulse\Services\WooCommerceSystemPages::isSystemPage($post->ID)) {
                continue;
            }

            $videos = $this->media()->videos($post);

            if ($videos !== []) {
                $urls[] = [
                    'loc'     => $permalink,
                    'lastmod' => get_the_modified_time('Y-m-d', $post),
                    'videos'  => $videos,
                ];
            }
        }

        return $urls;
    }

    /**
     * Renders a <video:video> block
     *
     * @param array $video Video data
     * @return string XML
     */
    private function render_video(array $video): string
    {
        $xml = '  <video:video>' . "\n";

        $xml .= '    <video:thumbnail_loc>' . esc_url($video['thumbnail_loc']) . '</video:thumbnail_loc>' . "\n";
        $xml .= '    <video:title>' . esc_xml($video['title']) . '</video:title>' . "\n";
        $xml .= '    <video:description>' . esc_xml($video['description']) . '</video:description>' . "\n";

        if (!empty($video['content_loc'])) {
            $xml .= '    <video:content_loc>' . esc_url($video['content_loc']) . '</video:content_loc>' . "\n";
        }

        if (!empty($video['player_loc'])) {
            $xml .= '    <video:player_loc>' . esc_url($video['player_loc']) . '</video:player_loc>' . "\n";
        }

        if (!empty($video['duration'])) {
            $xml .= '    <video:duration>' . (int) $video['duration'] . '</video:duration>' . "\n";
        }

        if (!empty($video['publication_date'])) {
            $xml .= '    <video:publication_date>' . esc_xml($video['publication_date']) . '</video:publication_date>' . "\n";
        }

        return $xml . '  </video:video>' . "\n";
    }

    /**
     * Retrieves images from a post
     *
     * @param int $post_id Post ID
     * @return array Images
     */
    private function get_post_images(int $post_id): array
    {
        $post = get_post($post_id);

        if (!$post) {
            return [];
        }

        $options = $this->get_options();

        return array_values(
            array_filter(
                $this->media()->images($post),
                fn (array $image): bool => !$this->is_excluded($image['loc'], $options),
            ),
        );
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
