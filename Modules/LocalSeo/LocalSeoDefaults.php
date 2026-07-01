<?php

/**
 * Default values for Local SEO
 *
 * @package SEOPulse\Modules\LocalSeo
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\LocalSeo;

/**
 * LocalSeoDefaults class
 */
class LocalSeoDefaults {

	/**
	 * Get allowed types from Schema.org
	 *
	 * @return array
	 */
	public static function get_allowed_types(): array {
		return array(
			'LocalBusiness'           => __( 'Local Business', 'seopulse' ),
			'Person'                  => __( 'Person', 'seopulse' ),
			'Organization'            => __( 'Organization', 'seopulse' ),
			'Place'                   => __( 'Place', 'seopulse' ),
			'Restaurant'              => __( 'Restaurant', 'seopulse' ),
			'Hotel'                   => __( 'Hotel', 'seopulse' ),
			'Store'                   => __( 'Store', 'seopulse' ),
			'HealthAndBeautyBusiness' => __( 'Health & Beauty', 'seopulse' ),
			'DaySpa'                  => __( 'Day Spa', 'seopulse' ),
		);
	}

	/**
	 * Days of the week for opening hours
	 *
	 * @return array
	 */
	public static function get_days_of_week(): array {
		return array(
			'Monday'    => __( 'Monday', 'seopulse' ),
			'Tuesday'   => __( 'Tuesday', 'seopulse' ),
			'Wednesday' => __( 'Wednesday', 'seopulse' ),
			'Thursday'  => __( 'Thursday', 'seopulse' ),
			'Friday'    => __( 'Friday', 'seopulse' ),
			'Saturday'  => __( 'Saturday', 'seopulse' ),
			'Sunday'    => __( 'Sunday', 'seopulse' ),
		);
	}

	/**
	 * Get default settings
	 *
	 * @return array
	 */
	public static function get_default_settings(): array {
		return array(
			'@context'    => 'https://schema.org',
			'@type'       => 'LocalBusiness',
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url(),
		);
	}
}
