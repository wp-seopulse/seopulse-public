<?php

/**
 * Redirections module for SEOPulse
 *
 * @package SEOPulse\Modules\Redirections
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Redirections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPulse\Core\Abstracts\Module;
use SEOPulse\Core\Attributes\AsModule;
use SEOPulse\Core\Contracts\ExecuteHooksFrontend;
use SEOPulse\Core\Contracts\ModuleInterface;
use WP_Post;

/**
 * RedirectionsModule class
 *
 * Advanced redirect management with 5 match types (exact, contains,
 * starts_with, ends_with, regex), maintenance codes (410/451),
 * scheduled activation/deactivation, redirect debugger,
 * query-string passthrough, groups, categories, import/export,
 * and hit tracking.
 *
 * Frontend only (template_redirect, wp)
 */
#[AsModule(
	key: 'redirections',
	label: 'Redirections',
	description: 'Advanced redirect management with regex, groups, scheduling, and import/export.',
	icon: 'dashicons-randomize',
	namespace: 'SEOPulse\\Modules\\Redirections\\',
)]
class RedirectionsModule extends Module implements ExecuteHooksFrontend, ModuleInterface {

	/** Valid redirect HTTP codes. */
	public const REDIRECT_CODES = array( 301, 302, 307, 308 );

	/** Valid maintenance HTTP codes. */
	public const MAINTENANCE_CODES = array( 410, 451 );

	/**
	 * Redirections manager
	 *
	 * @var RedirectionsManager
	 */
	private RedirectionsManager $manager;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->name   = 'redirections';
		$this->weight = 0.10; // 10% du score total

		// Load dependencies
		require_once __DIR__ . '/RedirectionsManager.php';
		require_once __DIR__ . '/RegexRedirectEngine.php';
		require_once __DIR__ . '/RedirectRepository.php';

		$this->manager = new RedirectionsManager();
	}

	/**
	 * Registers the module's WordPress hooks
	 *
	 * @return void
	 */
	public function hooks(): void {
		// Frontend: Apply redirections (advanced matching)
		add_action( 'template_redirect', array( $this->manager, 'apply_redirects' ), 1 );
	}

	/**
	 * Analyzes a post (required by the Module interface)
	 *
	 * @param WP_Post $post Post to analyze
	 * @return array Analysis result
	 */
	public function analyze( WP_Post $post ): array {
		$score           = 100;
		$issues          = array();
		$recommendations = array();

		// Check if the post has a configured redirect
		$redirect_url = get_post_meta( $post->ID, '_seopulse_redirect_url', true );

		if ( ! empty( $redirect_url ) ) {
			$redirect_type = get_post_meta( $post->ID, '_seopulse_redirect_type', true );

			$recommendations[] = array(
				'type'     => 'redirect_active',
				'priority' => 'low',
				'message'  => sprintf(
					/* translators: 1: redirect type (e.g. 301), 2: redirect URL */
					__( 'This post has a %1$s redirect to: %2$s', 'seopulse' ),
					$redirect_type ?: '301',
					$redirect_url,
				),
				'action'   => __( 'Redirects are useful for maintaining SEO when moving or merging content.', 'seopulse' ),
			);
		}

		return array(
			'score'           => $score,
			'issues'          => $issues,
			'recommendations' => $recommendations,
			'data'            => array(
				'has_redirect'  => ! empty( $redirect_url ),
				'redirect_type' => $redirect_url ? ( $redirect_type ?: '301' ) : null,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getKey(): string {
		return 'redirections';
	}

	/**
	 * {@inheritDoc}
	 */
	public function onActivate(): void {
		// Redirect table is created by the Installer.
	}

	/**
	 * {@inheritDoc}
	 */
	public function onDeactivate(): void {
	}
}
