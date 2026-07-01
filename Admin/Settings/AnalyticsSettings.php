<?php

/**
 * Analytics Settings administration page
 *
 * Registers the "Analytics" submenu under SEOPulse and renders the
 * React page for cookie consent configuration.
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
use SEOPulse\Modules\Analytics\ConsentManager;

/**
 * AnalyticsSettings class
 *
 * Manages the Analytics & Cookie Consent admin page with React UI.
 */
class AnalyticsSettings implements ExecuteHooksAdmin
{
    /**
     * Page slug
     *
     * @var string
     */
    private string $page_slug = 'seopulse-analytics';

    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_page'], 11);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Registers the "Analytics" submenu in the SEOPulse menu
     *
     * @return void
     */
    public function register_page(): void
    {
        add_submenu_page(
            'seopulse',
            __('Analytics & Cookies', 'seopulse'),
            AdminPageContent::menuLabel('analytics', __('Analytics', 'seopulse')),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page'],
        );
    }

    /**
     * Enqueue React assets only on the Analytics page
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
        $script_asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/analytics-settings.asset.php';
        $script_asset      = file_exists($script_asset_path)
            ? require $script_asset_path
            : [
                'dependencies' => [],
                'version'      => SEOPULSE_VERSION,
            ];

        wp_enqueue_style(
            'seopulse-analytics-settings',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-settings.min.css',
            ['wp-components', 'seopulse-admin-global'],
            $script_asset['version'],
        );

        wp_enqueue_style(
            'seopulse-analytics-css',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-analytics.min.css',
            ['seopulse-analytics-settings'],
            $script_asset['version'],
        );

        // When the module is disabled, skip JS/localization — the API
        // routes they call are gated and would 404.
        if (!ModuleManager::instance()->isModuleEnabled('analytics')) {
            return;
        }

        wp_enqueue_script(
            'seopulse-analytics-settings',
            SEOPULSE_PLUGIN_URL . 'assets/build/analytics-settings.js',
            $script_asset['dependencies'],
            $script_asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-analytics-settings', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        $consentManager = new ConsentManager();
        $settings       = $consentManager->getSettings();

        wp_localize_script(
            'seopulse-analytics-settings',
            'seopulseAnalytics',
            [
                'restUrl'        => rest_url('seopulse/v1/analytics'),
                'nonce'          => wp_create_nonce('wp_rest'),
                'pluginUrl'      => SEOPULSE_PLUGIN_URL,
                'settings'       => $settings,
                'tracking'       => [
                    'gtm_enabled' => !empty($settings['gtm_enabled']),
                    'gtm_id'      => $settings['gtm_id'] ?? '',
                    'ga4_enabled' => !empty($settings['ga4_enabled']),
                    'ga4_id'      => $settings['ga4_id'] ?? '',
                ],
                'availableRoles' => $this->get_available_roles(),
                'privacyPageUrl' => get_privacy_policy_url(),
                'i18n'           => $this->get_i18n_strings(),
            ],
        );
    }

    /**
     * Returns an array of available roles for Google Analytics
     *
     * @return array<string, string>
     */
    private function get_available_roles(): array
    {
        $roles = wp_roles();
        $result = [];
        foreach ($roles->roles as $key => $role) {
            $result[] = [
                'value' => $key,
                'label' => translate_user_role($role['name']),
            ];
        }

        return $result;
    }

