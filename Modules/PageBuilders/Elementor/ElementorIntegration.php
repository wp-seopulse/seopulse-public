<?php

/**
 * Elementor adapter for the Page Builders Integration module.
 *
 * Ties SEOPulse's existing Meta SEO REST endpoints into Elementor's
 * classic Editor Panel (documented "Page Settings" API — see
 * https://developers.elementor.com/docs/editor/page-settings-panel and
 * https://developers.elementor.com/docs/hooks/injecting-controls).
 *
 * Targets ONLY the classic Elementor editor (v3.x). Elementor's upcoming
 * "Atomic Editor" (v4) has no public developer API yet (confirmed by the
 * Elementor team, GitHub discussions, late 2025) — do not build against it.
 *
 * @package SEOPulse\Modules\PageBuilders\Elementor
 * @since 1.7.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\PageBuilders\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\PageBuilders\BuilderDetector;
use SEOPulse\Modules\PageBuilders\PageBuildersModule;

/**
 * ElementorIntegration class
 */
class ElementorIntegration
{
    /**
     * Container ID the JS bundle mounts its React app into.
     */
    public const PANEL_ROOT_ID = 'seopulse-elementor-panel-root';

    /**
     * Registers Elementor hooks — no-op if Elementor isn't active/enabled.
     *
     * @return void
     */
    public function hooks(): void
    {
        if (!BuilderDetector::isElementorActive() || !PageBuildersModule::isBuilderEnabled('elementor')) {
            return;
        }

        add_action('elementor/documents/register_controls', [$this, 'register_seo_section']);
        add_action('elementor/editor/before_enqueue_scripts', [$this, 'enqueue_panel_assets']);
    }

    /**
     * Determines whether the SEO section should appear on a given document.
     *
     * @param \Elementor\Core\Base\Document $document
     * @return bool
     */
    private function should_register_section($document): bool
    {
        if (!$document::get_property('has_elements')) {
            return false;
        }

        $excluded_types = apply_filters(
            'seopulse/elementor/excluded_document_types',
            ['header', 'footer', 'popup'], // exlude some Elementor page builders from SEOPulse
            $document,
        );

        return !in_array($document->get_name(), $excluded_types, true);
    }

    /**
     * Adds a "SEOPulse SEO" section to Elementor's Page Settings panel
     * (Settings tab), containing a single mount point for our React app.
     *
     * @param \Elementor\Core\Base\Document $document The document being edited.
     * @return void
     */
    public function register_seo_section($document): void
    {
        if (!$this->should_register_section($document)) {
            return;
        }

        $post_id = $document->get_main_id();

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $document->start_controls_section(
            'seopulse_seo_section',
            [
                'label' => esc_html__('SEOPulse SEO', 'seopulse'),
                'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
            ],
        );

        $document->add_control(
            'seopulse_seo_panel_root',
            [
                'type' => \Elementor\Controls_Manager::RAW_HTML,
                'raw'  => '<div id="' . esc_attr(self::PANEL_ROOT_ID) . '" class="seopulse-elementor-panel-root" data-post-id="' . esc_attr((string) $post_id) . '"></div>',
            ],
        );

        $document->end_controls_section();
    }

    /**
     * Enqueues the Elementor editor-panel bundle.
     *
     * Fires on `elementor/editor/before_enqueue_scripts` — only while
     * Elementor's own editor is loading, never on the frontend or on
     * unrelated wp-admin screens.
     *
     * @return void
     */
    public function enqueue_panel_assets(): void
    {
        // Don't register the JS bundle if the current document is excluded.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; used only to look up the current document, no form data is processed.
        $post_id = isset($_GET['post']) ? (int) sanitize_text_field(wp_unslash($_GET['post'])) : 0;
        if ($post_id) {
            $document = \Elementor\Plugin::$instance->documents->get($post_id);

            if ($document && !$this->should_register_section($document)) {
                return;
            }
        }

        $asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-elementor.asset.php';

        if (!file_exists($asset_path)) {
            return;
        }

        $asset = require $asset_path;

        wp_enqueue_script(
            'seopulse-page-builder-elementor',
            SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-elementor.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-page-builder-elementor', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        // `seopulse-admin-global` (assets/css/seopulse.min.css) carries the base
        // --sp-* design tokens and is normally loaded by Core\Assets::enqueue_admin_scripts()
        // on `admin_enqueue_scripts` for any post.php screen — but Elementor's editor
        // bootstraps its own template/asset pipeline and does not reliably fire that
        // generic WP hook the same way, so it must be enqueued explicitly here too.
        if (!wp_style_is('seopulse-admin-global', 'enqueued')) {
            wp_enqueue_style(
                'seopulse-admin-global',
                SEOPULSE_PLUGIN_URL . 'assets/css/seopulse.min.css',
                [],
                SEOPULSE_VERSION,
            );
        }

        if (file_exists(SEOPULSE_PLUGIN_DIR . 'assets/build/page-builder-elementor.css')) {
            // `wp-components` supplies the Modal/Button base CSS (overlay, z-index,
            // fixed positioning) — unlike the block editor screen, Elementor's own
            // editor page never loads it on its own, so SocialEditModal would render
            // completely unstyled (and effectively invisible/non-functional) without it.
            wp_enqueue_style(
                'seopulse-page-builder-elementor',
                SEOPULSE_PLUGIN_URL . 'assets/build/page-builder-elementor.css',
                ['wp-components', 'seopulse-admin-global'],
                $asset['version'],
            );
        }

        $post_id = 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; retrieving post ID for script localization.
        if (isset($_GET['post'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; retrieving post ID for script localization.
            $post_id = (int) sanitize_text_field(wp_unslash($_GET['post']));
        }

        wp_localize_script(
            'seopulse-page-builder-elementor',
            'seopulseAdmin',
            [
                'restUrl' => rest_url('seopulse/v1'),
                'nonce'   => wp_create_nonce('wp_rest'),
                'postId'  => $post_id,
                'panelRootId' => self::PANEL_ROOT_ID,
            ],
        );
    }
}
