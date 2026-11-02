<?php
/**
 * Headless head payload generator.
 *
 * @package SEOPulse\Modules\MetaSeo\Headless
 * @since   1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Headless;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;
use SEOPulse\Modules\I18n\LanguageDetector;
use SEOPulse\Modules\MetaSeo\BreadcrumbRenderer;
use SEOPulse\Modules\MetaSeo\Engine\MetaEngine;
use SEOPulse\Modules\MetaSeo\Engine\MetaOutput;
use SEOPulse\Modules\MetaSeo\Engine\Providers\SchemaFactory;
use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Builds the SEO "head" payload (title, robots, canonical, Open Graph, Twitter, JSON-LD).
 *
 * Single source of truth shared by the REST endpoint and the REST field. It
 * delegates to the existing MetaEngine, SchemaFactory providers and
 * BreadcrumbRenderer by presenting them a main query equivalent to the
 * requested page, so the output matches what the theme prints in <head>.
 */
final class HeadPayloadGenerator
{
    /**
     * Payload contract version, incremented on breaking changes.
     */
    public const VERSION = 1;
    /**
     * Meta engine, created on first use because it registers every variable provider.
     *
     * @var MetaEngine|null
     */
    private ?MetaEngine $engine = null;

    /**
     * Payload cache.
     *
     * @var HeadCache
     */
    private HeadCache $cache;

    /**
     * URL resolver.
     *
     * @var UrlResolver
     */
    private UrlResolver $resolver;

    /**
     * Frontend origin rewriter.
     *
     * @var FrontendUrl
     */
    private FrontendUrl $frontend;

    /**
     * Redirect lookup.
     *
     * @var RedirectLookup
     */
    private RedirectLookup $redirects;

    /**
     * Language alternates provider.
     *
     * @var AlternatesProvider
     */
    private AlternatesProvider $alternates;

    /**
     * Dimensions lookup for images outside the media library.
     *
     * @var ImageSize
     */
    private ImageSize $image_size;

    /**
     * Constructor.
     *
     * @param HeadCache|null $cache Cache override.
     * @param UrlResolver|null $resolver Resolver override.
     */
    public function __construct(?HeadCache $cache = null, ?UrlResolver $resolver = null)
    {
        $this->cache      = $cache ?? new HeadCache();
        $this->resolver   = $resolver ?? new UrlResolver();
        $this->frontend   = new FrontendUrl();
        $this->redirects  = new RedirectLookup();
        $this->alternates = new AlternatesProvider($this->resolver);
        $this->image_size = new ImageSize();
    }

    /**
     * Returns the payload for a public URL of this site.
     *
     * @param string $url Absolute URL or site-relative path.
     * @return array<string, mixed>|WP_Error Payload (or a redirect payload), or an error (400 foreign URL, 404 not public or unknown).
     */
    public function for_url(string $url): array|WP_Error
    {
        $normalized = $this->resolver->normalize($url);

        if (null === $normalized) {
            return new WP_Error(
                'seopulse_head_invalid_url',
                __('The URL does not belong to this site.', 'seopulse'),
                ['status' => 400],
            );
        }

        $key = 'url:' . $normalized;

        $miss = $this->cache->get_miss($key);

        if (null !== $miss) {
            return $this->unresolved($miss['redirect']);
        }

        $cached = $this->cache->get($key);

        if (null !== $cached) {
            return $this->with_tags($this->frontend->rewrite($cached));
        }

        // Captured before generating so a concurrent change invalidates the stored entry.
        $content_dependency = $this->cache->content_dependency();
        $dependency         = $content_dependency;
        $query              = $this->resolver->resolve($normalized);

        if (null === $query) {
            $redirect = $this->redirects->find($normalized);
            $this->cache->set_miss($key, $redirect);

            return $this->unresolved($redirect);
        }

        if ($query->is_singular && $query->post instanceof WP_Post) {
            $dependency = $this->cache->post_dependency($query->post->ID);
        }

        $payload = $this->generate($query);
        $this->cache->set($key, $payload, $this->dependency_for($payload, $dependency, $content_dependency));

        return $this->with_tags($this->frontend->rewrite($payload));
    }

