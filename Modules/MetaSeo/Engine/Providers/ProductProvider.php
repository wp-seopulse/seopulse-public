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

        // GTIN (typed by length) and MPN
        $gtin = $this->get_gtin($product, $post->ID);
        if ($gtin !== '') {
            $schema[ $this->gtin_property($gtin) ] = $gtin;
        }

        $mpn = $this->get_mpn($post->ID);
        if ($mpn !== '') {
            $schema['mpn'] = $mpn;
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

        // Variable and grouped products expose a price range instead of a single price.
        if (empty($price_override) && $product->is_type(['variable', 'grouped'])) {
            $aggregate = $this->build_aggregate_offer($product, $post_id);

            if ($aggregate !== []) {
                return $aggregate;
            }
        }

        $price = !empty($price_override)
            ? (float) $price_override
            : (float) $product->get_price();

        if ($price <= 0) {
            return [];
        }

        $offer = [
            '@type'           => 'Offer',
            'url'             => get_permalink($post_id),
            'price'           => number_format($price, 2, '.', ''),
            'priceCurrency'   => get_woocommerce_currency(),
            'availability'    => $this->map_availability($product),
            'priceValidUntil' => $this->price_valid_until($product),
        ];

        $seller = $this->get_seller();
        if ($seller !== []) {
            $offer['seller'] = $seller;
        }

        return $offer;
    }

    /**
     * Build an AggregateOffer from the prices of variations or grouped children
     *
     * @param \WC_Product $product
     * @param int $post_id
     * @return array<string, mixed>
     */
    private function build_aggregate_offer(\WC_Product $product, int $post_id): array
    {
        $prices = [];

        if ($product instanceof \WC_Product_Variable) {
            $variation_prices = $product->get_variation_prices(true);
            $prices           = array_map('floatval', $variation_prices['price'] ?? []);
        } else {
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product($child_id);

                if ($child && $child->is_purchasable()) {
                    $prices[ $child_id ] = (float) wc_get_price_to_display($child);
                }
            }
        }

        $prices = array_filter($prices, static fn (float $price): bool => $price > 0);

        if ($prices === []) {
            return [];
        }

        $offer = [
            '@type'           => 'AggregateOffer',
            'url'             => get_permalink($post_id),
            'priceCurrency'   => get_woocommerce_currency(),
            'lowPrice'        => number_format(min($prices), 2, '.', ''),
            'highPrice'       => number_format(max($prices), 2, '.', ''),
            'offerCount'      => count($prices),
            'availability'    => $this->map_availability($product),
            'priceValidUntil' => $this->price_valid_until($product),
        ];

        $seller = $this->get_seller();
        if ($seller !== []) {
            $offer['seller'] = $seller;
        }

        return $offer;
    }

    /**
     * Seller reference (organization node, or site name fallback)
     *
     * @return array<string, mixed>
     */
    private function get_seller(): array
    {
        $reference = OrgSchemaProvider::reference();
        if ($reference !== null) {
            return $reference;
        }

        $site_name = get_bloginfo('name');

        return $site_name !== '' ? ['@type' => 'Organization', 'name' => $site_name] : [];
    }

    /**
     * End of the scheduled sale when set, otherwise the end of the current year
     *
     * @param \WC_Product $product
     * @return string Y-m-d
     */
    private function price_valid_until(\WC_Product $product): string
    {
        $sale_end = $product->is_type(['variable', 'grouped']) ? null : $product->get_date_on_sale_to();

        return $sale_end ? $sale_end->date('Y-m-d') : wp_date('Y-12-31');
    }

    /**
     * GTIN from the override, WooCommerce's unique ID field or common third-party fields
     *
     * @param \WC_Product $product
     * @param int $post_id
     * @return string Digits only, or empty when not a valid length
     */
    private function get_gtin(\WC_Product $product, int $post_id): string
    {
        $candidates = [get_post_meta($post_id, '_seopulse_product_schema_gtin', true)];

        if (method_exists($product, 'get_global_unique_id')) {
            $candidates[] = $product->get_global_unique_id();
        }

        foreach (['_wpm_gtin_code', '_ts_gtin'] as $key) {
            $candidates[] = get_post_meta($post_id, $key, true);
        }

        foreach ($candidates as $candidate) {
            $digits = preg_replace('/\D+/', '', is_scalar($candidate) ? (string) $candidate : '');

            if (in_array(strlen((string) $digits), [8, 12, 13, 14], true)) {
                return (string) $digits;
            }
        }

        return '';
    }

    /**
     * schema.org property matching a GTIN length
     *
     * @param string $gtin Digits only
     * @return string
     */
    private function gtin_property(string $gtin): string
    {
        return 'gtin' . strlen($gtin);
    }

    /**
     * Manufacturer part number from the override or common third-party field
     *
     * @param int $post_id
     * @return string
     */
    private function get_mpn(int $post_id): string
    {
        foreach (['_seopulse_product_schema_mpn', '_wpm_mpn', '_mpn'] as $key) {
            $value = get_post_meta($post_id, $key, true);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return sanitize_text_field((string) $value);
            }
        }

        return '';
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
