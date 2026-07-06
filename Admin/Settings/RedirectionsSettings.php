<?php

/**
 * Redirections configuration page
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
use SEOPulse\Core\Module\ModuleManager;
use SEOPulse\Modules\Redirections\RedirectionsManager;
use SEOPulse\Modules\Redirections\RedirectRepository;

/**
 * RedirectionsSettings class
 */
class RedirectionsSettings implements ExecuteHooksAdmin
{
    /**
     * Page slug
     *
     * @var string
     */
    private string $page_slug = 'seopulse-redirections';
    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_settings_page'], 14);
    }
    /**
     * Manager
     *
     * @var RedirectionsManager
     */
    private RedirectionsManager $manager;

    /**
     * SQL repository
     *
     * @var RedirectRepository
     */
    private RedirectRepository $repository;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->manager    = new RedirectionsManager();
        $this->repository = new RedirectRepository();
    }

    /**
     * Registers the settings page
     *
     * @return void
     */
    public function register_settings_page(): void
    {
        add_submenu_page(
            'seopulse',
            __('Redirects Manager', 'seopulse'),
            AdminPageContent::menuLabel('redirections', __('Redirects Manager', 'seopulse')),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page'],
        );

        if (is_admin()) {
            add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        }
    }

    /**
     * Enqueues admin assets
     *
     * @param string $hook Current page
     * @return void
     */
    public function enqueue_scripts(string $hook): void
    {
        if (strpos($hook, 'seopulse-redirections') === false) {
            return;
        }

        // When the module is disabled, skip JS/CSS/localization — the API
        // routes they call are gated and would 404.
        if (!ModuleManager::instance()->isModuleEnabled('redirections')) {
            return;
        }

        // React SPA entry point
        $asset_file = SEOPULSE_PLUGIN_DIR . 'assets/build/redirections.asset.php';
        $asset      = file_exists($asset_file) ? require $asset_file : ['dependencies' => [], 'version' => SEOPULSE_VERSION];

        wp_enqueue_style(
            'seopulse-redirections-admin-spa',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-settings.min.css',
            [],
            $asset['version'],
        );

        wp_enqueue_style(
            'seopulse-redirections-css',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-redirections.min.css',
            ['seopulse-redirections-admin-spa'],
            $asset['version'],
        );

        wp_enqueue_script(
            'seopulse-redirections-admin',
            SEOPULSE_PLUGIN_URL . 'assets/build/redirections.js',
            array_merge($asset['dependencies'], ['seopulse-admin-global']),
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-redirections-admin', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        // Localization
        wp_localize_script(
            'seopulse-redirections-admin',
            'seopulseRedirections',
            [
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('seopulse_redirections'),
                'restUrl'    => rest_url('seopulse/v1/redirections'),
                'restNonce'  => wp_create_nonce('wp_rest'),
                'pluginUrl'  => SEOPULSE_PLUGIN_URL,
                'i18n'       => $this->get_react_i18n(),
            ],
        );
    }

    /**
     * Returns i18n strings consumed by the React SPA.
     *
     * @return array<string, string>
     */
    private function get_react_i18n(): array
    {
        return [
            /* -- Confirm dialogs ------------------------------------ */
            'confirmDelete'          => __('Are you sure you want to delete this redirect?', 'seopulse'),
            'confirmDeleteTitle'     => __('Delete Redirect', 'seopulse'),
            'confirmBulkDelete'      => __('Delete selected redirects? This cannot be undone.', 'seopulse'),
            'confirmBulkDeleteTitle' => __('Delete Redirects', 'seopulse'),

            /* -- Toast / feedback ----------------------------------- */
            'settingsSaved'          => __('Settings saved.', 'seopulse'),
            'error'                  => __('An error occurred', 'seopulse'),
            'redirectSaved'          => __('Redirect saved successfully.', 'seopulse'),
            'redirectDeleted'        => __('Redirect deleted successfully.', 'seopulse'),
            'csvExported'            => __('CSV exported successfully.', 'seopulse'),
            'copiedToClipboard'      => __('Copied to clipboard.', 'seopulse'),

            /* -- Header --------------------------------------------- */
            'Redirections'           => __('Redirections', 'seopulse'),

            /* -- Tabs ----------------------------------------------- */
            'Redirects'              => __('Redirects', 'seopulse'),
            'Import/Export'          => __('Import/Export', 'seopulse'),
            'Tools'                  => __('Tools', 'seopulse'),
            'Settings'               => __('Settings', 'seopulse'),

            /* -- Stats strip ---------------------------------------- */
            'Total Redirects'        => __('Total Redirects', 'seopulse'),
            'Active Redirects'       => __('Active Redirects', 'seopulse'),
            'Disabled Redirects'     => __('Disabled Redirects', 'seopulse'),
            'Total Hits'             => __('Total Hits', 'seopulse'),

            /* -- Section header ------------------------------------- */
            'manageRedirections'     => __('Manage Redirections', 'seopulse'),
            'manageRedirectionsDesc' => __('Create and manage URL redirections for your site.', 'seopulse'),

            /* -- Empty state ---------------------------------------- */
            'noRedirects'            => __('No redirections found.', 'seopulse'),

            /* -- Redirect modal ------------------------------------- */
            'addRedirect'            => __('Add Redirect', 'seopulse'),
            'editRedirect'           => __('Edit Redirect', 'seopulse'),
            'sourceUrl'              => __('Source URL', 'seopulse'),
            'sourceRequired'         => __('Source URL is required.', 'seopulse'),
            'matchType'              => __('Match Type', 'seopulse'),
            'destinationUrl'         => __('Destination URL', 'seopulse'),
            'destinationHint'        => __('Leave empty for 410 Gone responses.', 'seopulse'),
            'destinationRequired'    => __('Destination URL is required for redirect codes.', 'seopulse'),
            'httpCode'               => __('HTTP Code', 'seopulse'),
            'ignoreCase'             => __('Ignore case', 'seopulse'),
            'passQueryString'        => __('Pass query string', 'seopulse'),
            'groupPlaceholder'       => __('Select a group…', 'seopulse'),
            'categoryPlaceholder'    => __('Select a category…', 'seopulse'),
            'description'            => __('Description', 'seopulse'),
            'descriptionPlaceholder' => __('Optional description…', 'seopulse'),
            'saving'                 => __('Saving…', 'seopulse'),
            'saveRedirect'           => __('Save Redirect', 'seopulse'),
            'cancel'                 => __('Cancel', 'seopulse'),

            /* -- Filters bar ---------------------------------------- */
            'searchPlaceholder'      => __('Search redirections…', 'seopulse'),
            'allStatuses'            => __('All Statuses', 'seopulse'),
            'allMatchTypes'          => __('All Match Types', 'seopulse'),
            'allGroups'              => __('All Groups', 'seopulse'),
            'allCategories'          => __('All Categories', 'seopulse'),
            'resetFilters'           => __('Reset Filters', 'seopulse'),

            /* -- Bulk actions --------------------------------------- */
            'selected'               => __('selected', 'seopulse'),
            'bulkActions'            => __('Bulk Actions', 'seopulse'),
            'activate'               => __('Activate', 'seopulse'),
            'deactivate'             => __('Deactivate', 'seopulse'),
            'clearSelection'         => __('Clear Selection', 'seopulse'),

            /* -- Table headers -------------------------------------- */
            'thSource'               => __('Source', 'seopulse'),
            'thDestination'          => __('Destination', 'seopulse'),
            'thMatchType'            => __('Match', 'seopulse'),
            'thCode'                 => __('Code', 'seopulse'),
            'thStatus'               => __('Status', 'seopulse'),
            'thGroup'                => __('Group', 'seopulse'),
            'thCategory'             => __('Category', 'seopulse'),
            'thHits'                 => __('Hits', 'seopulse'),
            'thActions'              => __('Actions', 'seopulse'),

            /* -- Table row actions ---------------------------------- */
            'statusActive'           => __('Active', 'seopulse'),
            'statusDisabled'         => __('Disabled', 'seopulse'),
            'edit'                   => __('Edit', 'seopulse'),
            'disableLabel'           => __('Disable', 'seopulse'),
            'enableLabel'            => __('Enable', 'seopulse'),
            'deleteLabel'            => __('Delete', 'seopulse'),
            'apply'                  => __('Apply', 'seopulse'),
            'prev'                   => __('Prev', 'seopulse'),
            'next'                   => __('Next', 'seopulse'),

            /* -- Import / Export ------------------------------------ */
            'exportRedirections'     => __('Export Redirections', 'seopulse'),
            'exportDesc'             => __('Download your redirections as a CSV file.', 'seopulse'),
            'exportCsv'              => __('Export CSV', 'seopulse'),
            'exportServerRules'      => __('Export Server Rules', 'seopulse'),
            'exportServerRulesDesc'  => __('Generate server configuration rules for your redirections.', 'seopulse'),
            'importRedirections'     => __('Import Redirections', 'seopulse'),
            'importDesc'             => __('Upload a CSV file to import redirections.', 'seopulse'),
            'importCsv'              => __('Import CSV', 'seopulse'),
            'importing'              => __('Importing…', 'seopulse'),
            'created'                => __('Created', 'seopulse'),
            'updated'                => __('Updated', 'seopulse'),
            'deleted'                => __('Deleted', 'seopulse'),
            'skipped'                => __('Skipped', 'seopulse'),

            /* -- Rules modal ---------------------------------------- */
            'htaccessTitle'          => __('.htaccess Rules', 'seopulse'),
            'nginxTitle'             => __('Nginx Rules', 'seopulse'),
            'close'                  => __('Close', 'seopulse'),
            'copyToClipboard'        => __('Copy to Clipboard', 'seopulse'),

            /* -- Tools ---------------------------------------------- */
            'chainDetection'         => __('Chain Detection', 'seopulse'),
            'chainDetectionDesc'     => __('Detect redirect chains and loops.', 'seopulse'),
            'detecting'              => __('Analyzing…', 'seopulse'),
            'runDetection'           => __('Run Detection', 'seopulse'),
            'noChainsFound'          => __('No chains or loops detected.', 'seopulse'),
            'chainsFound'            => __('Chains', 'seopulse'),
            'loopsFound'             => __('Loops', 'seopulse'),
            'impactAnalysis'         => __('Impact Analysis', 'seopulse'),
            'impactAnalysisDesc'     => __('Analyze the SEO impact of your redirections.', 'seopulse'),
            'loading'                => __('Loading…', 'seopulse'),
            'loadImpact'             => __('Load Impact Report', 'seopulse'),

            /* -- Settings ------------------------------------------- */
            'debugMode'              => __('Debug Mode', 'seopulse'),
            'debugModeDesc'          => __('Enable debug logging for redirections.', 'seopulse'),
            'enableDebugger'         => __('Enable Debugger', 'seopulse'),
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

        AdminPageContent::begin('redirections', __('Redirects Manager', 'seopulse'));
        if (ModuleManager::instance()->isModuleEnabled('redirections')) {
            echo '<div id="seopulse-settings-root"></div>';
        }
        AdminPageContent::end();
    }
}
