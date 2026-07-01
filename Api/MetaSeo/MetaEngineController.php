<?php

/**
 * REST API controller for the Meta Template Engine.
 *
 * Provides endpoints for variable listing, template resolution,
 * post preview, and headless meta consumption.
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
use SEOPulse\Modules\MetaSeo\Archives\ArchiveSettingsManager;
use SEOPulse\Modules\MetaSeo\Engine\ContextBag;
use SEOPulse\Modules\MetaSeo\Engine\MetaEngine;
use WP_REST_Request;
use WP_REST_Response;

/**
 * MetaEngineController — REST endpoints for the variable engine.
 *
 * Routes:
 *  GET  /seopulse/v1/meta-engine/variables           – List all variables (autocomplete)
 *  POST /seopulse/v1/meta-engine/resolve              – Resolve a template string
 *  GET  /seopulse/v1/meta-engine/preview/<post_id>    – Preview resolved meta for a post
 *  POST /seopulse/v1/meta-engine/meta                 – Headless: resolve full meta for given context
 *  POST /seopulse/v1/meta-engine/templates            – Save template, archive & taxonomy settings (unified)
 *  GET  /seopulse/v1/meta-engine/templates             – Get template configuration
 */
class MetaEngineController extends RestController
{
    /**
     * Allowed template fields per context type.
     *
     * Global templates include keywords, author and separator.
     * CPT templates (post, page, etc.) include robots.
     * Archive context types (author, search, 404, archive) include robots.
     * OG/Twitter fields are resolved automatically from title/description
     * and are no longer user-editable in the Templates tab.
     */
    private const GLOBAL_FIELDS = [
        'title',
        'description',
        'canonical',
        'separator',
        'keywords',
        'author',
    ];

    private const CPT_FIELDS = [
        'title',
        'description',
        'canonical',
        'robots',
    ];

    /** @var string[] Allowed template fields for taxonomy context types. */
    private const TAXONOMY_FIELDS = [
        'title',
        'description',
        'canonical',
        'robots',
    ];

    /** @var string[] Archive-related context type keys. */
    private const ARCHIVE_CONTEXT_TYPES = [
        'author',
        'search',
        '404',
        'archive',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rest_base = 'meta-engine';
    }

