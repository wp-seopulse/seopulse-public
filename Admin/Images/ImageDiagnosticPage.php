<?php

/**
 * Image Diagnostic administration page
 *
 * Lists all images with diagnostic info (alt text, filename SEO, size)
 * and provides filter, bulk actions, inline edit, and CSV export.
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\Images;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Admin\AdminPageContent;
use SEOPulse\Core\Contracts\ExecuteHooksAdmin;
use SEOPulse\Core\Module\ModuleManager;
use SEOPulse\Services\ImageAltFiller;

/**
 * ImageDiagnosticPage class
 */
class ImageDiagnosticPage implements ExecuteHooksAdmin
{
    /**
     * Page slug
     *
     * @var string
     */
    private string $page_slug = 'seopulse-image-diagnostic';

    /**
     * @var ImageAltFiller
     */
    private ImageAltFiller $filler;

    public function __construct()
    {
        $this->filler = new ImageAltFiller();
    }

    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Registers the submenu page under SEOPulse
     *
     * @return void
     */
    public function register_page(): void
    {
        add_submenu_page(
            'seopulse',
            __('Image Diagnostic', 'seopulse'),
            AdminPageContent::menuLabel('meta_seo', __('Image Diagnostic', 'seopulse')),
            'manage_options',
            $this->page_slug,
            [$this, 'render_page'],
        );
    }

    /**
     * Enqueue page assets
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'seopulse_page_' . $this->page_slug) {
            return;
        }

        // CSS is always loaded so the page renders correctly even when
        // the module is disabled (the "disabled" overlay needs styling).
        wp_enqueue_style(
            'seopulse-image-diagnostic',
            SEOPULSE_PLUGIN_URL . 'assets/css/image-diagnostic.css',
            ['seopulse-admin-global'],
            SEOPULSE_VERSION,
        );

        // When the module is disabled, skip JS/localization — the API
        // routes they call are gated and would 404.
        if (!ModuleManager::instance()->isModuleEnabled('meta_seo')) {
            return;
        }

        wp_enqueue_script(
            'seopulse-image-diagnostic',
            SEOPULSE_PLUGIN_URL . 'assets/js/image-diagnostic.js',
            ['jquery'],
            SEOPULSE_VERSION,
            true,
        );

        $diagnostics = $this->filler->get_diagnostics();

        wp_localize_script(
            'seopulse-image-diagnostic',
            'seopulseImageDiag',
            [
                'restUrl'     => rest_url('seopulse/v1/image-diagnostic'),
                'nonce'       => wp_create_nonce('wp_rest'),
                'diagnostics' => $diagnostics,
                'i18n'        => [
                    'loading'       => __('Loading...', 'seopulse'),
                    'noImages'      => __('No images found matching your filters.', 'seopulse'),
                    'selectAll'     => __('Select all', 'seopulse'),
                    'selected'      => __('selected', 'seopulse'),
                    'bulkAlt'       => __('Auto-fill alt', 'seopulse'),
                    'bulkRename'    => __('Auto-rename', 'seopulse'),
                    'exportCsv'     => __('Export CSV', 'seopulse'),
                    'processing'    => __('Processing...', 'seopulse'),
                    'updated'       => __('updated', 'seopulse'),
                    'skipped'       => __('skipped', 'seopulse'),
                    'renamed'       => __('renamed', 'seopulse'),
                    'errors'        => __('errors', 'seopulse'),
                    'done'          => __('Done!', 'seopulse'),
                    'error'         => __('An error occurred.', 'seopulse'),
                    'saved'         => __('Saved', 'seopulse'),
                    'editAlt'       => __('Edit alt text', 'seopulse'),
                    'save'          => __('Save', 'seopulse'),
                    'cancel'        => __('Cancel', 'seopulse'),
                    'page'          => __('Page', 'seopulse'),
                    'prevPage'      => __('Previous', 'seopulse'),
                    'nextPage'      => __('Next', 'seopulse'),
                    'pageOf'        => __('of', 'seopulse'),
                    'missing'       => __('Missing', 'seopulse'),
                    'missingAlt'    => __('Missing alt', 'seopulse'),
                    'poorFilename'  => __('Poor filename', 'seopulse'),
                    'largeFile'     => __('Large file', 'seopulse'),
                    'unused'        => __('Unused', 'seopulse'),
                    'all'           => __('All images', 'seopulse'),
                    'images'        => __('images', 'seopulse'),
                    'ok'            => __('OK', 'seopulse'),
                    'confirmRename' => __('Rename files on disk? This will also update URLs in post content. Continue?', 'seopulse'),
                ],
            ],
        );
    }

    /**
     * Render the admin page — redirects to Meta SEO tab
     *
     * @return void
     */
    public function render_page(): void
    {
        $url = admin_url('admin.php?page=seopulse-meta-seo#tab=image-diagnostic');
        if (!headers_sent()) {
            wp_safe_redirect($url);
            exit;
        }
        // Fallback if headers already sent.
        wp_enqueue_script('seopulse-redirect-fallback', false, [], SEOPULSE_VERSION, false);
        wp_add_inline_script('seopulse-redirect-fallback', 'window.location.replace(' . wp_json_encode($url) . ');');
    }
}
