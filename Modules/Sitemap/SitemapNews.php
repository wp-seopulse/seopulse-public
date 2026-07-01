<?php

/**
 * Sitemap Google News
 *
 * Generates a dedicated sitemap for Google News with recent articles
 *
 * @package SEOPulse\Modules\Sitemap
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SitemapNews class
 */
class SitemapNews {

	/**
	 * Cache key for the news sitemap
	 */
	private const CACHE_KEY = 'seopulse_sitemap_news';

	/**
	 * Cache duration (30 minutes)
	 */
	private const CACHE_DURATION = 1800;

	/**
	 * Initializes hooks
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( $this, 'handle_news_sitemap' ) );
		add_filter( 'seopulse_sitemap_list', array( $this, 'add_to_sitemap_index' ) );
		add_action( 'save_post', array( $this, 'clear_cache' ) );
		add_action( 'delete_post', array( $this, 'clear_cache' ) );
		add_action( 'seopulse_sitemap_clear_cache', array( $this, 'clear_cache' ) );
	}

	/**
	 * Adds rewrite rules
	 *
	 * @return void
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^sitemap-news\.xml$', 'index.php?seopulse_sitemap_news=1', 'top' );
		add_rewrite_tag( '%seopulse_sitemap_news%', '([0-9]+)' );
	}

	/**
	 * Handles news sitemap requests
	 *
	 * @return void
	 */
	public function handle_news_sitemap(): void {
		$query_var = get_query_var( 'seopulse_sitemap_news' );

		// Strict validation
		if ( ! $query_var || absint( $query_var ) !== 1 ) {
			return;
		}

		// Appropriate headers
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow' );

		// Generate and output sitemap
		$sitemap = $this->generate_news_sitemap();

		// Security: Ensure valid XML before output
		if ( $this->is_valid_xml( $sitemap ) ) {
			echo $sitemap; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML validated
		} else {
			status_header( 500 );
			echo '<?xml version="1.0" encoding="UTF-8"?><error>'
				. esc_html__( 'Invalid sitemap', 'seopulse' )
				. '</error>';
		}

		exit;
	}

	/**
	 * Generates the Google News sitemap
	 *
	 * @return string XML content
	 */
	public function generate_news_sitemap(): string {
		$cache_key = self::CACHE_KEY;
		$cached    = get_transient( $cache_key );

		// Return cache if available (except in debug mode)
		if ( $cached && ! $this->is_debug_mode() ) {
			return $cached;
		}

		// Start XML
		$xsl_url = SEOPULSE_PLUGIN_URL . 'Modules/Sitemap/assets/xsl/sitemap-style.xsl';
		$xml     = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml    .= '<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl_url ) . '"?>' . "\n";
		$xml    .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$xml    .= '        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		// Get recent posts within configured window
		$posts = $this->get_recent_posts();

		if ( empty( $posts ) ) {
			$xml .= '<!-- No articles published in the last 48 hours -->' . "\n";
		}

		foreach ( $posts as $post ) {
			// Check if post is excluded
			if ( get_post_meta( $post->ID, '_seopulse_exclude_sitemap', true ) ) {
				continue;
			}

			// Get necessary information
			$publication_name = $this->get_publication_name();
			$language         = $this->get_post_language( $post->ID );

			$xml .= '<url>' . "\n";
			$xml .= '  <loc>' . esc_url( get_permalink( $post ) ) . '</loc>' . "\n";
			$xml .= '  <news:news>' . "\n";
			$xml .= '    <news:publication>' . "\n";
			$xml .= '      <news:name>' . $this->esc_xml( $publication_name ) . '</news:name>' . "\n";
			$xml .= '      <news:language>' . $this->esc_xml( $language ) . '</news:language>' . "\n";
			$xml .= '    </news:publication>' . "\n";
			$xml .= '    <news:publication_date>' . esc_xml( get_the_date( 'c', $post ) ) . '</news:publication_date>' . "\n";
			$xml .= '    <news:title>' . $this->esc_xml( get_the_title( $post ) ) . '</news:title>' . "\n";
			$xml .= '  </news:news>' . "\n";
			$xml .= '</url>' . "\n";
		}

		$xml .= '</urlset>';

		// Cache for 30 minutes only (recent changing articles)
		set_transient( $cache_key, $xml, self::CACHE_DURATION );

		return $xml;
	}

