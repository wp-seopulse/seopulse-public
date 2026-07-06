<?php

/**
 * SEOPulse notification panel
 *
 * Collects and provides system notifications for the slide-in panel.
 * Each notification has a type, a message,
 * a severity level and optionally an action (link).
 *
 * Severities are aligned with the dashboard contract:
 * - blocker: prevents correct SEO functioning
 * - quick_win: easy action with visible impact
 * - info: informational, no urgency
 * - success: positive confirmation
 *
 * Extensible via the `seopulse_panel_notifications` filter.
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\Notifications;

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Kernel;
use SEOPulse\Core\Module\ModuleManager;
use SEOPulse\Modules\MetaSeo\Archives\ArchiveSettingsManager;
use SEOPulse\Services\ImageAltFiller;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AdminNotificationPanel class
 */
final class AdminNotificationPanel
{
    /**
     * Severity levels (ordered by priority, aligned with dashboard)
     */
    public const SEVERITY_CRITICAL  = 'critical';
    public const SEVERITY_BLOCKER   = 'blocker';
    public const SEVERITY_QUICK_WIN = 'quick_win';
    public const SEVERITY_WARNING   = 'warning';
    public const SEVERITY_INFO      = 'info';
    public const SEVERITY_SUCCESS   = 'success';

    /**
     * Notification categories
     */
    public const TYPE_NEEDS_IMPROVEMENT = 'needs_improvement';
    public const TYPE_HTTPS             = 'https';
    public const TYPE_SETUP             = 'setup';
    public const TYPE_MODULE            = 'module';
    public const TYPE_CONTENT           = 'content';
    public const TYPE_SITEMAP           = 'sitemap';
    public const TYPE_INDEXATION        = 'indexation';
    // Q2 types
    public const TYPE_IMAGE_SEO      = 'image_seo';
    public const TYPE_IMAGE_SEO_BULK = 'image_seo_bulk';
    public const TYPE_INDEXING       = 'instant_indexing';

    /**
     * User meta key storing the list of dismissed notification IDs.
     */
    private const DISMISSED_META_KEY = 'seopulse_dismissed_notifications';

    /**
     * Marks a notification as dismissed for the current user.
     *
     * @param string $id Notification ID.
     * @return void
     */
    public static function dismiss(string $id): void
    {
        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            return;
        }

        $id = sanitize_key($id);

        if ($id === '') {
            return;
        }

        $dismissed = self::get_dismissed();

        if (in_array($id, $dismissed, true)) {
            return;
        }

        $dismissed[] = $id;

