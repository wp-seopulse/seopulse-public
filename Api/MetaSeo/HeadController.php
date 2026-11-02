<?php
/**
 * Headless "head" REST controller.
 *
 * Route: GET /seopulse/v1/head?url=<url>
 *
 * @package SEOPulse\Api\MetaSeo
 * @since   1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\MetaSeo;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Modules\MetaSeo\Headless\HeadPayloadGenerator;
use SEOPulse\Modules\MetaSeo\Headless\HeadSettings;
use SEOPulse\Modules\MetaSeo\Headless\UrlResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes the SEO head payload of any public URL of the site.
 */
class HeadController extends RestController
{
    /**
     * Payload generator, created on first use.
     *
     * @var HeadPayloadGenerator|null
     */
    private ?HeadPayloadGenerator $generator = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->rest_base = 'head';
    }

    /**
     * Registers the route.
     *
     * @return void
     */
    public function register_routes(): void
    {
        if (!HeadSettings::endpoint_enabled()) {
            return;
        }

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => 'GET',
                    // Public by design: the payload only contains what the public page prints in its <head>.
                    // Drafts, private and password-protected content are rejected in the resolver.
                    'permission_callback' => '__return_true',
                    'callback'            => [$this, 'get_head'],
                    'args'                => [
                        'url' => [
                            'description'       => __('Absolute URL of this site, or a path starting with "/".', 'seopulse'),
                            'type'              => 'string',
                            'required'          => true,
                            'validate_callback' => [$this, 'validate_url'],
                            'sanitize_callback' => 'esc_url_raw',
                        ],
                    ],
                ],
                'schema' => [$this, 'get_schema'],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/batch',
            [
                [
                    'methods'             => 'POST',
                    // Same exposure rules as GET /head; the size cap bounds the work of one request.
                    'permission_callback' => '__return_true',
                    'callback'            => [$this, 'get_head_batch'],
                    'args'                => [
                        'urls' => [
                            'description'       => __('List of absolute URLs of this site or paths starting with "/".', 'seopulse'),
                            'type'              => 'array',
                            'required'          => true,
                            'items'             => ['type' => 'string'],
                            'validate_callback' => [$this, 'validate_urls'],
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Maximum number of URLs accepted by the batch route.
     *
     * @return int
     */
    private function batch_limit(): int
    {
        /**
         * Filters the maximum number of URLs of a head batch request.
         *
         * @since 1.8.0
         * @param int $limit Maximum number of URLs.
         */
        return max(1, (int) apply_filters('seopulse_head_batch_limit', 50));
    }

    /**
     * Validates the "urls" argument of the batch route.
     *
     * @param mixed $value Raw value.
     * @return bool|WP_Error
     */
    public function validate_urls($value)
    {
        if (!is_array($value) || [] === $value || count($value) > $this->batch_limit()) {
            return new WP_Error(
                'rest_invalid_param',
                /* translators: %d: maximum number of URLs. */
                sprintf(__('The "urls" parameter must contain between 1 and %d URLs.', 'seopulse'), $this->batch_limit()),
                ['status' => 400],
            );
        }

        foreach ($value as $url) {
            if (true !== $this->validate_url($url)) {
                return $this->validate_url('');
            }
        }

        return true;
    }

    /**
     * JSON schema of a head payload.
     *
     * @return array<string, mixed>
     */
    public function get_schema(): array
    {
        $nullable_string = ['type' => ['string', 'null']];
        $image           = [
            'type'       => ['object', 'null'],
            'properties' => [
                'url'    => ['type' => 'string'],
                'width'  => ['type' => ['integer', 'null']],
                'height' => ['type' => ['integer', 'null']],
                'alt'    => $nullable_string,
            ],
        ];

        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'seopulse_head',
            'type'       => 'object',
            'properties' => [
                'version'     => [
                    'description' => 'Payload contract version, incremented on breaking changes.',
                    'type'        => 'integer',
                ],
                'title'       => ['type' => 'string'],
                'status'      => [
                    'description' => 'Only present (404) on the head of the site 404 page, when that option is enabled.',
                    'type'        => 'integer',
                ],
                'pending'     => [
                    'description' => 'REST field only: true when the payload is not built yet; fetch it from GET /head.',
                    'type'        => 'boolean',
                ],
                'description' => ['type' => 'string'],
                'robots'      => ['type' => 'string'],
                'canonical'   => ['type' => 'string'],
                'og'          => [
                    'type'       => 'object',
                    'properties' => [
                        'title'       => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'url'         => ['type' => 'string'],
                        'site_name'   => ['type' => 'string'],
                        'type'        => ['type' => 'string'],
                        'locale'      => $nullable_string,
                        'image'       => $image,
                    ],
                ],
                'twitter'     => [
                    'type'       => 'object',
                    'properties' => [
                        'card'        => ['type' => 'string'],
                        'site'        => $nullable_string,
                        'creator'     => $nullable_string,
                        'title'       => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'image'       => $nullable_string,
                    ],
                ],
                'article'     => [
                    'description' => 'Open Graph article data; null outside posts.',
                    'type'        => ['object', 'null'],
                    'properties'  => [
                        'published_time' => $nullable_string,
                        'modified_time'  => $nullable_string,
                        'author'         => $nullable_string,
                    ],
                ],
                'pagination'  => [
                    'description' => 'Previous/next pages of a paginated archive.',
                    'type'        => 'object',
                    'properties'  => [
                        'prev' => $nullable_string,
                        'next' => $nullable_string,
                    ],
                ],
                'breadcrumbs' => [
                    'description' => 'Breadcrumb trail; empty when breadcrumbs are disabled in the settings.',
                    'type'        => 'array',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'url'  => ['type' => 'string'],
                        ],
                    ],
                ],
                'tags'        => [
                    'description' => 'Ready-to-render head tags (endpoint and batch only, not the REST field). Values are plain text to escape when printed.',
                    'type'        => 'array',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'tag'     => ['type' => 'string', 'enum' => ['title', 'meta', 'link', 'script']],
                            'attrs'   => ['type' => 'object'],
                            'content' => ['type' => 'string'],
                        ],
                    ],
                ],
                'alternates'  => [
                    'description' => 'Translations of the page (hreflang), empty on single-language sites.',
                    'type'        => 'array',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'hreflang' => ['type' => 'string'],
                            'url'      => ['type' => 'string'],
                        ],
                    ],
                ],
                'redirect'    => [
                    'description' => 'Only present, alone with version, when the URL is redirected (or gone: to is null for 410/451) instead of served.',
                    'type'        => 'object',
                    'properties'  => [
                        'to'     => $nullable_string,
                        'status' => ['type' => 'integer'],
                    ],
                ],
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        '@context' => ['type' => 'string'],
                        '@graph'   => ['type' => 'array'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Handles POST /seopulse/v1/head/batch.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_head_batch(WP_REST_Request $request): WP_REST_Response
    {
        $generator = $this->generator();
        $results   = [];

        foreach (array_unique(array_map('esc_url_raw', (array) $request->get_param('urls'))) as $url) {
            $payload = $generator->for_url($url);

            if ($payload instanceof WP_Error) {
                $payload = [
                    'error' => [
                        'code'    => $payload->get_error_code(),
                        'message' => $payload->get_error_message(),
                        'status'  => (int) ($payload->get_error_data()['status'] ?? 400),
                    ],
                ];
            }

            $results[$url] = $payload;
        }

        return new WP_REST_Response($results, 200);
    }

    /**
     * Returns the payload generator, creating it on first use.
     *
     * @return HeadPayloadGenerator
     */
    private function generator(): HeadPayloadGenerator
    {
        if (null === $this->generator) {
            $this->generator = new HeadPayloadGenerator();
        }

        return $this->generator;
    }

    /**
     * Validates the "url" argument.
     *
     * @param mixed $value Raw value.
     * @return bool|WP_Error
     */
    public function validate_url($value)
    {
        $is_valid = is_string($value)
            && strlen($value) <= UrlResolver::MAX_URL_LENGTH
            && 1 === preg_match('#^(?:/(?!/)|https?://)#i', $value);

        if ($is_valid) {
            return true;
        }

        return new WP_Error(
            'rest_invalid_param',
            __('The "url" parameter must be an absolute http(s) URL or a path starting with "/".', 'seopulse'),
            ['status' => 400],
        );
    }

    /**
     * Handles GET /seopulse/v1/head.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_head(WP_REST_Request $request)
    {
        $payload = $this->generator()->for_url((string) $request->get_param('url'));

        if ($payload instanceof WP_Error) {
            return $payload;
        }

        $etag = '"' . md5((string) wp_json_encode($payload)) . '"';

        /**
         * Filters the Cache-Control header of the head endpoint.
         *
         * @since 1.8.0
         * @param string $value Header value.
         */
        $cache_control = (string) apply_filters(
            'seopulse_head_cache_control',
            'public, max-age=60, stale-while-revalidate=300',
        );

        $headers = [
            'ETag'          => $etag,
            'Cache-Control' => $cache_control,
        ];

        $if_none_match = trim((string) $request->get_header('if_none_match'));
        $status        = (int) ($payload['status'] ?? 200);

        if (200 === $status && '' !== $if_none_match) {
            $candidates = array_map(
                static fn (string $tag): string => (string) preg_replace('#^W/#', '', trim($tag)),
                explode(',', $if_none_match),
            );

            if ('*' === $if_none_match || in_array($etag, $candidates, true)) {
                $not_modified = new WP_REST_Response(null, 304);
                $not_modified->set_headers($headers);

                return $not_modified;
            }
        }

        $response = new WP_REST_Response($payload, $status);
        $response->set_headers($headers);

        return $response;
    }
}
