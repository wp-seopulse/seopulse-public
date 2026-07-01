<?php

/**
 * REST API Controller for the Setup Wizard
 *
 * Provides endpoints to read/write MetaSEO settings,
 * collect a site profile, and mark the wizard as completed.
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Core\Constants\Options;
use SEOPulse\Modules\LocalSeo\LocalSeoDefaults;
use SEOPulse\Modules\LocalSeo\LocalSeoValidator;
use WP_REST_Request;
use WP_REST_Response;

class SetupWizardController extends RestController
{
    /**
     * Allowed values for the site profile fields.
     */
    private const SITE_TYPES = [
        'blog',
        'ecommerce',
        'service',
        'portfolio',
        'corporate',
        'media',
        'other',
    ];

    private const ACTIVITY_TYPES = [
        'content_creator',
        'agency',
        'freelancer',
        'brand',
        'nonprofit',
        'other',
    ];

    private const SEO_PRESETS = [
        'default',
        'blog',
        'local_business',
        'ecommerce',
        'portfolio',
    ];

    public function __construct()
    {
        $this->rest_base = 'setup-wizard';
    }

    public function register_routes(): void
    {
        // GET all wizard data (MetaSEO + Local SEO + profile + status)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_wizard_data'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // POST save site profile step
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/profile',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_profile'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // POST save MetaSEO step
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/meta-seo',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_meta_seo'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // POST save Local SEO step
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/local-seo',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_local_seo'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // POST mark wizard as complete
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/complete',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'complete_wizard'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // GET environment detection (other SEO plugins, WooCommerce, etc.)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/environment',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_environment'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // POST import settings from another SEO plugin
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/import',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'import_from_plugin'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );
    }

    /**
     * GET — Returns all wizard data (profile + modules + wizard status)
     */
    public function get_wizard_data(WP_REST_Request $request): WP_REST_Response
    {
        $meta_seo       = (array) get_option(Options::META_SEO_GLOBAL, []);
        $local_seo      = (array) get_option(Options::LOCAL_SEO, LocalSeoDefaults::get_default_settings());
        $analytics      = (array) get_option(Options::ANALYTICS, []);
        $profile        = (array) get_option(Options::WIZARD_PROFILE, []);
        $setup_complete = (bool) get_option(Options::SETUP_COMPLETE, false);

        // Merge tracking data from analytics settings into metaSeo for the wizard
        $tracking = [
            'gtm_enabled' => !empty($analytics['gtm_enabled']),
            'gtm_id'      => $analytics['gtm_id'] ?? '',
            'ga4_enabled' => !empty($analytics['ga4_enabled']),
            'ga4_id'      => $analytics['ga4_id'] ?? '',
        ];

        return $this->success(
            [
                'profile'             => $this->get_profile_with_defaults($profile),
                'metaSeo'             => array_merge($meta_seo, $tracking),
                'localSeo'            => $local_seo,
                'recommendationState' => [
                    'metaSeo'  => (array) get_option(Options::WIZARD_META_SEO_RECOMMENDATIONS, []),
                    'localSeo' => (array) get_option(Options::WIZARD_LOCAL_SEO_RECOMMENDATIONS, []),
                ],
                'setupComplete'       => $setup_complete,
                'siteInfo'            => [
                    'name'        => get_bloginfo('name'),
                    'description' => get_bloginfo('description'),
                    'url'         => home_url(),
                ],
            ],
        );
    }

    /**
     * POST — Save site profile step
     */
    public function save_profile(WP_REST_Request $request): WP_REST_Response
    {
        $data = $request->get_json_params();

        if (empty($data) || !is_array($data)) {
            return new WP_REST_Response($this->error_response('Invalid data.'), 400);
        }

        $sanitized = $this->sanitize_profile($data);

        // Merge with existing profile to keep any previously saved fields
        $existing = (array) get_option(Options::WIZARD_PROFILE, []);
        $merged   = array_merge($existing, $sanitized);
        update_option(Options::WIZARD_PROFILE, $merged);

        return $this->success(
            [
                'message' => __('Site profile saved successfully.', 'seopulse'),
                'data'    => $merged,
            ],
        );
    }

    /**
     * POST — Save MetaSEO settings
     */
    public function save_meta_seo(WP_REST_Request $request): WP_REST_Response
    {
        $data = $request->get_json_params();

        if (empty($data) || !is_array($data)) {
            return new WP_REST_Response($this->error_response('Invalid data.'), 400);
        }

        $recommendation_meta = $this->sanitize_recommendation_meta(
            is_array($data['recommendationMeta'] ?? null) ? $data['recommendationMeta'] : [],
        );
        unset($data['recommendationMeta']);

        $sanitized = $this->sanitize_meta_seo($data);

        // Separate tracking fields → save to analytics settings
        $tracking_fields = ['gtm_enabled', 'gtm_id', 'ga4_enabled', 'ga4_id'];
        $tracking        = [];
        foreach ($tracking_fields as $field) {
            if (isset($sanitized[ $field ])) {
                $tracking[ $field ] = $sanitized[ $field ];
                unset($sanitized[ $field ]);
            }
        }

        update_option(Options::META_SEO_GLOBAL, $sanitized);
        if (!empty($tracking)) {
            // Merge tracking into existing analytics settings
            $analytics = (array) get_option(Options::ANALYTICS, []);
            $analytics = array_merge($analytics, $tracking);
            update_option(Options::ANALYTICS, $analytics);
        }
        update_option(Options::WIZARD_META_SEO_RECOMMENDATIONS, $recommendation_meta);

        return $this->success(
            [
                'message'            => __('MetaSEO settings saved successfully.', 'seopulse'),
                'data'               => $sanitized,
                'recommendationMeta' => $recommendation_meta,
            ],
        );
    }

    /**
     * POST — Save Local SEO settings
     */
    public function save_local_seo(WP_REST_Request $request): WP_REST_Response
    {
        $data = $request->get_json_params();

        if (empty($data) || !is_array($data)) {
            return new WP_REST_Response($this->error_response('Invalid data.'), 400);
        }

        $recommendation_meta = $this->sanitize_recommendation_meta(
            is_array($data['recommendationMeta'] ?? null) ? $data['recommendationMeta'] : [],
        );
        unset($data['recommendationMeta']);

        $sanitized = LocalSeoValidator::sanitize_settings($data);

        update_option(Options::LOCAL_SEO, $sanitized);
        update_option(Options::WIZARD_LOCAL_SEO_RECOMMENDATIONS, $recommendation_meta);

        return $this->success(
            [
                'message'            => __('Local SEO settings saved successfully.', 'seopulse'),
                'data'               => $sanitized,
                'recommendationMeta' => $recommendation_meta,
            ],
        );
    }

    /**
     * POST — Mark wizard as complete and store completion metadata.
     */
    public function complete_wizard(WP_REST_Request $request): WP_REST_Response
    {
        update_option(Options::SETUP_COMPLETE, true);

        // Persist completion metadata in the profile
        $profile                   = (array) get_option(Options::WIZARD_PROFILE, []);
        $profile['completed_at']   = current_time('mysql');
        $profile['wizard_version'] = '2.1.0';
        update_option(Options::WIZARD_PROFILE, $profile);

        return $this->success(
            [
                'message' => __('Setup wizard completed successfully!', 'seopulse'),
            ],
        );
    }

    /**
     * GET — Detect the WordPress environment: other SEO plugins, WooCommerce, etc.
     */
    public function get_environment(WP_REST_Request $request): WP_REST_Response
    {
        $seo_plugins     = $this->detect_seo_plugins();
        $has_woocommerce = class_exists('WooCommerce') || is_plugin_active('woocommerce/woocommerce.php');

        return $this->success(
            [
                'seoPlugins'     => $seo_plugins,
                'hasWooCommerce' => $has_woocommerce,
                'phpVersion'     => PHP_VERSION,
                'wpVersion'      => get_bloginfo('version'),
                'isMultisite'    => is_multisite(),
                'postCount'      => (int) wp_count_posts('post')->publish,
                'pageCount'      => (int) wp_count_posts('page')->publish,
            ],
        );
    }

    /**
     * POST — Import settings from another SEO plugin.
     */
    public function import_from_plugin(WP_REST_Request $request): WP_REST_Response
    {
        $data   = $request->get_json_params();
        $plugin = sanitize_text_field($data['plugin'] ?? '');

        $migrator_map = [
            'yoast'    => \SEOPulse\Services\YoastMigrator::class,
            'rankmath' => \SEOPulse\Services\RankMathMigrator::class,
            'seopress' => \SEOPulse\Services\SeoPressMigrator::class,
        ];

        if (!isset($migrator_map[ $plugin ])) {
            return new WP_REST_Response($this->error_response(__('Unsupported plugin.', 'seopulse')), 400);
        }

        $migrator_class = $migrator_map[ $plugin ];

        if (!class_exists($migrator_class)) {
            return new WP_REST_Response($this->error_response(__('Migrator not available.', 'seopulse')), 500);
        }

        $migrator = new $migrator_class();
        $result   = $migrator->import();

        $totalImported = count($result['options_imported'] ?? []) + ($result['post_meta_imported'] ?? 0);

        return $this->success(
            [
                'message' => sprintf(
                    /* translators: %d: number of migrated items */
                    __('Import complete. %d items migrated.', 'seopulse'),
                    $totalImported,
                ),
                'details' => $result,
                'count'   => $totalImported,
            ],
        );
    }

    /**
     * Detect installed SEO plugins that can be imported from.
     *
     * @return array<array{slug: string, name: string, active: bool, canImport: bool}>
     */
    private function detect_seo_plugins(): array
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $known = [
            [
                'slug'   => 'yoast',
                'name'   => 'Yoast SEO',
                'file'   => 'wordpress-seo/wp-seo.php',
                'option' => 'wpseo_titles',
            ],
            [
                'slug'   => 'rankmath',
                'name'   => 'Rank Math',
                'file'   => 'seo-by-rank-math/rank-math.php',
                'option' => 'rank-math-options-general',
            ],
            [
                'slug'   => 'seopress',
                'name'   => 'SEOPress',
                'file'   => 'wp-seopress/seopress.php',
                'option' => 'seopress_activated',
            ],
            [
                'slug'   => 'aioseo',
                'name'   => 'All in One SEO',
                'file'   => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
                'option' => 'aioseo_options',
            ],
        ];

        $detected = [];

        foreach ($known as $plugin) {
            $active   = is_plugin_active($plugin['file']);
            $has_data = (bool) get_option($plugin['option'], false);

            if ($active || $has_data) {
                $detected[] = [
                    'slug'      => $plugin['slug'],
                    'name'      => $plugin['name'],
                    'active'    => $active,
                    'canImport' => $has_data && in_array($plugin['slug'], ['yoast', 'rankmath', 'seopress'], true),
                ];
            }
        }

        return $detected;
    }

    /**
     * Sanitize site profile data.
     *
     * @param array $input Raw input data
     * @return array Sanitized profile
     */
    private function sanitize_profile(array $input): array
    {
        $sanitized = [];

        // Allowlisted enums
        $site_type              = sanitize_text_field($input['site_type'] ?? '');
        $sanitized['site_type'] = in_array($site_type, self::SITE_TYPES, true) ? $site_type : 'other';

        $activity_type              = sanitize_text_field($input['activity_type'] ?? '');
        $sanitized['activity_type'] = in_array($activity_type, self::ACTIVITY_TYPES, true) ? $activity_type : 'other';

        $seo_preset              = sanitize_text_field($input['seo_preset'] ?? '');
        $sanitized['seo_preset'] = in_array($seo_preset, self::SEO_PRESETS, true) ? $seo_preset : 'default';

        // Optional connections / features
        $sanitized['wants_analytics'] = !empty($input['wants_analytics']);
        $sanitized['wants_local_seo'] = !empty($input['wants_local_seo']);

        return $sanitized;
    }

    /**
     * Sanitize MetaSEO settings (reuses same logic as MetaSeoSettings)
     */
    private function sanitize_meta_seo(array $input): array
    {
        $sanitized = [];

        $text_fields = [
            'title',
            'keywords',
            'author',
            'robots',
            'theme_color',
            'geo_region',
            'geo_placename',
            'geo_position',
            'og_title',
            'og_type',
            'og_site_name',
            'twitter_card',
            'twitter_title',
            'twitter_site',
            'twitter_creator',
            'gtm_id',
            'ga4_id',
        ];

        foreach ($text_fields as $field) {
            if (isset($input[ $field ])) {
                $sanitized[ $field ] = sanitize_text_field($input[ $field ]);
            }
        }

        $textarea_fields = ['description', 'og_description', 'twitter_description'];
        foreach ($textarea_fields as $field) {
            if (isset($input[ $field ])) {
                $sanitized[ $field ] = sanitize_textarea_field($input[ $field ]);
            }
        }

        $url_fields = ['canonical', 'og_url', 'og_image', 'twitter_image'];
        foreach ($url_fields as $field) {
            if (isset($input[ $field ])) {
                $sanitized[ $field ] = esc_url_raw($input[ $field ]);
            }
        }

        $sanitized['gtm_enabled']      = !empty($input['gtm_enabled']);
        $sanitized['ga4_enabled']      = !empty($input['ga4_enabled']);
        $sanitized['remove_generator'] = !empty($input['remove_generator']);

        return $sanitized;
    }

    /**
     * Sanitize recommendation provenance metadata sent by the wizard.
     */
    private function sanitize_recommendation_meta(array $input): array
    {
        $sanitized = [];

        foreach ($input as $field => $meta) {
            if (!is_array($meta)) {
                continue;
            }

            $source = sanitize_text_field((string) ($meta['source'] ?? 'profile'));
            if ($source !== 'profile') {
                continue;
            }

            $origin = sanitize_text_field((string) ($meta['origin'] ?? 'manual'));
            if (!in_array($origin, ['recommended', 'manual'], true)) {
                $origin = 'manual';
            }

            $clean_field = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $field);
            if ($clean_field === null || $clean_field === '') {
                continue;
            }

            $entry = [
                'source' => 'profile',
                'origin' => $origin,
            ];

            if (array_key_exists('recommendedValue', $meta)) {
                $entry['recommendedValue'] = $this->sanitize_recommendation_value($meta['recommendedValue']);
            }

            $sanitized[ $clean_field ] = $entry;
        }

        return $sanitized;
    }

    /**
     * Sanitize scalar recommendation values.
     *
     * @param mixed $value Raw recommended value
     * @return string|bool|null
     */
    private function sanitize_recommendation_value($value)
    {
        if (is_bool($value) || $value === null) {
            return $value;
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * Returns profile data with sane defaults for missing fields.
     *
     * Ensures backward compatibility: existing installations that never
     * went through the profile step still get a fully-formed object.
     *
     * @param array $profile Stored profile data
     * @return array Profile with defaults
     */
    private function get_profile_with_defaults(array $profile): array
    {
        return array_merge(
            [
                'site_type'       => 'other',
                'activity_type'   => 'other',
                'seo_preset'      => 'default',
                'wants_analytics' => false,
                'wants_local_seo' => false,
                'completed_at'    => null,
                'wizard_version'  => null,
            ],
            $profile,
        );
    }

    /**
     * Build error response array
     */
    private function error_response(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
    }
}
