<?php

/**
 * Post variable provider (post.*).
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\MetaSeo\Engine\ContextBag;
use SEOPulse\Modules\MetaSeo\Engine\VariableDefinition;
use SEOPulse\Modules\MetaSeo\Engine\VariableProviderInterface;
use WP_Post;

/**
 * PostProvider — resolves post.* variables.
 */
final class PostProvider implements VariableProviderInterface
{
    private const VARIABLES = [
        'title',
        'excerpt',
        'content',
        'date',
        'modified',
        'author',
        'author_first',
        'author_last',
        'id',
        'slug',
        'url',
        'type',
        'status',
        'thumbnail',
        'thumbnail_alt',
        'word_count',
        'reading_time',
        'comment_count',
        'category',
        'categories',
        'tag',
        'tags',
        'parent_title',
    ];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        $post = $context->getPost();

        if (!$post instanceof WP_Post) {
            return null;
        }

        return match ($variable) {
            'title'         => get_the_title($post),
            'excerpt'       => $this->getExcerpt($post),
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP filter.
            'content'       => wp_strip_all_tags((string) apply_filters('the_content', $post->post_content)),
            'date'          => (string) get_the_date('', $post),
            'modified'      => (string) get_the_modified_date('', $post),
            'author'        => (string) get_the_author_meta('display_name', (int) $post->post_author),
            'author_first'  => (string) get_the_author_meta('first_name', (int) $post->post_author),
            'author_last'   => (string) get_the_author_meta('last_name', (int) $post->post_author),
            'id'            => (string) $post->ID,
            'slug'          => $post->post_name,
            'url'           => (string) get_permalink($post),
            'type'          => $post->post_type,
            'status'        => $post->post_status,
            'thumbnail'     => (string) get_the_post_thumbnail_url($post, 'large'),
            'thumbnail_alt' => $this->getThumbnailAlt($post),
            'word_count'    => (string) str_word_count(wp_strip_all_tags($post->post_content)),
            'reading_time'  => $this->getReadingTime($post),
            'comment_count' => (string) ($post->comment_count ?? 0),
            'category'      => $this->getFirstTerm($post->ID, 'category'),
            'categories'    => $this->getTermList($post->ID, 'category'),
            'tag'           => $this->getFirstTerm($post->ID, 'post_tag'),
            'tags'          => $this->getTermList($post->ID, 'post_tag'),
            'parent_title'  => $post->post_parent ? get_the_title($post->post_parent) : '',
            default         => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('title', 'Post title', 'My Blog Post', 'post'),
            new VariableDefinition('excerpt', 'Post excerpt (auto-generated if empty)', 'Lorem ipsum dolor sit amet…', 'post'),
            new VariableDefinition('content', 'Post content (HTML stripped)', 'Full text content…', 'post'),
            new VariableDefinition('date', 'Publication date', 'March 2, 2026', 'post'),
            new VariableDefinition('modified', 'Last modification date', 'March 2, 2026', 'post'),
            new VariableDefinition('author', 'Author display name', 'John Doe', 'post'),
            new VariableDefinition('author_first', 'Author first name', 'John', 'post'),
            new VariableDefinition('author_last', 'Author last name', 'Doe', 'post'),
            new VariableDefinition('id', 'Post ID', '42', 'post'),
            new VariableDefinition('slug', 'Post slug', 'my-blog-post', 'post'),
            new VariableDefinition('url', 'Post permalink', 'https://example.com/my-blog-post/', 'post'),
            new VariableDefinition('type', 'Post type slug', 'post', 'post'),
            new VariableDefinition('status', 'Post status', 'publish', 'post'),
            new VariableDefinition('thumbnail', 'Featured image URL', 'https://example.com/image.jpg', 'post'),
            new VariableDefinition('thumbnail_alt', 'Featured image alt text', 'Blog header image', 'post'),
            new VariableDefinition('word_count', 'Content word count', '1250', 'post'),
            new VariableDefinition('reading_time', 'Estimated reading time', '5 min', 'post'),
            new VariableDefinition('comment_count', 'Number of comments', '12', 'post'),
            new VariableDefinition('category', 'Primary category name', 'Technology', 'post'),
            new VariableDefinition('categories', 'All categories (comma-separated)', 'Tech, WordPress', 'post'),
            new VariableDefinition('tag', 'Primary tag name', 'SEO', 'post'),
            new VariableDefinition('tags', 'All tags (comma-separated)', 'SEO, WordPress, Meta', 'post'),
            new VariableDefinition('parent_title', 'Parent post/page title', 'Parent Page', 'post'),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function getExcerpt(WP_Post $post): string
    {
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags($post->post_excerpt);
        }

        return wp_trim_words(wp_strip_all_tags($post->post_content), 30, '…');
    }

    private function getThumbnailAlt(WP_Post $post): string
    {
        $thumbId = get_post_thumbnail_id($post);

        if (!$thumbId) {
            return '';
        }

        return (string) get_post_meta((int) $thumbId, '_wp_attachment_image_alt', true);
    }

    private function getReadingTime(WP_Post $post): string
    {
        $words   = str_word_count(wp_strip_all_tags($post->post_content));
        $minutes = max(1, (int) ceil($words / 200));

        return sprintf(
            /* translators: %d: number of minutes */
            _n('%d min', '%d min', $minutes, 'seopulse'),
            $minutes,
        );
    }

    private function getFirstTerm(int $postId, string $taxonomy): string
    {
        $terms = get_the_terms($postId, $taxonomy);

        return (is_array($terms) && !empty($terms)) ? $terms[0]->name : '';
    }

    private function getTermList(int $postId, string $taxonomy): string
    {
        $terms = get_the_terms($postId, $taxonomy);

        if (!is_array($terms)) {
            return '';
        }

        return implode(', ', wp_list_pluck($terms, 'name'));
    }
}
