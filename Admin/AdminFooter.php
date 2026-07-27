<?php

/**
 * Admin footer management
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Contracts\ExecuteHooksAdmin;

/**
 * AdminFooter class
 */
class AdminFooter implements ExecuteHooksAdmin
{
    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_footer', [$this, 'add_root_element']);
    }

    /**
     * Determines whether the #seopulse-root mount point will be rendered
     * on the current screen. Kept public/static so callers that need to
     * decide whether to enqueue the editor bundle (e.g. Assets::enqueue_editor_assets())
     * can reuse the exact same logic instead of duplicating it.
     *
     * @return bool
     */
    public static function should_render_root(): bool
    {
        $screen = get_current_screen();

        if (!$screen) {
            return false;
        }

        $allowed_screens = ['post', 'page', 'toplevel_page_seopulse'];

        if (!in_array($screen->base, $allowed_screens, true)) {
            return false;
        }

        // Never show on attachment edit screen
        if (isset($screen->post_type) && $screen->post_type === 'attachment') {
            return false;
        }

        // In the block editor the sidebar handles the analysis UI;
        // rendering the React root here would duplicate results.
        if ($screen->is_block_editor()) {
            return false;
        }

        // On the WooCommerce product edit screen, the dedicated product
        // metabox (Score tab) already renders the AnalysisPanel — avoid
        // showing it twice on the same page.
        if (isset($screen->post_type) && $screen->post_type === 'product') {
            return false;
        }

        return true;
    }

    /**
     * Adds the root element in the footer
     *
     * @return void
     */
    public function add_root_element(): void
    {
        if (!self::should_render_root()) {
            return;
        }

        echo '<div id="seopulse-root"></div>';
    }
}
