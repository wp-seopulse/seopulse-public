<?php

/**
 * WebSite + SearchAction + Organization schema provider for JSON-LD
 *
 * Injects site-wide schemas on the homepage only.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;

/**
 * WebSiteProvider — generates WebSite + SearchAction + Organization schemas
 * for the homepage only, injected via wp_head.
 */
final class WebSiteProvider implements SchemaProvider
{
    /**
     * Store error message
     *
     * @var string|null
     */
    private ?string $error = null;

    /**
     * Get the schema type
     *
     * @return string
     */
    public function get_type(): string
    {
        return 'WebSite';
    }

    /**
     * Check if this provider should inject on the current request
     *
     * Only inject on the homepage to avoid duplication.
     *
     * @return bool
     */
    public function should_inject(): bool
    {
        // Check admin toggle (default: enabled)
        $settings = get_option('seopulse_meta_seo_global', []);
        if (isset($settings['schema_website_enabled']) && !$settings['schema_website_enabled']) {
            return false;
        }

        return is_front_page();
    }

    /**
     * Build the WebSite schema with SearchAction and Organization
     *
     * Returns an @graph array containing WebSite + Organization.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $site_url  = home_url('/');
        $site_name = get_bloginfo('name');
        $site_desc = get_bloginfo('description');

        // WebSite schema
        $website = [
            '@type'           => 'WebSite',
            '@id'             => $site_url . '#website',
            'url'             => $site_url,
            'name'            => $site_name,
            'potentialAction' => $this->build_search_action($site_url),
        ];

        if (!empty($site_desc)) {
            $website['description'] = $site_desc;
        }

        // Link to Organization if available
        $org = $this->build_organization();
        if (!empty($org)) {
            $website['publisher'] = ['@id' => $site_url . '#organization'];
        }

        // Build @graph combining WebSite + Organization
        $graph = [$website];

        if (!empty($org)) {
            // Add @id to org for cross-referencing
            $org['@id'] = $site_url . '#organization';
            $graph[]    = $org;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];
    }

    /**
     * Validate the schema
     *
     * @return bool
     */
    public function validate(): bool
    {
        $site_name = get_bloginfo('name');

        if (empty($site_name)) {
            $this->error = 'WebSite requires site name';

            return false;
        }

        return true;
    }

    /**
     * Get error message if validation failed
     *
     * @return string|null
     */
    public function get_error(): ?string
    {
        return $this->error;
    }

    /**
     * Build the SearchAction schema for WordPress native search
     *
     * @param string $site_url
     *
     * @return array<string, mixed>
     */
    private function build_search_action(string $site_url): array
    {
        $search_url = $site_url . '?s={search_term_string}';

        return [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => $search_url,
            ],
            'query-input' => 'required name=search_term_string',
        ];
    }

    /**
     * Build Organization schema from Local SEO settings or site defaults
     *
     * Returns empty array if insufficient data available.
     *
     * @return array<string, mixed>
     */
    private function build_organization(): array
    {
        $settings = $this->get_org_settings();

        $name = $this->str($settings['company_name'] ?? $settings['name'] ?? get_bloginfo('name'));

        if (empty($name)) {
            return [];
        }

        $org = [
            '@type' => 'Organization',
            'name'  => $name,
            'url'   => home_url('/'),
        ];

        // Logo
        $logo = $this->str($settings['logo'] ?? $this->get_site_logo());
        if (!empty($logo)) {
            $org['logo'] = [
                '@type' => 'ImageObject',
                'url'   => esc_url($logo),
            ];
        }

        // Contact info
        $email = $this->str($settings['email'] ?? '');
        if (!empty($email)) {
            $org['email'] = sanitize_email($email);
        }

        $phone = $this->str($settings['phone'] ?? $settings['telephone'] ?? '');
        if (!empty($phone)) {
            $org['telephone'] = $phone;
        }

        // Address
        $address = $this->build_address($settings);
        if (!empty($address)) {
            $org['address'] = $address;
        }

        // Social profiles
        $social = $this->get_social_profiles($settings);
        if (!empty($social)) {
            $org['sameAs'] = $social;
        }

        return $org;
    }

    /**
     * Build PostalAddress from Local SEO settings
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function build_address(array $settings): array
    {
        $street  = $this->str($settings['address'] ?? $settings['street_address'] ?? '');
        $city    = $this->str($settings['city'] ?? '');
        $country = $this->str($settings['country'] ?? '');

        if (empty($street) && empty($city)) {
            return [];
        }

        $address = ['@type' => 'PostalAddress'];

        if (!empty($street)) {
            $address['streetAddress'] = $street;
        }
        if (!empty($city)) {
            $address['addressLocality'] = $city;
        }

        $state = $this->str($settings['state'] ?? '');
        if (!empty($state)) {
            $address['addressRegion'] = $state;
        }

        $zip = $this->str($settings['zip'] ?? $settings['postal_code'] ?? '');
        if (!empty($zip)) {
            $address['postalCode'] = $zip;
        }
        if (!empty($country)) {
            $address['addressCountry'] = $country;
        }

        return $address;
    }

    /**
     * Get social profile URLs from settings
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string>
     */
    private function get_social_profiles(array $settings): array
    {
        $profiles = [];
        $networks = ['facebook', 'twitter', 'linkedin', 'instagram', 'youtube', 'pinterest'];

        foreach ($networks as $network) {
            $url = $this->str($settings[ $network ] ?? $settings[ $network . '_url' ] ?? '');
            if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                $profiles[] = esc_url($url);
            }
        }

        return $profiles;
    }

    /**
     * Safely cast a value to string (handles arrays from settings).
     *
     * @param mixed $value
     *
     * @return string
     */
    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Get organization settings from Local SEO option
     *
     * @return array<string, mixed>
     */
    private function get_org_settings(): array
    {
        $settings = get_option(Options::LOCAL_SEO, []);

        return is_array($settings) ? $settings : [];
    }

    /**
     * Get site logo URL from theme or Local SEO settings
     *
     * @return string
     */
    private function get_site_logo(): string
    {
        if (has_custom_logo()) {
            $logo_id  = (int) get_theme_mod('custom_logo');
            $logo_url = wp_get_attachment_image_url($logo_id, 'full');
            if ($logo_url) {
                return $logo_url;
            }
        }

        return '';
    }
}
