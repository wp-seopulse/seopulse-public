<?php

/**
 * Admin page for the Setup Wizard
 *
 * Registers a hidden submenu page that renders the React-based wizard.
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Contracts\ExecuteHooksAdmin;
use SEOPulse\Modules\LocalSeo\LocalSeoDefaults;
use SEOPulse\Modules\MetaSeo\MetaSeoDefaults;

class SetupWizardPage implements ExecuteHooksAdmin
{
    private string $page_slug = 'seopulse-setup-wizard';

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_page'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'maybe_redirect_after_activation']);
    }

    /**
     * Redirect to the setup wizard on first plugin activation
     */
    public function maybe_redirect_after_activation(): void
    {
        if (!get_transient('seopulse_activation_redirect')) {
            return;
        }

        delete_transient('seopulse_activation_redirect');

        // Don't redirect during bulk activation or AJAX/CLI requests
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for bulk activation flag, no form data processed.
        if (wp_doing_ajax() || wp_doing_cron() || defined('WP_CLI') || isset($_GET['activate-multi'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . $this->page_slug));
        exit;
    }

    /**
     * Register the wizard as a hidden admin page (no menu entry).
     *
     * Uses null parent to avoid a hookname mismatch caused by remove_submenu_page
     * which strips the entry from $submenu, preventing WordPress from resolving
     * the correct parent during the access check in user_can_access_admin_page().
     */
    public function register_page(): void
    {
        $hook = add_submenu_page(
            '',
            __('Setup Wizard', 'seopulse'),
            __('Setup Wizard', 'seopulse'),
            'manage_options',
            $this->page_slug,
            [$this, 'render_page'],
        );

        // Hidden submenu pages (empty parent) don't populate $title automatically.
        // Set it on the load hook, which fires before admin-header.php.
        if ($hook) {
            add_action("load-{$hook}", function () {
                global $title;
                $title = __('Setup Wizard', 'seopulse');
            });
        }
    }

    /**
     * Enqueue React wizard assets only on the wizard page
     */
    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'admin_page_' . $this->page_slug) {
            return;
        }

        $script_asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/setup-wizard.asset.php';

        if (!file_exists($script_asset_path)) {
            return;
        }

        $script_asset = require $script_asset_path;

        wp_enqueue_media();
        wp_enqueue_style('wp-components');

        wp_enqueue_script(
            'seopulse-setup-wizard',
            SEOPULSE_PLUGIN_URL . 'assets/build/setup-wizard.js',
            $script_asset['dependencies'],
            $script_asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-setup-wizard', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        wp_enqueue_style(
            'seopulse-setup-wizard',
            SEOPULSE_PLUGIN_URL . 'assets/build/setup-wizard.css',
            ['wp-components', 'seopulse-admin-global'],
            $script_asset['version'],
        );

        // Provide data for the wizard
        wp_localize_script(
            'seopulse-setup-wizard',
            'seopulseWizard',
            [
                'restUrl'             => rest_url('seopulse/v1/setup-wizard'),
                'nonce'               => wp_create_nonce('wp_rest'),
                'dashboardUrl'        => admin_url('admin.php?page=seopulse'),
                'editPostUrl'         => admin_url('edit.php'),
                'settingsUrl'         => admin_url('admin.php?page=seopulse-settings'),
                'siteInfo'            => [
                    'name'        => get_bloginfo('name'),
                    'description' => get_bloginfo('description'),
                    'url'         => home_url(),
                ],
                'profile'             => $this->get_profile_with_defaults(),
                'metaSeo'             => (array) get_option(Options::META_SEO_GLOBAL, []),
                'metaSeoTracking'     => array_intersect_key(
                    (array) get_option(Options::ANALYTICS, []),
                    array_flip(['gtm_enabled', 'gtm_id', 'ga4_enabled', 'ga4_id']),
                ),
                'localSeo'            => (array) get_option(Options::LOCAL_SEO, LocalSeoDefaults::get_default_settings()),
                'recommendationState' => $this->get_recommendation_state(),
                'ogTypes'             => MetaSeoDefaults::get_og_type_options(),
                'twitterCards'        => MetaSeoDefaults::get_twitter_card_options(),
                'geoTypes'            => LocalSeoDefaults::get_allowed_types(),
                'daysOfWeek'          => LocalSeoDefaults::get_days_of_week(),
                'i18n'                => $this->get_i18n_strings(),
                'environment'         => $this->get_environment_info(),
            ],
        );
    }

    /**
     * Render the wizard page container
     */
    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
