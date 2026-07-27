<?php

/**
 * Default values for meta tags
 *
 * @package SEOPulse\Modules\MetaSeo
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo;

use WP_Post;

/**
 * MetaSeoDefaults class
 */
class MetaSeoDefaults
{
    /**
     * Gets default values for a post
     *
     * @param WP_Post $post WordPress post
     * @param array $meta Existing meta (can be empty)
     * @return array Default values
     */
    public static function get_post_defaults(WP_Post $post, array $meta): array
    {
        $post_title       = get_the_title($post->ID);
        $post_excerpt     = get_the_excerpt($post);
        $blog_description = get_bloginfo('description');
        $thumbnail_url    = get_the_post_thumbnail_url($post->ID, 'large');
        $permalink        = self::get_reliable_permalink($post);

        return [
            'title'               => $meta['title'] ?? $post_title,
            'description'         => $meta['description'] ?? ($post_excerpt ?: $blog_description),
            'keywords'            => $meta['keywords'] ?? '',
            'robots'              => $meta['robots'] ?? 'index,follow',
            'canonical'           => $meta['canonical'] ?? $permalink,

            // Open Graph
            'og_title'            => $meta['og_title'] ?? ($meta['title'] ?? $post_title),
            'og_description'      => $meta['og_description'] ?? ($meta['description'] ?? $post_excerpt),
            'og_image'            => $meta['og_image'] ?? $thumbnail_url,
            'og_url'              => $meta['og_url'] ?? $permalink,
            'og_type'             => $meta['og_type'] ?? 'article',
            'og_site_name'        => $meta['og_site_name'] ?? get_bloginfo('name'),

            // Twitter
            'twitter_card'        => $meta['twitter_card'] ?? 'summary_large_image',
            'twitter_title'       => $meta['twitter_title'] ?? ($meta['title'] ?? $post_title),
            'twitter_description' => $meta['twitter_description'] ?? ($meta['description'] ?? $post_excerpt),
            'twitter_image'       => $meta['twitter_image'] ?? $thumbnail_url,
            'twitter_site'        => $meta['twitter_site'] ?? '',
            'twitter_creator'     => $meta['twitter_creator'] ?? '',
        ];
    }

    /**
     * Returns a reliable permalink for the post.
     *
     * `get_permalink()` returns `false` for `auto-draft` posts (e.g. a
     * brand new, never-yet-saved product), which would otherwise leave
     * `canonical`/`og_url` empty in the REST response and the SERP/social
     * preview blank. Falls back to the same "sample permalink" WordPress
     * itself uses to preview the URL of an unpublished post (mirrors the
     * approach already used by the classic metabox view, see
     * Modules/MetaSeo/metabox/metabox-seotags.php).
     *
     * @param WP_Post $post WordPress post
     * @return string Permalink, never empty for a valid post.
     */
    private static function get_reliable_permalink(WP_Post $post): string
    {
        $permalink = get_permalink($post->ID);

        if (is_string($permalink) && $permalink !== '') {
            return $permalink;
        }

        if (!function_exists('get_sample_permalink')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        $sample = get_sample_permalink($post->ID);

        if (is_array($sample) && isset($sample[0], $sample[1])) {
            return str_replace(['%postname%', '%pagename%'], $sample[1], $sample[0]);
        }

        return home_url('/');
    }

    /**
     * Gets options for the robots field
     *
     * @return array Robots options
     */
    public static function get_robots_options(): array
    {
        return [
            'index,follow'     => __('Index, Follow', 'seopulse'),
            'noindex,follow'   => __('No Index, Follow', 'seopulse'),
            'index,nofollow'   => __('Index, No Follow', 'seopulse'),
            'noindex,nofollow' => __('No Index, No Follow', 'seopulse'),
        ];
    }

    /**
     * Gets options for the OG type
     *
     * @return array OG type options
     */
    public static function get_og_type_options(): array
    {
        return [
            'website' => __('Website', 'seopulse'),
            'article' => __('Article', 'seopulse'),
            'product' => __('Product', 'seopulse'),
            'profile' => __('Profile', 'seopulse'),
        ];
    }

    /**
     * Gets options for the Twitter card type
     *
     * @return array Twitter card options
     */
    public static function get_twitter_card_options(): array
    {
        return [
            'summary'             => __('Summary', 'seopulse'),
            'summary_large_image' => __('Summary Large Image', 'seopulse'),
            'app'                 => __('App', 'seopulse'),
            'player'              => __('Player', 'seopulse'),
        ];
    }
}
