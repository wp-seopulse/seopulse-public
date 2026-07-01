<?php

/**
 * Local SEO module for SEOPulse
 *
 * @package SEOPulse\Modules\LocalSeo
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\LocalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPulse\Core\Abstracts\Module;
use SEOPulse\Core\Attributes\AsModule;
use SEOPulse\Core\Contracts\ModuleInterface;
use WP_Post;

/**
 * LocalSeoModule class
 *
 * Manages geographic tags and JSON-LD Schema.org
 * for local SEO
 */
#[AsModule(
	key: 'local_seo',
	label: 'Local SEO',
	description: 'Geographic meta tags and JSON-LD Schema.org for local search.',
	icon: 'dashicons-location-alt',
	namespace: 'SEOPulse\\Modules\\LocalSeo\\',
)]
class LocalSeoModule extends Module implements ModuleInterface {

	/**
	 * Renderer instance
	 *
	 * @var LocalSeoRenderer
	 */
	private LocalSeoRenderer $renderer;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->name   = 'local-seo';
		$this->weight = 0.15; // 15% of total score

		// Load dependencies
		require_once __DIR__ . '/LocalSeoRenderer.php';
		require_once __DIR__ . '/LocalSeoDefaults.php';
		require_once __DIR__ . '/LocalSeoValidator.php';

		$this->renderer = new LocalSeoRenderer();
	}

	/**
	 * Registers the module's WordPress hooks
	 *
	 * @return void
	 */
	public function hooks(): void {
		// Frontend: JSON-LD injection
		add_action( 'wp_head', array( $this->renderer, 'inject_jsonld' ), 10 );

		// Admin only
		if ( is_admin() ) {
			// AJAX for JSON-LD verification
			add_action( 'wp_ajax_seopulse_check_jsonld', array( $this, 'ajax_check_jsonld' ) );
		}
	}

	/**
	 * Analyzes a post (required by Module interface)
	 *
	 * @param WP_Post $post Post to analyze
	 * @return array Analysis result
	 */
	public function analyze( WP_Post $post ): array {
		$score           = 0;
		$issues          = array();
		$recommendations = array();

		$settings = get_option( 'seopulse_local_seo_settings', array() );

		// Check if JSON-LD is configured
		if ( empty( $settings ) || ! isset( $settings['@context'] ) ) {
			$score             = 0;
			$issues[]          = array(
				'type'     => 'local_seo_missing',
				'severity' => 'medium',
				'message'  => __( 'No geographic metadata configured.', 'seopulse' ),
			);
			$recommendations[] = array(
				'type'     => 'local_seo_setup',
				'priority' => 'medium',
				'message'  => __( 'Configure your business location and details.', 'seopulse' ),
				'action'   => __( 'Go to SEOPulse > Local SEO to set up local business information.', 'seopulse' ),
			);
		} else {
			// Check essential fields
			$required_fields = array( '@type', 'name' );
			$missing_fields  = array();

			foreach ( $required_fields as $field ) {
				if ( empty( $settings[ $field ] ) ) {
					$missing_fields[] = $field;
				}
			}

			if ( empty( $missing_fields ) ) {
				$score = 100;

				// Bonus for optional fields
				$optional_fields  = array( 'address', 'geo', 'telephone', 'openingHoursSpecification' );
				$present_optional = 0;

				foreach ( $optional_fields as $field ) {
					if ( ! empty( $settings[ $field ] ) ) {
						++$present_optional;
					}
				}

				if ( $present_optional < 2 ) {
					$recommendations[] = array(
						'type'     => 'local_seo_enhance',
						'priority' => 'low',
						'message'  => __( 'Add more details to your local business schema.', 'seopulse' ),
						'action'   => __( 'Include address, phone, GPS coordinates and opening hours.', 'seopulse' ),
					);
				}
			} else {
				$score    = 50;
				$issues[] = array(
					'type'     => 'local_seo_incomplete',
					'severity' => 'medium',
					'message'  => sprintf(
						/* translators: %s: comma-separated list of missing field names */
						__( 'Missing required fields: %s', 'seopulse' ),
						implode( ', ', $missing_fields ),
					),
				);
			}
		}

		return array(
			'score'           => $score,
			'issues'          => $issues,
			'recommendations' => $recommendations,
			'data'            => array(
				'settings_configured' => ! empty( $settings ),
				'jsonld_valid'        => isset( $settings['@context'] ),
				'business_type'       => $settings['@type'] ?? null,
			),
		);
	}

	/**
	 * AJAX: Check for JSON-LD presence
	 *
	 * @return void
	 */
	public function ajax_check_jsonld(): void {
		check_ajax_referer( 'seopulse_local_seo', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seopulse' ) ) );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'URL is required.', 'seopulse' ) ) );
		}

		// Local test
		if ( strpos( $url, home_url() ) === 0 ) {
			$settings   = get_option( 'seopulse_local_seo_settings', array() );
			$has_jsonld = ! empty( $settings ) && isset( $settings['@context'] );

			wp_send_json_success(
				array(
					'has_jsonld' => $has_jsonld,
					'message'    => $has_jsonld
						? __( 'JSON-LD detected on this page.', 'seopulse' )
						: __( 'No JSON-LD detected.', 'seopulse' ),
				)
			);
		}

		// External test
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'SEOPulse/1.0',
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to check URL.', 'seopulse' ) ) );
		}

		$body       = wp_remote_retrieve_body( $response );
		$has_jsonld = strpos( $body, 'application/ld+json' ) !== false;

		wp_send_json_success(
			array(
				'has_jsonld' => $has_jsonld,
				'message'    => $has_jsonld
					? __( 'JSON-LD detected on this page.', 'seopulse' )
					: __( 'No JSON-LD detected.', 'seopulse' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getKey(): string {
		return 'local_seo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function onActivate(): void {
		// No initial setup needed
	}

	/**
	 * {@inheritDoc}
	 */
	public function onDeactivate(): void {
		// No cleanup needed
	}
}