<div class="seopulse-setup-wizard-wrap">
    <div id="seopulse-setup-wizard-root"></div>
</div>
<?php
    }

    /**
     * Get i18n strings for the wizard
     */
    private function get_i18n_strings(): array
    {
        return [
            // Welcome step
            'welcomeTitle'             => __('Welcome to SEOPulse', 'seopulse'),
            'welcomeDescription'       => __('In just a few minutes, we\'ll set up everything you need to improve your search engine visibility and get actionable SEO recommendations.', 'seopulse'),
            'startButton'              => __('Start Setup', 'seopulse'),
            'estimatedTime'            => __('Takes about 3 minutes', 'seopulse'),
            'benefitVisibility'        => __('Optimized meta tags for better search visibility', 'seopulse'),
            'benefitRecommendations'   => __('Actionable SEO recommendations on every page', 'seopulse'),
            'benefitTechnical'         => __('Technical SEO checks and monitoring', 'seopulse'),
            'metaSeoDescription'       => __('Configures how search engines see your site.', 'seopulse'),

            // MetaSEO step
            'metaSeoTitle'             => __('MetaSEO Configuration', 'seopulse'),
            'metaSeoDescription'       => __('Configure your global SEO meta tags, Open Graph settings, and tracking codes.', 'seopulse'),

            // Summary step
            'summaryTitle'             => __('Your Configuration Checklist', 'seopulse'),
            'summaryDescription'       => __('Here\'s what you\'ve configured. You can go back and edit any section before finishing.', 'seopulse'),
            'summaryPartial'           => __('Partially configured', 'seopulse'),
            'summarySkipped'           => __('Not yet configured', 'seopulse'),
            'summaryProgress'          => __('{done} of {total} sections configured', 'seopulse'),
            'summaryReady'             => __('Everything looks good — you\'re ready to go!', 'seopulse'),
            'summaryIncomplete'        => __('Some sections are not configured yet. You can finish the setup now and complete them later from Settings.', 'seopulse'),
            'optional'                 => __('Optional', 'seopulse'),
            'highImpact'               => __('High Impact', 'seopulse'),
            'advancedSettings'         => __('Advanced settings', 'seopulse'),
            'geoCoordinates'           => __('Coordinates', 'seopulse'),
            'additionalInfo'           => __('Additional Info', 'seopulse'),

            // Completion step
            'completeTitle'            => __('You\'re All Set!', 'seopulse'),
            'completeMessage'          => __('SEOPulse is now configured and ready to help you improve your search engine rankings. Here\'s what to do next.', 'seopulse'),
            'goToDashboard'            => __('Go to Dashboard', 'seopulse'),
            'completeMetaSeo'          => __('Meta SEO tags configured', 'seopulse'),
            'completeProfile'          => __('Site profile saved', 'seopulse'),
            'whatsNext'                => __('Recommended Next Steps', 'seopulse'),
            'nextActionEditPost'       => __('Optimize Your First Post', 'seopulse'),
            'nextActionEditPostDesc'   => __('Open the editor and see SEOPulse\'s real-time SEO analysis in action.', 'seopulse'),
            'nextActionDashboard'      => __('Check Your SEO Dashboard', 'seopulse'),
            'nextActionDashboardDesc'  => __('See your configuration score and top recommendations at a glance.', 'seopulse'),
            'nextActionSettings'       => __('Fine-tune Settings', 'seopulse'),
            'nextActionSettingsDesc'   => __('Customize modules, sitemaps, and redirections to match your needs.', 'seopulse'),

            // New UI labels
            'launchHeroTitle'          => __('Launch a stronger SEO baseline from the first screen.', 'seopulse'),
            'launchHeroText'           => __('Move through a cleaner workflow designed to set up metadata, technical signals, and local visibility with minimal friction.', 'seopulse'),
            'guidedSteps'              => __('guided steps', 'seopulse'),
            'averageSetup'             => __('average setup', 'seopulse'),
            'wpNative'                 => __('WordPress native', 'seopulse'),
            'mapSeoBaseline'           => __('Map your SEO baseline', 'seopulse'),
            'mapSeoDesc'               => __('Start with a clear setup flow designed to avoid scattered admin configuration.', 'seopulse'),
            'applyDefaults'            => __('Apply the right defaults', 'seopulse'),
            'applyDefaultsDesc'        => __('Structure core metadata, technical signals, and local visibility with less friction.', 'seopulse'),
            'launchConfidence'         => __('Launch with confidence', 'seopulse'),
            'launchConfDesc'           => __('Finish with a sharper summary and a dashboard-ready technical SEO foundation.', 'seopulse'),
            'trustNative'              => __('Native WordPress workflow', 'seopulse'),
            'trustFast'                => __('Fast setup, no lock-in', 'seopulse'),
            'trustClarity'             => __('Built for technical SEO clarity', 'seopulse'),
            'welcomeWorkflowLabel'     => __('What this flow sets up', 'seopulse'),
            'welcomeWorkflowTitle'     => __('A cleaner route from onboarding to a credible SEO baseline.', 'seopulse'),
            'welcomeWorkflowDesc'      => __('Each step removes common setup ambiguity so your first technical SEO version is publishable faster.', 'seopulse'),
            'welcomeStageProfile'      => __('Profile the site', 'seopulse'),
            'welcomeStageProfileDesc'  => __('Define the site model, publishing context and recommended preset before touching technical settings.', 'seopulse'),
            'welcomeStageMetadata'     => __('Shape search signals', 'seopulse'),
            'welcomeStageMetadataDesc' => __('Set the metadata, sharing assets and technical toggles that most affect how the site is presented.', 'seopulse'),
            'welcomeStageLaunch'       => __('Review before launch', 'seopulse'),
            'welcomeStageLaunchDesc'   => __('Finish with a checklist designed to catch weak signals before the baseline goes live.', 'seopulse'),

            // Welcome video (set a YouTube ID to enable the player)
            'welcomeVideoId'           => '', // e.g. 'dQw4w9WgXcQ'
            'welcomeVideoLabel'        => __('Quick overview', 'seopulse'),
            'welcomeVideoTitle'        => __('See the setup in action before you start.', 'seopulse'),
            'welcomeVideoPlay'         => __('Play video', 'seopulse'),

            'profileLiveSummary'       => __('Live profile summary', 'seopulse'),
            'profileConfigDir'         => __('Your configuration direction', 'seopulse'),
            'profileConfigDesc'        => __('SEOPulse stores this context and uses it to present a clearer setup recap and more relevant defaults.', 'seopulse'),
            'profileConfidence'        => __('Profile confidence', 'seopulse'),
            'profileSiteModel'         => __('Site model', 'seopulse'),
            'profilePreset'            => __('SEO preset', 'seopulse'),
            'profilePresetRec'         => __('Preset recommendation', 'seopulse'),
            'profileCoreOnly'          => __('Core SEO setup only', 'seopulse'),
            'presetGuideDefault'       => __('Balanced configuration for a clean all-around technical baseline.', 'seopulse'),
            'presetGuideBlog'          => __('Better suited for editorial publishing rhythms and content-heavy websites.', 'seopulse'),
            'presetGuideLocal'         => __('Optimized for local intent, trust signals, and location-oriented visibility.', 'seopulse'),
            'presetGuideEcom'          => __('A stronger fit for product discovery, store pages, and commercial indexing.', 'seopulse'),
            'presetGuidePortfolio'     => __('A lighter setup focused on clarity, presentation, and core discoverability.', 'seopulse'),

            'metaOptScore'             => __('Optimization score', 'seopulse'),
            'metaBuildSnippet'         => __('Build the core snippet first, then enrich social and technical layers only where they improve visibility.', 'seopulse'),
            'metaPubGuidance'          => __('Publishing guidance', 'seopulse'),
            'metaSignalCoreReady'      => __('Core metadata is almost ready for launch.', 'seopulse'),
            'metaSignalCoreFill'       => __('Fill the main metadata fields to shape your search result presence.', 'seopulse'),
            'metaSignalSocialReady'    => __('Social cards are starting to reflect your brand voice.', 'seopulse'),
            'metaSignalSocialFill'     => __('Add Open Graph and Twitter assets for stronger sharing previews.', 'seopulse'),
            'metaSignalTrackReady'     => __('Measurement is connected to the onboarding plan.', 'seopulse'),
            'metaSignalTrackFill'      => __('Enable analytics only if this site is ready for measurement.', 'seopulse'),
            'metaSearchResult'         => __('Search result narrative', 'seopulse'),
            'metaSearchResultDesc'     => __('Title and description remain the primary assets shaping click-through in Google.', 'seopulse'),
            'metaSocialConsist'        => __('Social consistency', 'seopulse'),
            'metaSocialConsistDesc'    => __('Open Graph and Twitter fields keep preview cards aligned with your homepage positioning.', 'seopulse'),
            'metaCleanInst'            => __('Clean instrumentation', 'seopulse'),
            'metaCleanInstDesc'        => __('Turn on analytics only when your tags and measurement plan are actually ready to ship.', 'seopulse'),

            'localPresScore'           => __('Local presence score', 'seopulse'),
            'localPubOnly'             => __('Publish only the business details that are stable, verifiable and consistent with the contact information shown on the site.', 'seopulse'),
            'localAuthCues'            => __('Local authority cues', 'seopulse'),
            'localSignalIdentityReady' => __('Your business profile is clearly identified for structured data.', 'seopulse'),
            'localSignalIdentityFill'  => __('Clarify the business identity so search engines can classify the entity correctly.', 'seopulse'),
            'localSignalAddressReady'  => __('Location signals are almost complete for local discovery.', 'seopulse'),
            'localSignalAddressFill'   => __('Complete the postal address to improve local relevance and map consistency.', 'seopulse'),
            'localSignalGeoReady'      => __('Additional geo signals help define where the brand is active.', 'seopulse'),
            'localSignalGeoFill'       => __('Use optional geo fields only when they reflect real service areas.', 'seopulse'),
            'localEntityClar'          => __('Entity clarity', 'seopulse'),
            'localEntityClarDesc'      => __('A precise business type and name help search engines map the site to a real organization.', 'seopulse'),
            'localAddrTrust'           => __('Address trust', 'seopulse'),
            'localAddrTrustDesc'       => __('Postal data should match the site footer, contact page and directory listings.', 'seopulse'),
            'localServPerim'           => __('Service perimeter', 'seopulse'),
            'localServPerimDesc'       => __('Geo fields are strongest when they describe a real operating area, not an aspirational footprint.', 'seopulse'),

            // Local SEO accordion sections
            'lsIdentity'               => __('Identity', 'seopulse'),
            'lsMedia'                  => __('Media', 'seopulse'),
            'lsContact'                => __('Contact', 'seopulse'),
            'lsAddressArea'            => __('Address & Area', 'seopulse'),
            'lsOpeningHours'           => __('Opening Hours', 'seopulse'),
            'lsSocialKeywords'         => __('Social & Keywords', 'seopulse'),
            'lsRatingsPricing'         => __('Ratings & Pricing', 'seopulse'),

            // Local SEO Pro fields
            'pro'                      => __('Pro', 'seopulse'),
            'availableWithPro'         => __('Available with SEOPulse Pro', 'seopulse'),
            'lsSlogan'                 => __('Slogan', 'seopulse'),
            'placeholderSlogan'        => __('Your business tagline', 'seopulse'),
            'lsFoundingDate'           => __('Founding Date', 'seopulse'),
            'lsEmployees'              => __('Number of Employees', 'seopulse'),
            'lsImage'                  => __('Main Image URL', 'seopulse'),
            'lsImageHint'              => __('Your business representative image (storefront, interior, etc.).', 'seopulse'),
            'lsLogo'                   => __('Logo URL', 'seopulse'),
            'lsLogoHint'               => __('Schema.org recommends a distinct logo in addition to the main image.', 'seopulse'),
            'lsFax'                    => __('Fax Number', 'seopulse'),
            'lsEmail'                  => __('Email', 'seopulse'),
            'lsMapUrl'                 => __('Map URL', 'seopulse'),
            'lsMapUrlHint'             => __('URL to a Google Maps page or other map for your business.', 'seopulse'),
            'lsHoursHint'              => __('Define your business opening hours. You can add multiple time slots.', 'seopulse'),
            'lsOpens'                  => __('Opens', 'seopulse'),
            'lsCloses'                 => __('Closes', 'seopulse'),
            'lsAddTimeSlot'            => __('Add Time Slot', 'seopulse'),
            'remove'                   => __('Remove', 'seopulse'),
            'lsSocialLinks'            => __('Social Media Links', 'seopulse'),
            'lsSocialHint'             => __('Add links to your social media profiles (Facebook, Twitter, LinkedIn, Instagram, etc.).', 'seopulse'),
            'lsAddSocialLink'          => __('Add Social Link', 'seopulse'),
            'lsPriceRange'             => __('Price Range', 'seopulse'),
            'lsPriceRangeHint'         => __('Use dollar signs: $ (cheap), $$ (moderate), $$$ (expensive), $$$$ (very expensive).', 'seopulse'),
            'lsPayment'                => __('Payment Accepted', 'seopulse'),
            'lsCurrencies'             => __('Currencies Accepted', 'seopulse'),
            'lsRatingValue'            => __('Rating Value', 'seopulse'),
            'lsReviewCount'            => __('Review Count', 'seopulse'),
            'lsBestRating'             => __('Best Rating', 'seopulse'),
            'lsWorstRating'            => __('Worst Rating', 'seopulse'),
            'founder'                  => __('Founder', 'seopulse'),

            'sumConfigBlocks'          => __('Configured blocks', 'seopulse'),
            'sumConfigBlocksDesc'      => __('Sections already ready to ship without further edits.', 'seopulse'),
            'sumReadiness'             => __('Readiness', 'seopulse'),
            'sumRefinePass'            => __('Refinement passes', 'seopulse'),
            'sumRefinePassDesc'        => __('Areas that are usable, but still have room for stronger signals.', 'seopulse'),
            'sumOpenDec'               => __('Open decisions', 'seopulse'),
            'sumOpenDecDesc'           => __('Optional or skipped pieces that can be revisited later.', 'seopulse'),
            'sumLaunchPersp'           => __('Launch perspective', 'seopulse'),
            'sumFoundCoherent'         => __('The foundation is coherent enough to go live.', 'seopulse'),
            'sumFewEsst'               => __('A few essentials still need explicit validation.', 'seopulse'),
            'sumReviewCards'           => __('Review the cards below as a final editorial pass: make sure each configured block reflects the real positioning, audience and local footprint of the site.', 'seopulse'),
            'sumReadySpan'             => __('The wizard has enough validated inputs to publish a clean first SEO baseline.', 'seopulse'),
            'sumIncompleteSpan'        => __('Finish the missing essentials before closing the onboarding flow.', 'seopulse'),
            'sumReviewLaneLabel'       => __('Editorial review lane', 'seopulse'),
            'sumReviewLaneTitle'       => __('Three fast checks before you close the wizard.', 'seopulse'),
            'sumReviewLaneDesc'        => __('Use this final pass to confirm that positioning, snippet quality and local trust signals are aligned with the real site.', 'seopulse'),
            'sumEditorialMeta'         => __('Search snippet clarity', 'seopulse'),
            'sumEditorialMetaReady'    => __('Title and description already frame a usable search narrative.', 'seopulse'),
            'sumEditorialMetaTodo'     => __('Add both a clear title and meta description to avoid a weak first impression in search results.', 'seopulse'),
            'sumEditorialTrust'        => __('Local trust signals', 'seopulse'),
            'sumEditorialTrustReady'   => __('Business identity and location cues are strong enough for local relevance.', 'seopulse'),
            'sumEditorialTrustTodo'    => __('Business identity, address or phone details still need validation before local publication.', 'seopulse'),
            'sumEditorialProfile'      => __('Configuration fit', 'seopulse'),
            'sumEditorialProfileReady' => __('The saved profile gives the wizard a credible direction for defaults and summaries.', 'seopulse'),
            'sumEditorialProfileTodo'  => __('Save the site profile first so the rest of the setup reflects the right publishing context.', 'seopulse'),
            'sumCheckReady'            => __('Ready', 'seopulse'),
            'sumCheckAttention'        => __('Needs attention', 'seopulse'),

            'compSetupComplete'        => __('Setup complete', 'seopulse'),
            'compConfigModules'        => __('Configured modules', 'seopulse'),
            'compWhatHappens'          => __('What happens next', 'seopulse'),
            'compSeoReady'             => __('Your SEO baseline is ready.', 'seopulse'),
            'compReviewDash'           => __('Review the dashboard, refine page-level metadata, and keep improving your technical SEO signals from the admin area.', 'seopulse'),
            'compReady'                => __('Ready', 'seopulse'),
            'compTechBaseline'         => __('technical baseline', 'seopulse'),
            'compLive'                 => __('Live', 'seopulse'),
            'compMetaFramework'        => __('metadata framework', 'seopulse'),
            'compClear'                => __('Clear', 'seopulse'),
            'compNextActions'          => __('next actions', 'seopulse'),
            'compBadgeMeta'            => __('Metadata configured', 'seopulse'),
            'compBadgeLocal'           => __('Local signals prepared', 'seopulse'),
            'compBadgeDash'            => __('Dashboard next steps ready', 'seopulse'),
            'compRunwayLabel'          => __('Launch runway', 'seopulse'),
            'compRunwayTitle'          => __('Turn setup into operating momentum.', 'seopulse'),
            'compRunwayDesc'           => __('The wizard is complete, but the strongest SEO gains come from the first review cycle you run after onboarding.', 'seopulse'),
            'compRunwayStep1'          => __('Review the dashboard', 'seopulse'),
            'compRunwayStep1Desc'      => __('Use the dashboard as the control tower for configuration score, health checks and quick wins.', 'seopulse'),
            'compRunwayStep2'          => __('Optimize a real page', 'seopulse'),
            'compRunwayStep2Desc'      => __('Open a post or page to validate that your metadata framework also works at the editorial level.', 'seopulse'),
            'compRunwayStep3'          => __('Tighten technical signals', 'seopulse'),
            'compRunwayStep3Desc'      => __('Refine sitemap, archive visibility and redirections once the initial baseline is live.', 'seopulse'),

            'progSetupFlow'            => __('Setup flow', 'seopulse'),
            'progCheckpoints'          => __('checkpoints completed', 'seopulse'),
            'progCompleted'            => __('Completed', 'seopulse'),
            'progInProgress'           => __('In progress', 'seopulse'),
            'progUpcoming'             => __('Upcoming', 'seopulse'),

            // Navigation
            'next'                     => __('Next', 'seopulse'),
            'previous'                 => __('Previous', 'seopulse'),
            'save'                     => __('Save', 'seopulse'),
            'saving'                   => __('Saving...', 'seopulse'),
            'saveAndContinue'          => __('Save & Continue', 'seopulse'),
            'finishSetup'              => __('Finish Setup', 'seopulse'),
            'skipStep'                 => __('Skip this step', 'seopulse'),
            'edit'                     => __('Edit', 'seopulse'),

            // Sections
            'metaSeoSection'           => __('Meta SEO', 'seopulse'),
            'openGraphSection'         => __('Open Graph', 'seopulse'),
            'twitterSection'           => __('X Card (Twitter)', 'seopulse'),
            'trackingSection'          => __('Tracking & Analytics', 'seopulse'),
            'technicalSection'         => __('Technical', 'seopulse'),
            'businessSection'          => __('Business Info', 'seopulse'),
            'addressSection'           => __('Address', 'seopulse'),

            // Fields
            'siteTitle'                => __('Site Title', 'seopulse'),
            'metaDescription'          => __('Meta Description', 'seopulse'),
            'keywords'                 => __('Keywords', 'seopulse'),
            'canonicalUrl'             => __('Canonical URL', 'seopulse'),
            'author'                   => __('Author', 'seopulse'),
            'robots'                   => __('Robots', 'seopulse'),
            'themeColor'               => __('Theme Color', 'seopulse'),
            'removeGenerator'          => __('Remove WordPress Generator Tag', 'seopulse'),
            'ogTitle'                  => __('og:title', 'seopulse'),
            'ogDescription'            => __('og:description', 'seopulse'),
            'ogUrl'                    => __('og:url', 'seopulse'),
            'ogType'                   => __('og:type', 'seopulse'),
            'ogSiteName'               => __('og:site_name', 'seopulse'),
            'ogImage'                  => __('og:image', 'seopulse'),
            'chooseImage'              => __('Choose Image', 'seopulse'),
            'removeImage'              => __('Remove', 'seopulse'),
            'ogImageHint'              => __('Image for sharing (recommended: 1200x630px).', 'seopulse'),
            'twitterImageHint'         => __('Image for tweets (recommended: 1200x675px).', 'seopulse'),
            'hintTitleLength'          => __('50-60 characters recommended', 'seopulse'),
            'hintDescLength'           => __('120-160 characters recommended', 'seopulse'),
            'hintCommaSeparated'       => __('Comma-separated list', 'seopulse'),
            'hintRemoveGenerator'      => __('Hide WordPress version from page source', 'seopulse'),
            'hintIsoCode'              => __('ISO 3166-2 code', 'seopulse'),
            'hintLatLng'               => __('latitude;longitude', 'seopulse'),
            'selectImage'              => __('Select Image', 'seopulse'),
            'useThisImage'             => __('Use this image', 'seopulse'),
            'twitterCard'              => __('Card Type', 'seopulse'),
            'twitterSite'              => __('Site @username', 'seopulse'),
            'twitterCreator'           => __('Creator @username', 'seopulse'),
            'twitterTitle'             => __('Title', 'seopulse'),
            'twitterDescription'       => __('Description', 'seopulse'),
            'twitterImage'             => __('Image', 'seopulse'),
            'gtmEnabled'               => __('Enable Google Tag Manager', 'seopulse'),
            'gtmId'                    => __('GTM ID', 'seopulse'),
            'ga4Enabled'               => __('Enable Google Analytics 4', 'seopulse'),
            'ga4Id'                    => __('GA4 Measurement ID', 'seopulse'),
            'geoRegion'                => __('geo.region', 'seopulse'),
            'geoPlacename'             => __('geo.placename', 'seopulse'),
            'geoPosition'              => __('geo.position', 'seopulse'),
            'schemaType'               => __('Schema Type', 'seopulse'),
            'businessName'             => __('Business Name', 'seopulse'),
            'alternateName'            => __('Alternate Name', 'seopulse'),
            'description'              => __('Description', 'seopulse'),
            'websiteUrl'               => __('Website URL', 'seopulse'),
            'phoneNumber'              => __('Phone Number', 'seopulse'),
            'streetAddress'            => __('Street Address', 'seopulse'),
            'postalCode'               => __('Postal Code', 'seopulse'),
            'city'                     => __('City', 'seopulse'),
            'region'                   => __('State/Region', 'seopulse'),
            'country'                  => __('Country', 'seopulse'),
            'latitude'                 => __('Latitude', 'seopulse'),
            'longitude'                => __('Longitude', 'seopulse'),
            'serviceRegion'            => __('Service Region', 'seopulse'),
            'serviceCountry'           => __('Service Country', 'seopulse'),
            'hintSchemaType'           => __('Choose the type that best describes your business', 'seopulse'),
            'hintKeywords'             => __('Comma-separated list of keywords', 'seopulse'),
            'hintCountryCode'          => __('ISO 3166-1 alpha-2 (e.g., FR, US, UK)', 'seopulse'),
            'hintServiceRegion'        => __('Geographic area where you provide services', 'seopulse'),
            'placeholderAlternateName' => __('Also known as...', 'seopulse'),

            // Status
            'configured'               => __('Configured', 'seopulse'),
            'notConfigured'            => __('Not configured', 'seopulse'),
            'saved'                    => __('Settings saved.', 'seopulse'),
            'error'                    => __('An error occurred. Please try again.', 'seopulse'),

            // Steps
            'step'                     => __('Step', 'seopulse'),
            'of'                       => __('of', 'seopulse'),
            'welcome'                  => __('Welcome', 'seopulse'),
            'siteProfile'              => __('Site Profile', 'seopulse'),
            'metaSeo'                  => __('Meta SEO', 'seopulse'),
            'summary'                  => __('Summary', 'seopulse'),
            'complete'                 => __('Complete', 'seopulse'),

            // Profile step
            'profileTitle'             => __('Tell us about your site', 'seopulse'),
            'profileDescription'       => __('This helps SEOPulse tailor recommendations to your specific needs.', 'seopulse'),
            'siteType'                 => __('What type of site is this?', 'seopulse'),
            'activityType'             => __('What best describes you?', 'seopulse'),
            'seoPreset'                => __('Choose an SEO configuration preset', 'seopulse'),
            'optionalFeatures'         => __('Optional features', 'seopulse'),
            'wantsAnalytics'           => __('I want to configure analytics tracking (GTM / GA4)', 'seopulse'),
            'saveError'                => __('Failed to save profile.', 'seopulse'),
            'profileRecommended'       => __('Profile recommendation', 'seopulse'),
            'customValue'              => __('Custom value', 'seopulse'),
            'recommendationHint'       => __('Suggested from your site profile.', 'seopulse'),

            // Import step
            'importStep'               => __('Import', 'seopulse'),
            'importKicker'             => __('Migration', 'seopulse'),
            'importTitle'              => __('Import from another SEO plugin', 'seopulse'),
            'importDescription'        => __('We detected SEO data from another plugin. Import your existing settings so you don\'t start from scratch.', 'seopulse'),
            'importDetecting'          => __('Scanning your WordPress installation…', 'seopulse'),
            'importActive'             => __('Active', 'seopulse'),
            'importInactive'           => __('Inactive (data found)', 'seopulse'),
            'importBtn'                => __('Import settings', 'seopulse'),
            'importImporting'          => __('Importing…', 'seopulse'),
            'importSkip'               => __('Skip import', 'seopulse'),
            'importContinue'           => __('Continue with imported data', 'seopulse'),
            'importNoneTitle'          => __('No other SEO plugin detected', 'seopulse'),
            'importNoneDesc'           => __('Great — you\'re starting fresh. SEOPulse will configure everything from scratch.', 'seopulse'),
        ];
    }

    /**
     * Returns persisted recommendation provenance for wizard-managed fields.
     */
    private function get_recommendation_state(): array
    {
        return [
            'metaSeo'  => (array) get_option(Options::WIZARD_META_SEO_RECOMMENDATIONS, []),
            'localSeo' => (array) get_option(Options::WIZARD_LOCAL_SEO_RECOMMENDATIONS, []),
        ];
    }

    /**
     * Returns the stored site profile merged with defaults.
     */
    private function get_profile_with_defaults(): array
    {
        $profile = (array) get_option(Options::WIZARD_PROFILE, []);

        return array_merge(
            [
                'site_type'       => 'other',
                'activity_type'   => 'other',
                'seo_preset'      => 'default',
                'wants_analytics' => false,
                'wants_local_seo' => false,
                'completed_at'    => null,
                'wizard_version'  => null,
            ],
            $profile,
        );
    }

    /**
     * Detect SEO-related environment info to pass to the wizard frontend.
     */
    private function get_environment_info(): array
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $known_plugins = [
            [
                'slug'   => 'yoast',
                'name'   => 'Yoast SEO',
                'file'   => 'wordpress-seo/wp-seo.php',
                'option' => 'wpseo_titles',
            ],
            [
                'slug'   => 'rankmath',
                'name'   => 'Rank Math',
                'file'   => 'seo-by-rank-math/rank-math.php',
                'option' => 'rank-math-options-general',
            ],
            [
                'slug'   => 'seopress',
                'name'   => 'SEOPress',
                'file'   => 'wp-seopress/seopress.php',
                'option' => 'seopress_activated',
            ],
            [
                'slug'   => 'aioseo',
                'name'   => 'All in One SEO',
                'file'   => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
                'option' => 'aioseo_options',
            ],
        ];

        $detected = [];
        foreach ($known_plugins as $plugin) {
            $active   = is_plugin_active($plugin['file']);
            $has_data = (bool) get_option($plugin['option'], false);

            if ($active || $has_data) {
                $detected[] = [
                    'slug'      => $plugin['slug'],
                    'name'      => $plugin['name'],
                    'active'    => $active,
                    'canImport' => $has_data && in_array($plugin['slug'], ['yoast', 'rankmath', 'seopress'], true),
                ];
            }
        }

        return [
            'seoPlugins'     => $detected,
            'hasWooCommerce' => class_exists('WooCommerce') || is_plugin_active('woocommerce/woocommerce.php'),
            'phpVersion'     => PHP_VERSION,
            'wpVersion'      => get_bloginfo('version'),
            'isMultisite'    => is_multisite(),
            'postCount'      => (int) wp_count_posts('post')->publish,
            'pageCount'      => (int) wp_count_posts('page')->publish,
        ];
    }

}
