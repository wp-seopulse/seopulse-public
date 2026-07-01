<?php

/**
 * Organization JSON-LD schema provider
 *
 * Generates Organization schema for the site homepage.
 * Separate from OrgProvider (which resolves org.* template variables).
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
 * OrgSchemaProvider — generates standalone Organization JSON-LD schema
 *
 * Used by WebSiteProvider for the @graph on the homepage, and can also
 * be injected standalone on inner pages via SchemaFactory.
 */
final class OrgSchemaProvider implements SchemaProvider
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
        return 'Organization';
    }

    /**
     * Should inject only when WebSiteProvider is NOT active (non-homepage pages).
     * On the homepage, Organization is already included in WebSiteProvider's @graph.
     *
     * @return bool
     */
    public function should_inject(): bool
    {
        // Organization is already embedded in WebSiteProvider on the homepage
        if (is_front_page()) {
            return false;
        }

        // Only inject on singular pages and archives (not 404s, search, etc.)
        return is_singular() || is_archive() || is_home();
    }

    /**
     * Build Organization schema from Local SEO settings or site defaults
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $settings = $this->get_org_settings();
        $name     = $this->str($settings['company_name'] ?? $settings['name'] ?? get_bloginfo('name'));

        if (empty($name)) {
            return [];
        }

        $site_url = home_url('/');

        $org = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            '@id'      => $site_url . '#organization',
            'name'     => $name,
            'url'      => $site_url,
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

        return $org;
    }

    /**
     * Validate the schema
     *
     * @return bool
     */
    public function validate(): bool
    {
        $name = ($this->get_org_settings())['company_name']
            ?? ($this->get_org_settings())['name']
            ?? get_bloginfo('name');

        if (empty($name)) {
            $this->error = 'Organization requires name';

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
     * Get site logo URL
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

    /**
     * Safely cast a value to string (handles arrays from settings).
     */
    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