	/**
	 * Retrieves posts from the last 48 hours
	 *
	 * @return array List of WP_Post
	 */
	private function get_recent_posts(): array {
		$args = array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
            // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'posts_per_page'         => 1000, // Google News limit
			'date_query'             => array(
				array(
					'after'     => $this->get_news_window() . ' hours ago',
					'inclusive' => true,
				),
			),
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => true,
		);

		// Allow filtering arguments via hooks
		$args = apply_filters( 'seopulse_news_sitemap_query_args', $args );

		// Security check: ensure posts_per_page is not too high
		$args['posts_per_page'] = min( absint( $args['posts_per_page'] ), 1000 );

		$posts = get_posts( $args );

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Retrieves the publication name
	 *
	 * @return string Publication name
	 */
	private function get_publication_name(): string {
		$options = get_option( 'seopulse_sitemap_settings', array() );

		// Use custom name if defined
		if ( ! empty( $options['news_publication_name'] ) ) {
			return sanitize_text_field( $options['news_publication_name'] );
		}

		// Otherwise use site name
		return get_bloginfo( 'name' );
	}

	/**
	 * Returns the news window in hours from settings
	 *
	 * @return int Hours
	 */
	private function get_news_window(): int {
		$options = get_option( 'seopulse_sitemap_settings', array() );
		$days    = isset( $options['news_sitemap_days'] ) ? absint( $options['news_sitemap_days'] ) : 2;

		// Clamp between 1 and 30 days
		$days = max( 1, min( 30, $days ) );

		return $days * 24;
	}

	/**
	 * Retrieves the post language
	 *
	 * @param int $post_id Post ID
	 * @return string Language code (e.g., fr, en, es)
	 */
	private function get_post_language( int $post_id ): string {
		// Support WPML
		if ( function_exists( 'wpml_get_language_information' ) ) {
			$lang_info = wpml_get_language_information( $post_id );
			if ( ! is_wp_error( $lang_info ) && isset( $lang_info['locale'] ) ) {
				return substr( sanitize_text_field( $lang_info['locale'] ), 0, 2 );
			}
		}

		// Support Polylang
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post_id );
			if ( $lang ) {
				return substr( sanitize_text_field( $lang ), 0, 2 );
			}
		}

		// Support TranslatePress
		if ( function_exists( 'trp_get_current_language' ) ) {
			$lang = trp_get_current_language();
			if ( ! empty( $lang ) ) {
				return substr( sanitize_text_field( $lang ), 0, 2 );
			}
		}

		// Default site language
		$locale = get_locale();

		return substr( $locale, 0, 2 );
	}

	/**
	 * Adds the news sitemap to the main index
	 *
	 * @param array $sitemaps Existing list
	 * @return array Modified list
	 */
	public function add_to_sitemap_index( array $sitemaps ): array {
		$options = get_option( 'seopulse_sitemap_settings', array() );

		// Add only if enabled in options
		if ( empty( $options['enable_news_sitemap'] ) ) {
			return $sitemaps;
		}

		// Check if there are recent posts
		$recent_posts = $this->get_recent_posts();
		if ( empty( $recent_posts ) ) {
			return $sitemaps;
		}

		$sitemaps[] = array(
			'loc'     => home_url( '/sitemap-news.xml' ),
			'lastmod' => get_the_date( 'Y-m-d', $recent_posts[0] ),
		);

		return $sitemaps;
	}

	/**
	 * Clears the cache
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Checks if debug mode is active
	 *
	 * @return bool
	 */
	private function is_debug_mode(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG &&
			isset( $_GET['debug_sitemap'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'1' === sanitize_text_field( wp_unslash( $_GET['debug_sitemap'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Escapes special XML characters
	 *
	 * @param string $text Text to escape
	 * @return string Escaped text
	 */
	private function esc_xml( string $text ): string {
		if ( empty( $text ) ) {
			return '';
		}

		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Validates an XML string
	 *
	 * @param string $xml XML string
	 * @return bool True if valid
	 */
	private function is_valid_xml( string $xml ): bool {
		if ( empty( $xml ) ) {
			return false;
		}

		libxml_use_internal_errors( true );
		$doc    = new \DOMDocument();
		$result = $doc->loadXML( $xml );
		libxml_clear_errors();

		return $result !== false;
	}

	/**
	 * Retrieves news sitemap statistics
	 *
	 * @return array Statistics
	 */
	public function get_news_stats(): array {
		$recent_posts = $this->get_recent_posts();

		return array(
			'total' => count( $recent_posts ),
		);
	}
}
