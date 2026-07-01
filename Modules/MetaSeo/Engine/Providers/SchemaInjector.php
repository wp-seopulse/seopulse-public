<?php

/**
 * JSON-LD schema injector for frontend and API
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
 * SchemaInjector — handles injection of JSON-LD schemas
 */
class SchemaInjector
{
    /**
     * Check if LD+JSON block already exists (to avoid conflicts with other plugins)
     *
     * @return bool
     */
    private static function has_existing_schema(): bool
    {
        // Check for common schema plugins
        return (
            function_exists('yoast_json_ld') ||
            function_exists('rank_math_the_schema') ||
            class_exists('AIOSEO\\Core\\Schema\\Schema') ||
            defined('SEOPRESS_VERSION')
        );
    }

    /**
     * Inject schemas in the frontend (wp_head or wp_footer)
     *
     * @return void
     */
    public static function inject_frontend(): void
    {
        $schemas = SchemaFactory::build_all();

        if (empty($schemas)) {
            return;
        }

        // If there are multiple schemas, strip individual @context and wrap in @graph
        if (count($schemas) > 1) {
            $graph = array_map(
                static function (array $schema): array {
                    unset($schema['@context']);

                    return $schema;
                },
                $schemas,
            );

            $output = [
                '@context' => 'https://schema.org',
                '@graph'   => $graph,
            ];
        } else {
            $output = reset($schemas);
        }

        self::render_jsonld($output);
    }

    /**
     * Render a JSON-LD script tag with proper encoding
     *
     * @param array<string, mixed> $data Schema data to encode
     *
     * @return void
     */
    private static function render_jsonld(array $data): void
    {
        // wp_json_encode applies JSON_HEX_TAG | JSON_HEX_AMP by default,
        // which prevents </script> injection in JSON-LD context.
        $json = wp_json_encode($data);

        if (!is_string($json)) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode applies JSON_HEX_TAG|JSON_HEX_AMP; esc_html would break JSON-LD.
        echo '<script type="application/ld+json">' . $json . "</script>\n";
    }

    /**
     * Public wrapper for rendering JSON-LD from outside this class.
     *
     * @param array<string, mixed> $data Schema data to encode
     *
     * @return void
     */
    public static function render_jsonld_public(array $data): void
    {
        self::render_jsonld($data);
    }

    /**
     * Register hooks for frontend injection
     *
     * Priority 20 — injected in footer, after all content is rendered.
     * WebSite/Organization schemas inject via wp_head separately.
     *
     * @return void
     */
    public static function register_frontend_hooks(): void
    {
        // Only inject if no conflicting plugins are doing it
        if (!self::has_existing_schema()) {
            add_action('wp_footer', [__CLASS__, 'inject_frontend'], 20);
        }
    }

    /**
     * Register hooks for head injection (WebSite, Organization, SearchAction)
     *
     * Priority 5 to output after meta tags.
     *
     * @param callable $callback
     *
     * @return void
     */
    public static function register_head_hooks(callable $callback): void
    {
        if (!self::has_existing_schema()) {
            add_action('wp_head', $callback, 5);
        }
    }

    /**
     * REST API endpoint for fetching current page's schemas
     *
     * @return array<string, mixed>
     */
    public static function get_schemas_rest(): array
    {
        $schemas = SchemaFactory::build_all();

        if (empty($schemas)) {
            return [];
        }

        // If multiple schemas, strip individual @context and wrap in @graph
        if (count($schemas) > 1) {
            $graph = array_map(
                static function (array $schema): array {
                    unset($schema['@context']);

                    return $schema;
                },
                $schemas,
            );

            return [
                '@context' => 'https://schema.org',
                '@graph'   => $graph,
            ];
        }

        return reset($schemas);
    }

    /**
     * Register REST endpoint for schemas
     *
     * @return void
     */
    public static function register_rest_endpoint(): void
    {
        // Intentionally public: this endpoint returns the same JSON-LD
        // structured data that is already injected into the <head> of every
        // public page. The optional ?preview=1 parameter is gated by an
        // explicit current_user_can('manage_options') check inside the callback.
        register_rest_route(
            'seopulse/v1',
            '/schema',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'rest_get_schemas'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'preview' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ],
                ],
            ],
        );
    }

    /**
     * REST callback to get schemas
     *
     * @param \WP_REST_Request $request REST request.
     *
     * @return \WP_REST_Response
     */
    public static function rest_get_schemas(\WP_REST_Request $request): \WP_REST_Response
    {
        // Admin preview mode — bypasses should_inject, uses sample content
        if ($request->get_param('preview') === '1') {
            if (!current_user_can('manage_options')) {
                return new \WP_REST_Response(['error' => 'Unauthorized'], 403);
            }

            $schemas = SchemaFactory::build_all_preview();

            if (empty($schemas)) {
                return rest_ensure_response(
                    [
                        '_preview' => true,
                        '_note'    => 'No schemas available. Check that providers are enabled and content exists.',
                    ],
                );
            }

            $graph = array_map(
                static function (array $schema): array {
                    unset($schema['@context']);

                    return $schema;
                },
                $schemas,
            );

            return rest_ensure_response(
                [
                    '@context' => 'https://schema.org',
                    '@graph'   => $graph,
                    '_preview' => true,
                    '_note'    => 'Preview using sample content. Actual schemas depend on page context.',
                ],
            );
        }

        return rest_ensure_response(self::get_schemas_rest());
    }

    /**
     * Detect and respect existing schema plugins
     *
     * Returns info about conflicting schema plugins
     *
     * @return array<string, bool>
     */
    public static function get_schema_conflicts(): array
    {
        return [
            'yoast'     => function_exists('yoast_json_ld'),
            'rank_math' => function_exists('rank_math_the_schema'),
            'aioseo'    => class_exists('AIOSEO\\Core\\Schema\\Schema'),
            'seopress'  => defined('SEOPRESS_VERSION'),
        ];
    }
}
