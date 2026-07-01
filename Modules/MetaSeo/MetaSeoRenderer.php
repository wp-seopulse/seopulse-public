<?php

/**
 * Renderer for meta tags in the <head>
 *
 * Now powered by the Meta Template Engine for dynamic variable resolution.
 * Falls back to legacy behaviour if the engine is unavailable.
 *
 * @package SEOPulse\Modules\MetaSeo
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo;

use SEOPulse\Modules\MetaSeo\Engine\MetaEngine;
use SEOPulse\Modules\MetaSeo\Engine\MetaOutput;

/**
 * MetaSeoRenderer class
 */
class MetaSeoRenderer {

	/**
	 * Meta Engine instance (lazy-loaded).
	 */
	private ?MetaEngine $engine = null;

	/**
	 * Cached MetaOutput for the current request (avoids resolving multiple times).
	 */
	private ?MetaOutput $resolvedMeta = null;

	/**
	 * Renders all meta tags
	 *
	 * @return void
	 */
	public function render_all_tags(): void {
		$output = $this->getResolvedMeta();

		$this->render_basic_meta_tags( $output );
		$this->render_open_graph_tags( $output );
		$this->render_twitter_cards( $output );
		$this->render_canonical( $output );
	}

	/**
	 * Renders basic meta tags
	 *
	 * @param MetaOutput $output Resolved meta
	 * @return void
	 */
	private function render_basic_meta_tags( MetaOutput $output ): void {
		global $post;

		$meta = $this->get_current_meta();

		// Meta description (engine-resolved)
		if ( $output->description !== '' ) {
			echo '<meta name="description" content="' . esc_attr( $output->description ) . '">' . "\n";
		}

		// Meta keywords — post-specific keywords take priority over global template
		$post_keywords = '';
		if ( is_singular() && $post ) {
			$post_meta = get_post_meta( $post->ID, '_seopulse_meta_seo', true );
			if ( is_array( $post_meta ) && ! empty( $post_meta['keywords'] ) ) {
				$post_keywords = $post_meta['keywords'];
			}
		}
		$keywords = $post_keywords !== '' ? $post_keywords : $this->getGlobalTemplateField( 'keywords', $meta['keywords'] ?? '' );
		if ( $keywords !== '' ) {
			echo '<meta name="keywords" content="' . esc_attr( $keywords ) . '">' . "\n";
		}

		// Meta author — read from global templates first, then legacy option
		$author = $this->getGlobalTemplateField( 'author', $meta['author'] ?? '' );
		if ( $author !== '' ) {
			echo '<meta name="author" content="' . esc_attr( $author ) . '">' . "\n";
		}

		// Theme color
		if ( ! empty( $meta['theme_color'] ) ) {
			echo '<meta name="theme-color" content="' . esc_attr( $meta['theme_color'] ) . '">' . "\n";
		}

		// Geographic tags
		$this->render_geo_tags( $meta );
	}

	/**
	 * Renders geographic tags
	 *
	 * @param array $meta Metadata
	 * @return void
	 */
	private function render_geo_tags( array $meta ): void {
		if ( ! empty( $meta['geo_region'] ) ) {
			echo '<meta name="geo.region" content="' . esc_attr( $meta['geo_region'] ) . '">' . "\n";
		}

		if ( ! empty( $meta['geo_placename'] ) ) {
			echo '<meta name="geo.placename" content="' . esc_attr( $meta['geo_placename'] ) . '">' . "\n";
		}

		if ( ! empty( $meta['geo_position'] ) ) {
			echo '<meta name="geo.position" content="' . esc_attr( $meta['geo_position'] ) . '">' . "\n";
		}
	}

