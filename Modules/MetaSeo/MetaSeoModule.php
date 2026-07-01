<?php

/**
 * MetaSEO module for SEOPulse
 *
 * Manages meta tags, Open Graph, Twitter Cards
 * and existing meta tags analysis
 *
 * @package SEOPulse\Modules\MetaSeo
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\Module;
use SEOPulse\Core\Attributes\AsModule;
use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Contracts\ModuleInterface;
use SEOPulse\Services\ImageAltFiller;
use WP_Post;

/**
 * MetaSeoModule class
 */
#[AsModule(
    key: 'meta_seo',
    label: 'Meta SEO',
    description: 'Meta tags, Open Graph, Twitter Cards and tracking codes.',
    icon: 'dashicons-search',
    namespace: 'SEOPulse\\Modules\\MetaSeo\\',
)]
class MetaSeoModule extends Module implements ModuleInterface
{
    /**
     * Renderer for HTML tags
     *
     * @var MetaSeoRenderer
     */
    private MetaSeoRenderer $renderer;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->name   = 'meta-seo';
        $this->weight = 0.20; // 20% of the total score

        $this->renderer = new MetaSeoRenderer();
    }

    /**
     * Registers WordPress hooks for the module
     *
     * @return void
     */
    public function hooks(): void
    {
        // Hooks frontend
        add_action('wp_head', [$this->renderer, 'render_all_tags'], 1);

        // Overrides WordPress
        add_filter('pre_get_document_title', [$this, 'override_title'], 10);
        add_filter('wp_robots', [$this, 'override_robots'], 10);

        // Remove default tags
        remove_action('wp_head', 'rel_canonical');

        // Head cleanup (generator, WLW, shortlink, emoji, RSS)
        add_action('init', [$this, 'apply_head_cleanup'], 99);

        // Schema (JSON-LD) injection
        $this->register_schema_hooks();

        // Cache invalidation for the meta engine
        add_action('save_post', [$this, 'invalidate_engine_cache_post'], 20, 1);
        add_action('updated_option', [$this, 'invalidate_engine_cache_option'], 10, 1);

        // Image ALT auto-fill on upload
        (new ImageAltFiller())->register_hooks();

        // Register focus keyword meta for REST API (Gutenberg sidebar)
        add_action('init', [$this, 'register_focus_keyword_meta']);

        // Admin
        if (is_admin()) {
            // SEO tags metabox — classic editor only (Gutenberg uses editor-sidebar).
            add_action('add_meta_boxes', [$this, 'register_meta_boxes'], 10, 2);
            add_action('save_post', [$this, 'save_meta_boxes'], 10, 1);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        }
    }

    /**
     * Register schema (JSON-LD) hooks
     *
     * @return void
     */
    private function register_schema_hooks(): void
    {
        // Register per-page providers (Article, WebPage, FAQ) → wp_footer
        $this->register_schema_providers();
        Engine\Providers\SchemaInjector::register_frontend_hooks();

        // Register site-wide providers (WebSite, Org, SearchAction) → wp_head
        Engine\Providers\SchemaInjector::register_head_hooks([$this, 'inject_website_schema']);

        // Register REST endpoint
        add_action(
            'rest_api_init',
            [
                Engine\Providers\SchemaInjector::class,
                'register_rest_endpoint',
            ],
        );
    }

    /**
     * Inject WebSite + Organization + SearchAction in wp_head
     *
     * Called only on the homepage.
     *
     * @return void
     */
    public function inject_website_schema(): void
    {
        $provider = Engine\Providers\SchemaFactory::get('website');

        if (!$provider || !$provider->should_inject() || !$provider->validate()) {
            return;
        }

        $schema = $provider->build();

        if (empty($schema)) {
            return;
        }

        Engine\Providers\SchemaInjector::render_jsonld_public($schema);
    }

    /**
     * Register all per-page schema providers
     *
     * @return void
     */
    private function register_schema_providers(): void
    {
        // Register Article/BlogPosting schema provider
        Engine\Providers\SchemaFactory::register(
            'article',
            new Engine\Providers\ArticleProvider(),
        );

        // Register WebPage schema provider
        Engine\Providers\SchemaFactory::register(
            'webpage',
            new Engine\Providers\WebPageProvider(),
        );

        // Register FAQ schema provider
        Engine\Providers\SchemaFactory::register(
            'faq',
            new Engine\Providers\FAQProvider(),
        );

        // Register WebSite + Organization schema provider (homepage)
        Engine\Providers\SchemaFactory::register(
            'website',
            new Engine\Providers\WebSiteProvider(),
        );

        // Register Organization schema provider (non-homepage pages)
        Engine\Providers\SchemaFactory::register(
            'organization',
            new Engine\Providers\OrgSchemaProvider(),
        );

        // Register HowTo schema provider (block-based)
        Engine\Providers\SchemaFactory::register(
            'howto',
            new Engine\Providers\BlockHowToProvider(),
        );

        // Register Product schema provider (WooCommerce)
        Engine\Providers\SchemaFactory::register(
            'product',
            new Engine\Providers\ProductProvider(),
        );

        // Register Event schema provider
        Engine\Providers\SchemaFactory::register(
            'event',
            new Engine\Providers\EventProvider(),
        );

        // Register HowTo fallback provider (heading-based detection)
        Engine\Providers\SchemaFactory::register(
            'howto-fallback',
            new Engine\Providers\HowToFallbackProvider(),
        );
    }

    /**
     * Enqueues admin assets (CSS and JS)
     *
     * @return void
     */
    public function enqueue_admin_assets(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, ['post', 'page'], true)) {
            return;
        }

        // Metabox CSS and jQuery variable-picker removed — all meta tag editing
        // is now handled by the React sidebar (VariablePickerButton, SocialEditModal).
        // Keep media library for the sidebar's image uploaders.
        wp_enqueue_media();
    }

    /**
     * Analyzes the meta tags of a post
     *
     * @param WP_Post $post WordPress post
     * @return array{score: int, issues: array, recommendations: array, data: array}
     */
    public function analyze(WP_Post $post): array
    {
        $analyzer = new MetaSeoAnalyzer();

        return $analyzer->analyze($post);
    }

    /**
     * Overrides the site title using the Meta Engine.
     *
     * The engine resolves templates with variables (e.g. %%post.title%% %%sep%% %%site.name%%)
     * through the four-level priority system.
     *
     * @param string $title Default title
     * @return string Modified title
     */
    public function override_title(string $title): string
    {
        $resolved = $this->renderer->getResolvedTitle();

        if ($resolved !== '') {
            return esc_html($resolved);
        }

        return $title;
    }

    /**
     * Overrides robots directives using the Meta Engine.
     *
     * The engine resolves robots through the four-level priority system:
     * Post Meta > CPT Template > Global Template > Fallback.
     *
     * @param array $robots Default robots directives
     * @return array Modified directives
     */
    public function override_robots(array $robots): array
    {
        try {
            $robots_string = $this->renderer->getResolvedRobots();

            if (empty($robots_string)) {
                return $robots;
            }

            return $this->parse_robots_string($robots_string, $robots);
        } catch (\Throwable $e) {
            // Fallback to WP defaults if template resolution fails
            return $robots;
        }
    }

    /**
     * Parses a robots string into an array
     *
     * @param string $robots_string Robots string (e.g., "index,follow")
     * @param array $default Default array
     * @return array Parsed robots array
     */
    private function parse_robots_string(string $robots_string, array $default): array
    {
        $directives = array_map('trim', explode(',', $robots_string));
        $robots     = $default;

        foreach ($directives as $directive) {
            if ($directive === '') {
                continue;
            }

            // Handle index/noindex and follow/nofollow toggles (mutually exclusive)
            if ($directive === 'index') {
                unset($robots['noindex']);
                $robots['index'] = true;
            } elseif ($directive === 'noindex') {
                unset($robots['index']);
                $robots['noindex'] = true;
            } elseif ($directive === 'follow') {
                unset($robots['nofollow']);
                $robots['follow'] = true;
            } elseif ($directive === 'nofollow') {
                unset($robots['follow']);
                $robots['nofollow'] = true;
            } elseif (str_contains($directive, ':')) {
                // Key:value directives (max-snippet:-1, max-image-preview:large, etc.)
                [$key, $value]          = explode(':', $directive, 2);
                $robots[ trim($key) ] = is_numeric($value) ? (int) $value : trim($value);
            } else {
                // Simple directives (noarchive, nosnippet, noimageindex, etc.)
                $robots[ $directive ] = true;
            }
        }

        return $robots;
    }

    /**
     * Applies head cleanup actions based on global settings.
     *
     * Runs on `init` so all remove_action calls fire before `wp_head`.
     *
     * @return void
     */
    public function apply_head_cleanup(): void
    {
        $settings = get_option('seopulse_meta_seo_global', []);

        // Generator meta tag
        if (!empty($settings['remove_generator'])) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
        }

        // Windows Live Writer manifest
        if (!empty($settings['remove_wlw_manifest'])) {
            remove_action('wp_head', 'wlwmanifest_link');
        }

        // Shortlink
        if (!empty($settings['remove_shortlink'])) {
            remove_action('wp_head', 'wp_shortlink_wp_head', 10);
            remove_action('template_redirect', 'wp_shortlink_header', 11);
        }

        // RSD / EditURI link
        if (!empty($settings['remove_rsd_link'])) {
            remove_action('wp_head', 'rsd_link');
        }

        // Emoji scripts and styles
        if (!empty($settings['remove_emoji'])) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_action('admin_print_styles', 'print_emoji_styles');
            add_filter('emoji_svg_url', '__return_false');
        }

        // RSS feed links
        if (!empty($settings['remove_feed_links'])) {
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
        }
    }

    /**
     * Invalidates Meta Engine cache when a post is saved.
     *
     * @param int $post_id Post ID
     * @return void
     */
    public function invalidate_engine_cache_post(int $post_id): void
    {
        try {
            $engine = new Engine\MetaEngine();
            $engine->invalidatePost($post_id);
        } catch (\Throwable $e) {
            // Silently ignore — cache invalidation is non-critical
        }
    }

    /**
     * Invalidates Meta Engine cache when relevant options are updated.
     *
     * @param string $option Option name
     * @return void
     */
    public function invalidate_engine_cache_option(string $option): void
    {
        $watchedOptions = [
            'seopulse_meta_seo_global',
            'seopulse_meta_templates',
            Options::LOCAL_SEO,
        ];

        if (!in_array($option, $watchedOptions, true)) {
            return;
        }

        try {
            $engine = new Engine\MetaEngine();
            $engine->flushCache();
        } catch (\Throwable $e) {
            // Silently ignore
        }
    }

    /**
     * Registers the focus keyword post meta for REST API / Block Editor.
     *
     * @return void
     */
    public function register_focus_keyword_meta(): void
    {
        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);

        foreach ($post_types as $post_type) {
            register_post_meta(
                $post_type,
                \SEOPulse\Core\Constants\PostMeta::FOCUS_KEYWORD,
                [
                    'single'            => true,
                    'type'              => 'string',
                    'default'           => '',
                    'show_in_rest'      => true,
                    'auth_callback'     => static function (bool $allowed, string $meta_key, int $post_id): bool {
                        return current_user_can('edit_post', $post_id);
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            );
        }
    }

    /**
     * Registers meta boxes (classic editor only)
     *
     * In the block editor, the Gutenberg sidebar (editor-sidebar) handles
     * all meta editing, so the metabox is intentionally skipped.
     *
     * @param string $post_type Current post type.
     * @param WP_Post $post Current post object.
     * @return void
     */
    public function register_meta_boxes(string $post_type, WP_Post $post): void
    {
        // Skip in block editor — the Gutenberg sidebar handles meta editing.
        if (function_exists('use_block_editor_for_post') && use_block_editor_for_post($post)) {
            return;
        }

        $post_types = apply_filters('seopulse_meta_seo_post_types', ['post', 'page']);

        if (!in_array($post_type, $post_types, true)) {
            return;
        }

        add_meta_box(
            'seopulse-meta-seo',
            __('SEOPulse Meta Tags', 'seopulse'),
            [$this, 'render_meta_box_seo_tags'],
            $post_type,
            'normal',
            'high',
        );
    }

    /**
     * Displays the SEO Tags meta box
     *
     * @param WP_Post $post Current post
     * @return void
     */
    public function render_meta_box_seo_tags(WP_Post $post): void
    {
        wp_nonce_field('seopulse_meta_seo_nonce', 'seopulse_meta_seo_nonce');

        $meta          = get_post_meta($post->ID, '_seopulse_meta_seo', true);
        $meta          = is_array($meta) ? $meta : [];
        $defaults      = MetaSeoDefaults::get_post_defaults($post, $meta);
        $focus_keyword = (string) get_post_meta($post->ID, \SEOPulse\Core\Constants\PostMeta::FOCUS_KEYWORD, true);

        // Include the meta box view
        include __DIR__ . '/metabox/metabox-seotags.php';
    }

    /**
     * Saves meta box data
     *
     * @param int $post_id Post ID
     * @return void
     */
    public function save_meta_boxes(int $post_id): void
    {
        if (
            !isset($_POST['seopulse_meta_seo_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['seopulse_meta_seo_nonce'])), 'seopulse_meta_seo_nonce')
        ) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['seopulse_meta_seo'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field-by-field in sanitize_meta_data().
            $meta = $this->sanitize_meta_data(wp_unslash($_POST['seopulse_meta_seo']));
            update_post_meta($post_id, '_seopulse_meta_seo', $meta);

            // Invalidate the analysis cache
            $cache = \SEOPulse\seopulse()->cache();
            $cache->delete_analysis($post_id);
        }

        // Save focus keyword (separate post meta)
        if (isset($_POST['seopulse_focus_keyword'])) {
            $focus_kw = sanitize_text_field(wp_unslash($_POST['seopulse_focus_keyword']));
            update_post_meta($post_id, \SEOPulse\Core\Constants\PostMeta::FOCUS_KEYWORD, $focus_kw);
        }
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
            'keywords',
            'robots',
            'og_title',
            'og_type',
            'og_site_name',
            'twitter_card',
            'twitter_title',
            'twitter_site',
            'twitter_creator',
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

    /**
     * {@inheritDoc}
     */
    public function getKey(): string
    {
        return 'meta_seo';
    }

    /**
     * {@inheritDoc}
     */
    public function onActivate(): void
    {
        // Default meta templates are set by the Installer
    }

    /**
     * {@inheritDoc}
     */
    public function onDeactivate(): void
    {
        // No cleanup needed
    }
}
