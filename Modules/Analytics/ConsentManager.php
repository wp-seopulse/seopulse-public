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
        return wp_parse_args(
            get_option(self::OPTION_KEY, []),
            self::getDefaults(),
        );
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

            // Google Consent Mode v2
            'gcm_v2_enabled'      => true,

            // Tracking (GTM / GA4)
            'gtm_enabled'         => false,
            'gtm_id'              => '',
            'ga4_enabled'         => false,
            'ga4_id'              => '',

            // GA4 advanced
            'ga4_exclude_admins'      => false,
            'ga4_exclude_roles'       => [],
            'ga4_track_outbound'      => false,
            'ga4_track_downloads'     => false,
            'ga4_download_extensions' => 'pdf,zip,doc,docx,xls,xlsx,ppt,pptx',
            'ga4_custom_head_code'    => '',
            'ga4_custom_footer_code'  => '',

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
        $booleans = ['enabled', 'gcm_v2_enabled', 'log_consents', 'gtm_enabled', 'ga4_enabled', 'ga4_exclude_admins', 'ga4_track_outbound', 'ga4_track_downloads'];
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

        // Google Analytics (admin-only, stored as-is)
        foreach (['ga4_custom_head_code', 'ga4_custom_footer_code'] as $key) {
            if (isset($input[$key])) {
                $sanitized[$key] = wp_unslash((string) $input[$key]);
            }
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
            ? sanitize_text_field(wp_unslash($_COOKIE[ $this->cookieName ]))
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