        update_user_meta($user_id, self::DISMISSED_META_KEY, $dismissed);
    }

    /**
     * Returns the list of notification IDs dismissed by the current user.
     *
     * @return array<int, string>
     */
    private static function get_dismissed(): array
    {
        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            return [];
        }

        $dismissed = get_user_meta($user_id, self::DISMISSED_META_KEY, true);

        return is_array($dismissed) ? $dismissed : [];
    }

    /**
     * Collects all panel notifications.
     *
     * @return array<int, array{
     *   id: string,
     *   type: string,
     *   severity: string,
     *   title: string,
     *   message: string,
     *   icon: string,
     *   action_url?: string,
     *   action_label?: string
     * }>
     */
    public static function collect(): array
    {
        $notifications = [];

        // 1. Blockers first — setup & HTTPS
        $notifications = array_merge($notifications, self::check_setup_wizard());
        $notifications = array_merge($notifications, self::check_https());

        // 2. Technical status — sitemap, indexation
        $notifications = array_merge($notifications, self::check_sitemap());
        $notifications = array_merge($notifications, self::check_indexation());

        // 3. Quick wins — content improvements
        $notifications = array_merge($notifications, self::check_missing_meta());
        $notifications = array_merge($notifications, self::check_needs_improvement());
        $notifications = array_merge($notifications, self::check_image_seo());
        $notifications = array_merge($notifications, self::check_image_seo_bulk());

        // 4. Informational — disabled modules
        $notifications = array_merge($notifications, self::check_modules());

        /**
         * Filter to add/modify panel notifications.
         *
         * @since 1.0.0
         * @param array $notifications The collected notifications.
         */
        $notifications = apply_filters('seopulse_panel_notifications', $notifications);

        // Filter out notifications dismissed by the current user
        $dismissed = self::get_dismissed();

        if (!empty($dismissed)) {
            $notifications = array_values(
                array_filter(
                    $notifications,
                    static function (array $notification) use ($dismissed): bool {
                        return !in_array($notification['id'], $dismissed, true);
                    },
                ),
            );
        }

        // Sort by severity weight
        usort(
            $notifications,
            static function (array $a, array $b): int {
                return self::severity_weight($a['severity']) - self::severity_weight($b['severity']);
            },
        );

        return $notifications;
    }

    /**
     * Returns the count of notifications.
     *
     * @return int
     */
    public static function count(): int
    {
        return count(self::collect());
    }

    /**
     * Check if setup wizard has been completed.
     *
     * @return array
     */
    private static function check_setup_wizard(): array
    {
        $setup_complete = get_option(Options::SETUP_COMPLETE, false);

        if ($setup_complete) {
            return [];
        }

        return [
            [
                'id'           => 'setup_incomplete',
                'type'         => self::TYPE_SETUP,
                'severity'     => self::SEVERITY_BLOCKER,
                'title'        => __('Complete the setup wizard', 'seopulse'),
                'message'      => __('SEOPulse needs initial configuration to work properly. Run the wizard to set up your SEO in a few clicks.', 'seopulse'),
                'icon'         => 'dashicons-admin-tools',
                'action_url'   => admin_url('admin.php?page=seopulse-setup-wizard'),
                'action_label' => __('Start Setup', 'seopulse'),
            ],
        ];
    }

    /**
     * Check if site uses HTTPS.
     *
     * @return array
     */
    private static function check_https(): array
    {
        if (is_ssl()) {
            return [];
        }

        $site_url = get_site_url();

        if (str_starts_with($site_url, 'https://')) {
            return [];
        }

        return [
            [
                'id'       => 'no_https',
                'type'     => self::TYPE_HTTPS,
                'severity' => self::SEVERITY_BLOCKER,
                'title'    => __('Switch your site to HTTPS', 'seopulse'),
                'message'  => __('Search engines penalize non-secure sites. HTTPS is required for good SEO and user trust.', 'seopulse'),
                'icon'     => 'dashicons-lock',
            ],
        ];
    }

    /**
     * Check sitemap module status.
     *
     * @return array
     */
    private static function check_sitemap(): array
    {
        $module_active = Kernel::isModuleEnabled('sitemap');
        $page_slug  = self::get_module_page_slug('sitemap');
        $action_url = $page_slug !== null ? admin_url('admin.php?page=' . $page_slug) : admin_url('admin.php?page=seopulse');

        if (!$module_active) {
            return [
                [
                    'id'           => 'sitemap_disabled',
                    'type'         => self::TYPE_SITEMAP,
                    'severity'     => self::SEVERITY_QUICK_WIN,
                    'title'        => __('Enable your XML sitemap', 'seopulse'),
                    'message'      => __('A sitemap helps search engines discover all your pages. Enable the Sitemap module to get started.', 'seopulse'),
                    'icon'         => 'dashicons-networking',
                    'action_url'   => $action_url,
                    'action_label' => __('Enable', 'seopulse'),
                ],
            ];
        }

        $settings = (array) get_option(Options::SITEMAP, []);
        if (empty($settings)) {
            return [
                [
                    'id'           => 'sitemap_not_configured',
                    'type'         => self::TYPE_SITEMAP,
                    'severity'     => self::SEVERITY_QUICK_WIN,
                    'title'        => __('Configure your sitemap', 'seopulse'),
                    'message'      => __('The Sitemap module is active but not yet configured. Set it up so search engines can find your content.', 'seopulse'),
                    'icon'         => 'dashicons-networking',
                    'action_url'   => $action_url,
                    'action_label' => __('Configure', 'seopulse'),
                ],
            ];
        }

        return [];
    }

    /**
     * Check archive indexation settings.
     *
     * @return array
     */
    private static function check_indexation(): array
    {
        $manager  = new ArchiveSettingsManager();
        $settings = $manager->getAll();

        $search_robots = $settings['search']['robots'] ?? '';
        $date_robots   = $settings['date']['robots'] ?? '';

        $search_noindex = str_contains($search_robots, 'noindex');
        $date_noindex   = str_contains($date_robots, 'noindex');

        if ($search_noindex && $date_noindex) {
            return [];
        }

        $issues = [];
        if (!$search_noindex) {
            $issues[] = __('search results pages', 'seopulse');
        }
        if (!$date_noindex) {
            $issues[] = __('date archives', 'seopulse');
        }

        return [
            [
                'id'           => 'indexation_archives',
                'type'         => self::TYPE_INDEXATION,
                'severity'     => self::SEVERITY_QUICK_WIN,
                'title'        => __('Prevent duplicate content from archives', 'seopulse'),
                'message'      => sprintf(
                    /* translators: %s: comma-separated list of archive types */
                    __('Your %s are indexable by search engines, which can cause duplicate content issues. Set them to noindex.', 'seopulse'),
                    implode(', ', $issues),
                ),
                'icon'         => 'dashicons-visibility',
                'action_url'   => admin_url('admin.php?page=seopulse-meta-seo'),
                'action_label' => __('Fix indexation', 'seopulse'),
            ],
        ];
    }

    /**
     * Check 404 tracking status.
     *
     * @return array
     * @deprecated Handled by Monitor404 module.
     */
    private static function check_404_tracking(): array
    {
        return [];
    }

    /**
     * Check for disabled modules.
     *
     * @return array
     */
    private static function check_modules(): array
    {
        $modules       = ModuleManager::instance()->getDefinitionsForUI();
        $enabled       = get_option(Options::MODULES_ENABLED, []);
        $notifications = [];

        if (!is_array($enabled)) {
            $enabled = [];
        }

        // Skip sitemap — already covered by check_sitemap()
        foreach ($modules as $key => $module) {
            if ($key === 'sitemap') {
                continue;
            }

            $is_enabled = !isset($enabled[ $key ]) || (bool) $enabled[ $key ];

            if (!$is_enabled) {
                $page_slug  = self::get_module_page_slug($key);
                $action_url = $page_slug !== null
                    ? admin_url('admin.php?page=' . $page_slug)
                    : admin_url('admin.php?page=seopulse');

                $notifications[] = [
                    'id'           => 'module_disabled_' . sanitize_key($key),
                    'type'         => self::TYPE_MODULE,
                    'severity'     => self::SEVERITY_INFO,
                    'title'        => sprintf(
                        /* translators: %s: module name */
                        __('Module disabled: %s', 'seopulse'),
                        $module['label'],
                    ),
                    'message'      => sprintf(
                        /* translators: 1: module label, 2: module description */
                        __('The module "%1$s" is currently disabled. %2$s', 'seopulse'),
                        $module['label'],
                        $module['description'] ?? '',
                    ),
                    'icon'         => $module['icon'] ?? 'dashicons-admin-plugins',
                    'action_url'   => $action_url,
                    'action_label' => __('Enable module', 'seopulse'),
                ];
            }
        }

        return $notifications;
    }

    /**
     * Check for posts needing improvement (score < 60).
     *
     * @return array
     */
    private static function check_needs_improvement(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = '_seopulse_score'
                 AND CAST(meta_value AS UNSIGNED) < %d",
                60,
            ),
        );

        if ($count === 0) {
            return [];
        }

        // High volume = quick_win (bulk opportunity), low volume = info
        $severity = $count >= 5 ? self::SEVERITY_QUICK_WIN : self::SEVERITY_INFO;

        return [
            [
                'id'           => 'needs_improvement',
                'type'         => self::TYPE_NEEDS_IMPROVEMENT,
                'severity'     => $severity,
                'title'        => __('Improve low-scoring content', 'seopulse'),
                'message'      => sprintf(
                    /* translators: %d: number of posts with low scores */
                    _n(
                        '%d post scores below 60. Open it in the editor and follow the recommendations to improve ranking.',
                        '%d posts score below 60. Open them in the editor and follow the recommendations to improve rankings.',
                        $count,
                        'seopulse',
                    ),
                    $count,
                ),
                'icon'         => 'dashicons-edit',
                'action_url'   => admin_url('edit.php'),
                'action_label' => __('Review posts', 'seopulse'),
            ],
        ];
    }

    /**
     * Check for published posts without meta descriptions.
     *
     * @return array
     */
    private static function check_missing_meta(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 LEFT JOIN {$wpdb->postmeta} md ON p.ID = md.post_id AND md.meta_key = %s
                 WHERE p.post_status = 'publish'
                 AND p.post_type IN ('post', 'page')
                 AND (md.meta_value IS NULL OR md.meta_value = '')",
                '_seopulse_score',
                '_seopulse_meta_description',
            ),
        );

        if ($count === 0) {
            return [];
        }

        return [
            [
                'id'           => 'missing_meta_desc',
                'type'         => self::TYPE_CONTENT,
                'severity'     => self::SEVERITY_QUICK_WIN,
                'title'        => __('Add missing meta descriptions', 'seopulse'),
                'message'      => sprintf(
                    /* translators: %d: number of posts missing meta descriptions */
                    _n(
                        '%d published post has no meta description. Adding one improves click-through rates from search results.',
                        '%d published posts have no meta description. Adding them improves click-through rates from search results.',
                        $count,
                        'seopulse',
                    ),
                    $count,
                ),
                'icon'         => 'dashicons-editor-help',
                'action_url'   => admin_url('edit.php'),
                'action_label' => __('Add descriptions', 'seopulse'),
            ],
        ];
    }

    /**
     * Check for posts with images missing alt text.
     *
     * Only triggers when posts have been analyzed (meta present).
     * Severity scales with volume: quick_win if ≥5, info below.
     *
     * @return array
     */
    private static function check_image_seo(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $missing_alt = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id)
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = %s
                 AND CAST(meta_value AS UNSIGNED) > %d",
                '_seopulse_images_without_alt',
                0,
            ),
        );

        if ($missing_alt === 0) {
            return [];
        }

        $severity = $missing_alt >= 5 ? self::SEVERITY_QUICK_WIN : self::SEVERITY_INFO;

        return [
            [
                'id'           => 'image_seo_missing_alt',
                'type'         => self::TYPE_IMAGE_SEO,
                'severity'     => $severity,
                'title'        => __('Images missing alt text', 'seopulse'),
                'message'      => sprintf(
                    /* translators: %d: number of posts with images missing alt text */
                    _n(
                        '%d post has images without alt text. Alt text improves accessibility and image SEO.',
                        '%d posts have images without alt text. Alt text improves accessibility and image SEO.',
                        $missing_alt,
                        'seopulse',
                    ),
                    $missing_alt,
                ),
                'icon'         => 'dashicons-format-image',
                'action_url'   => admin_url('admin.php?page=seopulse-image-diagnostic'),
                'action_label' => __('View issues', 'seopulse'),
            ],
        ];
    }

    /**
     * Check media-library images for bulk alt text issues.
     *
     * Triggers when more than 10 images in the media library are
     * missing alt text — a quick-win notification pointing to the
     * Image Diagnostic page.
     *
     * @return array
     */
    private static function check_image_seo_bulk(): array
    {
        $filler      = new ImageAltFiller();
        $diagnostics = $filler->get_diagnostics();

        if ($diagnostics['missing_alt'] <= 10) {
            return [];
        }

        return [
            [
                'id'           => 'image_seo_bulk_alt',
                'type'         => self::TYPE_IMAGE_SEO_BULK,
                'severity'     => self::SEVERITY_QUICK_WIN,
                'title'        => __('Bulk image alt text missing', 'seopulse'),
                'message'      => sprintf(
                    /* translators: %d: number of images missing alt text in media library */
                    __('%d images in your media library have no alt text. Run an audit to fix them in bulk.', 'seopulse'),
                    $diagnostics['missing_alt'],
                ),
                'icon'         => 'dashicons-images-alt2',
                'action_url'   => admin_url('admin.php?page=seopulse-image-diagnostic'),
                'action_label' => __('Audit images', 'seopulse'),
            ],
        ];
    }

    /**
     * Map severity to weight (lower = higher priority).
     *
     * @param string $severity Severity level
     * @return int
     */
    private static function severity_weight(string $severity): int
    {
        return match ($severity) {
            self::SEVERITY_CRITICAL  => 0,
            self::SEVERITY_BLOCKER   => 0,
            self::SEVERITY_QUICK_WIN => 1,
            self::SEVERITY_WARNING   => 2,
            self::SEVERITY_INFO      => 3,
            self::SEVERITY_SUCCESS   => 4,
            default                  => 99,
        };
    }

    /**
     * Maps a module key to its dedicated admin page slug, if any.
     */
    private static function get_module_page_slug(string $key): ?string
    {
        return match ($key) {
            'analytics'    => 'seopulse-analytics',
            'local_seo'    => 'seopulse-local-seo',
            'meta_seo'     => 'seopulse-meta-seo',
            'monitor_404'  => 'seopulse-404-monitor',
            'redirections' => 'seopulse-redirections',
            'sitemap'      => 'seopulse-sitemap',
            default        => null,
        };
    }
}
