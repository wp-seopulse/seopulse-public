<?php

/**
 * Image ALT Wizard administration page
 *
 * Provides a UI for configuring auto-fill settings and running
 * batch operations on existing images missing alt text.
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
 * ImageAltWizard class
 */
class ImageAltWizard implements ExecuteHooksAdmin
{
    /**
     * Page slug
     *
     * @var string
     */
    private string $page_slug = 'seopulse-image-alt';

    /**
     * @var ImageAltFiller
     */
    private ImageAltFiller $filler;

    /**
     * Constructor
     */
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
            __('Image SEO', 'seopulse'),
            AdminPageContent::menuLabel('meta_seo', __('Image SEO', 'seopulse')),
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
            'seopulse-image-alt-wizard',
            SEOPULSE_PLUGIN_URL . 'assets/css/image-alt-wizard.css',
            ['seopulse-admin-global'],
            SEOPULSE_VERSION,
        );

        // When the module is disabled, skip JS/localization — the API
        // routes they call are gated and would 404.
        if (!ModuleManager::instance()->isModuleEnabled('meta_seo')) {
            return;
        }

        wp_enqueue_script(
            'seopulse-image-alt-wizard',
            SEOPULSE_PLUGIN_URL . 'assets/js/image-alt-wizard.js',
            ['jquery'],
            SEOPULSE_VERSION,
            true,
        );

        wp_localize_script(
            'seopulse-image-alt-wizard',
            'seopulseImageAlt',
            [
                'restUrl' => rest_url('seopulse/v1/image-alt'),
                'nonce'   => wp_create_nonce('wp_rest'),
                'i18n'    => [
                    'processing'    => __('Processing...', 'seopulse'),
                    'batchDone'     => __('Batch complete!', 'seopulse'),
                    'updated'       => __('updated', 'seopulse'),
                    'skipped'       => __('skipped', 'seopulse'),
                    'of'            => __('of', 'seopulse'),
                    'images'        => __('images', 'seopulse'),
                    'allDone'       => __('All images processed!', 'seopulse'),
                    'error'         => __('An error occurred. Please try again.', 'seopulse'),
                    'settingsSaved' => __('Settings saved.', 'seopulse'),
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
        $url = admin_url('admin.php?page=seopulse-meta-seo#tab=image-seo');
        if (!headers_sent()) {
            wp_safe_redirect($url);
            exit;
        }
        // Fallback if headers already sent.
        wp_enqueue_script('seopulse-redirect-fallback', false, [], SEOPULSE_VERSION, false);
        wp_add_inline_script('seopulse-redirect-fallback', 'window.location.replace(' . wp_json_encode($url) . ');');
    }
}