    /**
     * Adds the ready-to-render tags, built from the payload as the frontend will receive it.
     *
     * Tags are not cached: they are derived after the origin rewrite so JSON-LD URLs are rewritten too.
     *
     * @param array<string, mixed> $payload Rewritten payload.
     * @return array<string, mixed>
     */
    private function with_tags(array $payload): array
    {
        $payload['tags'] = HeadTags::build($payload);

        return $payload;
    }

    /**
     * Answers for a URL that has no public content.
     *
     * @param array{to: string|null, status: int}|null $redirect Redirect configured for the URL.
     * @return array<string, mixed>|WP_Error Redirect payload, or the 404 error.
     */
    private function unresolved(?array $redirect): array|WP_Error
    {
        if (null === $redirect) {
            return HeadSettings::not_found_head() ? $this->not_found_head() : $this->not_found();
        }

        return $this->frontend->rewrite([
            'version'  => self::VERSION,
            'redirect' => [
                'to'     => $redirect['to'],
                'status' => $redirect['status'],
            ],
        ]);
    }

    /**
     * Picks the cache dependency of a payload.
     *
     * Translations are linked from several posts, so a payload that lists them is
     * invalidated by any content change rather than by its own post only.
     *
     * @param array<string, mixed> $payload Generated payload.
     * @param array<string, mixed> $own Dependency on the page itself.
     * @param array<string, mixed> $content Dependency on all content.
     * @return array<string, mixed>
     */
    private function dependency_for(array $payload, array $own, array $content): array
    {
        return empty($payload['alternates']) ? $own : $content;
    }

    /**
     * Builds the head of the site's 404 page, served with status 404 instead of an error.
     *
     * One shared entry: it does not depend on the requested URL.
     *
     * @return array<string, mixed>
     */
    private function not_found_head(): array
    {
        $key     = 'not-found';
        $payload = $this->cache->get($key);

        if (null === $payload) {
            $dependency = $this->cache->content_dependency();
            $payload    = self::as_not_found($this->generate($this->resolver->not_found_query()));
            $this->cache->set($key, $payload, $dependency);
        }

        return $this->with_tags($this->frontend->rewrite($payload));
    }

    /**
     * Forces the fields of a 404 head: noindex, no canonical, no pagination or translations.
     *
     * @param array<string, mixed> $payload Payload generated for the 404 query.
     * @return array<string, mixed>
     */
    public static function as_not_found(array $payload): array
    {
        $payload['status']      = 404;
        $payload['robots']      = 'noindex, follow';
        $payload['canonical']   = '';
        $payload['og']['url']   = '';
        $payload['article']     = null;
        $payload['pagination']  = ['prev' => null, 'next' => null];
        $payload['breadcrumbs'] = [];
        $payload['alternates']  = [];

        return $payload;
    }

    /**
     * Builds the 404 error.
     *
     * @return WP_Error
     */
    private function not_found(): WP_Error
    {
        return new WP_Error(
            'seopulse_head_not_found',
            __('No public content was found for this URL.', 'seopulse'),
            ['status' => 404],
        );
    }

    /**
     * Tells whether a post may be exposed to anonymous visitors.
     *
     * @param WP_Post $post Post.
     * @return bool
     */
    public function is_exposable(WP_Post $post): bool
    {
        return $this->resolver->is_post_exposable($post);
    }

    /**
     * Returns the cached payload of a post without generating anything.
     *
     * @param WP_Post $post Post.
     * @return array<string, mixed>|null Null when the post is not public or not cached.
     */
    public function cached_post(WP_Post $post): ?array
    {
        if (!$this->resolver->is_post_exposable($post)) {
            return null;
        }

        $cached = $this->cache->get('post:' . $post->ID);

        return null === $cached ? null : $this->frontend->rewrite($cached);
    }

    /**
     * Returns the payload for a post.
     *
     * @param WP_Post $post Post.
     * @return array<string, mixed>|null Null when the post is not public (draft, private, password protected...).
     */
    public function for_post(WP_Post $post): ?array
    {
        if (!$this->resolver->is_post_exposable($post)) {
            return null;
        }

        $key    = 'post:' . $post->ID;
        $cached = $this->cache->get($key);

        if (null !== $cached) {
            return $this->frontend->rewrite($cached);
        }

        $dependency         = $this->cache->post_dependency($post->ID);
        $content_dependency = $this->cache->content_dependency();
        $query              = $this->resolver->for_post($post);

        if (null === $query) {
            return null;
        }

        $payload = $this->generate($query);
        $this->cache->set($key, $payload, $this->dependency_for($payload, $dependency, $content_dependency));

        return $this->frontend->rewrite($payload);
    }

