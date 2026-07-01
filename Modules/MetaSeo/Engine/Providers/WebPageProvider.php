<?php

/**
 * WebPage schema provider for JSON-LD
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\I18n\LanguageDetector;

/**
 * WebPageProvider — generates WebPage schema for pages and singular content
 */
final class WebPageProvider implements SchemaProvider
{
    private ?string $error = null;

    /**
     * Get the schema type
     *
     * @return string
     */
    public function get_type(): string
    {
        return 'WebPage';
    }

    /**
     * Check if this provider should inject on the current request
     *
     * @return bool
     */
    public function should_inject(): bool
    {
        // Check admin toggle — WebPage shares the Article toggle (both are article-type schemas)
        $settings = get_option('seopulse_meta_seo_global', []);
        if (isset($settings['schema_article_enabled']) && !$settings['schema_article_enabled']) {
            return false;
        }

        // Inject for pages, or singular items that are not single posts
        if (is_page()) {
            return true;
        }

        // Also for other singular post types (except 'post')
        if (is_singular() && !is_singular('post')) {
            return true;
        }

        return false;
    }

    /**
     * Build the WebPage schema
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        global $post;

        if (!$post instanceof \WP_Post) {
            return [];
        }

        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'WebPage',
            '@id'           => get_permalink($post),
            'url'           => get_permalink($post),
            'name'          => get_the_title($post),
            'description'   => $this->get_description($post),
            'datePublished' => $this->get_date_published($post),
            'dateModified'  => $this->get_date_modified($post),
        ];

        // Add language
        $language = LanguageDetector::locale();
        if (!empty($language)) {
            $schema['inLanguage'] = $language;
        }

        // Add breadcrumb if available
        $breadcrumb = $this->get_breadcrumb_schema($post);
        if (!empty($breadcrumb)) {
            $schema['breadcrumb'] = $breadcrumb;
        }

        // Add image if featured image exists
        $image = $this->get_featured_image($post);
        if (!empty($image)) {
            $schema['image'] = $image;
        }

        return $schema;
    }

    /**
     * Validate the schema
     *
     * @return bool
     */
    public function validate(): bool
    {
        $schema = $this->build();

        if (empty($schema)) {
            $this->error = 'Schema is empty';

            return false;
        }

        if (empty($schema['name'])) {
            $this->error = 'Missing page name';

            return false;
        }

        if (empty($schema['url'])) {
            $this->error = 'Missing page URL';

            return false;
        }

        return true;
    }

    /**
     * Get error message
     *
     * @return string|null
     */
    public function get_error(): ?string
    {
        return $this->error ?? null;
    }

    /**
     * Get page description
     *
     * @param \WP_Post $post
     * @return string
     */
    private function get_description(\WP_Post $post): string
    {
        // Check for SEOPulse meta description
        $meta_seo = get_post_meta($post->ID, '_seopulse_meta_seo', true);
        if (!empty($meta_seo['description'])) {
            return $meta_seo['description'];
        }

        // Use excerpt if available
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags($post->post_excerpt);
        }

        // Auto-generate from content
        $content = wp_strip_all_tags($post->post_content);

        return wp_trim_words($content, 30, '…');
    }

    /**
     * Get featured image as schema
     *
     * @param \WP_Post $post
     * @return array<string, mixed>|string
     */
    private function get_featured_image(\WP_Post $post)
    {
        $thumb_id = get_post_thumbnail_id($post);

        if (!$thumb_id) {
            return '';
        }

        $image_url = get_the_post_thumbnail_url($post, 'large');
        if (!$image_url) {
            return '';
        }

        // Get image dimensions
        $metadata = wp_get_attachment_metadata($thumb_id);
        $width    = $metadata['width'] ?? 1200;
        $height   = $metadata['height'] ?? 630;

        return [
            '@type'  => 'ImageObject',
            'url'    => $image_url,
            'width'  => $width,
            'height' => $height,
        ];
    }

    /**
     * Get breadcrumb schema
     *
     * @param \WP_Post $post
     * @return array<string, mixed>|array
     */
    private function get_breadcrumb_schema(\WP_Post $post): array
    {
        $breadcrumbs = [];
        $position    = 1;

        // Add home
        $breadcrumbs[] = [
            '@type'    => 'ListItem',
            'position' => $position++,
            'name'     => get_bloginfo('name'),
            'item'     => home_url('/'),
        ];

        // Add parent pages if any (for hierarchical post types)
        if ('page' === $post->post_type && $post->post_parent > 0) {
            $parent_ids = $this->get_page_parents($post->post_parent);

            foreach ($parent_ids as $parent_id) {
                $breadcrumbs[] = [
                    '@type'    => 'ListItem',
                    'position' => $position++,
                    'name'     => get_the_title($parent_id),
                    'item'     => get_permalink($parent_id),
                ];
            }
        }

        // Add current page
        $breadcrumbs[] = [
            '@type'    => 'ListItem',
            'position' => $position++,
            'name'     => get_the_title($post),
            'item'     => get_permalink($post),
        ];

        if (count($breadcrumbs) > 1) {
            return [
                '@type'           => 'BreadcrumbList',
                'itemListElement' => $breadcrumbs,
            ];
        }

        return [];
    }

    /**
     * Get all parent page IDs (ascending order)
     *
     * @param int $page_id
     * @return array<int>
     */
    private function get_page_parents(int $page_id): array
    {
        $parents = [];

        while ($page_id > 0) {
            $page = get_post($page_id);

            if (!$page || 'page' !== $page->post_type) {
                break;
            }

            $parents[] = $page_id;
            $page_id   = $page->post_parent;
        }

        // Reverse to get ascending order (home → parent → child)
        return array_reverse($parents);
    }

    /**
     * Get publication date in ISO 8601 format
     *
     * @param \WP_Post $post
     * @return string
     */
    private function get_date_published(\WP_Post $post): string
    {
        return wp_date('c', strtotime($post->post_date));
    }

    /**
     * Get modification date in ISO 8601 format
     *
     * @param \WP_Post $post
     * @return string
     */
    private function get_date_modified(\WP_Post $post): string
    {
        return wp_date('c', strtotime($post->post_modified));
    }
}
