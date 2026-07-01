<?php

/**
 * Archive SEO Analyzer.
 *
 * Provides intelligent analysis and recommendations for archive pages.
 * Detects issues (single author, empty archives, duplicate content)
 * and returns actionable, prioritized suggestions.
 *
 * @package SEOPulse\Modules\MetaSeo\Archives
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Archives;

if (!defined('ABSPATH')) {
    exit;
}

final class ArchiveAnalyzer
{
    private ArchiveSettingsManager $settings;

    public function __construct(?ArchiveSettingsManager $settings = null)
    {
        $this->settings = $settings ?? new ArchiveSettingsManager();
    }

    // ------------------------------------------------------------------
    // Full analysis
    // ------------------------------------------------------------------

    /**
     * Run full archive analysis and return all recommendations.
     *
     * @return array{
     *   author: array<int, array{type: string, severity: string, message: string, action: string}>,
     *   date: array<int, array{type: string, severity: string, message: string, action: string}>,
     *   search: array<int, array{type: string, severity: string, message: string, action: string}>,
     *   error_404: array<int, array{type: string, severity: string, message: string, action: string}>,
     *   summary: array{score: int, issues: int, warnings: int}
     * }
     */
    public function analyze(): array
    {
        $results = [
            'author'    => $this->analyzeAuthorArchives(),
            'date'      => $this->analyzeDateArchives(),
            'search'    => $this->analyzeSearchArchives(),
            'error_404' => $this->analyze404(),
        ];

        $issues   = 0;
        $warnings = 0;

        foreach ($results as $type => $recommendations) {
            if ($type === 'summary') {
                continue;
            }

            foreach ($recommendations as $rec) {
                if ($rec['severity'] === 'critical') {
                    ++$issues;
                } elseif ($rec['severity'] === 'warning') {
                    ++$warnings;
                }
            }
        }

        $score = max(0, 100 - ($issues * 15) - ($warnings * 5));

        $results['summary'] = [
            'score'    => $score,
            'issues'   => $issues,
            'warnings' => $warnings,
        ];

        return $results;
    }

    // ------------------------------------------------------------------
    // Author archives analysis
    // ------------------------------------------------------------------

    /**
     * Analyze author archive configuration.
     *
     * @return array<int, array{type: string, severity: string, message: string, action: string}>
     */
    public function analyzeAuthorArchives(): array
    {
        $settings        = $this->settings->get('author');
        $recommendations = [];

        // Detect single-author site
        $authorCount = $this->getActiveAuthorCount();

        if ($authorCount <= 1 && ($settings['robots'] ?? '') !== 'noindex,follow') {
            $recommendations[] = [
                'type'     => 'single_author',
                'severity' => 'warning',
                'message'  => __('Single-author site detected. Author archives duplicate your blog page content.', 'seopulse'),
                'action'   => __('Set author archives to noindex or disable them entirely.', 'seopulse'),
            ];
        }

        // Detect authors without published content
        $emptyAuthors = $this->getAuthorsWithoutContent();

        if (!empty($emptyAuthors) && ($settings['noindex_empty_authors'] ?? true) === false) {
            $recommendations[] = [
                'type'     => 'empty_authors',
                'severity' => 'warning',
                'message'  => sprintf(
                    /* translators: %d: number of authors without content */
                    __('%d author(s) have no published content. Their archive pages are empty.', 'seopulse'),
                    count($emptyAuthors),
                ),
                'action'   => __('Enable "noindex empty authors" or disable author archives.', 'seopulse'),
            ];
        }

        // Check for potential duplicate content with blog page
        if (empty($settings['disable_archives'])) {
            $blogPage = get_option('page_for_posts');

            if (empty($blogPage) && $authorCount <= 1) {
                $recommendations[] = [
                    'type'     => 'duplicate_blog',
                    'severity' => 'critical',
                    'message'  => __('Author archive is identical to the main blog listing (single author, no static blog page).', 'seopulse'),
                    'action'   => __('Disable author archives and redirect to homepage.', 'seopulse'),
                ];
            }
        }

        // Title template analysis
        if (empty($settings['title'])) {
            $recommendations[] = [
                'type'     => 'missing_title',
                'severity' => 'warning',
                'message'  => __('No custom title template for author archives.', 'seopulse'),
                'action'   => __('Set a title template like "{{author.name}} — Articles {{sep}} {{site.name}}"', 'seopulse'),
            ];
        }

        return $recommendations;
    }

    // ------------------------------------------------------------------
    // Date archives analysis
    // ------------------------------------------------------------------

    /**
     * Analyze date archive configuration.
     *
     * @return array<int, array{type: string, severity: string, message: string, action: string}>
     */
    public function analyzeDateArchives(): array
    {
        $settings        = $this->settings->get('date');
        $recommendations = [];

        // If date archives are disabled via toggle, no recommendations needed
        if (!empty($settings['disable_archives'])) {
            return $recommendations;
        }

        // Date archives indexed = almost always bad
        if (($settings['robots'] ?? '') !== 'noindex,follow') {
            $recommendations[] = [
                'type'     => 'date_indexed',
                'severity' => 'critical',
                'message'  => __('Date archives are indexed. This creates thin content pages and wastes crawl budget.', 'seopulse'),
                'action'   => __('Set date archives to noindex,follow — this is the recommended practice.', 'seopulse'),
            ];
        }

        // Check if date archives generate unique content
        $uniqueContent = $this->dateArchivesHaveUniqueContent();

        if (!$uniqueContent) {
            $recommendations[] = [
                'type'     => 'no_unique_content',
                'severity' => 'warning',
                'message'  => __('Date archives do not contain content distinct from other archive types or the blog page.', 'seopulse'),
                'action'   => __('Consider disabling date archives entirely and redirecting to the blog page.', 'seopulse'),
            ];
        }

        // Low post frequency — daily archives useless
        $postsPerMonth = $this->getAveragePostsPerMonth();

        if ($postsPerMonth < 4) {
            $recommendations[] = [
                'type'     => 'low_frequency',
                'severity' => 'info',
                'message'  => sprintf(
                    /* translators: %s: average posts per month */
                    __('Average publishing frequency is %s posts/month. Daily date archives are likely very thin.', 'seopulse'),
                    number_format($postsPerMonth, 1),
                ),
                'action'   => __('Disable date archives or at minimum noindex them.', 'seopulse'),
            ];
        }

        return $recommendations;
    }

    // ------------------------------------------------------------------
    // Search archives analysis
    // ------------------------------------------------------------------

    /**
     * Analyze search results page configuration.
     *
     * @return array<int, array{type: string, severity: string, message: string, action: string}>
     */
    public function analyzeSearchArchives(): array
    {
        $settings        = $this->settings->get('search');
        $recommendations = [];

        // Search pages should ALWAYS be noindex
        if (($settings['robots'] ?? '') !== 'noindex,follow' && ($settings['robots'] ?? '') !== 'noindex,nofollow') {
            $recommendations[] = [
                'type'     => 'search_indexed',
                'severity' => 'critical',
                'message'  => __('Search result pages are indexed. This creates infinite low-quality pages and is exploitable by spammers.', 'seopulse'),
                'action'   => __('Set search pages to noindex — this is mandatory for proper SEO.', 'seopulse'),
            ];
        }

        // Robots.txt blocking recommendation
        if (!($settings['block_robots_txt'] ?? false)) {
            $recommendations[] = [
                'type'     => 'no_robots_block',
                'severity' => 'info',
                'message'  => __('Search URLs are not blocked in robots.txt. While noindex is sufficient, blocking saves crawl budget.', 'seopulse'),
                'action'   => __('Consider blocking /?s= in robots.txt for optimal crawl budget.', 'seopulse'),
            ];
        }

        return $recommendations;
    }

    // ------------------------------------------------------------------
    // 404 page analysis
    // ------------------------------------------------------------------

    /**
     * Analyze 404 page configuration.
     *
     * @return array<int, array{type: string, severity: string, message: string, action: string}>
     */
    public function analyze404(): array
    {
        $settings        = $this->settings->get('error_404');
        $recommendations = [];

        // Check 404 tracking
        if (!($settings['track_404'] ?? true)) {
            $recommendations[] = [
                'type'     => 'no_tracking',
                'severity' => 'warning',
                'message'  => __('404 error tracking is disabled. You cannot identify broken links or migration issues.', 'seopulse'),
                'action'   => __('Enable 404 tracking to discover redirect opportunities.', 'seopulse'),
            ];
        }

        // Check if recovery features are enabled
        if (!($settings['show_popular'] ?? true) && !($settings['show_search'] ?? true)) {
            $recommendations[] = [
                'type'     => 'no_recovery',
                'severity' => 'warning',
                'message'  => __('No user recovery features on the 404 page. Visitors will leave immediately.', 'seopulse'),
                'action'   => __('Enable latest posts and/or search suggestions on your 404 page.', 'seopulse'),
            ];
        }

        // Get frequent 404s and suggest redirections
        $frequent404s = $this->getFrequent404Urls();

        if (!empty($frequent404s)) {
            $recommendations[] = [
                'type'     => 'frequent_404s',
                'severity' => 'warning',
                'message'  => sprintf(
                    /* translators: %d: number of frequent 404 URLs */
                    __('%d URLs are generating frequent 404 errors.', 'seopulse'),
                    count($frequent404s),
                ),
                'action'   => __('Review these URLs and create redirections to preserve link equity.', 'seopulse'),
                'data'     => $frequent404s,
            ];
        }

        return $recommendations;
    }

    // ------------------------------------------------------------------
    // Data helpers
    // ------------------------------------------------------------------

    /**
     * Count active authors (with at least 1 published post).
     */
    public function getActiveAuthorCount(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_author)
                 FROM {$wpdb->posts}
                 WHERE post_status = %s
                 AND post_type IN ('post', 'page')",
                'publish',
            ),
        );

        return (int) $count;
    }

    /**
     * Get authors without any published content.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getAuthorsWithoutContent(): array
    {
        $allAuthors = get_users(
            [
                'role__in' => ['author', 'editor', 'administrator', 'contributor'],
                'fields'   => ['ID', 'display_name'],
            ],
        );

        $empty = [];

        foreach ($allAuthors as $author) {
            $postCount = count_user_posts((int) $author->ID, 'post', true);

            if ($postCount === 0) {
                $empty[] = [
                    'id'   => (int) $author->ID,
                    'name' => $author->display_name,
                ];
            }
        }

        return $empty;
    }

    /**
     * Check if date archives contain content distinct from other archives.
     */
    private function dateArchivesHaveUniqueContent(): bool
    {
        global $wpdb;

        // If posts are spread across many months, date archives add value
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $monthCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT DATE_FORMAT(post_date, '%%Y-%%m'))
                 FROM {$wpdb->posts}
                 WHERE post_status = %s
                 AND post_type = %s
                 AND post_date > DATE_SUB(NOW(), INTERVAL 2 YEAR)",
                'publish',
                'post',
            ),
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $postCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->posts}
                 WHERE post_status = %s
                 AND post_type = %s
                 AND post_date > DATE_SUB(NOW(), INTERVAL 2 YEAR)",
                'publish',
                'post',
            ),
        );

        // If avg > 5 posts per month with many months, content is somewhat unique
        return $monthCount > 6 && ($postCount / max($monthCount, 1)) > 5;
    }

    /**
     * Get average posts per month (last 12 months).
     */
    private function getAveragePostsPerMonth(): float
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->posts}
                 WHERE post_status = %s
                 AND post_type = %s
                 AND post_date > DATE_SUB(NOW(), INTERVAL 12 MONTH)",
                'publish',
                'post',
            ),
        );

        return $count / 12.0;
    }

    /**
     * Get frequently hit 404 URLs.
     *
     * @param int $limit Maximum URLs to return.
     * @return array<int, array{url: string, hits: int, last_hit: string}>
     */
    private function getFrequent404Urls(int $limit = 10): array
    {
        $logs = get_option('seopulse_404_logs', []);

        if (!is_array($logs) || empty($logs)) {
            return [];
        }

        // Sort by hit count (descending)
        usort($logs, static fn ($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

        $result = [];

        foreach (array_slice($logs, 0, $limit) as $log) {
            if (($log['count'] ?? 0) >= 3) {
                $result[] = [
                    'url'      => $log['url'] ?? '',
                    'hits'     => (int) ($log['count'] ?? 0),
                    'last_hit' => $log['last_access'] ?? '',
                ];
            }
        }

        return $result;
    }
}
