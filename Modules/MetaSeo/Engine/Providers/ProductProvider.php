<?php

/**
 * Product (WooCommerce) schema provider for JSON-LD
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ProductProvider — generates Product schema for WooCommerce product pages
 */
final class ProductProvider implements SchemaProvider
{
    /**
     * Last error message
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
        return 'Product';
    }

    /**
     * Check if this provider should inject on the current request
     *
     * @return bool
     */
    public function should_inject(): bool
    {
        // WooCommerce must be active
        if (!function_exists('wc_get_product') || !function_exists('is_product')) {
            return false;
        }

        // Check admin toggle (default: enabled)
        $settings = get_option('seopulse_meta_seo_global', []);
        if (isset($settings['schema_product_enabled']) && !$settings['schema_product_enabled']) {
            return false;
        }

        // Only inject on WooCommerce product pages
        if (!is_product()) {
            return false;
        }

        // Check per-product disable meta
        $post_id = get_the_ID();
        if ($post_id && get_post_meta($post_id, '_seopulse_product_schema_disable', true) === '1') {
            return false;
        }

        // Allow site owners to disable when external plugin already injects Product schema
        if (apply_filters('seopulse_schema_product_external_present', false)) {
            return false;
        }

        return true;
    }

    /**
     * Build the Product schema
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        global $post;

        if (!$post instanceof \WP_Post || !function_exists('wc_get_product')) {
            return [];
        }

        $product = wc_get_product($post->ID);
        if (!$product) {
            return [];
        }

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => $product->get_name(),
            'url'         => get_permalink($post),
            'description' => $this->get_description($product),
        ];

        // Image
        $image = $this->get_image($product);
        if (!empty($image)) {
            $schema['image'] = $image;
        }

        // SKU (with override support)
        $sku = $this->get_sku($product, $post->ID);
        if (!empty($sku)) {
            $schema['sku'] = $sku;
        }

        // Brand (use the first product_brand or pa_brand term, or site name)
        $brand = $this->get_brand($product);
        if (!empty($brand)) {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name'  => $brand,
            ];
        }

        // Offers
        $offers = $this->build_offers($product, $post->ID);
        if (!empty($offers)) {
            $schema['offers'] = $offers;
        }

        // Aggregate rating
        $rating = $this->build_aggregate_rating($product);
        if (!empty($rating)) {
            $schema['aggregateRating'] = $rating;
        }

        return $schema;
    }

    /**
     * Validate the schema
     *
     * @return bool
     */
    public function validate(): bool
    {
        $schema = $this->build();

        if (empty($schema)) {
            $this->error = 'Schema is empty';

            return false;
        }

        if (empty($schema['name'])) {
            $this->error = 'Missing product name';

            return false;
        }

        if (empty($schema['image'])) {
            $this->error = 'Missing product image';

            return false;
        }

        if (empty($schema['offers'])) {
            $this->error = 'Missing offers (price)';

            return false;
        }

        return true;
    }

    /**
     * Get error message
     *
     * @return string|null
     */
    public function get_error(): ?string
    {
        return $this->error;
    }

    // ──────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────

    /**
     * Get product description (short description > excerpt > trimmed content)
     *
     * @param \WC_Product $product
     * @return string
     */
    private function get_description(\WC_Product $product): string
    {
        $description = $product->get_short_description();

        if (empty($description)) {
            $description = $product->get_description();
        }

        if (empty($description)) {
            return '';
        }

        return wp_trim_words(wp_strip_all_tags($description), 50, '…');
    }

    /**
     * Get product image(s)
     *
     * @param \WC_Product $product
     * @return array<string, mixed>|string
     */
    private function get_image(\WC_Product $product)
    {
        $image_id = $product->get_image_id();

        if (!$image_id) {
            return '';
        }

        $image_url = wp_get_attachment_url($image_id);

        if (!$image_url) {
            return '';
        }

        $metadata = wp_get_attachment_metadata($image_id);
        $width    = $metadata['width'] ?? 0;
        $height   = $metadata['height'] ?? 0;

        $image = [
            '@type' => 'ImageObject',
            'url'   => $image_url,
        ];

        if ($width > 0 && $height > 0) {
            $image['width']  = $width;
            $image['height'] = $height;
        }

        return $image;
    }

    /**
     * Get SKU with per-product override support
     *
     * @param \WC_Product $product
     * @param int $post_id
     * @return string
     */
    private function get_sku(\WC_Product $product, int $post_id): string
    {
        $override = get_post_meta($post_id, '_seopulse_product_schema_sku_override', true);

        if (!empty($override)) {
            return sanitize_text_field($override);
        }

        return $product->get_sku();
    }

    /**
     * Get brand name from taxonomy or site name fallback
     *
     * @param \WC_Product $product
     * @return string
     */
    private function get_brand(\WC_Product $product): string
    {
        $product_id = $product->get_id();

        // Try common brand taxonomies
        foreach (['product_brand', 'pa_brand', 'pwb-brand'] as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_the_terms($product_id, $taxonomy);
            if (!empty($terms) && !is_wp_error($terms)) {
                return $terms[0]->name;
            }
        }

        return get_bloginfo('name');
    }

    /**
     * Build the Offer(s) schema
     *
     * @param \WC_Product $product
     * @param int $post_id
     * @return array<string, mixed>
     */
    private function build_offers(\WC_Product $product, int $post_id): array
    {
        $price_override = get_post_meta($post_id, '_seopulse_product_schema_price_override', true);
        $price          = !empty($price_override)
            ? (float) $price_override
            : (float) $product->get_price();

        if ($price <= 0) {
            return [];
        }

        $currency = get_woocommerce_currency();

        $offer = [
            '@type'           => 'Offer',
            'url'             => get_permalink($post_id),
            'price'           => number_format($price, 2, '.', ''),
            'priceCurrency'   => $currency,
            'availability'    => $this->map_availability($product),
            'priceValidUntil' => wp_date('Y-12-31'),
        ];

        // Seller
        $site_name = get_bloginfo('name');
        if (!empty($site_name)) {
            $offer['seller'] = [
                '@type' => 'Organization',
                'name'  => $site_name,
            ];
        }

        return $offer;
    }

    /**
     * Map WooCommerce stock status to schema.org availability
     *
     * @param \WC_Product $product
     * @return string schema.org URL
     */
    private function map_availability(\WC_Product $product): string
    {
        if (!$product->is_in_stock()) {
            return 'https://schema.org/OutOfStock';
        }

        if ($product->is_on_backorder()) {
            return 'https://schema.org/BackOrder';
        }

        return 'https://schema.org/InStock';
    }

    /**
     * Build AggregateRating schema from WooCommerce reviews
     *
     * @param \WC_Product $product
     * @return array<string, mixed>
     */
    private function build_aggregate_rating(\WC_Product $product): array
    {
        if (!wc_review_ratings_enabled()) {
            return [];
        }

        $count   = (int) $product->get_review_count();
        $average = (float) $product->get_average_rating();

        if ($count < 1 || $average <= 0) {
            return [];
        }

        return [
            '@type'       => 'AggregateRating',
            'ratingValue' => number_format($average, 1, '.', ''),
            'reviewCount' => $count,
            'bestRating'  => '5',
            'worstRating' => '1',
        ];
    }
}