	/**
	 * Renders Open Graph tags
	 *
	 * @param MetaOutput $output Resolved meta
	 * @return void
	 */
	private function render_open_graph_tags( MetaOutput $output ): void {
		$og_tags = array(
			'og:url'         => $output->canonical,
			'og:site_name'   => get_bloginfo( 'name' ),
			'og:title'       => $output->ogTitle,
			'og:description' => $output->ogDescription,
			'og:image'       => $output->ogImage,
			'og:type'        => $output->ogType,
		);

		foreach ( $og_tags as $property => $content ) {
			if ( $content !== '' ) {
				echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '">' . "\n";
			}
		}
	}

	/**
	 * Renders Twitter Cards tags
	 *
	 * @param MetaOutput $output Resolved meta
	 * @return void
	 */
	private function render_twitter_cards( MetaOutput $output ): void {
		$meta = $this->get_current_meta();

		$twitter_tags = array(
			'twitter:card'        => $output->twitterCard,
			'twitter:site'        => $meta['twitter_site'] ?? '',
			'twitter:creator'     => $meta['twitter_creator'] ?? '',
			'twitter:title'       => $output->twitterTitle,
			'twitter:description' => $output->twitterDescription,
			'twitter:image'       => $output->twitterImage,
		);

		foreach ( $twitter_tags as $name => $content ) {
			if ( $content !== '' ) {
				echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $content ) . '">' . "\n";
			}
		}
	}

	/**
	 * Renders the canonical tag
	 *
	 * @param MetaOutput $output Resolved meta
	 * @return void
	 */
	private function render_canonical( MetaOutput $output ): void {
		if ( $output->canonical !== '' ) {
			echo '<link rel="canonical" href="' . esc_url( $output->canonical ) . '">' . "\n";
		}
	}

	/**
	 * Retrieves current metadata (post + global) — legacy fallback
	 *
	 * @return array Merged metadata
	 */
	private function get_current_meta(): array {
		global $post;

		$global = get_option( 'seopulse_meta_seo_global', array() );
		$meta   = $global;

		if ( is_singular() && $post ) {
			$post_meta = get_post_meta( $post->ID, '_seopulse_meta_seo', true );
			if ( is_array( $post_meta ) ) {
				$meta = array_merge( $global, $post_meta );
			}
		}

		return $meta;
	}

	/**
	 * Get the resolved MetaOutput from the engine (cached per-request).
	 *
	 * @return MetaOutput
	 */
	private function getResolvedMeta(): MetaOutput {
		if ( $this->resolvedMeta === null ) {
			$this->resolvedMeta = $this->getEngine()->resolveAll();
		}

		return $this->resolvedMeta;
	}

	/**
	 * Get the MetaEngine instance.
	 *
	 * @return MetaEngine
	 */
	private function getEngine(): MetaEngine {
		if ( $this->engine === null ) {
			$this->engine = new MetaEngine();
		}

		return $this->engine;
	}

	/**
	 * Get the document title resolved by the engine.
	 *
	 * Used by MetaSeoModule::override_title() to replace WordPress default.
	 *
	 * @return string Resolved title, or empty string if nothing configured.
	 */
	public function getResolvedTitle(): string {
		return $this->getResolvedMeta()->title;
	}

	/**
	 * Get the robots directive resolved by the engine.
	 *
	 * Used by MetaSeoModule::override_robots().
	 *
	 * @return string Robots directive string (e.g. "index,follow").
	 */
	public function getResolvedRobots(): string {
		return $this->getResolvedMeta()->robots;
	}

	/**
	 * Read a field from the global template store, with legacy fallback.
	 *
	 * Priority: global template value > legacy option value.
	 *
	 * @param string $field Field key (e.g. "keywords", "author").
	 * @param string $fallback Legacy fallback value.
	 * @return string
	 */
	private function getGlobalTemplateField( string $field, string $fallback = '' ): string {
		$templates = get_option( 'seopulse_meta_templates', array() );

		if ( is_array( $templates ) && ! empty( $templates['global'][ $field ] ) ) {
			return (string) $templates['global'][ $field ];
		}

		return $fallback;
	}
}
