<?php

/**
 * Main SEOPulse administration page
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin;

use SEOPulse\Core\Contracts\ExecuteHooksAdmin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AdminPage class
 */
class AdminPage implements ExecuteHooksAdmin
{
    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('in_admin_header', [$this, 'suppress_third_party_notices']);
    }

    /**
     * Suppresses all third-party admin notices on SEOPulse plugin pages.
     *
     * Fires after all plugins have registered their notices but before
     * they are rendered, so notices from plugins like Elementor,
     * WooCommerce, etc. are removed on SEOPulse-owned screens.
     *
     * @return void
     */
    public function suppress_third_party_notices(): void
    {
        $screen = get_current_screen();

        if (!$screen) {
            return;
        }

        // Target all SEOPulse admin screens (toplevel + subpages)
        if (
            str_contains($screen->id, 'seopulse') ||
            str_contains($screen->base, 'seopulse')
        ) {
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
            remove_all_actions('user_admin_notices');
            remove_all_actions('network_admin_notices');
        }
    }

    /**
     * Page slug
     *
     * @var string
     */
    private string $page_slug = 'seopulse';

    /**
     * Registers the page in the WordPress menu
     *
     * @return void
     */
    public function register_menu(): void
    {
        add_menu_page(
            __('SEOPulse', 'seopulse'),
            __('SEOPulse', 'seopulse'),
            'edit_posts',
            $this->page_slug,
            [$this, 'render_page'],
            SEOPULSE_ASSETS_URL . 'images/logo.svg',
            65,
        );

        wp_add_inline_style(
            'admin-menu',
            '#adminmenu a.toplevel_page_seopulse .dashicons-before { display: flex; align-items: center; justify-content: center; }
     #adminmenu a.toplevel_page_seopulse .wp-menu-image img { width: 20px; height: 20px; display: block; margin: 0; padding: 0; }',
        );

        // Rename the first submenu "SEOPulse" to "Dashboard"
        add_submenu_page(
            $this->page_slug,
            __('Dashboard', 'seopulse'),
            __('Dashboard', 'seopulse'),
            'edit_posts',
            $this->page_slug,
            [$this, 'render_page'],
        );
    }

    /**
     * Renders the React dashboard SPA shell.
     *
     * The <div#seopulse-settings-root> is the mount point for the React SPA
     * bundle enqueued by Core/Assets.php. All dashboard content (KPIs, widgets,
     * panels, and command palette) is rendered client-side by React.
     *
     * @return void
     */
    public function render_page(): void
    {
        ?>
        <div class="seopulse-admin-header-wrapper">
            <div id="seopulse-admin-header-root"></div>
        </div>
        <div class="seopulse-content-page">
            <div id="seopulse-settings-root"></div>
        </div>
<?php
    }
}
