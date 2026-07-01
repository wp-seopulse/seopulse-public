<?php

/**
 * Constants for WordPress option keys
 *
 * Centralizes all option keys to avoid magic strings
 * and ease maintenance.
 *
 * @package SEOPulse\Core\Constants
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Constants;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Options class — Constants for wp_options
 */
final class Options
{
    /** @var string General plugin settings */
    public const GENERAL = 'seopulse_settings';

    /** @var string Global Meta SEO settings */
    public const META_SEO_GLOBAL = 'seopulse_meta_seo_global';

    /** @var string Local SEO / JSON-LD settings */
    public const LOCAL_SEO = 'seopulse_local_seo_settings';

    /** @var string Redirections list */
    public const REDIRECTIONS = 'seopulse_redirections';

    /** @var string Logs 404 */
    public const REDIRECTIONS_404 = 'seopulse_404_logs';

    /** @var string Sitemap settings */
    public const SITEMAP = 'seopulse_sitemap_settings';

    /** @var string Installed plugin version */
    public const VERSION = 'seopulse_version';

    /** @var string Activation timestamp */
    public const ACTIVATED_TIME = 'seopulse_activated_time';

    /** @var string Cache transient prefix */
    public const CACHE_PREFIX = 'seopulse_analysis_';

    /** @var string Sitemap transient prefix */
    public const SITEMAP_CACHE_PREFIX = 'seopulse_sitemap_';

    /** @var string Module activation state */
    public const MODULES_ENABLED = 'seopulse_modules_enabled';

    /** @var string Meta template engine templates (global, per-CPT, etc.) */
    public const META_TEMPLATES = 'seopulse_meta_templates';

    /** @var string Archive SEO settings (author, date, search, 404) */
    public const ARCHIVE_SETTINGS = 'seopulse_archive_settings';

    /** @var string Taxonomy SEO settings (noindex, nofollow, thresholds per taxonomy) */
    public const TAXONOMY_SETTINGS = 'seopulse_taxonomy_settings';

    /** @var string Configuration backup before import */
    public const CONFIG_BACKUP = 'seopulse_config_backup';
    public const ANALYTICS     = 'seopulse_analytics_settings';

    /** @var string Boolean — wizard marked as complete */
    public const SETUP_COMPLETE = 'seopulse_setup_complete';

    /** @var string Site profile collected during the wizard (site_type, activity_type, etc.) */
    public const WIZARD_PROFILE = 'seopulse_wizard_profile';

    /** @var string Recommendation provenance stored for wizard Meta SEO fields */
    public const WIZARD_META_SEO_RECOMMENDATIONS = 'seopulse_wizard_meta_seo_recommendations';

    /** @var string Recommendation provenance stored for wizard Local SEO fields */
    public const WIZARD_LOCAL_SEO_RECOMMENDATIONS = 'seopulse_wizard_local_seo_recommendations';

    /** @var string Image ALT auto-fill settings */
    public const IMAGE_ALT = 'seopulse_image_alt_settings';

    /** @var string Admin list table SEO columns settings */
    public const ADMIN_COLUMNS = 'seopulse_admin_columns_settings';

    /** @var string Instant Indexing settings (IndexNow + Google) */
    public const INDEXING = 'seopulse_indexing_settings';
}
