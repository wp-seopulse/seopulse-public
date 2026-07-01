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
     * Injects JSON-LD LocalBusiness into the <head>.
     *
     * The Local SEO module is the sole authority for LocalBusiness schema injection.
     *
     * @return void
     */
    public function inject_jsonld(): void
    {
        $settings = get_option('seopulse_local_seo_settings', []);

        if (empty($settings) || !isset($settings['@context'])) {
            return;
        }

        echo "\n<!-- SEOPulse Local SEO JSON-LD -->\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode applies JSON_HEX_TAG|JSON_HEX_AMP; esc_html would break JSON-LD.
        echo '<script type="application/ld+json">' . wp_json_encode($settings) . '</script>';
        echo "\n<!-- /SEOPulse Local SEO JSON-LD -->\n";
    }
}