    /**
     * Registers REST routes.
     */
    public function register_routes(): void
    {
        // GET /variables — List available variables for autocomplete
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/variables',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_variables'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'namespace' => [
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );

        // POST /resolve — Resolve a single template string
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/resolve',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'resolve_template'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'template' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'context'  => [
                            'type'    => 'object',
                            'default' => [],
                        ],
                    ],
                ],
            ],
        );

        // GET /preview/{post_id} — Preview resolved meta for a post
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/preview/(?P<post_id>\d+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'preview_post'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'validate_callback' => static fn ($v) => is_numeric($v) && (int) $v > 0,
                        ],
                    ],
                ],
            ],
        );

        // GET /meta — Headless: resolve full meta given explicit context.
        // Intentionally public: this endpoint returns the same SEO meta tags
        // (title, description, Open Graph, canonical…) that are already output
        // in the <head> of the corresponding public page. It is designed for
        // headless / decoupled WordPress frontends that need to fetch meta via REST.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/meta',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_headless_meta'],
                    'permission_callback' => '__return_true',
                    'args'                => [
                        'post_id'      => ['type' => 'integer'],
                        'term_id'      => ['type' => 'integer'],
                        'author_id'    => ['type' => 'integer'],
                        'type'         => [
                            'type'    => 'string',
                            'default' => 'singular',
                        ],
                        'search_query' => ['type' => 'string'],
                        'page'         => [
                            'type'    => 'integer',
                            'default' => 1,
                        ],
                    ],
                ],
            ],
        );

        // GET/POST /templates — Template configuration CRUD
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/templates',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_templates'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_templates'],
                    'permission_callback' => [$this, 'check_admin_permissions'],
                    'args'                => [
                        'templates'         => [
                            'required' => false,
                            'type'     => 'object',
                            'default'  => [],
                        ],
                        'archive_settings'  => [
                            'required' => false,
                            'type'     => 'object',
                            'default'  => [],
                        ],
                        'taxonomy_settings' => [
                            'required' => false,
                            'type'     => 'object',
                            'default'  => [],
                        ],
                    ],
                ],
            ],
        );
    }

    // ------------------------------------------------------------------
    // Endpoint handlers
    // ------------------------------------------------------------------

    /**
     * GET /variables — Returns all available variables grouped by namespace.
     */
    public function get_variables(WP_REST_Request $request): WP_REST_Response
    {
        $engine    = $this->getEngine();
        $namespace = $request->get_param('namespace');

        $definitions = $engine->getAvailableVariables(
            is_string($namespace) && $namespace !== '' ? $namespace : null,
        );

        $grouped = [];

        foreach ($definitions as $def) {
            $ns               = $def->namespace ?? 'global';
            $grouped[ $ns ][] = $def->toArray();
        }

        return $this->success(
            [
                'variables' => $grouped,
                'count'     => count($definitions),
            ],
        );
    }

    /**
     * POST /resolve — Resolve a single template string with optional context.
     */
    public function resolve_template(WP_REST_Request $request): WP_REST_Response
    {
        $engine   = $this->getEngine();
        $template = (string) $request->get_param('template');
        $ctxData  = $request->get_param('context');

        $context = is_array($ctxData) && !empty($ctxData)
            ? ContextBag::fromArray($ctxData)
            : $this->buildSampleContext();

        try {
            $resolved = $engine->resolve($template, $context, 'html');
        } catch (\Throwable $e) {
            return $this->success(
                [
                    'resolved' => '',
                    'error'    => $e->getMessage(),
                ],
            );
        }

        return $this->success(
            [
                'template' => $template,
                'resolved' => $resolved,
            ],
        );
    }

    /**
     * GET /preview/{post_id} — Preview resolved meta for a post.
     */
    public function preview_post(WP_REST_Request $request): WP_REST_Response
    {
        $engine = $this->getEngine();
        $postId = (int) $request->get_param('post_id');

        $post = get_post($postId);

        if (!($post instanceof \WP_Post)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => __('Post not found.', 'seopulse'),
                ],
                404,
            );
        }

        $output = $engine->preview($postId);

        return $this->success($output->toArray());
    }

    /**
     * GET /meta — Public headless endpoint for resolved meta.
     */
    public function get_headless_meta(WP_REST_Request $request): WP_REST_Response
    {
        $engine = $this->getEngine();

        $contextData = [
            'type'         => $request->get_param('type') ?? 'singular',
            'post_id'      => $request->get_param('post_id'),
            'term_id'      => $request->get_param('term_id'),
            'author_id'    => $request->get_param('author_id'),
            'search_query' => $request->get_param('search_query'),
            'page'         => $request->get_param('page') ?? 1,
        ];

        // Remove null values
        $contextData = array_filter($contextData, static fn ($v) => $v !== null);

        $context = ContextBag::fromArray($contextData);
        $output  = $engine->resolveAll($context);

        return $this->success($output->toArray());
    }

    /**
     * GET /templates — Retrieve current template configuration.
     */
    public function get_templates(WP_REST_Request $request): WP_REST_Response
    {
        $engine   = $this->getEngine();
        $store    = $engine->getTemplateStore();
        $saved    = get_option(Options::META_TEMPLATES, []);
        $defaults = $store->getDefaultTemplates();

        // Available context types
        $contextDefaults = [];

        foreach (['singular', 'home', 'taxonomy', 'author', 'search', '404', 'archive'] as $ctx) {
            $contextDefaults[ $ctx ] = $store->getDefaultTemplates($ctx);
        }

        // Strip obsolete fields (OG/Twitter) from saved data
        $cleaned = $this->stripObsoleteFields(is_array($saved) ? $saved : []);

        return $this->success(
            [
                'saved'            => $cleaned,
                'defaults'         => $defaults,
                'context_defaults' => $contextDefaults,
            ],
        );
    }

    /**
     * POST /templates — Unified save for templates, archive & taxonomy settings.
     *
     * Accepts up to three optional keys:
     *  - `templates`         – Meta template configuration (title, description…)
     *  - `archive_settings`  – Archive SEO settings (author, date, search, 404)
     *  - `taxonomy_settings` – Per-taxonomy SEO settings + templates
     *
     * Each key is processed independently so the endpoint can be called with
     * any combination.  A single REST round-trip replaces the former three.
     */
    public function save_templates(WP_REST_Request $request): WP_REST_Response
    {
        try {
            // Read directly from JSON body to guarantee parsing.
            $body = $request->get_json_params();

            $templates        = is_array($body['templates'] ?? null) ? $body['templates'] : [];
            $archiveSettings  = is_array($body['archive_settings'] ?? null) ? $body['archive_settings'] : [];
            $taxonomySettings = is_array($body['taxonomy_settings'] ?? null) ? $body['taxonomy_settings'] : [];

            // ── 1. Templates ─────────────────────────────────────────
            if (is_array($templates) && !empty($templates)) {
                $sanitized = $this->sanitizeTemplates($templates);
                $sanitized = $this->filterAllowedFields($sanitized);

                $existing = get_option(Options::META_TEMPLATES, []);
                if (!is_array($existing)) {
                    $existing = [];
                }
                $existing = $this->stripObsoleteFields($existing);

                $merged = array_replace_recursive($existing, $sanitized);
                update_option(Options::META_TEMPLATES, $merged, false);
            }

            // ── 2. Archive settings ──────────────────────────────────
            if (is_array($archiveSettings) && !empty($archiveSettings)) {
                $this->saveArchiveSettings($archiveSettings);
            }

            // ── 3. Taxonomy settings ─────────────────────────────────
            if (is_array($taxonomySettings) && !empty($taxonomySettings)) {
                $this->saveTaxonomySettings($taxonomySettings);
            }

            // Flush engine cache once all sections are persisted
            $this->getEngine()->flushCache();

            return $this->success(
                [
                    'saved'   => true,
                    'message' => __('Settings saved successfully.', 'seopulse'),
                ],
            );
        } catch (\Throwable $e) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                ],
                500,
            );
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Get the MetaEngine instance.
     */
    private function getEngine(): MetaEngine
    {
        static $engine = null;

        if ($engine === null) {
            $engine = new MetaEngine();
        }

        return $engine;
    }

    /**
     * Build a sample singular context for admin template preview.
     *
     * Uses the most recently modified published post so that contextual
     * variables (%%post.title%%, %%author.name%%, etc.) resolve to real
     * values in the live preview instead of returning empty strings.
     */
    private function buildSampleContext(): ContextBag
    {
        $posts = get_posts(
            [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ],
        );

        if (!empty($posts)) {
            $post   = reset($posts);
            $author = ($post instanceof \WP_Post)
                ? get_userdata((int) $post->post_author)
                : null;

            return new ContextBag(
                type: 'singular',
                post: ($post instanceof \WP_Post) ? $post : null,
                author: ($author instanceof \WP_User) ? $author : null,
            );
        }

        return new ContextBag(type: 'home');
    }

    // ------------------------------------------------------------------
    // Archive & Taxonomy persistence (delegated from save_templates)
    // ------------------------------------------------------------------

    /**
     * Persist archive settings.
     *
     * Mirrors the logic formerly in ArchiveSettingsController::save_settings().
     *
     * @param array<string, mixed> $settings Partial archive settings keyed by type.
     */
    private function saveArchiveSettings(array $settings): void
    {
        $manager  = new ArchiveSettingsManager();
        $existing = $manager->getAll();

        foreach ($settings as $key => $values) {
            if (is_array($values) && isset($existing[ $key ])) {
                $existing[ $key ] = array_merge($existing[ $key ], $values);
            }
        }

        $manager->updateAll($existing);
    }

    /** @var string[] Allowed taxonomy setting fields (non-template). */
    private const TAXONOMY_SETTING_FIELDS = [
        'noindex_empty',
        'noindex_thin',
        'thin_threshold',
        'nofollow',
        'title_prefix',
        'title_suffix',
    ];

    /**
     * Persist per-taxonomy SEO settings and templates.
     *
     * Mirrors the logic formerly in TaxonomySettingsController::save_settings().
     *
     * @param array<string, mixed> $settings Keyed by taxonomy slug.
     */
    private function saveTaxonomySettings(array $settings): void
    {
        $sanitizedSettings = [];

        foreach ($settings as $tax => $taxSettings) {
            $safeTax = sanitize_key($tax);

            if (!is_array($taxSettings)) {
                continue;
            }

            $sanitizedSettings[ $safeTax ] = [];

            // Settings fields
            if (isset($taxSettings['settings']) && is_array($taxSettings['settings'])) {
                $allowedKeys = array_merge(self::TAXONOMY_SETTING_FIELDS, self::TAXONOMY_FIELDS);

                foreach ($taxSettings['settings'] as $key => $value) {
                    $safeKey = sanitize_key($key);

                    if (!in_array($safeKey, $allowedKeys, true)) {
                        continue;
                    }

                    if (is_bool($value) || in_array($safeKey, ['noindex_empty', 'noindex_thin', 'nofollow'], true)) {
                        $sanitizedSettings[ $safeTax ][ $safeKey ] = (bool) $value;
                    } elseif ($safeKey === 'thin_threshold') {
                        $sanitizedSettings[ $safeTax ][ $safeKey ] = max(1, (int) $value);
                    } else {
                        $sanitizedSettings[ $safeTax ][ $safeKey ] = sanitize_text_field((string) $value);
                    }
                }
            }

            // Template fields → META_TEMPLATES option under taxonomy_{slug}
            if (isset($taxSettings['templates']) && is_array($taxSettings['templates'])) {
                $existing = get_option(Options::META_TEMPLATES, []);

                if (!is_array($existing)) {
                    $existing = [];
                }

                $tplKey    = 'taxonomy_' . $safeTax;
                $sanitized = [];

                foreach ($taxSettings['templates'] as $field => $value) {
                    $safeField = sanitize_key($field);

                    if (in_array($safeField, self::TAXONOMY_FIELDS, true) && is_string($value)) {
                        $sanitized[ $safeField ] = wp_kses($value, []);
                    }
                }

                $existing[ $tplKey ] = $sanitized;
                update_option(Options::META_TEMPLATES, $existing, false);
            }
        }

        update_option(Options::TAXONOMY_SETTINGS, $sanitizedSettings, false);
    }

    /**
     * Keep only allowed fields for each context type.
     *
     * @param array<string, mixed> $templates
     * @return array<string, mixed>
     */
    private function filterAllowedFields(array $templates): array
    {
        $filtered = [];

        foreach ($templates as $type => $fields) {
            if (!is_array($fields)) {
                continue;
            }

            if ($type === 'global') {
                $allowed = self::GLOBAL_FIELDS;
            } elseif (str_starts_with($type, 'taxonomy_')) {
                $allowed = self::TAXONOMY_FIELDS;
            } elseif (in_array($type, self::ARCHIVE_CONTEXT_TYPES, true)) {
                $allowed = self::CPT_FIELDS; // Archive contexts share the same field set
            } else {
                $allowed = self::CPT_FIELDS;
            }

            $filtered[ $type ] = array_intersect_key($fields, array_flip($allowed));
        }

        return $filtered;
    }

    /**
     * Strip obsolete fields (OG/Twitter) from saved template data.
     *
     * @param array<string, mixed> $saved
     * @return array<string, mixed>
     */
    private function stripObsoleteFields(array $saved): array
    {
        $obsolete = [
            'og_title',
            'og_description',
            'og_image',
            'og_type',
            'twitter_title',
            'twitter_description',
            'twitter_image',
            'twitter_card',
        ];

        foreach ($saved as $type => &$fields) {
            if (!is_array($fields)) {
                continue;
            }

            foreach ($obsolete as $key) {
                unset($fields[ $key ]);
            }
        }
        unset($fields);

        return $saved;
    }

    /**
     * Recursively sanitize template arrays.
     *
     * @param array<string, mixed> $templates
     * @return array<string, mixed>
     */
    private function sanitizeTemplates(array $templates): array
    {
        $sanitized = [];

        foreach ($templates as $key => $value) {
            $safeKey = sanitize_key($key);

            if (is_array($value)) {
                $sanitized[ $safeKey ] = $this->sanitizeTemplates($value);
            } elseif (is_string($value)) {
                // Allow %% %% syntax but strip anything dangerous
                $sanitized[ $safeKey ] = wp_kses($value, []);
            }
        }

        return $sanitized;
    }
}
