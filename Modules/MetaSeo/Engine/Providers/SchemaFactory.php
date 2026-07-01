<?php

/**
 * JSON-LD schema factory with registry
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
 * SchemaFactory — central registry and factory for schema providers
 */
class SchemaFactory
{
    /**
     * Registered schema providers
     *
     * @var array<string, SchemaProvider>
     */
    private static array $providers = [];

    /**
     * Validator instance
     *
     * @var SchemaValidator
     */
    private static SchemaValidator $validator;

    /**
     * Initialize the factory
     *
     * @return void
     */
    public static function init(): void
    {
        if (!isset(self::$validator)) {
            self::$validator = new SchemaValidator();
        }
    }

    /**
     * Register a schema provider
     *
     * @param string $key Unique identifier for the provider
     * @param SchemaProvider $provider Provider instance
     *
     * @return void
     */
    public static function register(string $key, SchemaProvider $provider): void
    {
        self::init();
        self::$providers[ $key ] = $provider;
    }

    /**
     * Get a registered provider
     *
     * @param string $key Provider key
     *
     * @return SchemaProvider|null
     */
    public static function get(string $key): ?SchemaProvider
    {
        return self::$providers[ $key ] ?? null;
    }

    /**
     * Check if a provider is registered
     *
     * @param string $key Provider key
     *
     * @return bool
     */
    public static function has(string $key): bool
    {
        return isset(self::$providers[ $key ]);
    }

    /**
     * Get all registered providers
     *
     * @return array<string, SchemaProvider>
     */
    public static function get_all(): array
    {
        return self::$providers;
    }

    /**
     * Unregister a provider
     *
     * @param string $key Provider key
     *
     * @return void
     */
    public static function unregister(string $key): void
    {
        unset(self::$providers[ $key ]);
    }

    /**
     * Build all schemas that should inject (valid ones)
     *
     * @return array<int, array<string, mixed>>
     */
    public static function build_all(): array
    {
        self::init();
        $schemas = [];

        foreach (self::$providers as $key => $provider) {
            // Skip website provider — injected separately via wp_head
            if ($key === 'website') {
                continue;
            }

            // Only process providers that should inject on this request
            if (!$provider->should_inject()) {
                continue;
            }

            // Build the schema
            $schema = $provider->build();

            // Validate
            if (!self::$validator->validate($schema)) {
                // Log validation error but don't inject
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $logger = \SEOPulse\seopulse_get_service('Logger');
                    if ($logger instanceof \SEOPulse\Core\Logger) {
                        $logger->warning(
                            'Schema validation error',
                            [
                                'provider' => $provider->get_type(),
                                'error'    => self::$validator->get_error(),
                            ],
                        );
                    }
                }
                continue;
            }

            $schemas[] = $schema;
        }

        return $schemas;
    }

    /**
     * Build a single provider's schema
     *
     * @param string $key Provider key
     *
     * @return array<string, mixed>|null
     */
    public static function build(string $key): ?array
    {
        $provider = self::get($key);

        if (!$provider) {
            return null;
        }

        $schema = $provider->build();

        self::init();
        if (!self::$validator->validate($schema)) {
            return null;
        }

        return $schema;
    }

    /**
     * Get the validator instance
     *
     * @return SchemaValidator
     */
    public static function get_validator(): SchemaValidator
    {
        self::init();

        return self::$validator;
    }

    /**
     * Build all schemas for admin preview (bypasses should_inject, uses sample content).
     *
     * Site-level providers (website, organization, localbusiness) build directly.
     * Post-dependent providers (article, webpage) use the most recent published post/page.
     * FAQ is skipped (requires actual FAQ data on a specific post).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function build_all_preview(): array
    {
        self::init();
        $schemas  = [];
        $settings = get_option('seopulse_meta_seo_global', []);

        // Toggle mapping: provider key => settings key
        $toggle_map = [
            'article'      => 'schema_article_enabled',
            'webpage'      => 'schema_article_enabled',
            'faq'          => 'schema_faq_enabled',
            'website'      => 'schema_website_enabled',
            'organization' => 'schema_website_enabled',
            'product'      => 'schema_product_enabled',
            'event'        => 'schema_event_enabled',
        ];

        // Site-level providers (no post context needed)
        foreach (['website', 'organization'] as $key) {
            if (!isset(self::$providers[ $key ])) {
                continue;
            }
            $toggle = $toggle_map[ $key ] ?? null;
            if ($toggle !== null && !($settings[ $toggle ] ?? true)) {
                continue;
            }
            $schema = self::$providers[ $key ]->build();
            if (!empty($schema) && self::$validator->validate($schema)) {
                $schemas[] = $schema;
            }
        }

        // Article provider — needs a sample post
        if (isset(self::$providers['article'])) {
            $toggle = $toggle_map['article'];
            if ($settings[ $toggle ] ?? true) {
                $sample = get_posts(
                    [
                        'post_type'   => 'post',
                        'post_status' => 'publish',
                        'numberposts' => 1,
                        'orderby'     => 'date',
                        'order'       => 'DESC',
                    ],
                );
                if (!empty($sample)) {
                    global $post;
                    $original = $post;
                    $post     = $sample[0]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                    setup_postdata($post);

                    $schema = self::$providers['article']->build();
                    if (!empty($schema) && self::$validator->validate($schema)) {
                        $schemas[] = $schema;
                    }

                    wp_reset_postdata();
                    $post = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                }
            }
        }

        // WebPage provider — needs a sample page
        if (isset(self::$providers['webpage'])) {
            $toggle = $toggle_map['webpage'];
            if ($settings[ $toggle ] ?? true) {
                $sample = get_posts(
                    [
                        'post_type'   => 'page',
                        'post_status' => 'publish',
                        'numberposts' => 1,
                        'orderby'     => 'date',
                        'order'       => 'DESC',
                    ],
                );
                if (!empty($sample)) {
                    global $post;
                    $original = $post;
                    $post     = $sample[0]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                    setup_postdata($post);

                    $schema = self::$providers['webpage']->build();
                    if (!empty($schema) && self::$validator->validate($schema)) {
                        $schemas[] = $schema;
                    }

                    wp_reset_postdata();
                    $post = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                }
            }
        }

        // FAQ is skipped — requires actual FAQ data on a specific post

        // Product provider — needs a WooCommerce product
        if (isset(self::$providers['product']) && function_exists('wc_get_product')) {
            $toggle = $toggle_map['product'] ?? null;
            if ($toggle === null || ($settings[ $toggle ] ?? true)) {
                $sample = get_posts(
                    [
                        'post_type'   => 'product',
                        'post_status' => 'publish',
                        'numberposts' => 1,
                        'orderby'     => 'date',
                        'order'       => 'DESC',
                    ],
                );
                if (!empty($sample)) {
                    global $post;
                    $original = $post;
                    $post     = $sample[0]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                    setup_postdata($post);

                    $schema = self::$providers['product']->build();
                    if (!empty($schema) && self::$validator->validate($schema)) {
                        $schemas[] = $schema;
                    }

                    wp_reset_postdata();
                    $post = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                }
            }
        }

        return $schemas;
    }

    /**
     * Clear all providers (useful for testing)
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$providers = [];
    }
}
