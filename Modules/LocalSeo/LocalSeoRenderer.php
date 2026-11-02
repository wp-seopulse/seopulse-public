<?php

/**
 * Renderer for JSON-LD Schema.org
 *
 * @package SEOPulse\Modules\LocalSeo
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\LocalSeo;

/**
 * LocalSeoRenderer class
 */
class LocalSeoRenderer
{
    /**
     * Whether the Local SEO JSON-LD is (or will be) printed on the frontend.
     *
     * Other providers use it to avoid emitting a second Organization node.
     *
     * @return bool
     */
    public static function is_output_active(): bool
    {
        if (!\SEOPulse\Core\Module\ModuleManager::instance()->isModuleEnabled('local_seo')
            || \SEOPulse\Modules\MetaSeo\Engine\Providers\SchemaInjector::has_existing_schema()
        ) {
            return false;
        }

        $settings = get_option('seopulse_local_seo_settings', []);

        return !empty($settings) && isset($settings['@context']);
    }

    /**
     * The Local SEO JSON-LD node, with the @id other schemas use to reference the Organization.
     *
     * @return array<string, mixed>|null Null when nothing should be printed.
     */
    public static function get_node(): ?array
    {
        if (!self::is_output_active()) {
            return null;
        }

        $settings = get_option('seopulse_local_seo_settings', []);

        if (!isset($settings['@id'])) {
            $settings['@id'] = home_url('/') . '#organization';
        }

        return $settings;
    }

    /**
     * Injects JSON-LD LocalBusiness into the <head>.
     *
     * The Local SEO module is the sole authority for LocalBusiness schema injection.
     *
     * @return void
     */
    public function inject_jsonld(): void
    {
        $settings = self::get_node();

        if ($settings === null) {
            return;
        }

        echo "\n<!-- SEOPulse Local SEO JSON-LD -->\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode applies JSON_HEX_TAG|JSON_HEX_AMP; esc_html would break JSON-LD.
        echo '<script type="application/ld+json">' . wp_json_encode($settings) . '</script>';
        echo "\n<!-- /SEOPulse Local SEO JSON-LD -->\n";
    }
}
