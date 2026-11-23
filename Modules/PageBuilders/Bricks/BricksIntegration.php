<?php

/**
 * Bricks Builder adapter for the Page Builders Integration module.
 *
 * Bricks edits the page on the site's front end: the builder UI is the
 * public URL of the edited post loaded with `?bricks=run` (the "main"
 * frame, which also renders wp_head()/wp_footer()), and the canvas is an
 * iframe of the same URL. There is no documented API to dock a third-party
 * panel into Bricks' own toolbar, so — like the Live Composer adapter — the
 * bundle is enqueued on `wp_enqueue_scripts` and mounts into an empty root
 * printed on `wp_footer`, outside Bricks' own Vue root.
 *
 * Bricks also prints its own meta description/robots from its page
 * settings; those are disabled so they never duplicate SEOPulse's output.
 *
 * @package SEOPulse\Modules\PageBuilders\Bricks
 * @since 1.9.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\PageBuilders\Bricks;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\PageBuilders\BuilderDetector;
use SEOPulse\Modules\PageBuilders\PageBuildersModule;

/**
 * BricksIntegration class
 */
class BricksIntegration
{
    /**
     * Container ID the JS bundle mounts its React app into.
     */
    public const PANEL_ROOT_ID = 'seopulse-bricks-panel-root';

    /**
     * Registers Bricks hooks — no-op if Bricks isn't active/enabled.
     *
     * @return void
     */
    public function hooks(): void
    {
        // Bricks is a theme: its constants only exist once functions.php has loaded.
        add_action('after_setup_theme', [$this, 'register'], 20);
    }

    /**
     * Registers the Bricks hooks once the active theme is loaded.
     *
     * @return void
     */
    public function register(): void
    {
        if (!BuilderDetector::isBricksActive() || !PageBuildersModule::isBuilderEnabled('bricks')) {
            return;
        }

        // UNVERIFIED against a live Bricks install: filter name taken from Bricks' own SEO-plugin compatibility layer.
        add_filter('bricks/frontend/disable_seo', '__return_true');

        add_action('wp_enqueue_scripts', [$this, 'enqueue_panel_assets'], 20);
        add_action('wp_footer', [$this, 'render_panel_root'], 5);
    }

    /**
     * Resolves the post being edited when the current request is the Bricks
     * builder main frame and the user may edit it; 0 otherwise.
     *
     * @return int
     */
    private function getEditedPostId(): int
    {
        if (!is_user_logged_in()) {
            return 0;
        }

        if (function_exists('bricks_is_builder_main')) {
            $is_builder = (bool) bricks_is_builder_main();
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $is_builder = isset($_GET['bricks']) && 'run' === $_GET['bricks'];
        }

        if (!$is_builder) {
            return 0;
        }

        $post_id = (int) get_queried_object_id();

        // Bricks templates are layout parts, not indexable content.
        if (!$post_id || 'bricks_template' === get_post_type($post_id) || !current_user_can('edit_post', $post_id)) {
            return 0;
        }

        return $post_id;
    }

    /**
     * Enqueues the Bricks builder bundle.
     *
     * @return void
     */
    public function enqueue_panel_assets(): void
    {
        $post_id = $this->getEditedPostId();

        if (!$post_id) {
            return;
        }

        $asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-bricks.asset.php';

        if (!file_exists($asset_path)) {
            return;
        }

        $asset = require $asset_path;

        wp_enqueue_script(
            'seopulse-page-builder-bricks',
            SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-bricks.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-page-builder-bricks', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        if (!wp_style_is('seopulse-admin-global', 'enqueued')) {
            wp_enqueue_style(
                'seopulse-admin-global',
                SEOPULSE_PLUGIN_URL . 'assets/css/seopulse.min.css',
                [],
                SEOPULSE_VERSION,
            );
        }

        if (file_exists(SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-bricks.css')) {
            wp_enqueue_style(
                'seopulse-page-builder-bricks',
                SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-bricks.css',
                ['wp-components', 'seopulse-admin-global'],
                $asset['version'],
            );
        }

        wp_localize_script(
            'seopulse-page-builder-bricks',
            'seopulseAdmin',
            [
                'restUrl'     => rest_url('seopulse/v1'),
                'nonce'       => wp_create_nonce('wp_rest'),
                'postId'      => $post_id,
                'panelRootId' => self::PANEL_ROOT_ID,
            ],
        );
    }

    /**
     * Prints the empty mount point the JS bundle attaches its React app to.
     *
     * @return void
     */
    public function render_panel_root(): void
    {
        $post_id = $this->getEditedPostId();

        if (!$post_id) {
            return;
        }

        printf(
            '<div id="%s" class="seopulse-bricks-panel-root" data-post-id="%d"></div>',
            esc_attr(self::PANEL_ROOT_ID),
            (int) $post_id,
        );
    }
}
