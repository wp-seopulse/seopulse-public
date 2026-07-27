<?php

/**
 * WooCommerce product SEO meta box — React mount point
 *
 * The actual UI (title/description editor, SERP preview, variable picker,
 * social tab, robots, canonical, focus keyword + score) is rendered by the
 * `product-metabox` React bundle into #seopulse-product-metabox-root.
 *
 * @var WP_Post $post
 * @since 1.5.2
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="seopulse-product-metabox">
    <div id="seopulse-product-metabox-root">
        <p class="seopulse-product-metabox__placeholder">
            <?php esc_html_e('Loading SEOPulse…', 'seopulse'); ?>
        </p>
    </div>
</div>