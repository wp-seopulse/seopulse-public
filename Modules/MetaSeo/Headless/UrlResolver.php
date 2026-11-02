<?php
/**
 * Resolves a public URL to a WordPress main-query equivalent.
 *
 * @package SEOPulse\Modules\MetaSeo\Headless
 * @since   1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Headless;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;
use WP_Post_Type;
use WP_Query;
use WP_Term;
use WP_User;

/**
 * Turns a URL (or a post) into a WP_Query and decides whether it may be exposed.
 *
 * It replays the rewrite-rule matching done by WP::parse_request() without
 * firing its hooks (core's rest_api_loaded() is attached to "parse_request" and
 * would re-enter the REST server). Only publicly viewable content is returned,
 * whoever the current user is.
 */
final class UrlResolver
{
    /**
     * Maximum accepted URL length.
     */
    public const MAX_URL_LENGTH = 2048;

    /**
     * Query-string variables honoured in addition to taxonomy query vars.
     *
     * @var string[]
     */
    private const ALLOWED_QUERY_VARS = [
        'p',
        'page_id',
        'pagename',
        'name',
        'cat',
        'category_name',
        'tag',
        'tag_id',
        'author',
        'author_name',
        'year',
        'monthnum',
        'day',
        'm',
        'w',
        'paged',
        'page',
        'post_type',
    ];

    /**
     * Variables set by rewrite rules that point to content we never expose.
     *
     * @var string[]
     */
    private const UNSUPPORTED_VARS = [
        'feed',
        'embed',
        'tb',
        'robots',
        'favicon',
        'sitemap',
        'sitemap-subtype',
        'sitemap-stylesheet',
        'attachment',
        'attachment_id',
        'cpage',
        'error',
        's',
        'preview',
    ];

    /**
     * Normalizes a URL to "/path?query" relative to the site home.
     *
     * @param string $url Absolute URL or site-relative path.
     * @return string|null Normalized value, or null when the URL does not belong to this site.
     */
    public function normalize(string $url): ?string
    {
        $url = trim($url);

        if ('' === $url || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        $parts = wp_parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['host'])) {
            $host       = strtolower($parts['host']);
            $home_host  = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
            $front_host = (new FrontendUrl())->host();

            if ($host !== $home_host && $host !== $front_host) {
                return null;
            }
        }

        $path      = isset($parts['path']) ? (string) $parts['path'] : '/';
        $home_path = untrailingslashit((string) wp_parse_url(home_url(), PHP_URL_PATH));

        if ('' !== $home_path) {
            if ($path !== $home_path && !str_starts_with($path, $home_path . '/')) {
                return null;
            }

            $path = substr($path, strlen($home_path));
        }

        $path = '/' . trim($path, '/');
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        $path = (string) preg_replace('#^/index\.php(?=/|$)#', '', $path);
        $path = '' === $path ? '/' : $path;

        $query_vars = [];
        parse_str(isset($parts['query']) ? (string) $parts['query'] : '', $query_vars);
        $query_vars = $this->filter_query_vars($query_vars);
        ksort($query_vars);

        $query = http_build_query($query_vars, '', '&', PHP_QUERY_RFC3986);

