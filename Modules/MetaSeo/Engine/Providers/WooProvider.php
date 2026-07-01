<?php

/**
 * WooCommerce variable provider (woo.*).
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\MetaSeo\Engine\ContextBag;
use SEOPulse\Modules\MetaSeo\Engine\VariableDefinition;
use SEOPulse\Modules\MetaSeo\Engine\VariableProviderInterface;

/**
 * WooProvider — resolves woo.* variables.
 *
 * Only registered when WooCommerce is active.
 */
final class WooProvider implements VariableProviderInterface
{
    private const VARIABLES = [
        'price',
        'sale_price',
        'regular_price',
        'currency',
        'sku',
        'stock_status',
        'stock_qty',
        'rating',
        'review_count',
        'brand',
        'weight',
        'dimensions',
        'category',
        'short_desc',
        'shop_name',
        'shop_url',
    ];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        // Product-level variables
        $product = $this->getProduct($context);

        return match ($variable) {
            'price'         => $product ? (string) $product->get_price() : null,
            'sale_price'    => $product ? (string) $product->get_sale_price() : null,
            'regular_price' => $product ? (string) $product->get_regular_price() : null,
            'currency'      => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
            'sku'           => $product ? $product->get_sku() : null,
            'stock_status'  => $product ? $product->get_stock_status() : null,
            'stock_qty'     => $product ? (string) $product->get_stock_quantity() : null,
            'rating'        => $product ? (string) $product->get_average_rating() : null,
            'review_count'  => $product ? (string) $product->get_review_count() : null,
            'brand'         => $this->getBrand($product),
            'weight'        => $product ? (string) $product->get_weight() : null,
            'dimensions'    => $product ? $this->getDimensions($product) : null,
            'category'      => $product ? $this->getProductCategory($product) : null,
            'short_desc'    => $product ? wp_strip_all_tags($product->get_short_description()) : null,
            'shop_name'     => get_bloginfo('name'),
            'shop_url'      => function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('shop') : home_url('/shop/'),
            default         => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('price', 'Product price', '29.99', 'woocommerce'),
            new VariableDefinition('sale_price', 'Product sale price', '19.99', 'woocommerce'),
            new VariableDefinition('regular_price', 'Product regular price', '29.99', 'woocommerce'),
            new VariableDefinition('currency', 'Currency symbol', '€', 'woocommerce'),
            new VariableDefinition('sku', 'Product SKU', 'WC-PRD-001', 'woocommerce'),
            new VariableDefinition('stock_status', 'Stock status', 'instock', 'woocommerce'),
            new VariableDefinition('stock_qty', 'Stock quantity', '42', 'woocommerce'),
            new VariableDefinition('rating', 'Average rating', '4.5', 'woocommerce'),
            new VariableDefinition('review_count', 'Number of reviews', '18', 'woocommerce'),
            new VariableDefinition('brand', 'Product brand', 'Acme', 'woocommerce'),
            new VariableDefinition('weight', 'Product weight', '0.5', 'woocommerce'),
            new VariableDefinition('dimensions', 'Product dimensions', '10 × 5 × 3 cm', 'woocommerce'),
            new VariableDefinition('category', 'Primary product category', 'Clothing', 'woocommerce'),
            new VariableDefinition('short_desc', 'Product short description', 'A great product…', 'woocommerce'),
            new VariableDefinition('shop_name', 'Shop name', 'My Store', 'woocommerce'),
            new VariableDefinition('shop_url', 'Shop page URL', 'https://example.com/shop/', 'woocommerce'),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function getProduct(ContextBag $context): ?\WC_Product
    {
        $post = $context->getPost();

        if ($post === null || $post->post_type !== 'product') {
            return null;
        }

        if (!function_exists('wc_get_product')) {
            return null;
        }

        $product = wc_get_product($post->ID);

        return ($product instanceof \WC_Product) ? $product : null;
    }

    private function getBrand(\WC_Product $product = null): ?string
    {
        if ($product === null) {
            return null;
        }

        // Try common brand taxonomy/attribute names
        foreach (['pa_brand', 'brand', 'product_brand'] as $taxonomy) {
            $terms = get_the_terms($product->get_id(), $taxonomy);

            if (is_array($terms) && !empty($terms)) {
                return $terms[0]->name;
            }
        }

        return null;
    }

    private function getDimensions(\WC_Product $product): string
    {
        $length = $product->get_length();
        $width  = $product->get_width();
        $height = $product->get_height();

        if ($length || $width || $height) {
            $unit = function_exists('get_option') ? get_option('woocommerce_dimension_unit', 'cm') : 'cm';

            return trim("$length × $width × $height $unit");
        }

        return '';
    }

    private function getProductCategory(\WC_Product $product): string
    {
        $terms = get_the_terms($product->get_id(), 'product_cat');

        return (is_array($terms) && !empty($terms)) ? $terms[0]->name : '';
    }
}
