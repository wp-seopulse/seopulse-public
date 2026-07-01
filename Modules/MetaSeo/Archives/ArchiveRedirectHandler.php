<?php

/**
 * Archive Redirect Handler.
 *
 * Handles clean redirection when archive types are disabled:
 * - Author archives → homepage / blog / custom URL
 * - Date archives → homepage / blog / custom URL
 *
 * Also handles per-author noindex for empty or single-author sites.
 *
 * @package SEOPulse\Modules\MetaSeo\Archives
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Archives;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Contracts\ExecuteHooks;

final class ArchiveRedirectHandler implements ExecuteHooks
{
    private ArchiveSettingsManager $settings;

    public function __construct()
    {
        $this->settings = new ArchiveSettingsManager();
    }

    /**
     * Register WordPress hooks.
     */
    public function hooks(): void
    {
        // Early redirect for disabled archives (priority 1)
        add_action('template_redirect', [$this, 'handleRedirects'], 1);

        // Filter robots for smart noindex (author/date)
        add_filter('wp_robots', [$this, 'filterArchiveRobots'], 15);

        // Remove author rewrite rules when fully disabled
        add_filter('author_rewrite_rules', [$this, 'maybeRemoveAuthorRules']);

        // Remove date rewrite rules when fully disabled
        add_filter('date_rewrite_rules', [$this, 'maybeRemoveDateRules']);

        // Block search in robots.txt (WP native virtual robots.txt)
        add_filter('robots_txt', [$this, 'maybeBlockSearchInRobots'], 20, 2);

        // Block search in robots.txt (SEOPulse-generated virtual/physical robots.txt)
        add_filter('seopulse_robots_txt', [$this, 'appendSearchBlockRules'], 20);

        // Hide search form on 404 pages when "Show search box" is OFF
        // Classic themes: filter get_search_form()
        add_filter('get_search_form', [$this, 'maybeHideSearchFormOn404'], 20);
        // Block themes: filter the core/search block output
        add_filter('render_block_core/search', [$this, 'maybeHideSearchBlockOn404'], 20);

        // Hide latest posts on 404 pages when "Show latest posts" is OFF
        add_filter('render_block_core/latest-posts', [$this, 'maybeHideLatestPostsOn404'], 20);
    }

    // ------------------------------------------------------------------
    // Redirect handling
    // ------------------------------------------------------------------

    /**
     * Handle redirects for disabled archive types.
     */
    public function handleRedirects(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        // Author archives
        if (is_author()) {
            $authorSettings = $this->settings->get('author');

            if (!empty($authorSettings['disable_archives'])) {
                $this->redirect(
                    $authorSettings['redirect_target'] ?? 'homepage',
                    $authorSettings['redirect_custom_url'] ?? '',
                    (int) ($authorSettings['redirect_type'] ?? 301),
                );

                return;
            }
        }

        // Date archives
        if (is_date()) {
            $dateSettings = $this->settings->get('date');

            if (!empty($dateSettings['disable_archives'])) {
                $this->redirect(
                    $dateSettings['redirect_target'] ?? 'homepage',
                    $dateSettings['redirect_custom_url'] ?? '',
                    (int) ($dateSettings['redirect_type'] ?? 301),
                );

                return;
            }
        }
    }

    // ------------------------------------------------------------------
    // Smart robots filtering
    // ------------------------------------------------------------------

    /**
     * Filter wp_robots for intelligent archive noindexing.
     *
     * @param array<string, mixed> $robots
     * @return array<string, mixed>
     */
    public function filterArchiveRobots(array $robots): array
    {
        // Author archives — smart noindex
        if (is_author()) {
            $robots = $this->applyAuthorRobots($robots);
        }

        // Date archives
        if (is_date()) {
            $robots = $this->applyDateRobots($robots);
        }

        // Search pages
        if (is_search()) {
            $robots = $this->applySearchRobots($robots);
        }

        return $robots;
    }

    /**
     * Apply author archive robots directives.
     *
     * @param array<string, mixed> $robots
     * @return array<string, mixed>
     */
    private function applyAuthorRobots(array $robots): array
    {
        $settings = $this->settings->get('author');

        // Noindex empty authors
        if (!empty($settings['noindex_empty_authors'])) {
            $author = get_queried_object();

            if ($author instanceof \WP_User) {
                $postCount = (int) count_user_posts($author->ID, 'post', true);

                if ($postCount === 0) {
                    unset($robots['index']);
                    $robots['noindex'] = true;

                    return $robots;
                }
            }
        }

        // Noindex single-author site
        if (!empty($settings['noindex_single_author'])) {
            $analyzer = new ArchiveAnalyzer($this->settings);

            if ($analyzer->getActiveAuthorCount() <= 1) {
                unset($robots['index']);
                $robots['noindex'] = true;

                return $robots;
            }
        }

        return $robots;
    }

    /**
     * Apply date archive robots directives.
     *
     * @param array<string, mixed> $robots
     * @return array<string, mixed>
     */
    private function applyDateRobots(array $robots): array
    {
        $settings    = $this->settings->get('date');
        $robotString = $settings['robots'] ?? 'noindex,follow';

        if (str_contains($robotString, 'noindex')) {
            unset($robots['index']);
            $robots['noindex'] = true;
        }

        if (str_contains($robotString, 'nofollow')) {
            unset($robots['follow']);
            $robots['nofollow'] = true;
        }

        return $robots;
    }

    /**
     * Apply search page robots directives.
     *
     * @param array<string, mixed> $robots
     * @return array<string, mixed>
     */
    private function applySearchRobots(array $robots): array
    {
        $settings    = $this->settings->get('search');
        $robotString = $settings['robots'] ?? 'noindex,follow';

        if (str_contains($robotString, 'noindex')) {
            unset($robots['index']);
            $robots['noindex'] = true;
        }

        if (!empty($settings['add_nofollow']) || str_contains($robotString, 'nofollow')) {
            unset($robots['follow']);
            $robots['nofollow'] = true;
        }

        return $robots;
    }

    // ------------------------------------------------------------------
    // Rewrite rule suppression
    // ------------------------------------------------------------------

    /**
     * Remove author rewrite rules when archives are fully disabled.
     *
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    public function maybeRemoveAuthorRules(array $rules): array
    {
        $settings = $this->settings->get('author');

        if (!empty($settings['disable_archives'])) {
            return [];
        }

        return $rules;
    }

    /**
     * Remove date rewrite rules when archives are fully disabled.
     *
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    public function maybeRemoveDateRules(array $rules): array
    {
        $settings = $this->settings->get('date');

        if (!empty($settings['disable_archives'])) {
            return [];
        }

        return $rules;
    }

    // ------------------------------------------------------------------
    // robots.txt blocking
    // ------------------------------------------------------------------

    /**
     * Append "Disallow: /?s=" to robots.txt when search blocking is enabled.
     *
     * @param string $output Current robots.txt content.
     * @param string $public Site visibility flag.
     * @return string Modified robots.txt content.
     */
    public function maybeBlockSearchInRobots(string $output, string $public): string
    {
        if ('1' !== $public) {
            return $output;
        }

        return $this->appendSearchBlockRules($output);
    }

    /**
     * Append search-blocking rules to robots.txt content.
     *
     * Used by both the WP native `robots_txt` filter (via maybeBlockSearchInRobots)
     * and the SEOPulse `seopulse_robots_txt` filter.
     *
     * @param string $output Current robots.txt content.
     * @return string Modified robots.txt content.
     */
    public function appendSearchBlockRules(string $output): string
    {
        $settings = $this->settings->get('search');

        if (!empty($settings['block_robots_txt'])) {
            $output .= "\n# SEOPulse — Block search results pages\n";
            $output .= "Disallow: /?s=\n";
            $output .= "Disallow: /search/\n";
        }

        return $output;
    }

    // ------------------------------------------------------------------
    // 404 page: search form visibility
    // ------------------------------------------------------------------

    /**
     * Whether the search form should be hidden on the current 404 page.
     *
     * Centralised check used by both the classic and block-theme filters.
     *
     * @return bool True when the search form must be suppressed.
     */
    private function shouldHideSearchOn404(): bool
    {
        if (!is_404()) {
            return false;
        }

        $settings = $this->settings->get('error_404');

        return empty($settings['show_search']);
    }

    /**
     * Hide the search form on 404 pages (classic themes).
     *
     * Filters `get_search_form` so themes that call `get_search_form()` in
     * their 404.php template will receive an empty string.
     *
     * @param string $form The search form HTML.
     * @return string Empty string if search is hidden, original form otherwise.
     */
    public function maybeHideSearchFormOn404(string $form): string
    {
        return $this->shouldHideSearchOn404() ? '' : $form;
    }

    /**
     * Hide the core/search block on 404 pages (block themes).
     *
     * Filters `render_block_core/search` so block themes (Twenty Twenty-Five,
     * Twenty Twenty-Four, etc.) that embed `<!-- wp:search -->` in their 404
     * template pattern will receive an empty string.
     *
     * @param string $blockContent The rendered block HTML.
     * @return string Empty string if search is hidden, original block otherwise.
     */
    public function maybeHideSearchBlockOn404(string $blockContent): string
    {
        return $this->shouldHideSearchOn404() ? '' : $blockContent;
    }

    // ------------------------------------------------------------------
    // 404 page: latest posts visibility
    // ------------------------------------------------------------------

    /**
     * Whether the latest posts block should be hidden on the current 404 page.
     *
     * @return bool True when the latest posts block must be suppressed.
     */
    private function shouldHideLatestPostsOn404(): bool
    {
        if (!is_404()) {
            return false;
        }

        $settings = $this->settings->get('error_404');

        return empty($settings['show_popular']);
    }

    /**
     * Hide the core/latest-posts block on 404 pages.
     *
     * Filters `render_block_core/latest-posts` so block themes that embed
     * `<!-- wp:latest-posts -->` in their 404 template will receive an
     * empty string when "Show latest posts" is OFF.
     *
     * @param string $blockContent The rendered block HTML.
     * @return string Empty string if hidden, original block otherwise.
     */
    public function maybeHideLatestPostsOn404(string $blockContent): string
    {
        return $this->shouldHideLatestPostsOn404() ? '' : $blockContent;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Perform a clean redirect.
     *
     * @param string $target homepage|blog|custom
     * @param string $customUrl Custom URL if target is 'custom'.
     * @param int $statusCode HTTP status code (301 or 302).
     */
    private function redirect(string $target, string $customUrl, int $statusCode): void
    {
        $url = match ($target) {
            'blog'   => $this->getBlogUrl(),
            'custom' => $customUrl !== '' ? esc_url_raw($customUrl) : home_url('/'),
            default  => home_url('/'),
        };

        if (empty($url)) {
            $url = home_url('/');
        }

        $statusCode = in_array($statusCode, [301, 302], true) ? $statusCode : 301;

        wp_safe_redirect($url, $statusCode, 'SEOPulse');
        exit;
    }

    /**
     * Get the blog/posts page URL.
     */
    private function getBlogUrl(): string
    {
        $blogPageId = (int) get_option('page_for_posts');

        if ($blogPageId > 0) {
            $url = get_permalink($blogPageId);

            return is_string($url) ? $url : home_url('/');
        }

        return home_url('/');
    }
}
