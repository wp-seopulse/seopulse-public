<?php

/**
 * Tools > Settings administration page (Export / Import)
 *
 * Registers a "Tools" submenu under SEOPulse and renders the
 * React page that handles export and import of global configuration.
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Admin\AdminPageContent;
use SEOPulse\Core\Contracts\ExecuteHooksAdmin;

/**
 * ToolsSettingsPage class
 */
class ToolsSettingsPage implements ExecuteHooksAdmin
{
    /**
     * Page slug
     *
     * @var string
     */
    private string $page_slug = 'seopulse-tools';

    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_page'], 80);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Registers the "Tools" submenu in the SEOPulse menu
     *
     * @return void
     */
    public function register_page(): void
    {
        add_submenu_page(
            'seopulse',
            __('Tools', 'seopulse'),
            AdminPageContent::menuLabel('tools', __('Tools', 'seopulse')),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page'],
        );
    }

    /**
     * Enqueues React assets only on the Tools page
     *
     * @param string $hook Current page
     * @return void
     */
    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'seopulse_page_' . $this->page_slug) {
            return;
        }

        $script_asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/tools-settings.asset.php';

        if (!file_exists($script_asset_path)) {
            return;
        }

        $script_asset = require $script_asset_path;

        wp_enqueue_script(
            'seopulse-tools-settings',
            SEOPULSE_PLUGIN_URL . 'assets/build/tools-settings.js',
            $script_asset['dependencies'],
            $script_asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-tools-settings', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        wp_enqueue_style(
            'seopulse-tools-settings',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-settings.min.css',
            ['wp-components', 'seopulse-admin-global'],
            $script_asset['version'],
        );

        wp_enqueue_style(
            'seopulse-tools-css',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-tools.min.css',
            ['seopulse-tools-settings'],
            $script_asset['version'],
        );

        wp_localize_script(
            'seopulse-tools-settings',
            'seopulseTools',
            [
                'restUrl'   => rest_url('seopulse/v1/tools'),
                'nonce'     => wp_create_nonce('wp_rest'),
                'pluginUrl' => SEOPULSE_PLUGIN_URL,
                'i18n'      => $this->get_i18n_strings(),
            ],
        );
    }

    /**
     * i18n strings passed to the front-end
     *
     * @return array<string, string>
     */
    private function get_i18n_strings(): array
    {
        return [
            // Page
            'pageTitle'                       => __('Tools', 'seopulse'),

            // Tabs
            'tabExportImport'                 => __('Export / Import', 'seopulse'),
            'tabReset'                        => __('Reset', 'seopulse'),
            'tabImportPlugins'                => __('Import Plugins Data', 'seopulse'),

            // Export
            'exportTitle'                     => __('Export Configuration', 'seopulse'),
            'exportDescription'               => __('Download a JSON file containing all your SEOPulse settings. You can use this file to restore your configuration or migrate it to another site.', 'seopulse'),
            'exportButton'                    => __('Export', 'seopulse'),
            'exportSuccess'                   => __('Configuration exported successfully.', 'seopulse'),
            'exportError'                     => __('Failed to export configuration. Please try again.', 'seopulse'),

            // Import
            'importTitle'                     => __('Import Configuration', 'seopulse'),
            'importDescription'               => __('Upload a previously exported JSON file to restore your SEOPulse configuration. A backup of your current settings will be created automatically before importing.', 'seopulse'),
            'importButton'                    => __('Import', 'seopulse'),
            'importSelectFile'                => __('Select a JSON file', 'seopulse'),
            'importDragDrop'                  => __('Drag & drop a file here, or click to browse', 'seopulse'),
            'importConfirmTitle'              => __('Confirm Import', 'seopulse'),
            'importConfirmMessage'            => __('This will overwrite your current configuration with the imported settings. A backup will be saved automatically. Do you want to continue?', 'seopulse'),
            'importConfirmYes'                => __('Yes, import', 'seopulse'),
            'importConfirmCancel'             => __('Cancel', 'seopulse'),
            'importSuccess'                   => __('Configuration imported successfully! Your previous settings have been backed up.', 'seopulse'),
            'importError'                     => __('Failed to import configuration.', 'seopulse'),
            'importInvalidFile'               => __('Invalid file format. Please select a valid JSON file.', 'seopulse'),
            'importInvalidStructure'          => __('The file does not contain a valid SEOPulse configuration.', 'seopulse'),
            'importVersionMismatch'           => __('Configuration version mismatch. Some settings may not be compatible.', 'seopulse'),

            // General
            'settings'                        => __('Settings', 'seopulse'),
            'tools'                           => __('Tools', 'seopulse'),
            'loading'                         => __('Loading...', 'seopulse'),
            'processing'                      => __('Processing...', 'seopulse'),
            'close'                           => __('Close', 'seopulse'),
            'importFailed'                    => __('Import failed.', 'seopulse'),
            'backupNote'                      => __('Backup', 'seopulse'),
            'backupDescription'               => __('A backup of your current configuration is created automatically before each import.', 'seopulse'),
            'configVersion'                   => __('Config version', 'seopulse'),
            'pluginVersion'                   => __('Plugin version', 'seopulse'),
            'exportedAt'                      => __('Exported at', 'seopulse'),
            'modulesIncluded'                 => __('Modules included', 'seopulse'),
            'fileSelected'                    => __('File selected', 'seopulse'),
            'noFileSelected'                  => __('No file selected', 'seopulse'),
            'preview'                         => __('Preview', 'seopulse'),

            // Module labels (shared across FilePreview & Import panels)
            'modGeneral'                      => __('General', 'seopulse'),
            'modMetaSeoGlobal'                => __('Meta SEO (Global)', 'seopulse'),
            'modAnalytics'                    => __('Analytics & Tracking', 'seopulse'),
            'modLocalSeo'                     => __('Local SEO', 'seopulse'),
            'modRedirections'                 => __('Redirections', 'seopulse'),
            'modSitemap'                      => __('Sitemap', 'seopulse'),
            'modModulesEnabled'               => __('Modules Enabled', 'seopulse'),
            'modMetaSeo'                      => __('Meta SEO', 'seopulse'),
            'modSocial'                       => __('Open Graph / Twitter', 'seopulse'),
            'modTracking'                     => __('Analytics (GA4)', 'seopulse'),
            'modPostMeta'                     => __('Post Meta', 'seopulse'),
            'modVerification'                 => __('Verification Codes', 'seopulse'),
            'modSearchAppearance'             => __('Search Appearance', 'seopulse'),
            'modSitemaps'                     => __('XML Sitemaps', 'seopulse'),
            'modRobots'                       => __('Robots.txt', 'seopulse'),
            'modBreadcrumbs'                  => __('Breadcrumbs', 'seopulse'),
            'modRedirects'                    => __('Redirects', 'seopulse'),

            // Reset
            'resetTitle'                      => __('Reset Configuration', 'seopulse'),
            'resetDescription'                => __('Restore all SEOPulse settings to their factory defaults. This will delete every module option, all metabox data saved on posts/pages, and all analysis caches.', 'seopulse'),
            'resetWarning'                    => __('This action is irreversible. A backup will be created automatically, but all current data will be permanently deleted.', 'seopulse'),
            'resetButton'                     => __('Reset all settings', 'seopulse'),
            'resetConfirmTitle'               => __('Confirm Reset', 'seopulse'),
            'resetConfirmMessage'             => __('You are about to delete ALL SEOPulse configuration, metabox data, and analysis caches. A backup will be saved before proceeding. This cannot be undone. Are you sure?', 'seopulse'),
            'resetConfirmYes'                 => __('Yes, reset everything', 'seopulse'),
            'resetConfirmCancel'              => __('Cancel', 'seopulse'),
            'resetSuccess'                    => __('All settings have been reset to their defaults. A backup of your previous configuration has been saved.', 'seopulse'),
            'resetError'                      => __('Failed to reset configuration. Please try again.', 'seopulse'),

            // Import SEOPress
            'seopressTitle'                   => __('Import SEOPress', 'seopulse'),
            'seopressDescription'             => __('Automatically detect and import your SEOPress configuration into SEOPulse. Titles, meta descriptions, Open Graph, Analytics, sitemap settings, and post-level metadata will be migrated.', 'seopulse'),
            'seopressDetecting'               => __('Detecting SEOPress…', 'seopulse'),
            'seopressNotFound'                => __('SEOPress is not installed or has no data to import.', 'seopulse'),
            'seopressDetected'                => __('SEOPress detected', 'seopulse'),
            'seopressActive'                  => __('Active', 'seopulse'),
            'seopressScanButton'              => __('Scan available data', 'seopulse'),
            'seopressScanning'                => __('Scanning SEOPress data…', 'seopulse'),
            'seopressScanTitle'               => __('Available data', 'seopulse'),
            'seopressModulesFound'            => __('Modules found:', 'seopulse'),
            'seopressPostsFound'              => __('{posts} posts with {meta} meta entries', 'seopulse'),
            'seopressOverwrite'               => __('Overwrite existing SEOPulse data if it already exists', 'seopulse'),
            'seopressImportButton'            => __('Start import', 'seopulse'),
            'seopressImporting'               => __('Importing SEOPress data…', 'seopulse'),
            'seopressImportSuccess'           => __('SEOPress data imported successfully!', 'seopulse'),
            'seopressImportError'             => __('Failed to import SEOPress data.', 'seopulse'),
            'seopressSummaryTitle'            => __('Import summary', 'seopulse'),
            'seopressModulesImported'         => __('Modules imported:', 'seopulse'),
            'seopressPostsImported'           => __('{posts} posts processed, {meta} meta entries imported', 'seopulse'),
            'seopressWarnings'                => __('Warnings', 'seopulse'),
            'seopressBackupCreated'           => __('A backup has been created before the import.', 'seopulse'),
            'seopressConfirmTitle'            => __('Confirm SEOPress Import', 'seopulse'),
            'seopressConfirmMessage'          => __('This will import SEOPress data into SEOPulse. Existing SEOPulse data will be preserved (merge mode). A backup will be created. Continue?', 'seopulse'),
            'seopressConfirmMessageOverwrite' => __('This will import SEOPress data and OVERWRITE any existing SEOPulse data. A backup will be created. This cannot be undone. Continue?', 'seopulse'),
            'seopressConfirmYes'              => __('Yes, import', 'seopulse'),
            'seopressErrorScan'               => __('Failed to scan SEOPress data. Please try again.', 'seopulse'),

            // Import Yoast SEO
            'yoastTitle'                      => __('Import Yoast SEO', 'seopulse'),
            'yoastDescription'                => __('Automatically detect and import your Yoast SEO configuration into SEOPulse. Titles, meta descriptions, Open Graph, Twitter, verification codes, sitemap settings, and post-level metadata will be migrated.', 'seopulse'),
            'yoastDetecting'                  => __('Detecting Yoast SEO…', 'seopulse'),
            'yoastNotFound'                   => __('Yoast SEO is not installed or has no data to import.', 'seopulse'),
            'yoastDetected'                   => __('Yoast SEO detected', 'seopulse'),
            'yoastActive'                     => __('Active', 'seopulse'),
            'yoastScanButton'                 => __('Scan available data', 'seopulse'),
            'yoastScanning'                   => __('Scanning Yoast SEO data…', 'seopulse'),
            'yoastScanTitle'                  => __('Available data', 'seopulse'),
            'yoastModulesFound'               => __('Modules found:', 'seopulse'),
            'yoastPostsFound'                 => __('{posts} posts with {meta} meta entries', 'seopulse'),
            'yoastOverwrite'                  => __('Overwrite existing SEOPulse data if it already exists', 'seopulse'),
            'yoastImportButton'               => __('Start import', 'seopulse'),
            'yoastImporting'                  => __('Importing Yoast SEO data…', 'seopulse'),
            'yoastImportSuccess'              => __('Yoast SEO data imported successfully!', 'seopulse'),
            'yoastImportError'                => __('Failed to import Yoast SEO data.', 'seopulse'),
            'yoastSummaryTitle'               => __('Import summary', 'seopulse'),
            'yoastModulesImported'            => __('Modules imported:', 'seopulse'),
            'yoastPostsImported'              => __('{posts} posts processed, {meta} meta entries imported', 'seopulse'),
            'yoastWarnings'                   => __('Warnings', 'seopulse'),
            'yoastBackupCreated'              => __('A backup has been created before the import.', 'seopulse'),
            'yoastConfirmTitle'               => __('Confirm Yoast SEO Import', 'seopulse'),
            'yoastConfirmMessage'             => __('This will import Yoast SEO data into SEOPulse. Existing SEOPulse data will be preserved (merge mode). A backup will be created. Continue?', 'seopulse'),
            'yoastConfirmMessageOverwrite'    => __('This will import Yoast SEO data and OVERWRITE any existing SEOPulse data. A backup will be created. This cannot be undone. Continue?', 'seopulse'),
            'yoastConfirmYes'                 => __('Yes, import', 'seopulse'),
            'yoastErrorScan'                  => __('Failed to scan Yoast SEO data. Please try again.', 'seopulse'),

            // Import Rank Math SEO
            'rankMathTitle'                   => __('Import Rank Math SEO', 'seopulse'),
            'rankMathDescription'             => __('Automatically detect and import your Rank Math SEO configuration into SEOPulse. Titles, meta descriptions, Open Graph, Twitter, verification codes, sitemap settings, and post-level metadata will be migrated.', 'seopulse'),
            'rankMathDetecting'               => __('Detecting Rank Math SEO…', 'seopulse'),
            'rankMathNotFound'                => __('Rank Math SEO is not installed or has no data to import.', 'seopulse'),
            'rankMathDetected'                => __('Rank Math SEO detected', 'seopulse'),
            'rankMathActive'                  => __('Active', 'seopulse'),
            'rankMathScanButton'              => __('Scan available data', 'seopulse'),
            'rankMathScanning'                => __('Scanning Rank Math SEO data…', 'seopulse'),
            'rankMathScanTitle'               => __('Available data', 'seopulse'),
            'rankMathModulesFound'            => __('Modules found:', 'seopulse'),
            'rankMathPostsFound'              => __('{posts} posts with {meta} meta entries', 'seopulse'),
            'rankMathOverwrite'               => __('Overwrite existing SEOPulse data if it already exists', 'seopulse'),
            'rankMathImportButton'            => __('Start import', 'seopulse'),
            'rankMathImporting'               => __('Importing Rank Math SEO data…', 'seopulse'),
            'rankMathImportSuccess'           => __('Rank Math SEO data imported successfully!', 'seopulse'),
            'rankMathImportError'             => __('Failed to import Rank Math SEO data.', 'seopulse'),
            'rankMathSummaryTitle'            => __('Import summary', 'seopulse'),
            'rankMathModulesImported'         => __('Modules imported:', 'seopulse'),
            'rankMathPostsImported'           => __('{posts} posts processed, {meta} meta entries imported', 'seopulse'),
            'rankMathWarnings'                => __('Warnings', 'seopulse'),
            'rankMathBackupCreated'           => __('A backup has been created before the import.', 'seopulse'),
            'rankMathConfirmTitle'            => __('Confirm Rank Math SEO Import', 'seopulse'),
            'rankMathConfirmMessage'          => __('This will import Rank Math SEO data into SEOPulse. Existing SEOPulse data will be preserved (merge mode). A backup will be created. Continue?', 'seopulse'),
            'rankMathConfirmMessageOverwrite' => __('This will import Rank Math SEO data and OVERWRITE any existing SEOPulse data. A backup will be created. This cannot be undone. Continue?', 'seopulse'),
            'rankMathConfirmYes'              => __('Yes, import', 'seopulse'),
            'rankMathErrorScan'               => __('Failed to scan Rank Math SEO data. Please try again.', 'seopulse'),

            // Import All in One SEO
            'aioseoTitle'                     => __('Import All in One SEO', 'seopulse'),
            'aioseoDescription'               => __('Automatically detect and import your All in One SEO configuration into SEOPulse. Titles, meta descriptions, Open Graph, Twitter, sitemap settings, robots rules, breadcrumbs, redirects, local SEO, and post-level metadata will be migrated.', 'seopulse'),
            'aioseoDetecting'                 => __('Detecting All in One SEO…', 'seopulse'),
            'aioseoNotFound'                  => __('All in One SEO is not installed or has no data to import.', 'seopulse'),
            'aioseoDetected'                  => __('All in One SEO detected', 'seopulse'),
            'aioseoActive'                    => __('Active', 'seopulse'),
            'aioseoScanButton'                => __('Scan available data', 'seopulse'),
            'aioseoScanning'                  => __('Scanning All in One SEO data…', 'seopulse'),
            'aioseoScanTitle'                 => __('Available data', 'seopulse'),
            'aioseoModulesFound'              => __('Modules found:', 'seopulse'),
            'aioseoPostsFound'                => __('{posts} posts with {meta} meta entries', 'seopulse'),
            'aioseoOverwrite'                 => __('Overwrite existing SEOPulse data if it already exists', 'seopulse'),
            'aioseoImportButton'              => __('Start import', 'seopulse'),
            'aioseoImporting'                 => __('Importing All in One SEO data…', 'seopulse'),
            'aioseoImportSuccess'             => __('All in One SEO data imported successfully!', 'seopulse'),
            'aioseoImportError'               => __('Failed to import All in One SEO data.', 'seopulse'),
            'aioseoSummaryTitle'              => __('Import summary', 'seopulse'),
            'aioseoModulesImported'           => __('Modules imported:', 'seopulse'),
            'aioseoPostsImported'             => __('{posts} posts processed, {meta} meta entries imported', 'seopulse'),
            'aioseoWarnings'                  => __('Warnings', 'seopulse'),
            'aioseoBackupCreated'             => __('A backup has been created before the import.', 'seopulse'),
            'aioseoConfirmTitle'              => __('Confirm All in One SEO Import', 'seopulse'),
            'aioseoConfirmMessage'            => __('This will import All in One SEO data into SEOPulse. Existing SEOPulse data will be preserved (merge mode). A backup will be created. Continue?', 'seopulse'),
            'aioseoConfirmMessageOverwrite'   => __('This will import All in One SEO data and OVERWRITE any existing SEOPulse data. A backup will be created. This cannot be undone. Continue?', 'seopulse'),
            'aioseoConfirmYes'                => __('Yes, import', 'seopulse'),
            'aioseoErrorScan'                 => __('Failed to scan All in One SEO data. Please try again.', 'seopulse'),
        ];
    }

    /**
     * Renders the settings page
     *
     * @return void
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        AdminPageContent::begin('tools', __('Tools', 'seopulse'));
        echo '<div id="seopulse-settings-root"></div>';
        AdminPageContent::end();
    }
}
