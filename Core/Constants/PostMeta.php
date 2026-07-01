<?php

/**
 * Constants for WordPress post meta keys
 *
 * Centralizes all post meta keys to avoid magic strings.
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
 * PostMeta class — Constants for wp_postmeta
 */
final class PostMeta
{
    // ── Global analysis ──────────────────────────────────────
    /** @var string Post target keyword (deprecated, use FOCUS_KEYWORDS) */
    public const FOCUS_KEYWORD = '_seopulse_focus_keyword';

    /** @var string Post target keywords (multi-keyword support since 3.0.0) */
    public const FOCUS_KEYWORDS = '_seopulse_focus_keywords';

    /** @var string Global SEO score (0-100) */
    public const SCORE = '_seopulse_score';

    /** @var string Last analysis timestamp */
    public const LAST_ANALYSIS = '_seopulse_last_analysis';

    /** @var string Detailed scores by module (serialized array) */
    public const SCORES = '_seopulse_scores';

    /** @var string Number of recommendations */
    public const RECOMMENDATIONS_COUNT = '_seopulse_recommendations_count';

    /** @var string Dismissed recommendations (serialized array) */
    public const DISMISSED_RECOMMENDATIONS = '_seopulse_dismissed_recommendations';

    // ── Meta SEO Module ──────────────────────────────────────
    /** @var string Post Meta SEO data (title, desc, OG, Twitter, etc.) */
    public const META_SEO = '_seopulse_meta_seo';

    // ── Redirections Module ──────────────────────────────────
    /** @var string Redirect URL */
    public const REDIRECT_URL = '_seopulse_redirect_url';

    /** @var string Redirect type (301, 302) */
    public const REDIRECT_TYPE = '_seopulse_redirect_type';

    // ── Sitemap Module ───────────────────────────────────────
    /** @var string Exclude from sitemap (boolean) */
    public const EXCLUDE_SITEMAP = '_seopulse_exclude_sitemap';

    /** @var string Sitemap priority (0.0-1.0) */
    public const SITEMAP_PRIORITY = '_seopulse_sitemap_priority';

    /** @var string Change frequency (always, hourly, daily, etc.) */
    public const SITEMAP_CHANGEFREQ = '_seopulse_sitemap_changefreq';

    /** @var string Google News keywords */
    public const NEWS_KEYWORDS = '_seopulse_news_keywords';

    /** @var string Stock tickers (Google News) */
    public const STOCK_TICKERS = '_seopulse_stock_tickers';
}
