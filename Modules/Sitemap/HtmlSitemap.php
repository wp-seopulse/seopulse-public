<?php

/**
 * HTML Sitemap: [seopulse_html_sitemap] shortcode and seopulse/html-sitemap block.
 *
 * @package SEOPulse\Modules\Sitemap
 * @since 1.1.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Sitemap;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Cache\ObjectCache;
use WP_Post;
use WP_Term;

/**
 * Renders a crawlable HTML sitemap, cached per argument set and invalidated by a version counter.
 */
final class HtmlSitemap
{
    public const SHORTCODE = 'seopulse_html_sitemap';

    private const BLOCK_NAME      = 'seopulse/html-sitemap';
    private const CACHE_GROUP     = 'seopulse_html_sitemap';
    private const VERSION_OPTION  = 'seopulse_html_sitemap_version';
    private const CACHE_TTL       = 12 * HOUR_IN_SECONDS;
    private const MAX_LIMIT       = 5000;
    private const POST_META_SEO   = '_seopulse_meta_seo';
    private const TERM_META_SEO   = '_seopulse_term_meta_seo';
    private const META_EXCLUDE    = '_seopulse_exclude_sitemap';

    /**
     * Registers the shortcode, the block and the cache invalidation hooks.
     */
    public function init(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'renderShortcode']);
        add_action('init', [$this, 'registerBlock']);

        add_action('save_post', [$this, 'onPostChange']);
        add_action('deleted_post', [$this, 'onPostChange']);
        add_action('transition_post_status', [$this, 'invalidate'], 10, 0);
        add_action('created_term', [$this, 'invalidate'], 10, 0);
        add_action('edited_term', [$this, 'invalidate'], 10, 0);
        add_action('delete_term', [$this, 'invalidate'], 10, 0);
        add_action('update_option_seopulse_sitemap_settings', [$this, 'invalidate'], 10, 0);
    }

    /**
     * Registers the dynamic block from its block.json.
     */
    public function registerBlock(): void
    {
        $json = SEOPULSE_PLUGIN_DIR . 'assets/src/blocks/html-sitemap/block.json';

        if (!file_exists($json) || \WP_Block_Type_Registry::get_instance()->is_registered(self::BLOCK_NAME)) {
            return;
        }

        register_block_type($json, ['render_callback' => [$this, 'renderBlock']]);
    }

    /**
     * @param array<string, mixed>|string $atts Shortcode attributes.
     */
    public function renderShortcode($atts): string
    {
        return $this->render(is_array($atts) ? $atts : []);
    }

    /**
     * @param array<string, mixed> $attributes Block attributes (camelCase).
     */
    public function renderBlock(array $attributes): string
    {
        return $this->render(
            [
                'post_types'         => $attributes['postTypes'] ?? '',
                'exclude_post_types' => $attributes['excludePostTypes'] ?? '',
                'taxonomies'         => $attributes['taxonomies'] ?? 'category',
                'exclude_terms'      => $attributes['excludeTerms'] ?? '',
                'exclude_ids'        => $attributes['excludeIds'] ?? '',
                'show_date'          => !empty($attributes['showDate']) ? '1' : '0',
                'limit'              => $attributes['limit'] ?? 500,
            ],
        );
    }

    /**
     * Renders the sitemap, served from cache when possible.
     *
     * @param array<string, mixed> $atts Raw attributes.
     */
    public function render(array $atts): string
    {
        $args = $this->normalize($atts);
        $key  = 'seopulse_hs_' . md5((string) wp_json_encode([$this->version(), get_locale(), $args]));

        $cached = $this->cacheGet($key);

        if ($cached !== null) {
            return $cached;
        }

        $html = $this->build($args);
        $this->cacheSet($key, $html);

        return $html;
    }

    /**
     * Bumps the cache version so every stored sitemap becomes unreachable.
     */
    public function invalidate(): void
    {
        update_option(self::VERSION_OPTION, $this->version() + 1, false);
    }

    /**
     * @param int|WP_Post $post_id Post ID.
     */
    public function onPostChange($post_id): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $this->invalidate();
    }

    /**
     * @param array<string, mixed> $atts Raw attributes.
     * @return array{post_types: string[], taxonomies: string[], exclude_terms: array<string, int[]>, exclude_ids: int[], show_date: bool, limit: int}
     */
    private function normalize(array $atts): array
    {
        $atts = shortcode_atts(
            [
                'post_types'         => '',
                'exclude_post_types' => '',
                'taxonomies'         => 'category',
                'exclude_terms'      => '',
                'exclude_ids'        => '',
                'show_date'          => '0',
                'limit'              => 500,
            ],
            $atts,
            self::SHORTCODE,
        );

        $requested = array_map('sanitize_key', $this->csv((string) $atts['post_types']));
        $excluded  = array_map('sanitize_key', $this->csv((string) $atts['exclude_post_types']));

        $post_types = $requested !== [] ? $requested : $this->defaultPostTypes();
        $post_types = array_values(
            array_filter(
                array_diff($post_types, $excluded),
                static function (string $pt): bool {
                    $object = get_post_type_object($pt);

                    return $object !== null && $object->public && $pt !== 'attachment';
                },
            ),
        );
        sort($post_types);

        $taxonomies = array_values(
            array_filter(
                array_map('sanitize_key', $this->csv((string) $atts['taxonomies'])),
                static function (string $tax): bool {
                    $object = get_taxonomy($tax);

                    return $object !== false && $object->public;
                },
            ),
        );
        sort($taxonomies);

        $ids = array_values(array_unique(array_filter(array_map('absint', $this->csv((string) $atts['exclude_ids'])))));
        sort($ids);

        return [
            'post_types'    => $post_types,
            'taxonomies'    => $taxonomies,
            'exclude_terms' => $this->resolveExcludedTerms($this->csv((string) $atts['exclude_terms'])),
            'exclude_ids'   => $ids,
            'show_date'     => filter_var($atts['show_date'], FILTER_VALIDATE_BOOLEAN),
            'limit'         => max(1, min(self::MAX_LIMIT, absint($atts['limit']))),
        ];
    }

    /**
     * Resolves term IDs or slugs into IDs grouped by taxonomy.
     *
     * @param string[] $tokens
     * @return array<string, int[]>
     */
    private function resolveExcludedTerms(array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        $ids   = [];
        $slugs = [];

        foreach ($tokens as $token) {
            if (ctype_digit($token)) {
                $ids[] = (int) $token;
            } else {
                $slugs[] = sanitize_title($token);
            }
        }

        $base  = [
            'taxonomy'   => array_values(get_taxonomies(['public' => true])),
            'hide_empty' => false,
        ];
        $terms = [];

        if ($ids !== []) {
            $terms = array_merge($terms, (array) get_terms($base + ['include' => $ids]));
        }

        if ($slugs !== []) {
            $terms = array_merge($terms, (array) get_terms($base + ['slug' => $slugs]));
        }

        $grouped = [];

        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $grouped[ $term->taxonomy ][] = $term->term_id;
            }
        }

        foreach ($grouped as $taxonomy => $term_ids) {
            $term_ids = array_values(array_unique($term_ids));
            sort($term_ids);
            $grouped[ $taxonomy ] = $term_ids;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Post types enabled in the XML sitemap settings (post and page when unset).
     *
     * @return string[]
     */
    private function defaultPostTypes(): array
    {
        $options = get_option('seopulse_sitemap_settings', []);
        $options = is_array($options) ? $options : [];

        return array_values(
            array_filter(
                get_post_types(['public' => true], 'names'),
                static fn (string $pt): bool => isset($options[ "enable_{$pt}" ])
                    ? !empty($options[ "enable_{$pt}" ])
                    : in_array($pt, ['post', 'page'], true),
            ),
        );
    }

    /**
     * @param array<string, mixed> $args Normalized arguments.
     */
    private function build(array $args): string
    {
        $groups = '';

        foreach ($args['post_types'] as $post_type) {
            $groups .= $this->buildPostGroup($post_type, $args);
        }

        foreach ($args['taxonomies'] as $taxonomy) {
            $groups .= $this->buildTermGroup($taxonomy, $args);
        }

        if ($groups === '') {
            return '';
        }

        return '<div class="seopulse-html-sitemap">' . $groups . '</div>';
    }

    /**
     * @param array<string, mixed> $args Normalized arguments.
     */
    private function buildPostGroup(string $post_type, array $args): string
    {
        $query = [
            'post_type'              => $post_type,
            'post_status'            => 'publish',
            'has_password'           => false,
            'posts_per_page'         => $args['limit'],
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'post__not_in'           => array_merge($args['exclude_ids'], \SEOPulse\Services\WooCommerceSystemPages::ids()),
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'suppress_filters'       => false,
        ];

        $tax_query = [];

        foreach ($args['exclude_terms'] as $taxonomy => $term_ids) {
            if (is_object_in_taxonomy($post_type, $taxonomy)) {
                $tax_query[] = [
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $term_ids,
                    'operator' => 'NOT IN',
                ];
            }
        }

        if ($tax_query !== []) {
            $query['tax_query'] = ['relation' => 'AND', ...$tax_query];
        }

        $items = '';

        foreach (get_posts($query) as $post) {
            if (!$post instanceof WP_Post || $this->isPostHidden($post)) {
                continue;
            }

            $date = '';

            if ($args['show_date']) {
                $date = sprintf(
                    ' <time datetime="%s">%s</time>',
                    esc_attr((string) get_the_modified_date('c', $post)),
                    esc_html((string) get_the_modified_date('', $post)),
                );
            }

            $items .= sprintf(
                '<li><a href="%s">%s</a>%s</li>',
                esc_url((string) get_permalink($post)),
                esc_html(wp_strip_all_tags(get_the_title($post))),
                $date,
            );
        }

        $object = get_post_type_object($post_type);

        return $this->group($post_type, $object !== null ? $object->labels->name : $post_type, $items);
    }

    /**
     * @param array<string, mixed> $args Normalized arguments.
     */
    private function buildTermGroup(string $taxonomy, array $args): string
    {
        $terms = get_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'orderby'    => 'name',
                'number'     => $args['limit'],
                'exclude'    => $args['exclude_terms'][ $taxonomy ] ?? [],
            ],
        );

        $items = '';

        foreach (is_array($terms) ? $terms : [] as $term) {
            if (!$term instanceof WP_Term || $this->isTermNoindex($term)) {
                continue;
            }

            $link = get_term_link($term);

            if (is_wp_error($link)) {
                continue;
            }

            $items .= sprintf('<li><a href="%s">%s</a></li>', esc_url($link), esc_html($term->name));
        }

        $object = get_taxonomy($taxonomy);

        return $this->group($taxonomy, $object !== false ? $object->labels->name : $taxonomy, $items);
    }

    private function group(string $slug, string $label, string $items): string
    {
        if ($items === '') {
            return '';
        }

        return sprintf(
            '<section class="seopulse-html-sitemap__group seopulse-html-sitemap__group--%1$s"><h2 class="seopulse-html-sitemap__title">%2$s</h2><ul>%3$s</ul></section>',
            esc_attr($slug),
            esc_html($label),
            $items,
        );
    }

    private function isPostHidden(WP_Post $post): bool
    {
        if (get_post_meta($post->ID, self::META_EXCLUDE, true)) {
            return true;
        }

        return $this->hasNoindex(get_post_meta($post->ID, self::POST_META_SEO, true));
    }

    private function isTermNoindex(WP_Term $term): bool
    {
        return $this->hasNoindex(get_term_meta($term->term_id, self::TERM_META_SEO, true));
    }

    /**
     * @param mixed $meta Stored SEO meta array.
     */
    private function hasNoindex($meta): bool
    {
        if (!is_array($meta) || empty($meta['robots']) || !is_string($meta['robots'])) {
            return false;
        }

        return in_array('noindex', array_map('trim', explode(',', $meta['robots'])), true);
    }

    /**
     * @return string[]
     */
    private function csv(string $value): array
    {
        return array_values(
            array_unique(
                array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''),
            ),
        );
    }

    private function version(): int
    {
        return max(1, (int) get_option(self::VERSION_OPTION, 1));
    }

    private function cacheGet(string $key): ?string
    {
        $value = ObjectCache::get($key, self::CACHE_GROUP);

        return is_string($value) ? $value : null;
    }

    private function cacheSet(string $key, string $html): void
    {
        ObjectCache::set($key, $html, self::CACHE_GROUP, self::CACHE_TTL);
    }
}
