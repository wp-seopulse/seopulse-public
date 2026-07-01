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
class MetaSeoDefaults {

	/**
	 * Gets default values for a post
	 *
	 * @param WP_Post $post WordPress post
	 * @param array   $meta Existing meta (can be empty)
	 * @return array Default values
	 */
	public static function get_post_defaults( WP_Post $post, array $meta ): array {
		$post_title       = get_the_title( $post->ID );
		$post_excerpt     = get_the_excerpt( $post );
		$blog_description = get_bloginfo( 'description' );
		$thumbnail_url    = get_the_post_thumbnail_url( $post->ID, 'large' );

		return array(
			'title'               => $meta['title'] ?? $post_title,
			'description'         => $meta['description'] ?? ( $post_excerpt ?: $blog_description ),
			'keywords'            => $meta['keywords'] ?? '',
			'robots'              => $meta['robots'] ?? 'index,follow',
			'canonical'           => $meta['canonical'] ?? get_permalink( $post->ID ),

			// Open Graph
			'og_title'            => $meta['og_title'] ?? ( $meta['title'] ?? $post_title ),
			'og_description'      => $meta['og_description'] ?? ( $meta['description'] ?? $post_excerpt ),
			'og_image'            => $meta['og_image'] ?? $thumbnail_url,
			'og_url'              => $meta['og_url'] ?? get_permalink( $post->ID ),
			'og_type'             => $meta['og_type'] ?? 'article',
			'og_site_name'        => $meta['og_site_name'] ?? get_bloginfo( 'name' ),

			// Twitter
			'twitter_card'        => $meta['twitter_card'] ?? 'summary_large_image',
			'twitter_title'       => $meta['twitter_title'] ?? ( $meta['title'] ?? $post_title ),
			'twitter_description' => $meta['twitter_description'] ?? ( $meta['description'] ?? $post_excerpt ),
			'twitter_image'       => $meta['twitter_image'] ?? $thumbnail_url,
			'twitter_site'        => $meta['twitter_site'] ?? '',
			'twitter_creator'     => $meta['twitter_creator'] ?? '',
		);
	}

	/**
	 * Gets options for the robots field
	 *
	 * @return array Robots options
	 */
	public static function get_robots_options(): array {
		return array(
			'index,follow'     => __( 'Index, Follow', 'seopulse' ),
			'noindex,follow'   => __( 'No Index, Follow', 'seopulse' ),
			'index,nofollow'   => __( 'Index, No Follow', 'seopulse' ),
			'noindex,nofollow' => __( 'No Index, No Follow', 'seopulse' ),
		);
	}

	/**
	 * Gets options for the OG type
	 *
	 * @return array OG type options
	 */
	public static function get_og_type_options(): array {
		return array(
			'website' => __( 'Website', 'seopulse' ),
			'article' => __( 'Article', 'seopulse' ),
			'product' => __( 'Product', 'seopulse' ),
			'profile' => __( 'Profile', 'seopulse' ),
		);
	}

	/**
	 * Gets options for the Twitter card type
	 *
	 * @return array Twitter card options
	 */
	public static function get_twitter_card_options(): array {
		return array(
			'summary'             => __( 'Summary', 'seopulse' ),
			'summary_large_image' => __( 'Summary Large Image', 'seopulse' ),
			'app'                 => __( 'App', 'seopulse' ),
			'player'              => __( 'Player', 'seopulse' ),
		);
	}
}
