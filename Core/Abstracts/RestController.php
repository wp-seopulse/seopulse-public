<?php

/**
 * Abstract class for REST API controllers
 *
 * @package SEOPulse\Core\Abstracts
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Abstracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPulse\Core\Contracts\ExecuteHooks;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Abstract RestController class
 *
 * Implements ExecuteHooks for auto-registration via the Kernel.
 * The hooks() method registers register_routes() on rest_api_init.
 */
abstract class RestController implements ExecuteHooks {

	/**
	 * API namespace
	 *
	 * @var string
	 */
	protected string $namespace = 'seopulse/v1';

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected string $rest_base = '';

	/**
	 * Registers WordPress hooks (rest_api_init)
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers routes
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Checks permissions (edit_posts level)
	 *
	 * Suitable for content-level operations (analysis, meta editing).
	 *
	 * @param WP_REST_Request $request Request
	 * @return bool|WP_Error
	 */
	public function check_permissions( WP_REST_Request $request ) {
		// By default: the user must be able to edit posts
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'seopulse' ),
				array( 'status' => 403 ),
			);
		}

		return true;
	}

	/**
	 * Checks admin-level permissions (manage_options)
	 *
	 * Suitable for settings, tools, migration, and analytics configuration.
	 * Subclasses should reference this as their permission_callback for admin routes.
	 *
	 * @param WP_REST_Request $request Request
	 * @return bool|WP_Error
	 */
	public function check_admin_permissions( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage plugin settings.', 'seopulse' ),
				array( 'status' => 403 ),
			);
		}

		return true;
	}

	/**
	 * Verifies the nonce
	 *
	 * @param WP_REST_Request $request Request
	 * @return bool
	 */
	protected function verify_nonce( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( empty( $nonce ) ) {
			return false;
		}

		return wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Returns a success response
	 *
	 * @param mixed $data Data
	 * @param int   $status HTTP code
	 * @return WP_REST_Response
	 */
	protected function success( $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			$status
		);
	}

	/**
	 * Returns an error response
	 *
	 * @param string $message Error message
	 * @param int    $status HTTP code
	 * @param array  $data Additional data
	 * @return WP_Error
	 */
	protected function error( string $message, int $status = 400, array $data = array() ): WP_Error {
		return new WP_Error(
			'seopulse_error',
			$message,
			array_merge( array( 'status' => $status ), $data ),
		);
	}
}
