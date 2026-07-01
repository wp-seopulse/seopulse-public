<?php

/**
 * REST API controller for Taxonomy SEO settings and analysis.
 *
 * Provides endpoints for:
 *  - Taxonomy analysis (orphans, thin content, ratios)
 *  - Per-taxonomy template configuration
 *  - Taxonomy settings (noindex, nofollow)
 *  - Merge suggestions
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\MetaSeo;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Core\Constants\Options;
use SEOPulse\Services\TaxonomyAnalyzer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * TaxonomySettingsController — REST endpoints for taxonomy SEO.
 *
 * Routes:
 *  GET  /seopulse/v1/taxonomy/taxonomies     – List public taxonomies
 *  GET  /seopulse/v1/taxonomy/analyze         – Full site taxonomy analysis
 *  GET  /seopulse/v1/taxonomy/analyze/{tax}   – Single taxonomy analysis
 *  GET  /seopulse/v1/taxonomy/ratios          – Content-to-taxonomy ratios
 *  GET  /seopulse/v1/taxonomy/settings        – Get taxonomy SEO settings
 *  POST /seopulse/v1/taxonomy/settings        – Save taxonomy SEO settings
 */
class TaxonomySettingsController extends RestController
{
    /** @var string Option key for taxonomy-specific SEO settings. */
    private const OPTION_KEY = Options::TAXONOMY_SETTINGS;

    /**
     * Default taxonomy settings structure.
     *
     * @var array
     */
    private const DEFAULTS = [
        'category' => [
            'title'          => '',
            'description'    => '',
            'robots'         => 'index,follow',
            'canonical'      => '',
            'noindex_empty'  => true,
            'noindex_thin'   => false,
            'thin_threshold' => 3,
            'nofollow'       => false,
            'title_prefix'   => '',
            'title_suffix'   => '',
        ],
        'post_tag' => [
            'title'          => '',
            'description'    => '',
            'robots'         => 'noindex,follow',
            'canonical'      => '',
            'noindex_empty'  => true,
            'noindex_thin'   => true,
            'thin_threshold' => 2,
            'nofollow'       => false,
            'title_prefix'   => '',
            'title_suffix'   => '',
        ],
    ];

    /** @var string[] Allowed template fields for taxonomy contexts. */
    private const TAXONOMY_TEMPLATE_FIELDS = [
        'title',
        'description',
        'canonical',
        'robots',
    ];

