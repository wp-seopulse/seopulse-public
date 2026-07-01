<?php

/**
 * Google News fields in metabox view
 * File: includes/modules/sitemap/view/view-sitemap-news-metabox.php
 *
 * Displays only the HTML structure for Google News fields
  * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display Google News fields in metabox
 *
 * @param WP_Post $post Current post
 * @return void
 */
function seopulse_sitemap_render_news_fields( $post ) {
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$options = get_option( 'seopulse_sitemap_settings', array() );

	// Display only if Google News is enabled
	if ( empty( $options['enable_news_sitemap'] ) ) {
		return;
	}

	?>

<hr style="margin: 15px 0;">

<h4 style="margin: 15px 0 10px;">
	<?php esc_html_e( 'Google News', 'seopulse' ); ?>
</h4>

<p class="description" style="background: #e7f3ff; padding: 10px; border-left: 4px solid #2196F3;">
	ℹ️
	<?php esc_html_e( 'This article will be included in the Google News sitemap if published recently.', 'seopulse' ); ?>
</p>
	<?php
}

?>