    /**
     * Generates a payload while the given query is the global main query.
     *
     * @param WP_Query $query Query describing the page.
     * @return array<string, mixed>
     */
    private function generate(WP_Query $query): array
    {
        // The main query is swapped for the duration of the call and restored in "finally".
        // phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
        $previous_query     = $GLOBALS['wp_query'] ?? null;
        $previous_the_query = $GLOBALS['wp_the_query'] ?? null;
        $previous_post      = $GLOBALS['post'] ?? null;

        $current_post = $query->is_singular && $query->post instanceof WP_Post ? $query->post : null;

        $GLOBALS['wp_query']     = $query;
        $GLOBALS['wp_the_query'] = $query;
        $GLOBALS['post']         = $current_post;

        if ($current_post instanceof WP_Post) {
            setup_postdata($current_post);
        }

        try {
            return $this->build();
        } finally {
            $GLOBALS['wp_query']     = $previous_query;
            $GLOBALS['wp_the_query'] = $previous_the_query;
            $GLOBALS['post']         = $previous_post;

            if ($previous_post instanceof WP_Post) {
                setup_postdata($previous_post);
            } else {
                wp_reset_postdata();
            }
        }
        // phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
    }

    /**
     * Builds the payload for the current global query.
     *
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $meta      = $this->engine()->resolveAll();
        $canonical = $this->to_url($meta->canonical);
        $image     = $this->image($meta->ogImage);
        $object    = get_queried_object();
        $post      = $object instanceof WP_Post && is_singular() ? $object : null;

        return [
            'version'     => self::VERSION,
            'title'       => $this->to_text($meta->title),
            'description' => $this->to_text($meta->description),
            'robots'      => $this->to_text($meta->robots),
            'canonical'   => $canonical,
            'og'          => [
                'title'       => $this->to_text($meta->ogTitle),
                'description' => $this->to_text($meta->ogDescription),
                'url'         => $canonical,
                'site_name'   => $this->to_text(get_bloginfo('name')),
                'type'        => $this->to_text($meta->ogType),
                'locale'      => $this->locale($post),
                'image'       => $image,
            ],
            'twitter'     => $this->twitter($meta, $post),
            'article'     => $this->article($meta, $post),
            'pagination'  => $this->pagination($canonical),
            'breadcrumbs' => $this->breadcrumbs(),
            'alternates'  => $this->alternates->for_current_query(),
            'schema'      => [
                '@context' => 'https://schema.org',
                '@graph'   => $this->schema_graph(),
            ],
        ];
    }

    /**
     * Resolves the Open Graph locale.
     *
     * On multilingual sites the language of the page wins over the single global value.
     *
     * @param WP_Post|null $post Current post, if any.
     * @return string|null Locale such as "fr_FR".
     */
    private function locale(?WP_Post $post): ?string
    {
        $settings = get_option(Options::META_SEO_GLOBAL, []);
        $setting  = is_array($settings) ? trim((string) ($settings['og_locale'] ?? '')) : '';

        if ('' !== $setting && !LanguageDetector::is_multilingual()) {
            return $setting;
        }

        $locale = LanguageDetector::locale($post instanceof WP_Post ? $post->ID : null);

        return '' === $locale ? null : $locale;
    }

    /**
     * Builds the Open Graph article block.
     *
     * @param MetaOutput $meta Resolved meta.
     * @param WP_Post|null $post Current post, if any.
     * @return array{published_time: string|null, modified_time: string|null, author: string|null}|null Null outside articles.
     */
    private function article(MetaOutput $meta, ?WP_Post $post): ?array
    {
        if (!$post instanceof WP_Post || ('post' !== $post->post_type && 'article' !== $meta->ogType)) {
            return null;
        }

        $published = (string) get_post_time('c', true, $post);
        $modified  = (string) get_post_modified_time('c', true, $post);
        $author    = (string) get_author_posts_url((int) $post->post_author);

        return [
            'published_time' => '' === $published ? null : $published,
            'modified_time'  => '' === $modified ? null : $modified,
            'author'         => '' === $author ? null : esc_url_raw($author),
        ];
    }