    /** @var string[] Allowed setting fields (non-template). */
    private const SETTING_FIELDS = [
        'noindex_empty',
        'noindex_thin',
        'thin_threshold',
        'nofollow',
        'title_prefix',
        'title_suffix',
    ];

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->rest_base = 'taxonomy';
    }

    /**
     * Register REST routes.
     */
    public function register_routes(): void
    {
        // GET /taxonomies — List public taxonomies
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/taxonomies',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_taxonomies'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // GET /analyze — Full-site taxonomy analysis
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/analyze',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'analyze_all'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // GET /analyze/{taxonomy} — Single taxonomy analysis
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/analyze/(?P<taxonomy>[a-z0-9_-]+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'analyze_taxonomy'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'taxonomy' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_key',
                        ],
                    ],
                ],
            ],
        );

        // GET /ratios — Content-to-taxonomy ratios
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/ratios',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_ratios'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
            ],
        );

        // GET/POST /settings — Taxonomy SEO settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_settings'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'settings' => [
                            'required' => true,
                            'type'     => 'object',
                        ],
                    ],
                ],
            ],
        );
    }

    // ------------------------------------------------------------------
    // Endpoint Handlers
    // ------------------------------------------------------------------

    /**
     * GET /taxonomies — List all public taxonomies with metadata.
     */
    public function get_taxonomies(WP_REST_Request $request): WP_REST_Response
    {
        $analyzer   = $this->getAnalyzer();
        $taxonomies = $analyzer->getPublicTaxonomies();
        $result     = [];

        foreach ($taxonomies as $slug => $taxObj) {
            $termCount = wp_count_terms(
                [
                    'taxonomy'   => $slug,
                    'hide_empty' => false,
                ],
            );

            $result[ $slug ] = [
                'slug'         => $slug,
                'label'        => $taxObj->labels->singular_name ?? $taxObj->label,
                'plural_label' => $taxObj->labels->name ?? $taxObj->label,
                'hierarchical' => $taxObj->hierarchical,
                'public'       => $taxObj->public,
                'builtin'      => $taxObj->_builtin,
                'term_count'   => is_wp_error($termCount) ? 0 : (int) $termCount,
                'post_types'   => $taxObj->object_type,
            ];
        }

        return $this->success(
            [
                'taxonomies' => $result,
                'count'      => count($result),
            ],
        );
    }

    /**
     * GET /analyze — Full site taxonomy analysis.
     */
    public function analyze_all(WP_REST_Request $request): WP_REST_Response
    {
        $analyzer = $this->getAnalyzer();
        $result   = $analyzer->analyzeAll();

        return $this->success($result);
    }

    /**
     * GET /analyze/{taxonomy} — Single taxonomy analysis.
     */
    public function analyze_taxonomy(WP_REST_Request $request): WP_REST_Response
    {
        $taxonomy = $request->get_param('taxonomy');
        $taxObj   = get_taxonomy($taxonomy);

        if (!$taxObj) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => __('Taxonomy not found.', 'seopulse'),
                ],
                404,
            );
        }

        $analyzer = $this->getAnalyzer();
        $result   = $analyzer->analyzeTaxonomy($taxonomy);

        return $this->success($result);
    }

    /**
     * GET /ratios — Content-to-taxonomy ratio analysis.
     */
    public function get_ratios(WP_REST_Request $request): WP_REST_Response
    {
        $analyzer = $this->getAnalyzer();
        $ratios   = $analyzer->computeGlobalRatios();

        return $this->success($ratios);
    }

    /**
     * GET /settings — Get saved taxonomy SEO settings.
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response
    {
        $saved    = get_option(self::OPTION_KEY, []);
        $defaults = $this->getDefaultSettings();

        // Merge saved with defaults
        $merged = [];

        foreach ($defaults as $tax => $defaultFields) {
            $merged[ $tax ] = array_merge($defaultFields, $saved[ $tax ] ?? []);
        }

        // Include any custom taxonomies that have saved settings
        if (is_array($saved)) {
            foreach ($saved as $tax => $fields) {
                if (!isset($merged[ $tax ])) {
                    $merged[ $tax ] = array_merge($this->getCustomTaxonomyDefaults(), $fields);
                }
            }
        }

        // Template data from meta_templates option
        $templates         = get_option(Options::META_TEMPLATES, []);
        $taxonomyTemplates = [];

        foreach ($merged as $tax => $settings) {
            $taxonomyTemplates[ $tax ] = [
                'settings'  => $settings,
                'templates' => $templates[ 'taxonomy_' . $tax ] ?? [],
            ];
        }

        return $this->success(
            [
                'taxonomy_settings' => $taxonomyTemplates,
                'defaults'          => $defaults,
            ],
        );
    }

    /**
     * POST /settings — Save taxonomy SEO settings.
     */
    public function save_settings(WP_REST_Request $request): WP_REST_Response
    {
        $settings = $request->get_param('settings');

        if (!is_array($settings)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => __('Invalid settings format.', 'seopulse'),
                ],
                400,
            );
        }

        // Sanitize and save settings (non-template fields)
        $sanitizedSettings = [];

        foreach ($settings as $tax => $taxSettings) {
            $safeTax = sanitize_key($tax);

            if (!is_array($taxSettings)) {
                continue;
            }

            $sanitizedSettings[ $safeTax ] = [];

            // Settings fields
            if (isset($taxSettings['settings']) && is_array($taxSettings['settings'])) {
                foreach ($taxSettings['settings'] as $key => $value) {
                    $safeKey = sanitize_key($key);

                    if (!in_array($safeKey, array_merge(self::SETTING_FIELDS, self::TAXONOMY_TEMPLATE_FIELDS), true)) {
                        continue;
                    }

                    if (is_bool($value) || $safeKey === 'noindex_empty' || $safeKey === 'noindex_thin' || $safeKey === 'nofollow') {
                        $sanitizedSettings[ $safeTax ][ $safeKey ] = (bool) $value;
                    } elseif ($safeKey === 'thin_threshold') {
                        $sanitizedSettings[ $safeTax ][ $safeKey ] = max(1, (int) $value);
                    } else {
                        $sanitizedSettings[ $safeTax ][ $safeKey ] = sanitize_text_field((string) $value);
                    }
                }
            }

            // Template fields → save to META_TEMPLATES option under taxonomy_{slug} key
            if (isset($taxSettings['templates']) && is_array($taxSettings['templates'])) {
                $this->saveTaxonomyTemplates($safeTax, $taxSettings['templates']);
            }
        }

        update_option(self::OPTION_KEY, $sanitizedSettings, false);

        return $this->success(
            [
                'saved'   => true,
                'message' => __('Taxonomy settings saved successfully.', 'seopulse'),
            ],
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Save taxonomy-specific templates to the META_TEMPLATES option.
     *
     * @param string $taxonomy Taxonomy slug.
     * @param array $templates Template fields.
     */
    private function saveTaxonomyTemplates(string $taxonomy, array $templates): void
    {
        $existing = get_option(Options::META_TEMPLATES, []);

        if (!is_array($existing)) {
            $existing = [];
        }

        $key       = 'taxonomy_' . $taxonomy;
        $sanitized = [];

        foreach ($templates as $field => $value) {
            $safeField = sanitize_key($field);

            if (in_array($safeField, self::TAXONOMY_TEMPLATE_FIELDS, true) && is_string($value)) {
                $sanitized[ $safeField ] = wp_kses($value, []);
            }
        }

        $existing[ $key ] = $sanitized;

        update_option(Options::META_TEMPLATES, $existing, false);
    }

    /**
     * Get default settings for all standard taxonomies.
     *
     * @return array
     */
    private function getDefaultSettings(): array
    {
        $defaults   = self::DEFAULTS;
        $analyzer   = $this->getAnalyzer();
        $taxonomies = $analyzer->getPublicTaxonomies();

        foreach ($taxonomies as $slug => $taxObj) {
            if (!isset($defaults[ $slug ])) {
                $defaults[ $slug ] = $this->getCustomTaxonomyDefaults();
            }
        }

        return $defaults;
    }

    /**
     * Default settings for custom taxonomies.
     */
    private function getCustomTaxonomyDefaults(): array
    {
        return [
            'title'          => '',
            'description'    => '',
            'robots'         => 'index,follow',
            'canonical'      => '',
            'noindex_empty'  => true,
            'noindex_thin'   => false,
            'thin_threshold' => 3,
            'nofollow'       => false,
            'title_prefix'   => '',
            'title_suffix'   => '',
        ];
    }

    /**
     * Get the TaxonomyAnalyzer instance.
     */
    private function getAnalyzer(): TaxonomyAnalyzer
    {
        static $analyzer = null;

        if ($analyzer === null) {
            $analyzer = new TaxonomyAnalyzer();
        }

        return $analyzer;
    }
}
