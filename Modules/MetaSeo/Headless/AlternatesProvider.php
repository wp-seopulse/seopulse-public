<?php
/**
 * Language alternates (hreflang) for headless payloads.
 *
 * @package SEOPulse\Modules\MetaSeo\Headless
 * @since   1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Headless;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\I18n\Multilingual;
use WP_Post;
use WP_Term;

/**
 * Lists the translations of the current page for Polylang and WPML.
 *
 * Other multilingual plugins can feed the list through the
 * "seopulse_head_alternates" filter.
 */
class AlternatesProvider
{
    /**
     * URL resolver, used to keep unpublished translations out.
     *
     * @var UrlResolver
     */
    private UrlResolver $resolver;

    /**
     * Constructor.
     *
     * @param UrlResolver|null $resolver Resolver override.
     */
    public function __construct(?UrlResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new UrlResolver();
    }

    /**
     * Lists the translations of the page described by the global main query.
     *
     * @return array<int, array{hreflang: string, url: string}> Empty when the site is not multilingual or the page has no translation.
     */
    public function for_current_query(): array
    {
        $entries = !is_paged() && Multilingual::is_active() ? $this->entries_for_queried_object() : [];

        $alternates = self::format($entries, Multilingual::default_language());

        /**
         * Filters the language alternates of a headless payload.
         *
         * @param array<int, array{hreflang: string, url: string}> $alternates Alternates.
         */
        $filtered = apply_filters('seopulse_head_alternates', $alternates);

        return is_array($filtered) ? array_values($filtered) : [];
    }

    /**
     * Lists the translations of a post, for sitemaps.
     *
     * @param WP_Post $post Post.
     * @return array<int, array{hreflang: string, url: string}> Empty when the site is not multilingual or the post has no translation.
     */
    public function for_post(WP_Post $post): array
    {
        if (!Multilingual::is_active()) {
            return [];
        }

        return self::format($this->post_entries($post), Multilingual::default_language());
    }

    /**
     * Lists the translations of a term, for sitemaps.
     *
     * @param WP_Term $term Term.
     * @return array<int, array{hreflang: string, url: string}> Empty when the site is not multilingual or the term has no translation.
     */
    public function for_term(WP_Term $term): array
    {
        if (!Multilingual::is_active()) {
            return [];
        }

        return self::format($this->term_entries($term), Multilingual::default_language());
    }

    /**
     * Turns a language map into hreflang entries.
     *
     * @param array<string, array{locale?: string, hreflang?: string, url: string}> $entries Entries keyed by language slug.
     * @param string $default Slug of the default language, used for "x-default".
     * @return array<int, array{hreflang: string, url: string}> Empty below two languages.
     */
    public static function format(array $entries, string $default): array
    {
        $entries = array_filter(
            $entries,
            static fn ($entry): bool => is_array($entry) && !empty($entry['url']),
        );

        if (count($entries) < 2) {
            return [];
        }

        $alternates = [];

        foreach ($entries as $slug => $entry) {
            $tag          = (string) ($entry['hreflang'] ?? '');
            $alternates[] = [
                'hreflang' => '' !== $tag ? $tag : Multilingual::hreflang((string) ($entry['locale'] ?? ''), (string) $slug),
                'url'      => (string) $entry['url'],
            ];
        }

        if ('' !== $default && isset($entries[ $default ])) {
            $alternates[] = ['hreflang' => 'x-default', 'url' => (string) $entries[ $default ]['url']];
        }

        return $alternates;
    }
    /**
     * Collects the translations of the queried object.
     *
     * @return array<string, array{hreflang: string, url: string}>
     */
    private function entries_for_queried_object(): array
    {
        $object = get_queried_object();

        if ($object instanceof WP_Post) {
            return $this->post_entries($object);
        }

        if ($object instanceof WP_Term) {
            return $this->term_entries($object);
        }

        if (!is_front_page()) {
            return [];
        }

        $entries = [];

        foreach (Multilingual::languages() as $slug => $language) {
            $entries[ $slug ] = ['hreflang' => $language['hreflang'], 'url' => Multilingual::home_url($slug)];
        }

        return $entries;
    }

    /**
     * Published, exposable translations of a post.
     *
     * @param WP_Post $post Post.
     * @return array<string, array{hreflang: string, url: string}>
     */
    private function post_entries(WP_Post $post): array
    {
        $languages = Multilingual::languages();
        $entries   = [];

        foreach (Multilingual::post_translations($post->ID) as $slug => $translation_id) {
            $translation = get_post($translation_id);

            if (!isset($languages[ $slug ]) || !$translation instanceof WP_Post || !$this->resolver->is_post_exposable($translation)) {
                continue;
            }

            $url = get_permalink($translation);

            if (is_string($url) && '' !== $url) {
                $entries[ $slug ] = ['hreflang' => $languages[ $slug ]['hreflang'], 'url' => $url];
            }
        }

        return $entries;
    }

    /**
     * Translations of a term.
     *
     * @param WP_Term $term Term.
     * @return array<string, array{hreflang: string, url: string}>
     */
    private function term_entries(WP_Term $term): array
    {
        $languages = Multilingual::languages();
        $entries   = [];

        foreach (Multilingual::term_translations($term->term_id, $term->taxonomy) as $slug => $translation_id) {
            $link = isset($languages[ $slug ]) ? get_term_link($translation_id, $term->taxonomy) : null;

            if (is_string($link) && '' !== $link) {
                $entries[ $slug ] = [
                    'hreflang' => $languages[ $slug ]['hreflang'],
                    'url'      => Multilingual::localize_url($link, $slug),
                ];
            }
        }

        return $entries;
    }
}
