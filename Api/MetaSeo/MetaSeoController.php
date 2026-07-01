<?php

/**
 * REST API controller for the MetaSEO module
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\MetaSeo;

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Core\Constants\PostMeta;
use SEOPulse\Modules\MetaSeo\Engine\ContextBag;
use SEOPulse\Modules\MetaSeo\Engine\MetaEngine;
use SEOPulse\Modules\MetaSeo\MetaSeoDefaults;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * MetaSeoController class
 */
class MetaSeoController extends RestController
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base = 'meta-seo';
    }

    /**
     * Registers routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // GET /seopulse/v1/meta-seo/{post_id}
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_meta_seo'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id' => [
                            'required' => true,
                            'type'     => 'integer',
                        ],
                    ],
                ],
            ],
        );

        // POST /seopulse/v1/meta-seo/{post_id}
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<post_id>\d+)',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'update_meta_seo'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id' => [
                            'required' => true,
                            'type'     => 'integer',
                        ],
                    ],
                ],
            ],
        );

        // GET /seopulse/v1/meta-seo/preview/{post_id}
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/preview/(?P<post_id>\d+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_preview_data'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id' => [
                            'required' => true,
                            'type'     => 'integer',
                        ],
                    ],
                ],
            ],
        );

        // GET /seopulse/v1/meta-seo/global-settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/global-settings',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_global_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // POST /seopulse/v1/meta-seo/global-settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/global-settings',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_global_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // GET /seopulse/v1/meta-seo/schema-preview
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/schema-preview',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_schema_preview'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );
    }

    /**
     * Retrieves global MetaSEO settings.
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function get_global_settings(WP_REST_Request $request): WP_REST_Response
    {
        $settings = get_option('seopulse_meta_seo_global', []);

        return $this->success(['settings' => $settings]);
    }

    /**
     * Saves global MetaSEO settings via REST.
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function save_global_settings(WP_REST_Request $request)
    {
        $json = $request->get_json_params();

        // The React SPA sends { settings: { ... } }
        $data = isset($json['settings']) && is_array($json['settings']) ? $json['settings'] : $json;

        if (!is_array($data) || empty($data)) {
            return $this->error(__('No settings provided.', 'seopulse'), 400);
        }

        $sanitized = [];

        // Text fields
        $text_fields = [
            'title', 'keywords', 'author', 'robots', 'theme_color',
            'geo_region', 'geo_placename', 'geo_position',
            'og_title', 'og_type', 'og_site_name', 'og_locale',
            'twitter_card', 'twitter_title', 'twitter_site', 'twitter_creator',
            'separator', 'schema_type', 'schema_name',
            'breadcrumb_separator', 'image_alt_format', 'image_title_format',
            'image_diagnostic_size_threshold',
        ];

        foreach ($text_fields as $field) {
            if (isset($data[ $field ])) {
                $sanitized[ $field ] = sanitize_text_field((string) $data[ $field ]);
            }
        }

        // Textarea fields
        $textarea_fields = ['description', 'og_description', 'twitter_description', 'schema_description'];
        foreach ($textarea_fields as $field) {
            if (isset($data[ $field ])) {
                $sanitized[ $field ] = sanitize_textarea_field((string) $data[ $field ]);
            }
        }

        // URL fields
        $url_fields = ['canonical', 'og_url', 'og_image', 'twitter_image', 'schema_logo_url'];
        foreach ($url_fields as $field) {
            if (isset($data[ $field ])) {
                $sanitized[ $field ] = esc_url_raw((string) $data[ $field ]);
            }
        }

        // Boolean fields
        $bool_fields = [
            'remove_generator', 'remove_wlw_manifest', 'remove_shortlink',
            'remove_rsd_link', 'remove_emoji', 'remove_feed_links',
            'breadcrumbs_enabled', 'breadcrumbs_auto_insert',
            'enable_breadcrumbs', 'breadcrumb_show_home', 'breadcrumb_show_last',
            'schema_article_enabled', 'schema_faq_enabled', 'schema_website_enabled',
            'schema_product_enabled', 'schema_event_enabled',
            'enable_schema', 'enable_image_seo', 'image_auto_rename',
            'enable_image_diagnostic',
        ];

        foreach ($bool_fields as $field) {
            if (isset($data[ $field ])) {
                $sanitized[ $field ] = (bool) $data[ $field ];
            }
        }

        // Breadcrumbs post types (array)
        if (isset($data['breadcrumbs_post_types']) && is_array($data['breadcrumbs_post_types'])) {
            $sanitized['breadcrumbs_post_types'] = array_map('sanitize_key', $data['breadcrumbs_post_types']);
        }

        // Merge with existing to avoid losing fields not sent by the SPA
        $existing  = get_option('seopulse_meta_seo_global', []);
        $merged    = array_merge(is_array($existing) ? $existing : [], $sanitized);

        update_option('seopulse_meta_seo_global', $merged, false);

        return $this->success([
            'message'  => __('Settings saved.', 'seopulse'),
            'settings' => $merged,
        ]);
    }

    /**
     * Returns a JSON-LD schema preview.
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function get_schema_preview(WP_REST_Request $request): WP_REST_Response
    {
        $settings = get_option('seopulse_meta_seo_global', []);

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => $settings['schema_type'] ?? 'Organization',
            'name'        => $settings['schema_name'] ?? get_bloginfo('name'),
            'description' => $settings['schema_description'] ?? get_bloginfo('description'),
            'url'         => home_url('/'),
        ];

        if (!empty($settings['schema_logo_url'])) {
            $schema['logo'] = $settings['schema_logo_url'];
        }

        return $this->success(['schema' => $schema]);
    }
    public function get_meta_seo(WP_REST_Request $request)
    {
        $post_id = $request->get_param('post_id');

        $post = get_post($post_id);
        if (!$post) {
            return $this->error(__('Post not found.', 'seopulse'), 404);
        }

        $meta = get_post_meta($post_id, '_seopulse_meta_seo', true);
        if (!is_array($meta)) {
            $meta = [];
        }

        // Return focus_keywords array from dedicated post meta (v3.0+)
        $focus_keywords = get_post_meta($post_id, PostMeta::FOCUS_KEYWORDS, true);
        if (!is_array($focus_keywords)) {
            // Fallback: migrate legacy single keyword on read
            $legacy         = get_post_meta($post_id, PostMeta::FOCUS_KEYWORD, true);
            $focus_keywords = !empty($legacy) ? [sanitize_text_field($legacy)] : [];
        }

        $defaults = MetaSeoDefaults::get_post_defaults($post, $meta);

        // Resolve template variables in meta fields for preview
        $resolved       = [];
        $templateFields = ['title', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description'];

        $hasTemplates = false;
        foreach ($templateFields as $field) {
            if (isset($meta[ $field ]) && str_contains((string) $meta[ $field ], '%%')) {
                $hasTemplates = true;
                break;
            }
        }

        if ($hasTemplates) {
            $engine  = new MetaEngine();
            $context = ContextBag::fromArray(
                [
                    'post_id' => $post_id,
                    'type'    => 'singular',
                ],
            );

            foreach ($templateFields as $field) {
                if (isset($meta[ $field ]) && str_contains((string) $meta[ $field ], '%%')) {
                    try {
                        $resolved[ $field ] = $engine->resolve((string) $meta[ $field ], $context, 'html');
                    } catch (\Throwable $e) {
                        $resolved[ $field ] = '';
                    }
                }
            }
        }

        return $this->success(
            [
                'post_id'        => $post_id,
                'meta'           => $meta,
                'resolved'       => $resolved,
                'focus_keywords' => array_values($focus_keywords),
                'defaults'       => $defaults,
                'options'        => [
                    'robots'       => MetaSeoDefaults::get_robots_options(),
                    'og_type'      => MetaSeoDefaults::get_og_type_options(),
                    'twitter_card' => MetaSeoDefaults::get_twitter_card_options(),
                ],
            ],
        );
    }

    /**
     * Updates SEO metadata for a post
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function update_meta_seo(WP_REST_Request $request)
    {
        $post_id = $request->get_param('post_id');
        $data    = $request->get_json_params();

        $post = get_post($post_id);
        if (!$post) {
            return $this->error(__('Post not found.', 'seopulse'), 404);
        }

        if (!current_user_can('edit_post', $post_id)) {
            return $this->error(__('Permission denied.', 'seopulse'), 403);
        }

        // Handle focus_keywords separately (v3.0+ multi-keyword support)
        $focus_keywords = isset($data['focus_keywords']) && is_array($data['focus_keywords'])
            ? $data['focus_keywords']
            : [];

        if (!empty($focus_keywords)) {
            // Sanitize keywords
            $focus_keywords = array_filter(array_map('sanitize_text_field', $focus_keywords));

            // Validate keyword format (min 2 chars, max 50 chars)
            foreach ($focus_keywords as $kw) {
                $len = mb_strlen($kw);
                if ($len < 2 || $len > 50) {
                    return $this->error(
                        __('Each focus keyword must be between 2 and 50 characters.', 'seopulse'),
                        400,
                    );
                }
            }

            // Remove duplicates (case-insensitive)
            $seen   = [];
            $unique = [];
            foreach ($focus_keywords as $kw) {
                $lower = mb_strtolower($kw);
                if (!isset($seen[ $lower ])) {
                    $seen[ $lower ] = true;
                    $unique[]       = $kw;
                }
            }
            $focus_keywords = $unique;

            // Save focus keywords
            if (!empty($focus_keywords)) {
                update_post_meta($post_id, PostMeta::FOCUS_KEYWORDS, array_values($focus_keywords));
            }

            // Remove focus_keywords from data to avoid including it in meta_seo
            unset($data['focus_keywords']);
        }

        // Sanitize meta_seo data
        $sanitized = $this->sanitize_meta_data($data);

        // Merge with existing meta to avoid losing untouched fields
        $existing = get_post_meta($post_id, '_seopulse_meta_seo', true);
        if (!is_array($existing)) {
            $existing = [];
        }
        $merged = array_merge($existing, $sanitized);

        // Save
        update_post_meta($post_id, '_seopulse_meta_seo', $merged);

        // Invalidate cache
        $cache = \SEOPulse\seopulse()->cache();
        $cache->delete_analysis($post_id);

        return $this->success(
            [
                'message' => __('Meta SEO updated successfully.', 'seopulse'),
                'meta'    => $sanitized,
            ],
        );
    }

    /**
     * Retrieves preview data
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function get_preview_data(WP_REST_Request $request)
    {
        $post_id = $request->get_param('post_id');

        $post = get_post($post_id);
        if (!$post) {
            return $this->error(__('Post not found.', 'seopulse'), 404);
        }

        $meta = get_post_meta($post_id, '_seopulse_meta_seo', true);
        if (!is_array($meta)) {
            $meta = [];
        }

        $defaults = MetaSeoDefaults::get_post_defaults($post, $meta);

        return $this->success(
            [
                'google'   => [
                    'title'       => $defaults['title'],
                    'description' => $defaults['description'],
                    'url'         => get_permalink($post_id),
                ],
                'facebook' => [
                    'title'       => $defaults['og_title'],
                    'description' => $defaults['og_description'],
                    'image'       => $defaults['og_image'],
                ],
                'twitter'  => [
                    'title'       => $defaults['twitter_title'],
                    'description' => $defaults['twitter_description'],
                    'image'       => $defaults['twitter_image'],
                ],
            ],
        );
    }

    /**
     * Sanitizes meta data
     *
     * @param array $data Raw data
     * @return array Sanitized data
     */
    private function sanitize_meta_data(array $data): array
    {
        $sanitized = [];

        $text_fields = [
            'title',
            'robots',
            'keywords',
            'author',
            'theme_color',
            'og_title',
            'og_type',
            'og_site_name',
            'twitter_card',
            'twitter_title',
            'twitter_site',
            'twitter_creator',
            'geo_region',
            'geo_placename',
            'geo_position',
        ];

        $textarea_fields = ['description', 'og_description', 'twitter_description'];

        $url_fields = ['og_url', 'og_image', 'twitter_image', 'canonical'];

        foreach ($text_fields as $field) {
            if (isset($data[ $field ])) {
                $sanitized[ $field ] = sanitize_text_field($data[ $field ]);
            }
        }

        foreach ($textarea_fields as $field) {
            if (isset($data[ $field ])) {
                $sanitized[ $field ] = sanitize_textarea_field($data[ $field ]);
            }
        }

        foreach ($url_fields as $field) {
            if (isset($data[ $field ])) {
                $sanitized[ $field ] = esc_url_raw($data[ $field ]);
            }
        }

        return $sanitized;
    }
}
