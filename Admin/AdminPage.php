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
     * SEOPulse logo used as the admin menu icon (20×20 viewBox).
     *
     * The pulse line is a filled outline (not a stroke) 44 units wide,
     * versus 58 in the original logo, so WordPress can repaint it.
     *
     * The fill (#a7aaad) is the default WordPress menu icon color:
     * WordPress repaints SVG data URIs automatically to match the
     * admin color scheme (normal, hover and active states).
     *
     * @var string
     */
    private const MENU_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><g transform="translate(-0.15 0) scale(.027) translate(-100 -15)"><g fill="#a7aaad"><path d="M401 32A306 306 0 0 0 185 554L401 770L617 554A306 306 0 0 0 707 338H593A192 192 0 0 1 401 530A192 192 0 0 1 209 338A192 192 0 0 1 401 146Z"/><path d="M475 30H707V262H593V145H475Z"/><path d="M258 368L322 368A22 22 0 0 0 341.7 355.8L366.7 305.7L405.9 438.2A22 22 0 0 0 447.5 440.1L479 360L540 360A22 22 0 0 0 540 316L464 316A22 22 0 0 0 443.5 329.9L430 364.4L393.1 239.8A22 22 0 0 0 352.3 236.2L308.4 324L258 324A22 22 0 0 0 258 368Z"/></g></g></svg>';

    /**
     * Page slug
     *
     * @var string
     */
    private string $page_slug = 'seopulse';

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
     * Returns the menu icon as a base64 SVG data URI.
     *
     * @return string
     */
    private function get_menu_icon(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::MENU_ICON_SVG);
    }

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
            $this->get_menu_icon(),
            65,
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
