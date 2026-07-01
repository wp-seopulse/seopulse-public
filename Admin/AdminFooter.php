<?php

/**
 * Admin footer management
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPulse\Core\Contracts\ExecuteHooksAdmin;

/**
 * AdminFooter class
 */
class AdminFooter implements ExecuteHooksAdmin {

	/**
	 * Registers admin hooks
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_footer', array( $this, 'add_root_element' ) );
	}

	/**
	 * Adds the root element in the footer
	 *
	 * @return void
	 */
	public function add_root_element(): void {
		// Only add it if we're in the editor or SEOPulse pages
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$allowed_screens = array( 'post', 'page', 'toplevel_page_seopulse' );

		if ( ! in_array( $screen->base, $allowed_screens, true ) ) {
			return;
		}

		// Never show on attachment edit screen
		if ( isset( $screen->post_type ) && $screen->post_type === 'attachment' ) {
			return;
		}

		// In the block editor the sidebar handles the analysis UI;
		// rendering the React root here would duplicate results.
		if ( $screen->is_block_editor() ) {
			return;
		}

		echo '<div id="seopulse-root"></div>';
	}
}