    /**
     * Builds the previous/next links of a paginated archive.
     *
     * @param string $canonical Canonical URL of the page.
     * @return array{prev: string|null, next: string|null}
     */
    private function pagination(string $canonical): array
    {
        global $wp_query, $wp_rewrite;

        if (!$wp_query instanceof WP_Query || $wp_query->is_singular()) {
            return ['prev' => null, 'next' => null];
        }

        return self::pagination_links(
            $canonical,
            max(1, (int) $wp_query->get('paged')),
            (int) $wp_query->max_num_pages,
            (string) ($wp_rewrite->pagination_base ?? 'page'),
        );
    }

    /**
     * Computes the previous/next URLs from a pretty-permalink canonical URL.
     *
     * @param string $canonical Canonical URL, with or without a /page/N/ suffix.
     * @param int $paged Current page number.
     * @param int $max_pages Number of pages.
     * @param string $base Pagination base ("page").
     * @return array{prev: string|null, next: string|null}
     */
    public static function pagination_links(string $canonical, int $paged, int $max_pages, string $base): array
    {
        $none = ['prev' => null, 'next' => null];

        if ('' === $canonical || str_contains($canonical, '?') || $max_pages < 2) {
            return $none;
        }

        $root = trailingslashit((string) preg_replace('#/' . preg_quote($base, '#') . '/\d+/?$#', '', $canonical));
        $link = static fn (int $page): string => 1 === $page ? $root : $root . $base . '/' . $page . '/';

        return [
            'prev' => $paged > 1 ? $link($paged - 1) : null,
            'next' => $paged < $max_pages ? $link($paged + 1) : null,
        ];
    }

    /**
     * Reads a social handle, the post value overriding the global one like the theme output does.
     *
     * @param string $field Setting key.
     * @param WP_Post|null $post Current post, if any.
     * @return string|null
     */
    private function social_handle(string $field, ?WP_Post $post): ?string
    {
        $value = '';

        if ($post instanceof WP_Post) {
            $post_meta = get_post_meta($post->ID, '_seopulse_meta_seo', true);
            $value     = is_array($post_meta) ? trim((string) ($post_meta[ $field ] ?? '')) : '';
        }

        if ('' === $value) {
            $settings = get_option(Options::META_SEO_GLOBAL, []);
            $value    = is_array($settings) ? trim((string) ($settings[ $field ] ?? '')) : '';
        }

        return '' === $value ? null : $this->to_text($value);
    }

    /**
     * Builds the Twitter Card block.
     *
     * @param MetaOutput $meta Resolved meta.
     * @param WP_Post|null $post Current post, if any.
     * @return array<string, string|null>
     */
    private function twitter(MetaOutput $meta, ?WP_Post $post): array
    {
        $image = $this->to_url($meta->twitterImage);

        return [
            'card'        => $this->to_text($meta->twitterCard),
            'site'        => $this->social_handle('twitter_site', $post),
            'creator'     => $this->social_handle('twitter_creator', $post),
            'title'       => $this->to_text($meta->twitterTitle),
            'description' => $this->to_text($meta->twitterDescription),
            'image'       => '' === $image ? null : $image,
        ];
    }

