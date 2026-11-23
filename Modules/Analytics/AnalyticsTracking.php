<?php

/**
 * Tracking management (GTM, GA4, Analytics)
 *
 * Injects Google Tag Manager and Google Analytics 4 scripts
 * on the frontend when enabled in the Analytics settings.
 *
 * @package SEOPulse\Modules\Analytics
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Analytics;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;

/**
 * AnalyticsTracking class
 *
 * Handles injection of GTM and GA4 tracking scripts.
 */
class AnalyticsTracking
{
    /**
     * Normalizes a Matomo base URL (https/http, no query/fragment, trailing slash).
     *
     * @param string $url Raw user input
     * @return string Empty string when invalid
     */
    public static function normalizeMatomoUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9.\-]+$/', (string) $parts['host'])) {
            return '';
        }

        $clean = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $clean .= ':' . (int) $parts['port'];
        }

        $path = isset($parts['path']) ? preg_replace('#[^A-Za-z0-9._~/\-]#', '', (string) $parts['path']) : '';

        return $clean . rtrim((string) $path, '/') . '/';
    }

    /**
     * Matomo site IDs are positive integers.
     */
    public static function normalizeMatomoSiteId(string $id): string
    {
        $id = trim($id);

        return preg_match('/^[1-9][0-9]{0,9}$/', $id) === 1 ? $id : '';
    }

    /**
     * Microsoft Clarity project IDs are short alphanumeric strings.
     */
    public static function normalizeClarityId(string $id): string
    {
        $id = trim($id);

        return preg_match('/^[A-Za-z0-9]{6,20}$/', $id) === 1 ? $id : '';
    }

    /**
     * Get tracking settings from the analytics option
     *
     * @return array<string, mixed>
     */
    private function getTrackingSettings(): array
    {
        $settings = get_option(Options::ANALYTICS, []);

        return [
            'matomo_enabled' => !empty($settings['matomo_enabled']),
            'matomo_url'     => (string) ($settings['matomo_url'] ?? ''),
            'matomo_site_id' => (string) ($settings['matomo_site_id'] ?? ''),
            'clarity_enabled' => !empty($settings['clarity_enabled']),
            'clarity_id'     => (string) ($settings['clarity_id'] ?? ''),
            'gtm_enabled' => !empty($settings['gtm_enabled']),
            'gtm_id'      => $settings['gtm_id'] ?? '',
            'ga4_enabled' => !empty($settings['ga4_enabled']),
            'ga4_id'      => $settings['ga4_id'] ?? '',
            'ga4_exclude_admins'      => !empty($settings['ga4_exclude_admins']),
            'ga4_exclude_roles'       => $settings['ga4_exclude_roles'] ?? [],
            'ga4_track_outbound'      => !empty($settings['ga4_track_outbound']),
            'ga4_track_downloads'     => !empty($settings['ga4_track_downloads']),
            'ga4_download_extensions' => $settings['ga4_download_extensions'] ?? 'pdf,zip,doc,docx,xls,xlsx',
            // Custom code (legacy ga4_custom_* keys kept as fallback until the next save)
            'custom_head_code'        => $settings['custom_head_code'] ?? $settings['ga4_custom_head_code'] ?? '',
            'custom_footer_code'      => $settings['custom_footer_code'] ?? $settings['ga4_custom_footer_code'] ?? '',
        ];
    }

    /**
     * Check if current user should be excluded from tracking
     */
    public function should_exclude_current_user(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $settings = $this->getTrackingSettings();
        $user = wp_get_current_user();

        // Exclude admins
        if (!empty($settings['ga4_exclude_admins']) && in_array('administrator', (array) $user->roles, true)) {
            return true;
        }

        // Exclude specific roles
        $excluded_roles = $settings['ga4_exclude_roles'] ?? [];
        if (!empty($excluded_roles)) {
            foreach ((array) $user->roles as $role) {
                if (in_array($role, $excluded_roles, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Inject outbound link + download tracking scripts
     */
    public function inject_event_tracking(): void
    {
        $settings = $this->getTrackingSettings();

        if (empty($settings['ga4_enabled']) || empty($settings['ga4_id'])) {
            return;
        }

        if ($this->should_exclude_current_user()) {
            return;
        }

        $track_outbound  = !empty($settings['ga4_track_outbound']);
        $track_downloads = !empty($settings['ga4_track_downloads']);

        if (!$track_outbound && !$track_downloads) {
            return;
        }

        $extensions = '';
        if ($track_downloads) {
            $raw_ext    = sanitize_text_field($settings['ga4_download_extensions'] ?? 'pdf,zip,doc,docx,xls,xlsx');
            $ext_array  = array_filter(array_map('trim', explode(',', $raw_ext)));
            $extensions = implode('|', array_map('preg_quote', $ext_array));
        }

        $outbound_js  = $track_outbound ? 'true' : 'false';
        $download_js  = $track_downloads ? 'true' : 'false';
        $ext_regex_js = !empty($extensions) ? esc_js($extensions) : '';

        $script = sprintf(
            "(function(){
                var trackOutbound  = %s;
                var trackDownloads = %s;
                var dlExtensions   = /\\.(%s)(\?.*)?$/i;
                var siteHost       = window.location.hostname;
                document.addEventListener('click', function(e) {
                    var el = e.target.closest('a[href]');
                    if (!el) return;
                    var href = el.getAttribute('href') || '';
                    if (trackDownloads && dlExtensions.test(href)) {
                        gtag('event', 'file_download', {
                            file_name: href.split('/').pop().split('?')[0],
                            file_extension: href.split('.').pop().split('?')[0].toLowerCase(),
                            link_url: href
                        });
                        return;
                    }
                    if (trackOutbound) {
                        try {
                            var linkHost = new URL(href, window.location.href).hostname;
                            if (linkHost && linkHost !== siteHost) {
                                gtag('event', 'click', {
                                    event_category: 'outbound',
                                    event_label: href,
                                    transport_type: 'beacon'
                                });
                            }
                        } catch(err) {}
                    }
                }, true);
            })();",
            $outbound_js,
            $download_js,
            $ext_regex_js,
        );

        wp_register_script('seopulse-ga4-events', false, ['google-analytics-gtag'], SEOPULSE_VERSION, true);
        wp_enqueue_script('seopulse-ga4-events');
        wp_add_inline_script('seopulse-ga4-events', $script);
    }

    /**
     * Inject custom code (head and footer) server-side.
     *
     * Independent of GA4: does not require gtag nor any tracking provider.
     * Only hooked when the consent module is disabled; otherwise the code is
     * run by seopulse-consent.js after consent (see AnalyticsModule).
     *
     * @return void
     */
    public function inject_custom_code(): void
    {
        $this->inject_custom_head_code();
        $this->inject_custom_footer_code();
    }

    /**
     * Inject custom code — head
     */
    public function inject_custom_head_code(): void
    {
        $settings = $this->getTrackingSettings();

        if (empty($settings['custom_head_code'])) {
            return;
        }

        if ($this->should_exclude_current_user()) {
            return;
        }

        wp_register_script('seopulse-custom-head', false, [], SEOPULSE_VERSION, false);
        wp_enqueue_script('seopulse-custom-head');
        wp_add_inline_script('seopulse-custom-head', $settings['custom_head_code']);
    }

    /**
     * Inject custom code — footer
     */
    public function inject_custom_footer_code(): void
    {
        $settings = $this->getTrackingSettings();

        if (empty($settings['custom_footer_code'])) {
            return;
        }

        if ($this->should_exclude_current_user()) {
            return;
        }

        wp_register_script('seopulse-custom-footer', false, [], SEOPULSE_VERSION, true);
        wp_enqueue_script('seopulse-custom-footer');
        wp_add_inline_script('seopulse-custom-footer', $settings['custom_footer_code']);
    }

    /**
     * Injects the Matomo tracker (self-hosted or cloud, URL provided by the site owner)
     *
     * @return void
     */
    public function inject_matomo(): void
    {
        $tracking = $this->getTrackingSettings();

        if (empty($tracking['matomo_enabled'])) {
            return;
        }

        $base    = self::normalizeMatomoUrl($tracking['matomo_url']);
        $site_id = self::normalizeMatomoSiteId($tracking['matomo_site_id']);

        if ($base === '' || $site_id === '' || $this->should_exclude_current_user()) {
            return;
        }

        wp_register_script('seopulse-matomo', false, [], SEOPULSE_VERSION, false);
        wp_enqueue_script('seopulse-matomo');
        wp_add_inline_script('seopulse-matomo', self::matomoSnippet($base, $site_id));
    }

    /**
     * Injects Microsoft Clarity
     *
     * @return void
     */
    public function inject_clarity(): void
    {
        $tracking = $this->getTrackingSettings();

        if (empty($tracking['clarity_enabled'])) {
            return;
        }

        $clarity_id = self::normalizeClarityId($tracking['clarity_id']);

        if ($clarity_id === '' || $this->should_exclude_current_user()) {
            return;
        }

        wp_register_script('seopulse-clarity', false, [], SEOPULSE_VERSION, false);
        wp_enqueue_script('seopulse-clarity');
        wp_add_inline_script('seopulse-clarity', self::claritySnippet($clarity_id));
    }

    /**
     * Matomo JS snippet for already validated inputs.
     */
    public static function matomoSnippet(string $base_url, string $site_id): string
    {
        $base = wp_json_encode($base_url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        $id   = wp_json_encode($site_id);

        return 'var _paq=window._paq=window._paq||[];'
            . "_paq.push(['trackPageView']);_paq.push(['enableLinkTracking']);"
            . '(function(){var u=' . $base . ';'
            . "_paq.push(['setTrackerUrl',u+'matomo.php']);_paq.push(['setSiteId'," . $id . ']);'
            . "var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];"
            . "g.async=true;g.src=u+'matomo.js';s.parentNode.insertBefore(g,s);})();";
    }

    /**
     * Clarity JS snippet for an already validated project ID.
     */
    public static function claritySnippet(string $project_id): string
    {
        return '(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};'
            . 't=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;'
            . 'y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})'
            . '(window,document,"clarity","script",' . wp_json_encode($project_id) . ');';
    }

    /**
     * Injects Google Tag Manager in the <head>
     *
     * @return void
     */
    public function inject_gtm_head(): void
    {
        $tracking = $this->getTrackingSettings();

        if (empty($tracking['gtm_enabled']) || empty($tracking['gtm_id'])) {
            return;
        }

        if ($this->should_exclude_current_user()) {
            return;
        }

        $gtm_id = sanitize_text_field($tracking['gtm_id']);

        if (!preg_match('/^GTM-[A-Z0-9]+$/', $gtm_id)) {
            return;
        }

        wp_register_script('seopulse-gtm', false, [], SEOPULSE_VERSION, false);
        wp_enqueue_script('seopulse-gtm');

        $inline = "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
            . "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';"
            . "j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;"
            . 'f.parentNode.insertBefore(j,f);'
            . "})(window,document,'script','dataLayer','" . esc_js($gtm_id) . "');";

        wp_add_inline_script('seopulse-gtm', $inline);
    }

    /**
     * Injects Google Tag Manager noscript
     *
     * @return void
     */
    public function inject_gtm_noscript(): void
    {
        $tracking = $this->getTrackingSettings();

        if (empty($tracking['gtm_enabled']) || empty($tracking['gtm_id'])) {
            return;
        }

        if ($this->should_exclude_current_user()) {
            return;
        }

        $gtm_id = sanitize_text_field($tracking['gtm_id']);

        if (!preg_match('/^GTM-[A-Z0-9]+$/', $gtm_id)) {
            return;
        }

        ?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe
        src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($gtm_id); ?>"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
<?php
    }

    /**
     * Injects Google Analytics 4
     *
     * @return void
     */
    public function inject_analytics(): void
    {
        $tracking = $this->getTrackingSettings();

        if (empty($tracking['ga4_enabled']) || empty($tracking['ga4_id'])) {
            return;
        }

        if ($this->should_exclude_current_user()) {
            return;
        }

        $ga4_id = sanitize_text_field($tracking['ga4_id']);

        if (!preg_match('/^G-[A-Z0-9]+$/', $ga4_id)) {
            return;
        }

        wp_enqueue_script(
            'google-analytics-gtag',
            'https://www.googletagmanager.com/gtag/js?id=' . esc_attr($ga4_id),
            [],
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
            null,
            true,
        );

        $inline_script = sprintf(
            "window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '%s');",
            esc_js($ga4_id),
        );

        wp_add_inline_script('google-analytics-gtag', $inline_script);

        // Event tracking (depends on gtag being loaded)
        $this->inject_event_tracking();
    }
}
?>