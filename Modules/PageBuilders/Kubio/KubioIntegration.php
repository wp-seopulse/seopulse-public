<?php

/**
 * Kubio adapter for the Page Builders Integration module.
 *
 * Unlike Elementor and Live Composer, Kubio's real editing session lives
 * in a dedicated wp-admin screen (admin.php?page=kubio) rather than the
 * front end or the standard post.php screen — confirmed via Kubio's own
 * kubio_is_kubio_editor_page() (checks $pagenow === 'admin.php' and
 * $_REQUEST['page'] === 'kubio', itself filterable via
 * 'kubio/is_kubio_editor_page'), which this integration reuses directly
 * rather than re-sniffing $pagenow/$_REQUEST itself.
 *
 * That admin screen loads the real front-end permalink of the edited
 * post inside an iframe (confirmed via Kubio's own
 * kubio_edit_site_get_edited_entity(), which returns
 * get_permalink($loaded_id) for the $_GET['postId'] it reads — the same
 * query arg this integration reads below to resolve which post is
 * open), but the actual editing state lives in the *outer* admin page:
 * Kubio's own editor bundle deliberately boots the real WordPress
 * `core/editor` data store via setupEditorState(). Confirmed live on an
 * install that wp.data.select('core/editor') correctly exposes
 * getEditedPostAttribute('title'), getEditedPostContent(),
 * hasChangedContent() and getCurrentPostId() while a post is open here.
 * See ../hooks/useLiveKubioFields.ts — this SEOPulse integration only
 * uses the title, by deliberate choice, to stay consistent with the
 * Elementor/Live Composer/Divi adapters rather than building a
 * Kubio-specific fast path on top of the richer state available.
 *
 * Per-post detection uses Kubio's own `saved_in_kubio` postmeta
 * (confirmed in both Kubio's PHP — filters.php — and its own editor JS
 * bundle) — the same flag Kubio itself checks to know whether a given
 * post was actually built with its editor, rather than just containing
 * a Kubio block or two.
 *
 * Kubio's own editor shell does not register a
 * PluginSidebar/PluginDocumentSettingPanel slot (it's built directly on
 * @wordpress/block-editor primitives), so — like Live Composer — our
 * React panel is mounted into its own root div rather than through a
 * builder-native container.
 *
 * CONFIRMED on a live install: admin.php?page=kubio does not run the
 * normal wp-admin `<head>` pipeline, so styles enqueued via
 * wp_enqueue_style() from enqueue_panel_assets() (admin_enqueue_scripts)
 * never actually print. `admin_footer` does still fire reliably though
 * (that's how the JS bundle, enqueued with in_footer: true, gets
 * printed) — so stylesheets are linked directly in render_panel_root()
 * via print_panel_styles() instead of relying on wp_enqueue_style().
 *
 * @package SEOPulse\Modules\PageBuilders\Kubio
 * @since 1.9.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\PageBuilders\Kubio;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\PageBuilders\BuilderDetector;
use SEOPulse\Modules\PageBuilders\PageBuildersModule;

/**
 * KubioIntegration class
 */
class KubioIntegration
{
    /**
     * Container ID the JS bundle mounts its React app into.
     */
    public const PANEL_ROOT_ID = 'seopulse-kubio-panel-root';

    /**
     * Registers Kubio hooks — no-op if Kubio isn't active/enabled.
     *
     * @return void
     */
    public function hooks(): void
    {
        if (!BuilderDetector::isKubioActive() || !PageBuildersModule::isBuilderEnabled('kubio')) {
            return;
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueue_panel_assets']);
        add_action('admin_footer', [$this, 'render_panel_root']);
    }

    /**
     * Whether the current admin request is an active Kubio editing
     * session.
     *
     * Reuses Kubio's own kubio_is_kubio_editor_page() rather than
     * re-sniffing $pagenow/$_REQUEST ourselves, so this stays in sync
     * with however Kubio itself defines "the editor is open" — same
     * rationale as reusing DS_LIVE_COMPOSER_CAPABILITY for Live
     * Composer.
     *
     * @return bool
     */
    private function isEditingSession(): bool
    {
        if (!function_exists('kubio_is_kubio_editor_page') || !kubio_is_kubio_editor_page()) {
            return false;
        }

        return is_user_logged_in();
    }

    /**
     * Resolves the post currently open in the Kubio editor.
     *
     * Reads the same $_GET['postId'] Kubio's own
     * kubio_edit_site_get_edited_entity() reads, rather than a second,
     * possibly inconsistent way of finding "the current post" — there
     * is no queried object on a plain admin.php screen the way
     * get_queried_object_id() assumes.
     *
     * @return int
     */
    private function getEditedPostId(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset($_GET['postId']) ? (int) $_GET['postId'] : 0;
    }

    /**
     * Enqueues the Kubio editor-screen bundle.
     *
     * Fires on `admin_enqueue_scripts` — this is an admin request, so
     * unlike LiveComposerIntegration this never runs on the front end
     * at all.
     *
     * @return void
     */
    public function enqueue_panel_assets(): void
    {
        if (!$this->isEditingSession()) {
            return;
        }

        $post_id = $this->getEditedPostId();

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-kubio.asset.php';

        if (!file_exists($asset_path)) {
            return;
        }

        $asset = require $asset_path;

        wp_enqueue_script(
            'seopulse-page-builder-kubio',
            SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-kubio.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-page-builder-kubio', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        wp_localize_script(
            'seopulse-page-builder-kubio',
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
     * Prints the empty mount point the JS bundle attaches its React app
     * to.
     *
     * Kubio has no add_control()-style API to place this inside its own
     * UI chrome, so it's printed to `admin_footer` instead; the bundle
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

        $post_id = $this->getEditedPostId();

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $this->print_panel_styles();

        printf(
            '<div id="%s" class="seopulse-kubio-panel-root" data-post-id="%d"></div>',
            esc_attr(self::PANEL_ROOT_ID),
            (int) $post_id,
        );
    }

    /**
     * Prints the panel's stylesheets directly, instead of going through
     * wp_enqueue_style().
     *
     * CONFIRMED on a live install: wp_enqueue_style() calls made from
     * enqueue_panel_assets() (on admin_enqueue_scripts) never actually
     * reach the page — admin.php?page=kubio renders a custom app shell
     * that does not run the normal wp-admin `<head>` pipeline
     * (admin_print_styles), even though `admin_footer` itself still
     * fires reliably (that's how the JS bundle gets printed, since it's
     * enqueued with in_footer: true). So styles are linked here
     * instead, in the same hook already proven to run on this screen.
     *
     * NOT confirmed: whether wp-components' own CSS is available on
     * this screen at all. If the floating button or the Modal still
     * look unstyled after this fix, wp-components' stylesheet itself
     * may need the same direct-link treatment.
     *
     * @return void
     */
    private function print_panel_styles(): void
    {
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Intentional: wp_enqueue_style() does not reach this screen (confirmed above); this is the documented workaround, not an oversight.
        printf(
            '<link rel="stylesheet" id="seopulse-admin-global-css" href="%s">',
            esc_url(SEOPULSE_PLUGIN_URL . 'assets/css/seopulse.min.css'),
        );

        $kubio_css_path = SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-kubio.css';

        if (file_exists($kubio_css_path)) {
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Same documented workaround as above.
            printf(
                '<link rel="stylesheet" id="seopulse-page-builder-kubio-css" href="%s">',
                esc_url(SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-kubio.css' . '?ver=' . SEOPULSE_VERSION),
            );
        }
    }
}
