<?php

/**
 * WooCommerce system pages (cart, checkout, my account) that must stay out of search results.
 *
 * @package SEOPulse\Services
 * @since 1.1.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the page IDs of WooCommerce's transactional pages.
 */
final class WooCommerceSystemPages
{
    private const PAGES = ['cart', 'checkout', 'myaccount'];

    /**
     * IDs of the cart, checkout and my account pages (empty when WooCommerce is inactive).
     *
     * @return int[]
     */
    public static function ids(): array
    {
        if (!function_exists('wc_get_page_id')) {
            return [];
        }

        $ids = [];

        foreach (self::PAGES as $page) {
            $id = (int) wc_get_page_id($page);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        /**
         * Filters the pages treated as WooCommerce system pages.
         *
         * @param int[] $ids Page IDs.
         */
        $ids = apply_filters('seopulse_woocommerce_system_page_ids', $ids);

        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    }

    public static function isSystemPage(int $post_id): bool
    {
        return $post_id > 0 && in_array($post_id, self::ids(), true);
    }
}