        return '' === $query ? $path : $path . '?' . $query;
    }

    /**
     * Resolves a normalized URL to an exposable query.
     *
     * @param string $normalized Value returned by normalize().
     * @return WP_Query|null Query ready to be used as the main query, or null when not found or not public.
     */
    public function resolve(string $normalized): ?WP_Query
    {
        $query_vars = [];
        $path       = $normalized;
        $query_pos  = strpos($normalized, '?');

        if (false !== $query_pos) {
            $path = substr($normalized, 0, $query_pos);
            parse_str(substr($normalized, $query_pos + 1), $query_vars);
        }

        $perma_vars = $this->match_rewrite_rules(trim($path, '/'));

        if (null === $perma_vars) {
            return null;
        }

        // As in WP::parse_request(), explicit query-string values win over rewrite-rule values.
        $vars = array_merge($perma_vars, $query_vars);

        if (array_intersect_key($vars, array_flip(self::UNSUPPORTED_VARS))) {
            return null;
        }

        // The constructor skips the query when $vars is empty (home page).
        $query = new WP_Query();
        $query->query($vars);

        return $this->is_exposable($query) ? $query : null;
    }

    /**
     * Builds the query matching a single post.
     *
     * @param WP_Post $post Post.
     * @return WP_Query|null Null when the post is not public.
     */
    public function for_post(WP_Post $post): ?WP_Query
    {
        $vars = 'page' === $post->post_type
            ? ['page_id' => $post->ID]
            : [
                'p'         => $post->ID,
                'post_type' => $post->post_type,
            ];

        $query = new WP_Query($vars);

        return $this->is_exposable($query) ? $query : null;
    }

    /**
     * Builds the query of the site's 404 page.
     *
     * @return WP_Query
     */
    public function not_found_query(): WP_Query
    {
        $query = new WP_Query();
        $query->set_404();

        return $query;
    }

    /**
     * Tells whether a post may be exposed to anonymous visitors.
     *
     * @param WP_Post $post Post.
     * @return bool
     */
    public function is_post_exposable(WP_Post $post): bool
    {
        return is_post_publicly_viewable($post) && '' === $post->post_password;
    }

    /**
     * Keeps only supported scalar query variables.
     *
     * @param array<mixed> $vars Raw variables.
     * @return array<string, string>
     */
    private function filter_query_vars(array $vars): array
    {
        $allowed = self::ALLOWED_QUERY_VARS;

        foreach (get_taxonomies(['publicly_queryable' => true], 'objects') as $taxonomy) {
            if (is_string($taxonomy->query_var) && '' !== $taxonomy->query_var) {
                $allowed[] = $taxonomy->query_var;
            }
        }

        $filtered = [];

        foreach ($vars as $key => $value) {
            if (is_string($key) && is_string($value) && in_array($key, $allowed, true)) {
                $filtered[ $key ] = sanitize_text_field($value);
            }
        }

        return $filtered;
    }

    /**
     * Matches a request path against the rewrite rules.
     *
     * @param string $request Path without leading or trailing slash.
     * @return array<string, string>|null Query variables, or null when no rule matches.
     */
    private function match_rewrite_rules(string $request): ?array
    {
        global $wp_rewrite;

        if ('' === $request) {
            return [];
        }

        if (!$wp_rewrite->using_permalinks()) {
            return null;
        }

        $rules = $wp_rewrite->wp_rewrite_rules();

        if (!is_array($rules)) {
            return null;
        }

        foreach ($rules as $match => $query) {
            $matches = [];

            if (!preg_match("#^$match#", $request, $matches) && !preg_match("#^$match#", urldecode($request), $matches)) {
                continue;
            }

            // Verbose page rules match almost everything: confirm the page exists and is viewable.
            if ($wp_rewrite->use_verbose_page_rules && preg_match('/pagename=\$matches\[([0-9]+)\]/', $query, $page_match)) {
                $page = get_page_by_path($matches[ (int) $page_match[1] ] ?? '');

                if (!$page instanceof WP_Post || !is_post_status_viewable($page->post_status)) {
                    continue;
                }
            }

            $query = (string) preg_replace('!^.+\?!', '', $query);
            $query = (string) preg_replace_callback(
                '/\$matches\[(\d+)\]/',
                static fn (array $found): string => rawurlencode($matches[ (int) $found[1] ] ?? ''),
                $query,
            );

            $vars = [];
            parse_str($query, $vars);

            return array_map('strval', array_filter($vars, 'is_scalar'));
        }

        return null;
    }

    /**
     * Decides whether a query targets content that may be exposed publicly.
     *
     * @param WP_Query $query Query.
     * @return bool
     */
    private function is_exposable(WP_Query $query): bool
    {
        if ($query->is_feed || $query->is_search || $query->is_embed || $query->is_404 || $query->is_robots || $query->is_trackback || $query->is_attachment) {
            return false;
        }

        $object = $query->get_queried_object();

        if ($query->is_singular || $query->is_posts_page) {
            return $object instanceof WP_Post && $this->is_post_exposable($object);
        }

        if ($query->is_category || $query->is_tag || $query->is_tax) {
            $taxonomy = $object instanceof WP_Term ? get_taxonomy($object->taxonomy) : false;

            return $taxonomy && $taxonomy->publicly_queryable && $this->is_page_in_range($query);
        }

        if ($query->is_author) {
            return $object instanceof WP_User
                && count_user_posts($object->ID, get_post_types(['public' => true]), true) > 0
                && $this->is_page_in_range($query);
        }

        if ($query->is_post_type_archive) {
            return $object instanceof WP_Post_Type
                && $object->public
                && (bool) $object->has_archive
                && $this->is_page_in_range($query);
        }

        if ($query->is_date) {
            return $query->have_posts() && $this->is_page_in_range($query);
        }

        return $query->is_home && $this->is_page_in_range($query);
    }

    /**
     * Checks that the requested pagination page exists.
     *
     * @param WP_Query $query Query.
     * @return bool
     */
    private function is_page_in_range(WP_Query $query): bool
    {
        $paged = (int) $query->get('paged');

        return $paged <= 1 || $paged <= (int) $query->max_num_pages;
    }
}
