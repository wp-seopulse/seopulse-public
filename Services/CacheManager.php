<?php

/**
 * Cache manager for SEO analyses
 *
 * Uses WordPress transients to optimize performance
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CacheManager class
 */
class CacheManager {

	/**
	 * Prefix for cache keys
	 *
	 * @var string
	 */
	private string $prefix = 'seopulse_analysis_';

	/**
	 * Default cache duration (in seconds)
	 *
	 * @var int
	 */
	private int $default_expiration = 3600; // 1 hour

	/**
	 * Constructor
	 */
	public function __construct() {
		// Retrieve the duration from settings
		$settings                 = get_option( 'seopulse_settings', array() );
		$this->default_expiration = $settings['cache_duration'] ?? 3600;

		// Hook to invalidate cache on post save
		add_action( 'save_post', array( $this, 'invalidate_on_save' ), 10, 1 );
	}

	/**
	 * Retrieves an analysis from cache
	 *
	 * @param int $post_id Post ID
	 * @return array|null Analysis or null if not found/expired
	 */
	public function get_analysis( int $post_id ): ?array {
		$cache_key = $this->get_cache_key( $post_id );
		$cached    = get_transient( $cache_key );

		if ( $cached === false ) {
			return null;
		}

		// Check if the post has been modified since the cache
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		// Use post_modified_gmt (UTC) to compare with cached_at (UTC)
		// post_modified is in local timezone, which skews the comparison
		$post_modified_time = strtotime( $post->post_modified_gmt );
		$cache_time         = $cached['cached_at'] ?? 0;

		// If the post is more recent than the cache, invalidate
		if ( $post_modified_time > $cache_time ) {
			$this->delete_analysis( $post_id );

			return null;
		}

		return $cached['data'] ?? null;
	}

	/**
	 * Saves an analysis in cache
	 *
	 * @param int   $post_id Post ID
	 * @param array $analysis Analysis data
	 * @param int   $expiration Cache duration (optional)
	 * @return bool Success
	 */
	public function set_analysis( int $post_id, array $analysis, int $expiration = null ): bool {
		$cache_key  = $this->get_cache_key( $post_id );
		$expiration = $expiration ?? $this->default_expiration;

		$cache_data = array(
			'data'      => $analysis,
			'cached_at' => time(),
			'post_id'   => $post_id,
		);

		return set_transient( $cache_key, $cache_data, $expiration );
	}

	/**
	 * Deletes an analysis from cache
	 *
	 * @param int $post_id Post ID
	 * @return bool Success
	 */
	public function delete_analysis( int $post_id ): bool {
		$cache_key = $this->get_cache_key( $post_id );

		return delete_transient( $cache_key );
	}

	/**
	 * Invalidates cache on post save
	 *
	 * @param int $post_id Post ID
	 * @return void
	 */
	public function invalidate_on_save( int $post_id ): void {
		// Ignore autosaves and revisions
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$this->delete_analysis( $post_id );
	}

	/**
	 * Generates the cache key for a post
	 *
	 * @param int $post_id Post ID
	 * @return string Cache key
	 */
	private function get_cache_key( int $post_id ): string {
		return $this->prefix . $post_id;
	}

	/**
	 * Cleans all SEOPulse caches
	 *
	 * @return void
	 */
	public function clear_all(): void {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $this->prefix ) . '%',
			),
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_timeout_' . $this->prefix ) . '%',
			),
		);
	}
}
