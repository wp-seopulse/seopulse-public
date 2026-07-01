<?php

/**
 * Sitemap Module for SEOPulse
 *
 * Handles XML sitemaps, robots.txt generation and technical SEO
 *
 * @package SEOPulse\Modules\Sitemap
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Sitemap;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\Module;
use SEOPulse\Core\Attributes\AsModule;
use SEOPulse\Core\Contracts\ModuleInterface;
use WP_Post;

/**
 * SitemapModule class
 *
 * Main entry point of the Sitemap module
 */
#[AsModule(
    key: 'sitemap',
    label: 'Sitemap',
    description: 'XML Sitemap generation, robots.txt and Google News.',
    icon: 'dashicons-networking',
    namespace: 'SEOPulse\\Modules\\Sitemap\\',
)]
class SitemapModule extends Module implements ModuleInterface
{
    /**
     * Sitemap generator
     *
     * @var SitemapGenerator
     */
    private SitemapGenerator $generator;

    /**
     * Settings handler
     *
     * @var SitemapSettings
     */
    private SitemapSettings $settings;

    /**
     * Google News sitemap handler
     *
     * @var SitemapNews
     */
    private SitemapNews $news;

    /**
     * Bulk actions handler
     *
     * @var SitemapBulkActions
     */
    private SitemapBulkActions $bulk_actions;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->name   = 'sitemap';
        $this->weight = 0.10; // 10% of total score

        // Load dependencies
        require_once __DIR__ . '/SitemapGenerator.php';
        require_once __DIR__ . '/SitemapSettings.php';
        require_once __DIR__ . '/SitemapNews.php';
        require_once __DIR__ . '/SitemapBulkActions.php';

