<?php

/**
 * Analytics & Cookie Consent module for SEOPulse
 *
 * Manages the GDPR-compliant cookie consent banner, script blocking,
 * and Google Consent Mode v2 integration.
 *
 * @package SEOPulse\Modules\Analytics
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Analytics;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Attributes\AsModule;
use SEOPulse\Core\Contracts\ExecuteHooksFrontend;
use SEOPulse\Core\Contracts\ModuleInterface;

/**
 * AnalyticsModule class
 *
 * Main module class that orchestrates tracking scripts (GTM, GA4)
 * and the consent banner on the frontend.
 * Blocks tracking scripts until explicit consent is given when consent mode is enabled.
 */
#[AsModule(key:'analytics', label:'Analytics & Cookies', description:'GDPR-compliant cookie consent banner and tracking script management.', icon:'dashicons-shield', namespace:'SEOPulse\\Modules\\Analytics\\', )]
class AnalyticsModule implements ExecuteHooksFrontend, ModuleInterface
{
    /**
     * Consent manager instance
     *
     * @var ConsentManager
     */
    private ConsentManager $consentManager;

    /**
     * Consent banner renderer
     *
     * @var ConsentBanner
     */
    private ConsentBanner $consentBanner;

    /**
     * Tracking manager
     *
     * @var AnalyticsTracking
     */
    private AnalyticsTracking $tracking;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->consentManager = new ConsentManager();
        $this->consentBanner  = new ConsentBanner();
        $this->tracking       = new AnalyticsTracking();
    }

    /**
     * Register WordPress frontend hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        $settings = $this->consentManager->getSettings();

        // Register tracking script hooks
        $this->registerTrackingHooks($settings);

        // Only activate consent module if enabled
        if (!empty($settings['enabled'])) {
            // Conditionally block tracking based on consent
            add_action('wp_loaded', [$this, 'interceptTrackingScripts'], 5);

            // Enqueue consent banner assets
            add_action('wp_enqueue_scripts', [$this, 'enqueueConsentAssets'], 5);

            // Render consent banner in footer
            add_action('wp_footer', [$this->consentBanner, 'render'], 5);

            // Inject Google Consent Mode v2 defaults (must be before any gtag call)
            if (!empty($settings['gcm_v2_enabled'])) {
                add_action('wp_enqueue_scripts', [$this, 'injectGoogleConsentModeDefaults'], 1);
            }
        }
    }

    /**
     * Register tracking script hooks (GTM/GA4)
     *
     * @param array<string, mixed> $settings
     * @return void
     */
    private function registerTrackingHooks(array $settings): void
    {
        add_action('wp_enqueue_scripts', [$this->tracking, 'inject_gtm_head'], 5);
        add_action('wp_footer', [$this->tracking, 'inject_gtm_noscript'], 10);
        add_action('wp_enqueue_scripts', [$this->tracking, 'inject_analytics'], 20);
    }

    /**
     * Intercept and conditionally block tracking scripts.
     *
     * If the user has not given consent for the 'statistics' category,
     * the GTM/GA4 hooks from AnalyticsTracking are removed.
     * Scripts will be loaded dynamically via JS after consent.
     *
     * @return void
     */
    public function interceptTrackingScripts(): void
    {
        $settings = $this->consentManager->getSettings();

        if (empty($settings['enabled'])) {
            return;
        }

        // Check if user has consent via cookie (server-side check)
        $consent = $this->consentManager->getConsentFromCookie();

        // If no consent given yet, or statistics not accepted → block scripts
        if ($consent === null || empty($consent['statistics'])) {
            $this->removeTrackingHooks();
        }

        // If marketing not accepted → block marketing-related scripts
        if ($consent === null || empty($consent['marketing'])) {
            $this->removeMarketingHooks();
        }
    }

    /**
     * Remove tracking hooks from AnalyticsTracking
     *
     * @return void
     */
    private function removeTrackingHooks(): void
    {
        remove_action('wp_enqueue_scripts', [$this->tracking, 'inject_gtm_head'], 5);
        remove_action('wp_footer', [$this->tracking, 'inject_gtm_noscript'], 10);
        remove_action('wp_enqueue_scripts', [$this->tracking, 'inject_analytics'], 20);
    }

    /**
     * Remove marketing-related hooks (placeholder for future extension)
     *
     * @return void
     */
    private function removeMarketingHooks(): void
    {
        // Currently, SEOPulse does not have dedicated marketing scripts.
        // This method is ready for future pixel/remarketing integrations.
        do_action('seopulse_analytics_remove_marketing_hooks');
    }

    /**
     * Enqueue consent banner CSS and JS on the frontend
     *
     * @return void
     */
    public function enqueueConsentAssets(): void
    {
        $version = defined('WP_DEBUG') && WP_DEBUG ? (string) time() : SEOPULSE_VERSION;

        wp_enqueue_style(
            'seopulse-consent',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-consent.css',
            [],
            $version,
        );

        wp_enqueue_script(
            'seopulse-consent',
            SEOPULSE_PLUGIN_URL . 'assets/js/seopulse-consent.js',
            [],
            $version,
            false, // Load in head to prevent FOUC
        );

        $settings = $this->consentManager->getSettings();

        wp_localize_script(
            'seopulse-consent',
            'seopulseConsent',
            [
                'settings' => [
                    'cookieName'    => $settings['cookie_name'] ?? 'seopulse_consent',
                    'cookieExpiry'  => (int) ($settings['cookie_expiry'] ?? 365),
                    'position'      => $settings['position'] ?? 'bottom',
                    'theme'         => $settings['theme'] ?? 'light',
                    'gcmV2'         => !empty($settings['gcm_v2_enabled']),
                ],
                'scripts'  => [
                    'gtm_id' => (!empty($settings['gtm_enabled']) && !empty($settings['gtm_id']))
                        ? sanitize_text_field($settings['gtm_id']) : '',
                    'ga4_id' => (!empty($settings['ga4_enabled']) && !empty($settings['ga4_id']))
                        ? sanitize_text_field($settings['ga4_id']) : '',
                ],
                'i18n'     => [
                    'bannerTitle'        => $settings['banner_title'] ?: __('We respect your privacy', 'seopulse'),
                    'bannerDescription'  => $settings['banner_description'] ?: __('We use cookies to improve your experience, analyze traffic, and personalize content. You can choose which categories of cookies you accept.', 'seopulse'),
                    'acceptAll'          => $settings['btn_accept_all'] ?: __('Accept all', 'seopulse'),
                    'rejectAll'          => $settings['btn_reject_all'] ?: __('Reject all', 'seopulse'),
                    'customize'          => $settings['btn_customize'] ?: __('Customize', 'seopulse'),
                    'saveChoices'        => $settings['btn_save'] ?: __('Save my choices', 'seopulse'),
                    'close'              => __('Close', 'seopulse'),
                    'privacyPolicy'      => $settings['privacy_policy_text'] ?: __('Privacy policy', 'seopulse'),
                    'privacyPolicyUrl'   => $settings['privacy_policy_url'] ?: get_privacy_policy_url(),
                    // Category labels
                    'catEssential'       => __('Essential', 'seopulse'),
                    'catEssentialDesc'   => __('These cookies are necessary for the website to function properly. They cannot be disabled.', 'seopulse'),
                    'catStatistics'      => __('Statistics', 'seopulse'),
                    'catStatisticsDesc'  => __('These cookies help us understand how visitors interact with the website by collecting anonymous usage data (Google Analytics, GA4, Matomo).', 'seopulse'),
                    'catMarketing'       => __('Marketing', 'seopulse'),
                    'catMarketingDesc'   => __('These cookies are used to display personalized ads and track advertising campaign performance.', 'seopulse'),
                    'catPreferences'     => __('Preferences', 'seopulse'),
                    'catPreferencesDesc' => __('These cookies allow the website to remember choices you make (language, region, display preferences).', 'seopulse'),
                    // Modal
                    'modalTitle'         => __('Cookie preferences', 'seopulse'),
                    'alwaysActive'       => __('Always active', 'seopulse'),
                    // Footer link
                    'manageCookies'      => __('Manage cookies', 'seopulse'),
                ],
            ],
        );
    }

    /**
     * Inject Google Consent Mode v2 default values in <head>
     *
     * Must be rendered BEFORE any gtag.js or GTM script.
     * Sets all consent types to 'denied' by default.
     * The JS consent banner will update these after user consent.
     *
     * @return void
     */
    public function injectGoogleConsentModeDefaults(): void
    {
        wp_register_script('seopulse-gcm-defaults', false, [], SEOPULSE_VERSION, false);
        wp_enqueue_script('seopulse-gcm-defaults');

        $inline = 'window.dataLayer = window.dataLayer || [];'
            . 'function gtag(){dataLayer.push(arguments);}'
            . "gtag('consent','default',{"
            . "'ad_storage':'denied',"
            . "'ad_user_data':'denied',"
            . "'ad_personalization':'denied',"
            . "'analytics_storage':'denied',"
            . "'functionality_storage':'denied',"
            . "'personalization_storage':'denied',"
            . "'security_storage':'granted',"
            . "'wait_for_update':500"
            . '});'
            . "gtag('set','ads_data_redaction',true);"
            . "gtag('set','url_passthrough',true);";

        wp_add_inline_script('seopulse-gcm-defaults', $inline);
    }

    /**
     * {@inheritDoc}
     */
    public function getKey(): string
    {
        return 'analytics';
    }

    /**
     * {@inheritDoc}
     */
    public function onActivate(): void
    {
        // No initial setup needed
    }

    /**
     * {@inheritDoc}
     */
    public function onDeactivate(): void
    {
        // No cleanup needed
    }
}
