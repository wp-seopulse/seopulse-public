<?php

/**
 * Assets manager (JS, CSS)
 *
 * @package SEOPulse\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Contracts\ExecuteHooks;

/**
 * Assets class
 */
class Assets implements ExecuteHooks
{
    private string $version;

    public function __construct()
    {
        $this->version = SEOPULSE_VERSION;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->version = (string) time();
        }
    }

    /**
     * Registers hooks (admin + frontend for blocks)
     *
     * @return void
     */
    public function hooks(): void
    {
        // Blocks must be registered on both admin and frontend for render_callback to work.
        add_action('init', [$this, 'register_blocks']);

        if (is_admin()) {
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
            add_action('enqueue_block_editor_assets', [$this, 'enqueue_sidebar_assets']);
        }
    }

    /**
     * Enqueues assets for the admin
     *
     * @param string $hook Current admin page
     * @return void
     */
    public function enqueue_admin_assets(string $hook): void
    {
        // Only load React in the post/page editor
        if ($this->is_post_editor($hook)) {
            $this->enqueue_editor_assets();
        }

        // Only load global admin scripts/styles on SEOPulse pages or the post editor.
        // This avoids ~130 KB of JS + CSS being parsed on every unrelated admin screen.
        if ($this->is_seopulse_page($hook) || $this->is_post_editor($hook)) {
            $this->enqueue_admin_scripts($hook);
        }
    }

    /**
     * Returns true when the current admin page belongs to the SEOPulse plugin.
     *
     * WordPress generates hooks of the form:
     *   - "toplevel_page_seopulse"  (the main dashboard entry)
     *   - "seopulse_page_seopulse-{slug}"  (all sub-pages)
     *
     * @param string $hook Current admin page hook.
     * @return bool
     */
    private function is_seopulse_page(string $hook): bool
    {
        return str_contains($hook, 'seopulse');
    }

    /**
     * Checks if we are in the editor
     *
     * @param string $hook Current page
     * @return bool
     */
    private function is_post_editor(string $hook): bool
    {
        $editor_screens = ['post.php', 'post-new.php'];

        if (!in_array($hook, $editor_screens, true)) {
            return false;
        }

        // Exclude attachment edit screen
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'attachment') {
            return false;
        }

        return true;
    }

    /**
     * Enqueues assets for the editor sidebar (React)
     *
     * @return void
     */
    private function enqueue_editor_assets(): void
    {
        $script_asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/admin.asset.php';

        if (!file_exists($script_asset_path)) {
            return;
        }

        $script_asset = require $script_asset_path;

        wp_enqueue_script(
            'seopulse-admin',
            SEOPULSE_PLUGIN_URL . 'assets/build/admin.js',
            $script_asset['dependencies'],
            $script_asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-admin', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        // Retrieve the current post ID
        global $post;
        $current_post_id = 0;

        if ($post && isset($post->ID)) {
            $current_post_id = $post->ID;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; retrieving post ID for script localization.
        } elseif (isset($_GET['post'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $current_post_id = (int) $_GET['post'];
        }

        $localized_data = [
            'restUrl'  => rest_url('seopulse/v1'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'postId'   => $current_post_id,
            'i18n'     => [
                'analyzeButton'        => __('Analyze SEO', 'seopulse'),
                'analysisFailed'       => __('Analysis failed. Please try again.', 'seopulse'),
                'scoreLabel'           => __('SEO Score', 'seopulse'),
                'recommendationsTitle' => __('Recommendations', 'seopulse'),
                'selectImage'          => __('Choose Image', 'seopulse'),
                'useThisImage'         => __('Use this image', 'seopulse'),
            ],
            'settings' => $this->get_plugin_settings(),
        ];

        // Localize on the React handle (for admin.js build)
        wp_localize_script('seopulse-admin', 'seopulseAdmin', $localized_data);
    }

    /**
     * Enqueues the Gutenberg editor sidebar script.
     *
     * Fires on `enqueue_block_editor_assets` — only in the block editor.
     * Loads the separate editor-sidebar webpack entry that registers a
     * PluginSidebar with score, category breakdown and recommendations.
     *
     * @return void
     */
    public function enqueue_sidebar_assets(): void
    {
        $asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/editor-sidebar.asset.php';

        if (!file_exists($asset_path)) {
            return;
        }

        $asset = require $asset_path;

        wp_enqueue_script(
            'seopulse-editor-sidebar',
            SEOPULSE_PLUGIN_URL . 'assets/build/editor-sidebar.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-editor-sidebar', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        // Localize the same data used by the metabox admin bundle
        global $post;
        $current_post_id = 0;

        if ($post && isset($post->ID)) {
            $current_post_id = $post->ID;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; retrieving post ID for script localization.
        } elseif (isset($_GET['post'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $current_post_id = (int) $_GET['post'];
        }

        wp_localize_script(
            'seopulse-editor-sidebar',
            'seopulseAdmin',
            [
                'restUrl'  => rest_url('seopulse/v1'),
                'nonce'    => wp_create_nonce('wp_rest'),
                'postId'   => $current_post_id,
                'language' => \SEOPulse\Services\GoogleSuggestClient::detectLanguage(),
                'i18n'     => [
                    'analyzeButton'        => __('Analyze SEO', 'seopulse'),
                    'analysisFailed'       => __('Analysis failed. Please try again.', 'seopulse'),
                    'scoreLabel'           => __('SEO Score', 'seopulse'),
                    'recommendationsTitle' => __('Recommendations', 'seopulse'),
                ],
                'settings' => $this->get_plugin_settings(),
            ],
        );

        // Sidebar CSS
        $css_path = SEOPULSE_PLUGIN_DIR . 'assets/build/editor-sidebar.css';

        if (file_exists($css_path)) {
            wp_enqueue_style(
                'seopulse-editor-sidebar',
                SEOPULSE_PLUGIN_URL . 'assets/build/editor-sidebar.css',
                ['wp-components', 'seopulse-admin-global'],
                $asset['version'],
            );
        }
    }

    /**
     * Enqueues admin scripts and styles
     *
     * Loading order:
     *
     * On editor pages (post.php / post-new.php):
     *   1. `seopulse-admin` (React admin.js) — provides `seopulseAdmin` global
     *   2. `seopulse-admin-global` (jQuery seopulse-admin.js) — depends on #1
     *      so that `seopulseAdmin` is available.  Sets up
     *      `window.SEOPulse.notify` with a vanilla-JS fallback (the React
     *      bundle replaces it via `exposeNotificationStoreGlobally()`).
     *   3. `seopulse-editor-sidebar` (block editor only, via enqueue_block_editor_assets)
     *
     * On non-editor admin pages:
     *   1. `seopulse-admin-global` (jQuery only) — establishes the vanilla
     *      `window.SEOPulse.notify` fallback.
     *   2. Any React SPA bundle that mounts replaces it with Zustand.
     *
     * See: assets/src/types/global.d.ts for the TypeScript contract.
     *
     * @return void
     */
    private function enqueue_admin_scripts(string $hook): void
    {
        $is_editor = $this->is_post_editor($hook);

        // Global jQuery script dependencies
        $deps = ['jquery'];

        // In the editor, depend on the React script to ensure
        // that seopulseAdmin is defined before jQuery execution
        if ($is_editor) {
            $deps[] = 'seopulse-admin';
        }

        // Global JS
        wp_enqueue_script(
            'seopulse-admin-global',
            SEOPULSE_PLUGIN_URL . 'assets/js/seopulse-admin.js',
            $deps,
            $this->version,
            true,
        );

        // Localize module toggle data — REST API credentials for seopulse-admin.js
        wp_localize_script(
            'seopulse-admin-global',
            'seopulseModules',
            [
                'restNonce' => wp_create_nonce('wp_rest'),
                'restUrl'   => rest_url('seopulse/v1/'),
                'i18n'      => [
                    'active'           => __('Active', 'seopulse'),
                    'inactive'         => __('Inactive', 'seopulse'),
                    'error'            => __('An error occurred. Please try again.', 'seopulse'),
                    'dismiss'          => __('Dismiss', 'seopulse'),
                    'operationSuccess' => __('Operation successful', 'seopulse'),
                    'networkError'     => __('Network error occurred', 'seopulse'),
                ],
            ],
        );

        // Localize UI strings used by cockpit JS modules (dark mode, quick wins, etc.)
        wp_localize_script(
            'seopulse-admin-global',
            'seopulseI18n',
            [
                'collapse'        => __('Show less', 'seopulse'),
                'refreshedAt'     => __('Updated at', 'seopulse'),
                'analyzeAll'      => __('Analyze all pages', 'seopulse'),
                'noResults'       => __('No results found.', 'seopulse'),
            ],
        );

        // Global admin styles (includes design tokens)
        wp_enqueue_style(
            'seopulse-admin-global',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse.min.css',
            [],
            $this->version,
        );

        // React header admin styles
        wp_enqueue_style(
            'seopulse-admin-page-header',
            SEOPULSE_PLUGIN_URL . 'assets/css/header.min.css',
            [],
            $this->version,
        );

        // React admin styles
        $css_path = SEOPULSE_PLUGIN_DIR . 'assets/build/admin.css';

        if (file_exists($css_path) && $is_editor) {
            wp_enqueue_style(
                'seopulse-admin',
                SEOPULSE_PLUGIN_URL . 'assets/build/admin.css',
                ['wp-components', 'seopulse-admin-global'],
                $this->version,
            );
        }

        // Dashboard SPA — only on the main dashboard page
        if ($hook === 'toplevel_page_seopulse') {
            $this->enqueue_dashboard_assets();
        }

        // Admin Page Header SPA — on all pages
        if ($this->is_seopulse_page($hook)) {
            $this->enqueue_adminpageheader_assets();
        }
    }

    private function enqueue_adminpageheader_assets(): void
    {
        $asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/admin-page-header.asset.php';

        if (!file_exists($asset_path)) {
            return;
        }

        $asset = require $asset_path;

        wp_enqueue_script(
            'seopulse-admin-page-header',
            SEOPULSE_PLUGIN_URL . 'assets/build/admin-page-header.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-admin-page-header', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        wp_localize_script(
            'seopulse-admin-page-header',
            'seopulseAdminHeader',
            [
                'restUrl'  => rest_url('seopulse/v1'),
                'nonce'    => wp_create_nonce('wp_rest'),
                'i18n'     => [
                    'openCommandPalette'  => __('Open command palette (Ctrl+K)', 'seopulse'),
                    'openVisibilityPanel' => __('Toggle dashboard sections', 'seopulse'),
                    'openNotificationPanel' => __('Notifications', 'seopulse'),
                    'notifications'        => __('Notifications', 'seopulse'),
                    'notification'         => __('Notification', 'seopulse'),
                ],
            ],
        );
    }

    /**
     * Enqueues the React dashboard SPA bundle and its localised data.
     *
     * Called only when $hook === 'toplevel_page_seopulse'.
     *
     * @return void
     */
    private function enqueue_dashboard_assets(): void
    {
        $asset_path = SEOPULSE_PLUGIN_DIR . 'assets/build/dashboard.asset.php';

        if (!file_exists($asset_path)) {
            return;
        }

        $asset = require $asset_path;

        wp_enqueue_script(
            'seopulse-dashboard',
            SEOPULSE_PLUGIN_URL . 'assets/build/dashboard.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-dashboard', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        wp_localize_script(
            'seopulse-dashboard',
            'seopulseDashboard',
            [
                'restUrl'   => rest_url('seopulse/v1/'),
                'nonce'     => wp_create_nonce('wp_rest'),
                'version'   => SEOPULSE_VERSION,
                'assetsUrl' => SEOPULSE_ASSETS_URL,
                'adminUrl'  => admin_url(),
                'commands'  => [
                    ['label' => __('Dashboard', 'seopulse'),         'icon' => 'admin-home',    'url' => admin_url('admin.php?page=seopulse')],
                    ['label' => __('Setup Wizard', 'seopulse'),      'icon' => 'admin-generic', 'url' => admin_url('admin.php?page=seopulse-setup-wizard')],
                    ['label' => __('Meta SEO', 'seopulse'),          'icon' => 'tag',           'url' => admin_url('admin.php?page=seopulse-meta-seo')],
                    ['label' => __('Sitemap', 'seopulse'),           'icon' => 'networking',    'url' => admin_url('admin.php?page=seopulse-sitemap')],
                    ['label' => __('Redirections', 'seopulse'),      'icon' => 'randomize',     'url' => admin_url('admin.php?page=seopulse-redirections')],
                    ['label' => __('404 Monitor', 'seopulse'),       'icon' => 'warning',       'url' => admin_url('admin.php?page=seopulse-404-monitor')],
                    ['label' => __('Indexation', 'seopulse'),        'icon' => 'upload',        'url' => admin_url('admin.php?page=seopulse-indexing')],
                    ['label' => __('Analytics', 'seopulse'),         'icon' => 'chart-line',    'url' => admin_url('admin.php?page=seopulse-analytics')],
                    ['label' => __('Image Diagnostic', 'seopulse'),  'icon' => 'format-image',  'url' => admin_url('admin.php?page=seopulse-meta-seo#tab=image-diagnostic')],
                    ['label' => __('Logs', 'seopulse'),              'icon' => 'list-view',     'url' => admin_url('admin.php?page=seopulse-logs')],
                    ['label' => __('Refresh KPI data', 'seopulse'),  'icon' => 'update',        'type' => 'action', 'action' => 'refresh-kpis'],
                ],
            ],
        );
    }

    /**
     * Retrieves plugin settings
     *
     * @return array<string, mixed>
     */
    private function get_plugin_settings(): array
    {
        $defaults = [
            'ai_enabled'     => false,
            'ai_provider'    => 'openai',
            'target_score'   => 80,
            'cache_duration' => 3600,
        ];

        $settings = get_option('seopulse_settings', []);

        return wp_parse_args($settings, $defaults);
    }

    /**
     * Registers Gutenberg blocks.
     *
     * Uses block.json metadata for automatic asset discovery.
     *
     * @return void
     */
    public function register_blocks(): void
    {
        $faq_block_json = SEOPULSE_PLUGIN_DIR . 'assets/src/blocks/faq/block.json';

        if (file_exists($faq_block_json)) {
            register_block_type($faq_block_json);
        }

        $howto_block_json = SEOPULSE_PLUGIN_DIR . 'assets/src/blocks/howto/block.json';

        if (file_exists($howto_block_json)) {
            register_block_type($howto_block_json);
        }

        $toc_block_json = SEOPULSE_PLUGIN_DIR . 'assets/src/blocks/toc/block.json';

        if (file_exists($toc_block_json)) {
            register_block_type(
                $toc_block_json,
                [
                    'render_callback' => [$this, 'render_toc_block'],
                ],
            );
        }

        // Inject id attributes into headings when a ToC block is present.
        add_filter('the_content', [$this, 'inject_toc_heading_ids'], 10);

        // Advanced blocks
        $schema_block_json = SEOPULSE_PLUGIN_DIR . 'assets/src/blocks/schema/block.json';

        if (file_exists($schema_block_json)) {
            register_block_type(
                $schema_block_json,
                [
                    'render_callback' => [$this, 'render_schema_block'],
                ],
            );
        }

        $review_block_json = SEOPULSE_PLUGIN_DIR . 'assets/src/blocks/review/block.json';

        if (file_exists($review_block_json)) {
            register_block_type($review_block_json);
        }

        $product_grid_json = SEOPULSE_PLUGIN_DIR . 'assets/src/blocks/product-grid/block.json';

        if (file_exists($product_grid_json)) {
            register_block_type(
                $product_grid_json,
                [
                    'render_callback' => [$this, 'render_product_grid_block'],
                ],
            );
        }
    }

    /**
     * Server-side render callback for the seopulse/toc block.
     *
     * @param array $attributes Block attributes.
     * @param string $content Inner block content.
     * @param WP_Block $block Block instance.
     *
     * @return string Rendered HTML.
     */
    public function render_toc_block(array $attributes, string $content, \WP_Block $block): string
    {
        $include_h1   = !empty($attributes['includeH1']);
        $min_headings = isset($attributes['minHeadings']) ? (int) $attributes['minHeadings'] : 3;
        $collapsible  = !empty($attributes['collapsible']);
        $show_numbers = $attributes['showNumbers'] ?? true;

        $post = get_post();
        if (!$post instanceof \WP_Post || empty($post->post_content)) {
            return '';
        }

        $blocks   = parse_blocks($post->post_content);
        $headings = $this->toc_extract_headings($blocks, $include_h1);

        if (count($headings) < $min_headings) {
            return '';
        }

        $slugs = $this->toc_generate_slugs($headings);

        // Store slug map for the heading-ID injection filter.
        global $seopulse_toc_heading_slugs;
        $seopulse_toc_heading_slugs = $slugs;

        $tag       = $show_numbers ? 'ol' : 'ul';
        $min_level = min(array_column($headings, 'level'));

        $list_html = '<' . $tag . ' class="wp-block-seopulse-toc__list">';
        foreach ($headings as $i => $heading) {
            $indent     = ($heading['level'] - $min_level) * 16;
            $anchor     = esc_attr($slugs[ $i ]['slug']);
            $text       = esc_html($heading['text']);
            $list_html .= '<li class="wp-block-seopulse-toc__item" style="margin-left:' . $indent . 'px">';
            $list_html .= '<a class="wp-block-seopulse-toc__link" href="#' . $anchor . '">' . $text . '</a>';
            $list_html .= '</li>';
        }
        $list_html .= '</' . $tag . '>';

        $title_text = esc_html__('Table of Contents', 'seopulse');

        $inner_html = '';
        if ($collapsible) {
            $inner_html .= '<button class="wp-block-seopulse-toc__toggle" aria-expanded="true">' . $title_text . '</button>';
        } else {
            $inner_html .= '<p class="wp-block-seopulse-toc__heading">' . $title_text . '</p>';
        }
        $inner_html .= '<nav class="wp-block-seopulse-toc__nav" aria-label="' . $title_text . '">';
        $inner_html .= $list_html;
        $inner_html .= '</nav>';

        $wrapper_attrs = get_block_wrapper_attributes(
            [
                'class'            => 'wp-block-seopulse-toc',
                'data-collapsible' => $collapsible ? 'true' : 'false',
            ],
        );

        return '<div ' . $wrapper_attrs . '>' . $inner_html . '</div>';
    }

    /**
     * Recursively extract headings from parsed blocks.
     *
     * @param array $blocks Parsed block array.
     * @param bool $include_h1 Whether to include H1 headings.
     *
     * @return array<int, array{level: int, text: string, existing_id: string}>
     */
    private function toc_extract_headings(array $blocks, bool $include_h1): array
    {
        $headings = [];

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'core/heading') {
                $attrs = $block['attrs'] ?? [];
                $level = (int) ($attrs['level'] ?? 2);

                if (!$include_h1 && $level === 1) {
                    continue;
                }

                $raw  = $attrs['content'] ?? '';
                $text = trim(wp_strip_all_tags((string) $raw));

                if ($text === '') {
                    $text = trim(wp_strip_all_tags($block['innerHTML'] ?? ''));
                }

                if ($text === '') {
                    continue;
                }

                $headings[] = [
                    'level'       => $level,
                    'text'        => $text,
                    'existing_id' => !empty($attrs['anchor']) ? (string) $attrs['anchor'] : '',
                ];
            }

            if (!empty($block['innerBlocks'])) {
                $headings = array_merge($headings, $this->toc_extract_headings($block['innerBlocks'], $include_h1));
            }
        }

        return $headings;
    }

    /**
     * Slugify a string for use as a heading anchor.
     *
     * @param string $text Text to slugify.
     *
     * @return string
     */
    private function toc_slugify(string $text): string
    {
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        } else {
            $text = mb_strtolower($text, 'UTF-8');
        }

        $text = strtolower(trim($text));
        $text = (string) preg_replace('/[^\w\s-]/u', '', $text);
        $text = (string) preg_replace('/[\s_]+/', '-', $text);
        $text = (string) preg_replace('/-+/', '-', $text);

        return trim($text, '-');
    }

    /**
     * Generate deduplicated slugs for headings.
     *
     * @param array $headings Array of heading data.
     *
     * @return array<int, array{slug: string, text: string}>
     */
    private function toc_generate_slugs(array $headings): array
    {
        $seen  = [];
        $slugs = [];

        foreach ($headings as $heading) {
            $slug = $heading['existing_id'] !== ''
                ? $heading['existing_id']
                : $this->toc_slugify($heading['text']);

            $count         = $seen[ $slug ] ?? 0;
            $seen[ $slug ] = $count + 1;
            $final_slug    = $count === 0 ? $slug : $slug . '-' . ($count + 1);

            $slugs[] = [
                'slug' => $final_slug,
                'text' => $heading['text'],
            ];
        }

        return $slugs;
    }

    /**
     * Inject id attributes into heading tags for ToC anchor links.
     *
     * Only runs when the post contains a seopulse/toc block.
     * Uses the slug map generated by render_toc_block to ensure consistency.
     *
     * @param string $content Post HTML content.
     *
     * @return string
     */
    public function inject_toc_heading_ids(string $content): string
    {
        global $seopulse_toc_heading_slugs;

        if (empty($seopulse_toc_heading_slugs) || !is_array($seopulse_toc_heading_slugs)) {
            return $content;
        }

        $slug_index = 0;
        $slugs      = $seopulse_toc_heading_slugs;

        // Match all heading tags (h1-h6) and inject id if missing.
        $content = preg_replace_callback(
            '/<(h[1-6])([^>]*)>(.*?)<\/\1>/is',
            function ($matches) use (&$slug_index, $slugs) {
                $tag        = $matches[1];
                $attrs      = $matches[2];
                $inner_html = $matches[3];
                $text       = trim(wp_strip_all_tags($inner_html));

                if ($text === '' || $slug_index >= count($slugs)) {
                    return $matches[0];
                }

                // Only inject if this heading text matches the expected one.
                if ($text !== $slugs[ $slug_index ]['text']) {
                    return $matches[0];
                }

                $slug = esc_attr($slugs[ $slug_index ]['slug']);
                $slug_index++;

                // Skip if already has an id.
                if (preg_match('/\bid\s*=/i', $attrs)) {
                    return $matches[0];
                }

                return '<' . $tag . ' id="' . $slug . '"' . $attrs . '>' . $inner_html . '</' . $tag . '>';
            },
            $content,
        );

        // Clean up the global after use.
        $seopulse_toc_heading_slugs = null;

        return $content;
    }

    /**
     * Server-side render callback for the seopulse/schema block.
     *
     * Validates the JSON and outputs a <script type="application/ld+json"> tag.
     * Invalid JSON is silently suppressed on the frontend.
     *
     * @param array $attributes Block attributes.
     * @param string $content Inner block content.
     *
     * @return string Rendered HTML (empty string or script tag).
     */
    public function render_schema_block(array $attributes, string $content): string
    {
        $json_raw  = $attributes['schemaJson'] ?? '{}';
        $validated = !empty($attributes['isValidated']);

        if (!$validated) {
            return '';
        }

        $decoded = json_decode($json_raw, true);

        // Only allow objects, reject arrays/primitives.
        if (!is_array($decoded) || empty($decoded) || isset($decoded[0])) {
            return '';
        }

        $safe_json = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        if ($safe_json === false) {
            return '';
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with JSON_HEX_TAG|JSON_HEX_AMP prevents script injection.
        return '<script type="application/ld+json">' . $safe_json . '</script>';
    }

    /**
     * Server-side render callback for the seopulse/product-grid block.
     *
     * Fetches WooCommerce products and outputs a responsive CSS grid.
     *
     * @param array $attributes Block attributes.
     * @param string $content Inner block content.
     *
     * @return string Rendered HTML.
     */
    public function render_product_grid_block(array $attributes, string $content): string
    {
        if (!function_exists('wc_get_product')) {
            return '<p>' . esc_html__('WooCommerce is required for the Product Grid block.', 'seopulse') . '</p>';
        }

        $ids         = array_map('absint', $attributes['productIds'] ?? []);
        $columns     = min(max((int) ($attributes['columns'] ?? 3), 2), 4);
        $show_price  = $attributes['showPrice'] ?? true;
        $show_rating = $attributes['showRating'] ?? true;
        $show_button = $attributes['showButton'] ?? true;

        if (empty($ids)) {
            return '';
        }

        // Enforce max 12 products.
        $ids = array_slice($ids, 0, 12);

        $wrapper_attrs = get_block_wrapper_attributes(
            [
                'class' => 'wp-block-seopulse-product-grid',
            ],
        );

        $html  = '<div ' . $wrapper_attrs . '>';
        $html .= '<div class="wp-block-seopulse-product-grid__grid" style="display:grid;grid-template-columns:repeat(' . $columns . ',1fr);gap:16px">';

        foreach ($ids as $id) {
            $product = wc_get_product($id);

            if (!$product || $product->get_status() !== 'publish') {
                continue;
            }

            $image = wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail');
            $title = esc_html($product->get_name());
            $link  = esc_url(get_permalink($id));

            $html .= '<div class="wp-block-seopulse-product-grid__card">';

            if ($image) {
                $html .= '<img class="wp-block-seopulse-product-grid__card-img" src="' . esc_url($image) . '" alt="' . $title . '" loading="lazy" />';
            }

            $html .= '<h4 class="wp-block-seopulse-product-grid__card-title">' . $title . '</h4>';

            if ($show_price) {
                $html .= '<div class="wp-block-seopulse-product-grid__card-price">' . $product->get_price_html() . '</div>';
            }

            if ($show_rating && $product->get_average_rating() > 0) {
                $rating = (float) $product->get_average_rating();
                $html  .= '<div class="wp-block-seopulse-product-grid__card-rating">';
                for ($i = 1; $i <= 5; $i++) {
                    $filled = $i <= round($rating) ? ' wp-block-seopulse-product-grid__star--filled' : '';
                    $html  .= '<span class="wp-block-seopulse-product-grid__star' . $filled . '">★</span>';
                }
                $html .= '</div>';
            }

            if ($show_button) {
                $html .= '<a class="wp-block-seopulse-product-grid__card-btn" href="' . $link . '">';
                $html .= esc_html__('View Product', 'seopulse');
                $html .= '</a>';
            }

            $html .= '</div>';
        }

        $html .= '</div></div>';

        return $html;
    }
}
