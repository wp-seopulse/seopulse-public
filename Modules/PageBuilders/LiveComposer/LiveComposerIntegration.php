<?php

/**
 * Live Composer adapter for the Page Builders Integration module.
 *
 * Its editing canvas is also architecturally different: LC edits the
 * page on the site's actual front end (see its own
 * includes/scripts.php::dslc_load_scripts_frontend(), which enqueues the
 * builder JS/CSS on `wp_enqueue_scripts` — not `admin_enqueue_scripts` —
 * whenever the global `$dslc_active` flag is true), reached via a
 * dedicated wp-admin wrapper page (`toplevel_page_livecomposer_editor`).
 * Confirmed against Live Composer's own knowledge base: since v1.1 its
 * editing UI is split into that wp-admin "Admin page" (entry point +
 * editing panels) and a "Preview area" iframe rendering the actual page
 * being edited — the same shape as the WP Customizer. So `$dslc_active`
 * becomes true inside that iframe's own front-end request, not on the
 * wp-admin wrapper page itself, which is why this integration hooks
 * `wp_enqueue_scripts`/`wp_footer` rather than any admin-side hook.
 *
 * Given no native panel-registration API exists, this integration mounts
 * our React panel by printing an empty root div on `wp_footer` during an
 * active LC editing session and enqueueing the bundle to find and mount
 * into it — the same "PHP prints the div, JS mounts into it" contract
 * used by ElementorIntegration::register_seo_section(), just without a
 * builder-native container to put that div inside.
 *
 * @package SEOPulse\Modules\PageBuilders\LiveComposer
 * @since 1.7.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\PageBuilders\LiveComposer;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\PageBuilders\BuilderDetector;
use SEOPulse\Modules\PageBuilders\PageBuildersModule;

/**
 * LiveComposerIntegration class
 */
class LiveComposerIntegration
{
    /**
     * Container ID the JS bundle mounts its React app into.
     */
    public const PANEL_ROOT_ID = 'seopulse-live-composer-panel-root';

    /**
     * Registers Live Composer hooks — no-op if Live Composer isn't active/enabled.
     *
     * @return void
     */
    public function hooks(): void
    {
        if (!BuilderDetector::isLiveComposerActive() || !PageBuildersModule::isBuilderEnabled('live_composer')) {
            return;
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_panel_assets'], 20);
        add_action('wp_footer', [$this, 'render_panel_root'], 5);
    }

    /**
     * Whether the current front-end request is an active Live Composer
     * editing session for a post the current user may edit.
     *
     * `DS_LIVE_COMPOSER_CAPABILITY` is the capability constant LC itself
     * checks alongside `$dslc_active` in dslc_load_scripts_frontend() —
     * reused here rather than hardcoding one so we stay in sync with
     * whatever the site has configured. Falls back to `edit_posts` if a
     * future LC version renames/removes the constant.
     *
     * @return bool
     */
    private function isEditingSession(): bool
    {
        global $dslc_active;

        if (!$dslc_active || !is_user_logged_in()) {
            return false;
        }

        $capability = defined('DS_LIVE_COMPOSER_CAPABILITY') ? DS_LIVE_COMPOSER_CAPABILITY : 'edit_posts';

        return current_user_can($capability);
    }

    /**
     * Enqueues the Live Composer editor-canvas bundle.
     *
     * Fires on `wp_enqueue_scripts` — this is a front-end request, so
     * unlike ElementorIntegration this never runs in wp-admin at all.
     *
     * @return void
     */
    public function enqueue_panel_assets(): void
    {
        if (!$this->isEditingSession()) {
            return;
        }

        $post_id = get_queried_object_id();

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-live-composer.asset.php';

        if (!file_exists($asset_path)) {
            return;
        }

        $asset = require $asset_path;

        wp_enqueue_script(
            'seopulse-page-builder-live-composer',
            SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-live-composer.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-page-builder-live-composer', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        if (!wp_style_is('seopulse-admin-global', 'enqueued')) {
            wp_enqueue_style(
                'seopulse-admin-global',
                SEOPULSE_PLUGIN_URL . 'assets/css/seopulse.min.css',
                [],
                SEOPULSE_VERSION,
            );
        }

        if (file_exists(SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-live-composer.css')) {
            wp_enqueue_style(
                'seopulse-page-builder-live-composer',
                SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-live-composer.css',
                ['wp-components', 'seopulse-admin-global'],
                $asset['version'],
            );
        }

        wp_localize_script(
            'seopulse-page-builder-live-composer',
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
     * Live Composer has no `add_control()`-style API to place this inside
     * its own UI chrome, so it's printed to `wp_footer` instead; the bundle
     * is responsible for positioning it (e.g. as a floating panel) once
     * mounted.
     *
     * @return void
     */
    public function render_panel_root(): void
    {
        if (!$this->isEditingSession()) {
            return;
        }

        $post_id = get_queried_object_id();

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            return;
        }

        printf(
            '<div id="%s" class="seopulse-live-composer-panel-root" data-post-id="%d"></div>',
            esc_attr(self::PANEL_ROOT_ID),
            (int) $post_id,
        );
    }
}
