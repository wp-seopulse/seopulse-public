<?php

/**
 * Article (BlogPosting) schema provider for JSON-LD
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
 * ArticleProvider — generates BlogPosting schema for single posts
 */
final class ArticleProvider implements SchemaProvider
{
    private ?string $error = null;

    /**
     * Get the schema type
     *
     * @return string
     */
    public function get_type(): string
    {
        return 'BlogPosting';
    }

    /**
     * Check if this provider should inject on the current request
     *
     * @return bool
     */
    public function should_inject(): bool
    {
        // Check admin toggle (default: enabled)
        $settings = get_option('seopulse_meta_seo_global', []);
        if (isset($settings['schema_article_enabled']) && !$settings['schema_article_enabled']) {
            return false;
        }

        // Only inject for single posts (not pages)
        return is_singular('post') && !is_page();
    }

    /**
     * Build the BlogPosting schema
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        global $post;

        if (!$post instanceof \WP_Post) {
            return [];
        }

        $headline       = get_the_title($post);
        $description    = $this->get_description($post);
        $image          = $this->get_featured_image($post);
        $author         = $this->get_author_schema($post);
        $date_published = $this->get_date_published($post);
        $date_modified  = $this->get_date_modified($post);

        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'BlogPosting',
            'headline'         => $headline,
            'description'      => $description,
            'datePublished'    => $date_published,
            'dateModified'     => $date_modified,
            'author'           => $author,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => get_permalink($post),
            ],
        ];

        // Add image if available
        if (!empty($image)) {
            $schema['image'] = $image;
        }

        // Add language
        $language = LanguageDetector::locale();
        if (!empty($language)) {
            $schema['inLanguage'] = $language;
        }

        // Add publisher (organization from local SEO settings)
        $publisher = $this->get_publisher_schema();
        if (!empty($publisher)) {
            $schema['publisher'] = $publisher;
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

        if (empty($schema['headline'])) {
            $this->error = 'Missing headline';

            return false;
        }

        if (empty($schema['datePublished'])) {
            $this->error = 'Missing datePublished';

            return false;
        }

        if (empty($schema['author'])) {
            $this->error = 'Missing author';

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
     * Get post description (excerpt or auto-generated from content)
     *
     * @param \WP_Post $post
     * @return string
     */
    private function get_description(\WP_Post $post): string
    {
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
     * Get author as schema
     *
     * @param \WP_Post $post
     * @return array<string, mixed>
     */
    private function get_author_schema(\WP_Post $post): array
    {
        $author_id   = (int) $post->post_author;
        $author_name = get_the_author_meta('display_name', $author_id);

        return [
            '@type' => 'Person',
            'name'  => $author_name,
            'url'   => get_author_posts_url($author_id),
        ];
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

    /**
     * Get publisher organization schema
     *
     * @return array<string, mixed>|array
     */
    private function get_publisher_schema(): array
    {
        $org_name = get_bloginfo('name');
        $org_logo = $this->get_site_logo();

        if (empty($org_name)) {
            return [];
        }

        $publisher = [
            '@type' => 'Organization',
            'name'  => $org_name,
        ];

        if (!empty($org_logo)) {
            $publisher['logo'] = [
                '@type' => 'ImageObject',
                'url'   => $org_logo,
            ];
        }

        return $publisher;
    }

    /**
     * Get site logo URL
     *
     * @return string
     */
    private function get_site_logo(): string
    {
        // Try custom logo
        if (has_custom_logo()) {
            $logo_id  = get_theme_mod('custom_logo');
            $logo_url = wp_get_attachment_image_url($logo_id, 'full');
            if ($logo_url) {
                return $logo_url;
            }
        }

        // Try local SEO settings
        $local_seo = get_option('seopulse_local_seo', []);
        if (!empty($local_seo['logo'])) {
            return $local_seo['logo'];
        }

        return '';
    }
}
