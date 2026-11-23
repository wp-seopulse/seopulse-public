<?php

/**
 * Consent Manager — Server-side consent logic
 *
 * Reads/writes consent settings and validates consent state from cookies.
 *
 * @package SEOPulse\Modules\Analytics
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Analytics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ConsentManager class
 *
 * Handles server-side consent operations: reading settings, checking cookies,
 * and providing consent data for conditional script loading.
 */
class ConsentManager
{
    /**
     * Option key for analytics/consent settings
     *
     * @var string
     */
    public const OPTION_KEY = 'seopulse_analytics_settings';

    /**
     * Cookie name for consent storage
     *
     * @var string
     */
    private string $cookieName;

    /**
     * Constructor
     */
    public function __construct()
    {
        $settings         = $this->getSettings();
        $this->cookieName = $settings['cookie_name'] ?? 'seopulse_consent';
    }

    /**
     * Get all analytics/consent settings
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        $stored = is_array($stored) ? $stored : [];

        return wp_parse_args(
            self::migrateLegacyKeys($stored),
            self::getDefaults(),
        );
    }

    /**
     * Legacy option keys renamed when custom code was decoupled from GA4
     *
     * @var array<string, string> old key => new key
     */
    private const LEGACY_KEYS = [
        'ga4_custom_head_code'   => 'custom_head_code',
        'ga4_custom_footer_code' => 'custom_footer_code',
    ];

    /**
     * Map legacy keys (ga4_custom_*) to their new names.
     *
     * Non-destructive for the database: the legacy keys disappear from the
     * stored option on the next save, since saveSettings() only writes
     * sanitized (new) keys.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private static function migrateLegacyKeys(array $stored): array
    {
        foreach (self::LEGACY_KEYS as $old => $new) {
            if (array_key_exists($old, $stored)) {
                if (!array_key_exists($new, $stored)) {
                    $stored[ $new ] = $stored[ $old ];
                }
                unset($stored[ $old ]);
            }
        }

        return $stored;
    }

    /**
     * Save analytics/consent settings
     *
     * @param array<string, mixed> $settings
     * @return bool
     */
    public function saveSettings(array $settings): bool
    {
        $sanitized = $this->sanitizeSettings($settings);

        return update_option(self::OPTION_KEY, $sanitized);
    }

    /**
     * Settings keys holding a CSS color (cookie bar customization)
     *
     * @var string[]
     */
    public const COLOR_KEYS = [
        'backdrop_bg_color',
        'main_bg_color',
        'main_text_color',
        'main_link_color',
        'primary_btn_bg_color',
        'primary_btn_bg_color_hover',
        'primary_btn_text_color',
        'primary_btn_text_color_hover',
        'secondary_btn_bg_color',
        'secondary_btn_bg_color_hover',
        'secondary_btn_text_color',
        'secondary_btn_text_color_hover',
    ];