    /**
     * Renders the React page container
     *
     * @return void
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        AdminPageContent::begin('analytics', __('Analytics & Cookie Consent', 'seopulse'));
        if (ModuleManager::instance()->isModuleEnabled('analytics')) {
            echo '<div id="seopulse-settings-root"></div>';
        }
        AdminPageContent::end();
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
            'pageTitle'                  => __('Analytics & Cookie Consent', 'seopulse'),
            'pageDescription'            => __('Configure your GDPR-compliant cookie consent banner and manage tracking scripts.', 'seopulse'),

            // Tabs
            'tabGeneral'                 => __('General', 'seopulse'),
            'tabAppearance'              => __('Appearance', 'seopulse'),
            'tabTexts'                   => __('Texts', 'seopulse'),
            'tabAdvanced'                => __('Advanced', 'seopulse'),

            // General tab
            'enableConsent'              => __('Enable cookie consent banner', 'seopulse'),
            'enableConsentDesc'          => __('Display a GDPR-compliant consent banner to visitors. Tracking scripts will be blocked until consent is given.', 'seopulse'),
            'cookieName'                 => __('Cookie name', 'seopulse'),
            'cookieNameDesc'             => __('Name of the cookie used to store consent preferences.', 'seopulse'),
            'cookieExpiry'               => __('Cookie expiry (days)', 'seopulse'),
            'cookieExpiryDesc'           => __('How long the consent cookie is valid. CNIL recommends a maximum of 13 months (395 days).', 'seopulse'),

            // Appearance tab
            'position'                   => __('Banner position', 'seopulse'),
            'positionBottom'             => __('Bottom', 'seopulse'),
            'positionTop'                => __('Top', 'seopulse'),
            'positionBottomLeft'         => __('Bottom left', 'seopulse'),
            'positionBottomRight'        => __('Bottom right', 'seopulse'),
            'theme'                      => __('Theme', 'seopulse'),
            'themeLight'                 => __('Light', 'seopulse'),
            'themeDark'                  => __('Dark', 'seopulse'),
            'themeAuto'                  => __('Auto (system preference)', 'seopulse'),

            // Texts tab
            'bannerTitle'                => __('Banner title', 'seopulse'),
            'bannerTitleDefault'         => __('We respect your privacy', 'seopulse'),
            'bannerDescription'          => __('Banner description', 'seopulse'),
            'bannerDescDefault'          => __('We use cookies to improve your experience, analyze traffic, and personalize content. You can choose which categories of cookies you accept.', 'seopulse'),
            'btnAcceptAll'               => __('Accept all button text', 'seopulse'),
            'btnRejectAll'               => __('Reject all button text', 'seopulse'),
            'btnCustomize'               => __('Customize button text', 'seopulse'),
            'btnSave'                    => __('Save button text', 'seopulse'),
            'privacyPolicyText'          => __('Privacy policy link text', 'seopulse'),
            'privacyPolicyUrl'           => __('Privacy policy URL', 'seopulse'),
            'leaveEmptyDefault'          => __('Leave empty to use default text.', 'seopulse'),

            // Tracking scripts
            'detectedScripts'            => __('Tracking Scripts', 'seopulse'),
            'detectedScriptsDesc'        => __('Configure your Google Tag Manager and Google Analytics 4 tracking scripts. They will be automatically blocked until the user gives consent when the consent banner is enabled.', 'seopulse'),
            'scriptName'                 => __('Script', 'seopulse'),
            'scriptStatus'               => __('Status', 'seopulse'),
            'scriptCategory'             => __('Category', 'seopulse'),
            'scriptEnabled'              => __('Enabled', 'seopulse'),
            'scriptDisabled'             => __('Disabled', 'seopulse'),

            // Advanced tab
            'gcmV2'                      => __('Google Consent Mode v2', 'seopulse'),
            'gcmV2Desc'                  => __('Enable Google Consent Mode v2 integration. This sends default consent signals to Google services before the user makes a choice, ensuring proper data collection compliance.', 'seopulse'),
            'gcmV2Info'                  => __('Google Consent Mode v2 sends default consent signals (denied) to Google services before the user interacts with the banner. After consent, the signals are updated to granted for the accepted categories:', 'seopulse'),
            'gtmName'                    => __('Google Tag Manager (GTM)', 'seopulse'),
            'ga4Name'                    => __('Google Analytics 4 (GA4)', 'seopulse'),
            'scriptNamePlaceholder'      => __('Facebook Pixel', 'seopulse'),

            // Tracking configuration
            'enableGtm'                  => __('Enable Google Tag Manager', 'seopulse'),
            'enableGtmDesc'              => __('Check to enable Google Tag Manager.', 'seopulse'),
            'gtmId'                      => __('GTM ID', 'seopulse'),
            'gtmIdDesc'                  => __('Your Google Tag Manager ID (e.g., GTM-XXXXXXX).', 'seopulse'),
            'enableGa4'                  => __('Enable Google Analytics 4', 'seopulse'),
            'enableGa4Desc'              => __('Check to enable Google Analytics 4.', 'seopulse'),
            'ga4Id'                      => __('GA4 Measurement ID', 'seopulse'),
            'ga4IdDesc'                  => __('Your Google Analytics 4 Measurement ID (e.g., G-XXXXXXXXXX).', 'seopulse'),
            'ga4IdHelp'                  => __('Where to find it?', 'seopulse'),
            'logConsents'                => __('Log consents', 'seopulse'),
            'logConsentsDesc'            => __('Store consent records in the database for compliance auditing. (Coming soon)', 'seopulse'),

            // Google Analytics tab
            'tabGoogleAnalytics'         => __('Google Analytics', 'seopulse'),
            'ga4TrackingExclusions'      => __('Tracking Exclusions', 'seopulse'),
            'ga4ExcludeAdmins'           => __('Exclude administrators', 'seopulse'),
            'ga4ExcludeAdminsDesc'       => __('Do not track users with the Administrator role. Recommended to keep analytics data clean.', 'seopulse'),
            'ga4ExcludeRoles'            => __('Exclude by role', 'seopulse'),
            'ga4ExcludeRolesDesc'        => __('Additional user roles that should not be tracked.', 'seopulse'),
            'ga4EventTracking'           => __('Event Tracking', 'seopulse'),
            'ga4TrackOutbound'           => __('Track outbound link clicks', 'seopulse'),
            'ga4TrackOutboundDesc'       => __('Automatically send a GA4 event when a visitor clicks on a link pointing to an external domain.', 'seopulse'),
            'ga4TrackDownloads'          => __('Track file downloads', 'seopulse'),
            'ga4TrackDownloadsDesc'      => __('Automatically send a GA4 event when a visitor downloads a file matching the configured extensions.', 'seopulse'),
            'ga4DownloadExtensions'      => __('Tracked file extensions', 'seopulse'),
            'ga4DownloadExtensionsDesc'  => __('Comma-separated list of file extensions to track as downloads (e.g. pdf,zip,doc).', 'seopulse'),
            'ga4CustomCode'              => __('Custom Tracking Code', 'seopulse'),
            'ga4CustomHeadCode'          => __('Custom code — head', 'seopulse'),
            'ga4CustomHeadCodeDesc'      => __('JavaScript injected in <head> after the tracking scripts. Do not include <script> tags.', 'seopulse'),
            'ga4CustomFooterCode'        => __('Custom code — footer', 'seopulse'),
            'ga4CustomFooterCodeDesc'    => __('JavaScript injected before </body>. Do not include <script> tags.', 'seopulse'),

            // Categories
            'catEssential'               => __('Essential', 'seopulse'),
            'catStatistics'              => __('Statistics', 'seopulse'),
            'catMarketing'               => __('Marketing', 'seopulse'),
            'catPreferences'             => __('Preferences', 'seopulse'),

            // Actions
            'save'                       => __('Save settings', 'seopulse'),
            'saving'                     => __('Saving…', 'seopulse'),
            'saveSuccess'                => __('Settings saved.', 'seopulse'),
            'saveError'                  => __('Failed to save settings. Please try again.', 'seopulse'),
            'preview'                    => __('Preview banner', 'seopulse'),
            'previewDesc'                => __('Open a preview of the consent banner in a new tab.', 'seopulse'),
        ];
    }
}