        $this->generator    = new SitemapGenerator();
        $this->settings     = new SitemapSettings();
        $this->news         = new SitemapNews();
        $this->bulk_actions = new SitemapBulkActions();
    }

    /**
     * Registers WordPress hooks for the module
     *
     * @return void
     */
    public function hooks(): void
    {
        // Frontend: Sitemap generation
        add_action('init', [$this->generator, 'add_rewrite_rules']);
        add_action('template_redirect', [$this->generator, 'handle_sitemap_request']);
        add_action('template_redirect', [$this->generator, 'handle_robots_request']);
        add_filter('robots_txt', [$this->generator, 'add_robots_entries'], 10, 2);

        // Cache management
        add_action('save_post', [$this->generator, 'clear_cache']);
        add_action('deleted_post', [$this->generator, 'clear_cache']);
        add_action('update_option_seopulse_sitemap_settings', [$this, 'handle_settings_update'], 10, 3);

        // Regenerate physical robots.txt when archive settings change
        add_action('update_option_seopulse_archive_settings', [$this, 'maybeRefreshPhysicalRobots'], 10, 0);

        // Disable WordPress core sitemaps if necessary
        add_action(
            'after_setup_theme',
            function () {
                $options = get_option('seopulse_sitemap_settings', []);
                if (!empty($options['disable_wp_core_sitemaps'])) {
                    add_filter('wp_sitemaps_enabled', '__return_false');
                }
            },
        );

        // Sitemap Google News
        $this->news->init();

        // Admin only
        if (is_admin()) {
            // Settings
            add_action('admin_init', [$this->settings, 'register_settings']);

            // Meta boxes
            add_action('add_meta_boxes', [$this->settings, 'register_meta_boxes']);
            add_action('save_post', [$this->settings, 'save_post_meta'], 10, 2);
            add_action('admin_enqueue_scripts', [$this->settings, 'enqueue_metabox_styles']);

            // Bulk actions
            $this->bulk_actions->init();

            // Admin actions
            add_action('admin_post_seopulse_clear_sitemap_cache', [$this, 'manual_clear_cache']);
            add_action('admin_post_seopulse_regenerate_sitemap', [$this, 'regenerate_sitemap']);
            add_action('admin_post_seopulse_ping_search_engines', [$this, 'ping_search_engines']);
        }

    }

    /**
     * Analyzes a post (required by the Module interface)
     *
     * @param WP_Post $post Post to analyze
     * @return array Analysis result
     */
    public function analyze(WP_Post $post): array
    {
        $score           = 100;
        $issues          = [];
        $recommendations = [];

        // Check if sitemap is enabled
        $options = get_option('seopulse_sitemap_settings', []);

        if (empty($options)) {
            $score             = 50;
            $issues[]          = [
                'type'     => 'sitemap_not_configured',
                'severity' => 'high',
                'message'  => __('Sitemap is not configured.', 'seopulse'),
            ];
            $recommendations[] = [
                'type'     => 'configure_sitemap',
                'priority' => 'high',
                'message'  => __('Configure sitemap settings for better SEO.', 'seopulse'),
                'action'   => __('Go to SEOPulse > Sitemap settings to configure your sitemap.', 'seopulse'),
            ];
        }

        // Check if the post is excluded from the sitemap
        $excluded = get_post_meta($post->ID, '_seopulse_exclude_sitemap', true);

        if ($excluded) {
            $score             = 70;
            $issues[]          = [
                'type'     => 'sitemap_excluded',
                'severity' => 'low',
                'message'  => __('This post is excluded from sitemap.', 'seopulse'),
            ];
            $recommendations[] = [
                'type'     => 'include_in_sitemap',
                'priority' => 'low',
                'message'  => __('Consider including this post in the sitemap if you want it indexed.', 'seopulse'),
                'action'   => __('Uncheck "Exclude from sitemap" option in the Sitemap Settings meta box.', 'seopulse'),
            ];
        }

        // Check if the post type is enabled in the sitemap
        $post_type    = $post->post_type;
        $type_enabled = isset($options[ "enable_{$post_type}" ]) ? (bool) $options[ "enable_{$post_type}" ] : true;

        if (!$type_enabled && !$excluded) {
            $score             = 60;
            $issues[]          = [
                'type'     => 'post_type_disabled',
                'severity' => 'medium',
                'message'  => sprintf(
                    /* translators: %s: post type name */
                    __('Post type "%s" is not enabled in sitemap.', 'seopulse'),
                    $post_type,
                ),
            ];
            $recommendations[] = [
                'type'     => 'enable_post_type',
                'priority' => 'medium',
                'message'  => sprintf(
                    /* translators: %s: post type name */
                    __('Enable "%s" post type in sitemap settings.', 'seopulse'),
                    $post_type,
                ),
                'action'   => __('Go to SEOPulse > Sitemap settings to enable this post type.', 'seopulse'),
            ];
        }

        // Check if images are present (for the image sitemap)
        $has_images = has_post_thumbnail($post->ID);
        if (!$has_images && !empty($post->post_content)) {
            preg_match_all('/<img[^>]+>/i', $post->post_content, $matches);
            $has_images = !empty($matches[0]);
        }

        if (!$has_images && !empty($options['include_images'])) {
            $recommendations[] = [
                'type'     => 'add_images',
                'priority' => 'low',
                'message'  => __('Consider adding images to improve sitemap richness.', 'seopulse'),
                'action'   => __('Add a featured image or include images in your content.', 'seopulse'),
            ];
        }

        return [
            'score'           => $score,
            'issues'          => $issues,
            'recommendations' => $recommendations,
            'data'            => [
                'excluded'          => (bool) $excluded,
                'post_type_enabled' => $type_enabled,
                'has_images'        => $has_images,
                'priority'          => get_post_meta($post->ID, '_seopulse_sitemap_priority', true) ?: 'default',
                'changefreq'        => get_post_meta($post->ID, '_seopulse_sitemap_changefreq', true) ?: 'default',
            ],
        ];
    }

    /**
     * Handles settings update
     *
     * @param mixed $old_value Old value
     * @param mixed $value New value
     * @param string $option Option name
     * @return void
     */
    public function handle_settings_update($old_value, $value, string $option): void
    {
        // Clear cache
        $this->generator->clear_cache();

        // Check if disable_wp_core_sitemaps changed
        $old_disable = isset($old_value['disable_wp_core_sitemaps']) ? $old_value['disable_wp_core_sitemaps'] : 0;
        $new_disable = isset($value['disable_wp_core_sitemaps']) ? $value['disable_wp_core_sitemaps'] : 0;

        if ($old_disable !== $new_disable) {
            $this->generator->add_rewrite_rules();
            flush_rewrite_rules();
        }

        // Handle physical robots.txt file
        $this->handle_physical_robots_update($old_value, $value);
    }

    /**
     * Handles physical robots.txt file
     *
     * @param array $old_value Old options
     * @param array $value New options
     * @return void
     */
    private function handle_physical_robots_update(array $old_value, array $value): void
    {
        $old_physical = isset($old_value['create_physical_robots']) ? $old_value['create_physical_robots'] : 0;
        $new_physical = isset($value['create_physical_robots']) ? $value['create_physical_robots'] : 0;

        if ($old_physical === $new_physical && !$new_physical) {
            return;
        }

        $file = ABSPATH . 'robots.txt';

        if ($new_physical) {
            $this->write_physical_robots($file);
        } elseif (file_exists($file)) {
            wp_delete_file($file);
        }
    }

    /**
     * Writes the physical robots.txt file via WP_Filesystem
     *
     * @param string $file Absolute file path
     * @return void
     */
    private function write_physical_robots(string $file): void
    {
        $content = $this->generator->generate_robots_txt();

        // Load WP_Filesystem if not available (REST API context)
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;

        if (WP_Filesystem()) {
            $wp_filesystem->put_contents($file, $content, FS_CHMOD_FILE);
        }
    }

    /**
     * Regenerate the physical robots.txt if it currently exists.
     *
     * Called when archive settings change so that block_robots_txt
     * rules are reflected in the physical file.
     *
     * @return void
     */
    public function maybeRefreshPhysicalRobots(): void
    {
        $file = ABSPATH . 'robots.txt';

        if (file_exists($file)) {
            $this->write_physical_robots($file);
        }
    }

    /**
     * Manual cache clearing (admin action)
     *
     * @return void
     */
    public function manual_clear_cache(): void
    {
        check_admin_referer('seopulse_clear_cache');

        $this->generator->clear_cache();

        \SEOPulse\Admin\Notifications\AdminNotification::success(
            __('Sitemap cache cleared successfully!', 'seopulse'),
        );

        wp_safe_redirect(admin_url('admin.php?page=seopulse-sitemap'));
        exit;
    }

    /**
     * Sitemap regeneration (admin action)
     *
     * @return void
     */
    public function regenerate_sitemap(): void
    {
        check_admin_referer('seopulse_regenerate_sitemap');

        $this->generator->clear_cache();

        // Force regeneration
        $this->generator->generate_sitemap_index();

        \SEOPulse\Admin\Notifications\AdminNotification::success(
            __('Sitemap regenerated successfully!', 'seopulse'),
        );

        wp_safe_redirect(admin_url('admin.php?page=seopulse-sitemap'));
        exit;
    }

    /**
     * Search engine notification (admin action)
     *
     * @return void
     */
    public function ping_search_engines(): void
    {
        check_admin_referer('seopulse_ping_engines');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'seopulse'));
        }

        $sitemap_url = home_url('/sitemap.xml');
        $options     = get_option('seopulse_sitemap_settings', []);

        if (empty($options['disable_wp_core_sitemaps'])) {
            $sitemap_url = home_url('/wp-sitemap.xml');
        }

        $encoded_url = rawurlencode($sitemap_url);
        $errors      = [];

        // Ping Google (via Search Console API endpoint)
        $google_response = wp_remote_get(
            'https://www.google.com/ping?sitemap=' . $encoded_url,
            [
                'timeout'   => 10,
                'sslverify' => true,
            ],
        );

        if (is_wp_error($google_response)) {
            $errors[] = 'Google: ' . $google_response->get_error_message();
        } elseif (wp_remote_retrieve_response_code($google_response) >= 400) {
            $errors[] = 'Google: HTTP ' . wp_remote_retrieve_response_code($google_response);
        }

        // Ping Bing
        $bing_response = wp_remote_get(
            'https://www.bing.com/ping?sitemap=' . $encoded_url,
            [
                'timeout'   => 10,
                'sslverify' => true,
            ],
        );

        if (is_wp_error($bing_response)) {
            $errors[] = 'Bing: ' . $bing_response->get_error_message();
        } elseif (wp_remote_retrieve_response_code($bing_response) >= 400) {
            $errors[] = 'Bing: HTTP ' . wp_remote_retrieve_response_code($bing_response);
        }

        if (!empty($errors)) {
            \SEOPulse\Admin\Notifications\AdminNotification::warning(
                /* translators: %s: comma-separated list of error messages */
                sprintf(__('Some engines could not be notified: %s', 'seopulse'), implode(', ', $errors)),
            );
        } else {
            \SEOPulse\Admin\Notifications\AdminNotification::success(
                __('Search engines notified successfully!', 'seopulse'),
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=seopulse-sitemap'));
        exit;
    }

    /**
     * Retrieves the sitemap generator
     *
     * @return SitemapGenerator
     */
    public function get_generator(): SitemapGenerator
    {
        return $this->generator;
    }

    /**
     * Retrieves the settings handler
     *
     * @return SitemapSettings
     */
    public function get_settings(): SitemapSettings
    {
        return $this->settings;
    }

    /**
     * {@inheritDoc}
     */
    public function getKey(): string
    {
        return 'sitemap';
    }

    /**
     * {@inheritDoc}
     */
    public function onActivate(): void
    {
        // Flush rewrite rules for sitemap URLs
        flush_rewrite_rules();
    }

    /**
     * {@inheritDoc}
     */
    public function onDeactivate(): void
    {
        flush_rewrite_rules();
    }
}