    /**
     * Sanitize a CSS color: hex (#rgb, #rgba, #rrggbb, #rrggbbaa), rgb()/rgba() or hsl()/hsla().
     * Anything else is dropped (empty string = default color).
     *
     * @param string $color
     * @return string
     */
    public static function sanitizeColor(string $color): string
    {
        $color = trim($color);

        if ($color === '') {
            return '';
        }

        if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color) === 1) {
            return strtolower($color);
        }

        if (preg_match('/^(?:rgb|hsl)a?\(\s*[0-9.%\s,\/-]+\)$/i', $color) === 1) {
            return $color;
        }

        return '';
    }

    /**
     * Get default settings
     *
     * @return array<string, mixed>
     */
    public static function getDefaults(): array
    {
        return [
            // General
            'enabled'             => false,
            'cookie_name'         => 'seopulse_consent',
            'cookie_expiry'       => 365,

            // Appearance
            'position'            => 'bottom', // 'bottom' | 'top' | 'bottom-left' | 'bottom-right'
            'theme'               => 'light',   // 'light' | 'dark' | 'auto'

            // Texts
            'banner_title'        => '',
            'banner_description'  => '',
            'btn_accept_all'      => '',
            'btn_reject_all'      => '',
            'btn_customize'       => '',
            'btn_save'            => '',
            'privacy_policy_text' => '',
            'privacy_policy_url'  => '',

            // Cookie bar customization (empty color = default, shown as placeholder in the admin)
            'backdrop_enabled'               => false,
            'backdrop_bg_color'              => '',
            'main_bg_color'                  => '',
            'main_text_color'                => '',
            'main_link_color'                => '',
            'primary_btn_bg_color'           => '',
            'primary_btn_bg_color_hover'     => '',
            'primary_btn_text_color'         => '',
            'primary_btn_text_color_hover'   => '',
            'secondary_btn_bg_color'         => '',
            'secondary_btn_bg_color_hover'   => '',
            'secondary_btn_text_color'       => '',
            'secondary_btn_text_color_hover' => '',

            // Google Consent Mode v2
            'gcm_v2_enabled'      => true,

            // Tracking (GTM / GA4)
            'gtm_enabled'         => false,
            'gtm_id'              => '',
            'ga4_enabled'         => false,
            'ga4_id'              => '',

            // Matomo / Microsoft Clarity
            'matomo_enabled'      => false,
            'matomo_url'          => '',
            'matomo_site_id'      => '',
            'clarity_enabled'     => false,
            'clarity_id'          => '',

            // GA4 advanced
            'ga4_exclude_admins'      => false,
            'ga4_exclude_roles'       => [],
            'ga4_track_outbound'      => false,
            'ga4_track_downloads'     => false,
            'ga4_download_extensions' => 'pdf,zip,doc,docx,xls,xlsx,ppt,pptx',

            // Custom code (independent of any tracking provider)
            'custom_head_code'    => '',
            'custom_footer_code'  => '',

            // Consent logging (structure ready)
            'log_consents'        => false,
        ];
    }

    /**
     * Sanitize settings before saving
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitizeSettings(array $input): array
    {
        $sanitized = [];

        // Booleans
        $booleans = ['enabled', 'gcm_v2_enabled', 'log_consents', 'gtm_enabled', 'ga4_enabled', 'ga4_exclude_admins', 'ga4_track_outbound', 'ga4_track_downloads', 'matomo_enabled', 'clarity_enabled', 'backdrop_enabled'];
        foreach ($booleans as $key) {
            $sanitized[ $key ] = !empty($input[ $key ]);
        }

        // Text fields
        $textFields = [
            'cookie_name',
            'position',
            'theme',
            'banner_title',
            'banner_description',
            'btn_accept_all',
            'btn_reject_all',
            'btn_customize',
            'btn_save',
            'privacy_policy_text',
            'gtm_id',
            'ga4_id',
            'ga4_download_extensions',
        ];
        foreach ($textFields as $key) {
            if (isset($input[ $key ])) {
                $sanitized[ $key ] = sanitize_text_field($input[ $key ]);
            }
        }

        // Matomo / Clarity identifiers (strictly validated; invalid values are dropped)
        $sanitized['matomo_url']     = AnalyticsTracking::normalizeMatomoUrl((string) ($input['matomo_url'] ?? ''));
        $sanitized['matomo_site_id'] = AnalyticsTracking::normalizeMatomoSiteId((string) ($input['matomo_site_id'] ?? ''));
        $sanitized['clarity_id']     = AnalyticsTracking::normalizeClarityId((string) ($input['clarity_id'] ?? ''));

        // Custom code (admin-only, stored as-is)
        foreach (['custom_head_code', 'custom_footer_code'] as $key) {
            if (isset($input[$key])) {
                $sanitized[$key] = wp_unslash((string) $input[$key]);
            }
        }

        // Cookie bar colors (empty string = default)
        foreach (self::COLOR_KEYS as $key) {
            $sanitized[ $key ] = self::sanitizeColor((string) ($input[ $key ] ?? ''));
        }

        // Roles array
        if (isset($input['ga4_exclude_roles']) && is_array($input['ga4_exclude_roles'])) {
            $valid_roles = array_keys(wp_roles()->roles);
            $sanitized['ga4_exclude_roles'] = array_values(
                array_filter(
                    array_map('sanitize_text_field', $input['ga4_exclude_roles']),
                    fn ($r) => in_array($r, $valid_roles, true),
                ),
            );
        } else {
            $sanitized['ga4_exclude_roles'] = [];
        }

        // URL fields
        if (isset($input['privacy_policy_url'])) {
            $sanitized['privacy_policy_url'] = esc_url_raw($input['privacy_policy_url']);
        }

        // Numeric fields
        if (isset($input['cookie_expiry'])) {
            $sanitized['cookie_expiry'] = max(1, min(730, (int) $input['cookie_expiry']));
        }

        return $sanitized;
    }

    /**
     * Read consent state from cookie (server-side)
     *
     * @return array<string, bool>|null Null if no consent cookie exists
     */
    public function getConsentFromCookie(): ?array
    {
        $cookieValue = isset($_COOKIE[ $this->cookieName ])
            ? sanitize_text_field(wp_unslash($_COOKIE[ $this->cookieName ] ?? ''))
            : null;

        if ($cookieValue === null) {
            return null;
        }

        $decoded = json_decode($cookieValue, true);

        if (!is_array($decoded) || !isset($decoded['categories'])) {
            return null;
        }

        $categories = $decoded['categories'];

        if (!is_array($categories)) {
            return null;
        }

        return [
            'essential'   => true, // Always active
            'statistics'  => !empty($categories['statistics']),
            'marketing'   => !empty($categories['marketing']),
            'preferences' => !empty($categories['preferences']),
        ];
    }

    /**
     * Check if a specific consent category is granted
     *
     * @param string $category Category name (statistics, marketing, preferences)
     * @return bool
     */
    public function hasConsent(string $category): bool
    {
        $consent = $this->getConsentFromCookie();

        if ($consent === null) {
            return false;
        }

        return !empty($consent[ $category ]);
    }

    /**
     * Get consent log table name (for future use)
     *
     * @return string
     */
    public static function getConsentLogTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'seopulse_consent_log';
    }
}
