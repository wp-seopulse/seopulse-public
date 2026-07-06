<?php

/**
 * Complete settings page for the MetaSEO module
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Admin\AdminPageContent;
use SEOPulse\Core\Contracts\ExecuteHooksAdmin;
use SEOPulse\Core\Module\ModuleManager;
use SEOPulse\Modules\MetaSeo\Archives\ArchiveSettingsManager;
use SEOPulse\Modules\MetaSeo\MetaSeoDefaults;
use SEOPulse\Services\DashboardSummary;
use SEOPulse\Services\ImageAltFiller;

/**
 * MetaSeoSettings class
 */
class MetaSeoSettings implements ExecuteHooksAdmin
{
    /**
    * Page slug
    *
     * @var string
    */
    private string $page_slug = 'seopulse-meta-seo';
    /**
     * Registers admin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_settings_page'], 12);
    }
    /**
     * Option name
     *
     * @var string
     */
    private string $option_name = 'seopulse_meta_seo_global';

    /**
     * Registers the settings page
     *
     * @return void
     */
    public function register_settings_page(): void
    {
        add_submenu_page(
            'seopulse',
            __('Titles & Metas', 'seopulse'),
            AdminPageContent::menuLabel('meta_seo', __('Titles & Metas', 'seopulse')),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page'],
        );

        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Enqueues scripts and styles
     */
    public function enqueue_scripts($hook): void
    {
        if ($hook !== 'seopulse_page_seopulse-meta-seo') {
            return;
        }

        // When the module is disabled, skip JS/CSS/localization
        if (!ModuleManager::instance()->isModuleEnabled('meta_seo')) {
            return;
        }

        // ── React SPA bundle ────────────────────────────────────
        $asset_file = SEOPULSE_PLUGIN_DIR . 'assets/build/meta-seo-settings.asset.php';
        $asset      = file_exists($asset_file) ? require $asset_file : ['dependencies' => [], 'version' => SEOPULSE_VERSION];

        wp_enqueue_style(
            'seopulse-meta-seo-settings',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-settings.min.css',
            ['wp-components'],
            $asset['version'],
        );

        wp_enqueue_style(
            'seopulse-metaseo-css',
            SEOPULSE_PLUGIN_URL . 'assets/css/seopulse-metaseo.min.css',
            ['seopulse-meta-seo-settings'],
            $asset['version'],
        );

        // Legacy image diagnostic / alt-wizard CSS (still needed for styling)
        wp_enqueue_style(
            'seopulse-image-alt-wizard',
            SEOPULSE_PLUGIN_URL . 'assets/css/image-alt-wizard.css',
            ['seopulse-admin-global'],
            SEOPULSE_VERSION,
        );
        wp_enqueue_style(
            'seopulse-image-diagnostic',
            SEOPULSE_PLUGIN_URL . 'assets/css/image-diagnostic.css',
            ['seopulse-admin-global'],
            SEOPULSE_VERSION,
        );

        wp_enqueue_media();

        wp_enqueue_script(
            'seopulse-meta-seo-settings',
            SEOPULSE_PLUGIN_URL . 'assets/build/meta-seo-settings.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_set_script_translations('seopulse-meta-seo-settings', 'seopulse', SEOPULSE_PLUGIN_DIR . 'languages');

        // ── Single localization payload for React SPA ───────────
        $settings       = get_option($this->option_name, []);
        $templates      = get_option('seopulse_meta_templates', []);
        $archive_stg    = get_option('seopulse_archive_settings', []);
        $taxonomy_stg   = get_option('seopulse_taxonomy_settings', []);
        $dashboard      = (new DashboardSummary())->get();
        $tech_summary   = [
            'sitemap_configured'  => (bool) ($dashboard['sitemap_status']['configured'] ?? false),
            'archive_optimized'   => (bool) (
                ($dashboard['indexation_status']['date_noindex'] ?? false) &&
                ($dashboard['indexation_status']['search_noindex'] ?? false)
            ),
            'tracking_enabled'    => (bool) ($dashboard['404_tracking_status']['enabled'] ?? false),
            'tracking_count'      => (int) ($dashboard['404_tracking_status']['logged_count'] ?? 0),
            'redirections_active' => (bool) ($dashboard['modules_status']['redirections'] ?? false),
        ];
        $indexation_data = $this->get_indexation_center_data();

        $image_filler   = new ImageAltFiller();
        $image_settings = $image_filler->get_settings();

        wp_localize_script(
            'seopulse-meta-seo-settings',
            'seopulseMetaSeo',
            [
                'restUrl'         => rest_url('seopulse/v1/'),
                'nonce'           => wp_create_nonce('wp_rest'),
                'pluginUrl'       => SEOPULSE_PLUGIN_URL,
                'settings'        => $settings,
                'defaults'        => [],
                'templates'       => $templates,
                'templateDefaults' => [],
                'archiveSettings' => $archive_stg,
                'archiveDefaults' => ArchiveSettingsManager::getDefaults(),
                'taxSettings'     => $taxonomy_stg,
                'taxDefaults'     => [],
                'postTypes'       => $this->get_post_types_for_react(),
                'taxonomies'      => $this->get_taxonomies_for_react(),
                'techSummary'     => $tech_summary,
                'indexationData'  => $indexation_data,
                'imageSettings'   => $image_settings,
                'ogTypes'         => MetaSeoDefaults::get_og_type_options(),
                'twitterCards'    => MetaSeoDefaults::get_twitter_card_options(),
                'i18n'            => $this->get_react_i18n(),
            ],
        );
    }

    /**
     * Builds server-side data for the Indexation Center panel.
     *
     * @return array<string, mixed>
     */
    private function get_indexation_center_data(): array
    {
        $settings      = get_option($this->option_name, []);
        $global_robots = $settings['robots'] ?? 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1';

        $sitemap_active = \SEOPulse\Core\Kernel::isModuleEnabled('sitemap')
                          && !empty(get_option(\SEOPulse\Core\Constants\Options::SITEMAP, []));
        $sitemap_url    = admin_url('admin.php?page=seopulse-sitemap');

        $logs      = (array) get_option('seopulse_404_logs', []);
        $log_count = count($logs);

        return [
            'global_robots'  => $global_robots,
            'sitemap_active' => $sitemap_active,
            'sitemap_url'    => $sitemap_url,
            'log_404_count'  => $log_count,
            'i18n'           => [
                // Card titles
                'sitemap'                 => __('XML Sitemap', 'seopulse'),
                'authorArchives'          => __('Author Archives', 'seopulse'),
                'dateArchives'            => __('Date Archives', 'seopulse'),
                'searchPages'             => __('Search Pages', 'seopulse'),
                'error404'                => __('404 Monitoring', 'seopulse'),
                'globalRobots'            => __('Global Robots', 'seopulse'),
                // Status labels
                'active'                  => __('Active', 'seopulse'),
                'inactive'                => __('Inactive', 'seopulse'),
                'disabled'                => __('Disabled', 'seopulse'),
                'indexed'                 => __('Indexed', 'seopulse'),
                'smartNoindex'            => __('Smart Noindex', 'seopulse'),
                'trackingActive'          => __('Tracking active', 'seopulse'),
                'trackingInactive'        => __('Tracking inactive', 'seopulse'),
                'configure'               => __('Configure', 'seopulse'),
                // Descriptions
                'sitemapActiveDesc'       => __('Your XML sitemap is generated and accessible to search engines.', 'seopulse'),
                'sitemapInactiveDesc'     => __('The sitemap module is disabled. Search engines cannot discover your pages automatically.', 'seopulse'),
                'authorDisabledDesc'      => __('Author archives are disabled and redirected.', 'seopulse'),
                'authorNoindexDesc'       => __('Author archives exist but are hidden from search engines.', 'seopulse'),
                'authorSmartDesc'         => __('Indexed by default, with smart rules to hide low-value author pages.', 'seopulse'),
                'authorIndexedDesc'       => __('Author archives are visible to search engines.', 'seopulse'),
                'dateDisabledDesc'        => __('Date archives are disabled and redirected.', 'seopulse'),
                'dateNoindexDesc'         => __('Date archives are hidden from search engines. Recommended for most sites.', 'seopulse'),
                'dateIndexedDesc'         => __('Date archives are visible to search engines.', 'seopulse'),
                'searchNoindexDesc'       => __('Search result pages are hidden from search engines. Recommended.', 'seopulse'),
                'searchRobotsBlocked'     => __('Also blocked via robots.txt.', 'seopulse'),
                'searchIndexedDesc'       => __('Search result pages are visible to search engines. Consider using noindex to save crawl budget.', 'seopulse'),
                'tracking404InactiveDesc' => __('Enable 404 tracking to identify broken links and redirect opportunities.', 'seopulse'),
                'urlsLogged'              => __('broken URLs logged', 'seopulse'),
                'noUrlsLogged'            => __('No broken URLs detected yet.', 'seopulse'),
                'robotsNoindexDesc'       => __('Your entire site is set to noindex. Search engines will not index any page.', 'seopulse'),
                'robotsIndexDesc'         => __('Your global robots directive allows search engines to index your site.', 'seopulse'),
            ],
        ];
    }

    /**
     * Returns public post types for the template manager.
     *
     * @return array<string, string> slug => label
     */
    private function get_public_post_types(): array
    {
        $types  = get_post_types(['public' => true], 'objects');
        $result = [];

        foreach ($types as $slug => $type) {
            if ($slug === 'attachment') {
                continue;
            }
            $result[ $slug ] = $type->labels->singular_name ?? $type->label;
        }

        return $result;
    }

    /**
     * Returns public taxonomies for the taxonomy settings panel.
     *
     * @return array<string, array> slug => { label, plural_label, hierarchical, builtin }
     */
    private function get_public_taxonomies(): array
    {
        $taxonomies = get_taxonomies(
            [
                'public'  => true,
                'show_ui' => true,
            ],
            'objects',
        );
        $exclude    = ['post_format', 'nav_menu', 'link_category', 'wp_theme'];
        $result     = [];

        foreach ($taxonomies as $slug => $tax) {
            if (in_array($slug, $exclude, true)) {
                continue;
            }

            $result[ $slug ] = [
                'label'        => $tax->labels->singular_name ?? $tax->label,
                'plural_label' => $tax->labels->name ?? $tax->label,
                'hierarchical' => $tax->hierarchical,
                'builtin'      => $tax->_builtin,
                'icon'         => $slug === 'category'
                    ? 'dashicons-category'
                    : ($slug === 'post_tag' ? 'dashicons-tag' : 'dashicons-taxonomy'),
            ];
        }

        return $result;
    }

    /**
     * Registers WordPress settings
     *
     * @return void
     */
    public function register_settings(): void
    {
        register_setting(
            'seopulse_meta_seo_group',
            $this->option_name,
            [$this, 'sanitize_settings'],
        );
    }

    /**
     * Renders the settings page (React SPA root)
     *
     * @return void
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        AdminPageContent::begin('meta_seo', __('Titles & Metas', 'seopulse'));
        if (ModuleManager::instance()->isModuleEnabled('meta_seo')) {
            echo '<div id="seopulse-settings-root"></div>';
        }
        AdminPageContent::end();
    }

    /**
     * Returns post types formatted for the React SPA.
     *
     * @return array<int, array{slug: string, label: string, icon: string}>
     */
    private function get_post_types_for_react(): array
    {
        $types  = get_post_types(['public' => true], 'objects');
        $result = [];
        $icons  = [
            'post'    => 'dashicons-admin-post',
            'page'    => 'dashicons-admin-page',
            'product' => 'dashicons-cart',
        ];

        foreach ($types as $slug => $type) {
            if ($slug === 'attachment') {
                continue;
            }
            $result[] = [
                'slug'  => $slug,
                'label' => $type->labels->singular_name ?? $type->label,
                'icon'  => $icons[$slug] ?? 'dashicons-admin-post',
            ];
        }

        return $result;
    }

    /**
     * Returns taxonomies formatted for the React SPA.
     *
     * @return array<int, array{slug: string, label: string, plural_label: string, hierarchical: bool, icon: string}>
     */
    private function get_taxonomies_for_react(): array
    {
        $taxonomies = get_taxonomies(
            ['public' => true, 'show_ui' => true],
            'objects',
        );
        $exclude = ['post_format', 'nav_menu', 'link_category', 'wp_theme'];
        $result  = [];

        foreach ($taxonomies as $slug => $tax) {
            if (in_array($slug, $exclude, true)) {
                continue;
            }
            $result[] = [
                'slug'         => $slug,
                'label'        => $tax->labels->singular_name ?? $tax->label,
                'plural_label' => $tax->labels->name ?? $tax->label,
                'hierarchical' => $tax->hierarchical,
                'icon'         => $slug === 'category'
                    ? 'dashicons-category'
                    : ($slug === 'post_tag' ? 'dashicons-tag' : 'dashicons-taxonomy'),
            ];
        }

        return $result;
    }

    /**
     * Returns i18n strings for the React SPA.
     *
     * @return array<string, string>
     */
    private function get_react_i18n(): array
    {
        return [
            'saving'        => __('Saving…', 'seopulse'),
            'saved'         => __('Settings saved.', 'seopulse'),
            'saveError'     => __('Error saving settings.', 'seopulse'),
            'save'          => __('Save All Settings', 'seopulse'),
            'selectImage'   => __('Select Image', 'seopulse'),
            'useImage'      => __('Use this image', 'seopulse'),
            'configure'     => __('Configure', 'seopulse'),
            'active'        => __('Active', 'seopulse'),
            'inactive'      => __('Inactive', 'seopulse'),
            'disabled'      => __('Disabled', 'seopulse'),
            'indexed'       => __('Indexed', 'seopulse'),
        ];
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings(array $input): array
    {
        $sanitized = [];

        // Text fields
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
        ];

        foreach ($text_fields as $field) {
            if (isset($input[ $field ])) {
                $sanitized[ $field ] = sanitize_text_field($input[ $field ]);
            }
        }

        // Textarea fields
        $textarea_fields = ['description', 'og_description', 'twitter_description'];
        foreach ($textarea_fields as $field) {
            if (isset($input[ $field ])) {
                $sanitized[ $field ] = sanitize_textarea_field($input[ $field ]);
            }
        }

        // URL fields
        $url_fields = ['canonical', 'og_url', 'og_image', 'twitter_image'];
        foreach ($url_fields as $field) {
            if (isset($input[ $field ])) {
                $sanitized[ $field ] = esc_url_raw($input[ $field ]);
            }
        }

        // Checkboxes
        $sanitized['remove_generator']        = isset($input['remove_generator']) && $input['remove_generator'] === '1';
        $sanitized['remove_wlw_manifest']     = isset($input['remove_wlw_manifest']) && $input['remove_wlw_manifest'] === '1';
        $sanitized['remove_shortlink']        = isset($input['remove_shortlink']) && $input['remove_shortlink'] === '1';
        $sanitized['remove_rsd_link']         = isset($input['remove_rsd_link']) && $input['remove_rsd_link'] === '1';
        $sanitized['remove_emoji']            = isset($input['remove_emoji']) && $input['remove_emoji'] === '1';
        $sanitized['remove_feed_links']       = isset($input['remove_feed_links']) && $input['remove_feed_links'] === '1';
        $sanitized['breadcrumbs_enabled']     = isset($input['breadcrumbs_enabled']) && $input['breadcrumbs_enabled'] === '1';
        $sanitized['breadcrumbs_auto_insert'] = isset($input['breadcrumbs_auto_insert']) && $input['breadcrumbs_auto_insert'] === '1';

        // Schema toggle checkboxes (unchecked = not in $_POST = false)
        $sanitized['schema_article_enabled'] = isset($input['schema_article_enabled']) && $input['schema_article_enabled'] === '1';
        $sanitized['schema_faq_enabled']     = isset($input['schema_faq_enabled']) && $input['schema_faq_enabled'] === '1';
        $sanitized['schema_website_enabled'] = isset($input['schema_website_enabled']) && $input['schema_website_enabled'] === '1';
        $sanitized['schema_product_enabled'] = isset($input['schema_product_enabled']) && $input['schema_product_enabled'] === '1';
        $sanitized['schema_event_enabled']   = isset($input['schema_event_enabled']) && $input['schema_event_enabled'] === '1';

        // Breadcrumbs post types (array of slugs)
        if (isset($input['breadcrumbs_post_types']) && is_array($input['breadcrumbs_post_types'])) {
            $sanitized['breadcrumbs_post_types'] = array_map('sanitize_key', $input['breadcrumbs_post_types']);
        } else {
            $sanitized['breadcrumbs_post_types'] = [];
        }

        AdminNotification::success(__('Settings saved.', 'seopulse'));

        return $sanitized;
    }
}