    /**
     * Describes an Open Graph image.
     *
     * @param string $raw_url Image URL as resolved by the engine.
     * @return array{url: string, width: int|null, height: int|null, alt: string}|null
     */
    private function image(string $raw_url): ?array
    {
        $url = $this->to_url($raw_url);

        if ('' === $url) {
            return null;
        }

        $image         = [
            'url'    => $url,
            'width'  => null,
            'height' => null,
            'alt'    => '',
        ];
        $attachment_id = attachment_url_to_postid($url);

        if ($attachment_id > 0) {
            $size            = $this->attachment_size($attachment_id, $url);
            $image['width']  = $size[0];
            $image['height'] = $size[1];
            $image['alt']    = $this->to_text((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
        } else {
            $size            = $this->image_size->lookup($url);
            $image['width']  = $size[0];
            $image['height'] = $size[1];
        }

        return $image;
    }

    /**
     * Finds the dimensions of the attachment file that matches a URL.
     *
     * @param int $attachment_id Attachment ID.
     * @param string $url Image URL (original file or a generated size).
     * @return array{0: int|null, 1: int|null} Width and height.
     */
    private function attachment_size(int $attachment_id, string $url): array
    {
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (!is_array($metadata)) {
            return [null, null];
        }

        $file = wp_basename((string) wp_parse_url($url, PHP_URL_PATH));

        if (isset($metadata['file']) && wp_basename((string) $metadata['file']) === $file) {
            return [
                ((int) ($metadata['width'] ?? 0)) ? (int) $metadata['width'] : null,
                ((int) ($metadata['height'] ?? 0)) ? (int) $metadata['height'] : null,
            ];
        }

        foreach ((array) ($metadata['sizes'] ?? []) as $size) {
            if (is_array($size) && ($size['file'] ?? '') === $file) {
                return [
                    ((int) ($size['width'] ?? 0)) ? (int) $size['width'] : null,
                    ((int) ($size['height'] ?? 0)) ? (int) $size['height'] : null,
                ];
            }
        }

        return [null, null];
    }

    /**
     * Builds the breadcrumb trail as a plain list.
     *
     * @return array<int, array{name: string, url: string}> Empty when breadcrumbs are disabled or the trail has fewer than two items.
     */
    private function breadcrumbs(): array
    {
        if (!BreadcrumbRenderer::is_enabled()) {
            return [];
        }

        $trail = [];

        foreach ((new BreadcrumbRenderer())->build_trail() as $crumb) {
            $url = $this->to_url((string) $crumb['url']);

            if ('' !== $url) {
                $trail[] = ['name' => $this->to_text((string) $crumb['name']), 'url' => $url];
            }
        }

        return count($trail) < 2 ? [] : $trail;
    }

    /**
     * Assembles the JSON-LD @graph from the existing schema providers.
     *
     * @return array<int, array<string, mixed>>
     */
    private function schema_graph(): array
    {
        $schemas = [];

        $website = SchemaFactory::get('website');

        if (null !== $website && $this->is_schema_enabled('schema_website_enabled') && $website->validate()) {
            $schemas[] = $website->build();
        }

        foreach (SchemaFactory::build_all() as $schema) {
            $schemas[] = $schema;
        }

        // Schema-block FAQPage/HowTo nodes are printed inside the content on the classic frontend; headless has no content, so add them here.
        global $post;

        if (is_singular() && $post instanceof WP_Post) {
            foreach (\SEOPulse\Modules\MetaSeo\Engine\Providers\SchemaBlockDetector::nodes((string) $post->post_content, ['FAQPage', 'HowTo']) as $node) {
                $schemas[] = $node;
            }
        }

        $breadcrumb = BreadcrumbRenderer::is_enabled() ? (new BreadcrumbRenderer())->build_json_ld() : [];

        if (!empty($breadcrumb)) {
            $schemas[] = $breadcrumb;
        }

        // Same Organization/LocalBusiness node the classic frontend prints in wp_head
        $local = \SEOPulse\Modules\LocalSeo\LocalSeoRenderer::get_node();

        if ($local !== null) {
            $schemas[] = $local;
        }

        $graph = [];
        $seen  = [];

        foreach ($schemas as $schema) {
            $nodes = isset($schema['@graph']) && is_array($schema['@graph']) ? $schema['@graph'] : [$schema];

            foreach ($nodes as $node) {
                unset($node['@context']);

                $id = isset($node['@id']) && is_string($node['@id']) ? $node['@id'] : null;

                if (null !== $id) {
                    if (isset($seen[ $id ])) {
                        continue;
                    }

                    $seen[ $id ] = true;
                }

                $graph[] = $node;
            }
        }

        return $graph;
    }

    /**
     * Reads a schema toggle from the global Meta SEO settings (enabled by default).
     *
     * @param string $setting Setting key.
     * @return bool
     */
    private function is_schema_enabled(string $setting): bool
    {
        $settings = get_option(Options::META_SEO_GLOBAL, []);

        return !is_array($settings) || !isset($settings[ $setting ]) || (bool) $settings[ $setting ];
    }

    /**
     * Decodes the HTML entities the engine adds when escaping, for JSON consumers.
     *
     * @param string $value Escaped text.
     * @return string
     */
    private function to_text(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Decodes and re-validates a URL escaped for HTML output.
     *
     * @param string $value Escaped URL.
     * @return string
     */
    private function to_url(string $value): string
    {
        return esc_url_raw(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Returns the meta engine.
     *
     * @return MetaEngine
     */
    private function engine(): MetaEngine
    {
        if (null === $this->engine) {
            $this->engine = new MetaEngine();
        }

        return $this->engine;
    }
}
