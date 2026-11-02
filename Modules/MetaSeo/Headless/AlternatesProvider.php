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
        $entries = [];
        $default = '';

        if (!is_paged()) {
            if (function_exists('pll_languages_list')) {
                $default = (string) pll_default_language('slug');
                $entries = $this->polylang();
            } elseif (defined('ICL_SITEPRESS_VERSION')) {
                $default = (string) apply_filters('wpml_default_language', null);
                $entries = $this->wpml();
            }
        }

        $alternates = self::format($entries, $default);

        /**
         * Filters the language alternates of a headless payload.
         *
         * @param array<int, array{hreflang: string, url: string}> $alternates Alternates.
         */
        $filtered = apply_filters('seopulse_head_alternates', $alternates);

        return is_array($filtered) ? array_values($filtered) : [];
    }

    /**
     * Turns a language map into hreflang entries.
     *
     * @param array<string, array{locale: string, url: string}> $entries Entries keyed by language slug.
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
            $locale       = (string) ($entry['locale'] ?? '');
            $alternates[] = [
                'hreflang' => '' !== $locale ? str_replace('_', '-', $locale) : (string) $slug,
                'url'      => (string) $entry['url'],
            ];
        }

        if ('' !== $default && isset($entries[ $default ])) {
            $alternates[] = ['hreflang' => 'x-default', 'url' => (string) $entries[ $default ]['url']];
        }

        return $alternates;
    }

    /**
     * Collects translations through the Polylang API.
     *
     * @return array<string, array{locale: string, url: string}>
     */
    private function polylang(): array
    {
        $object  = get_queried_object();
        $entries = [];

        if ($object instanceof WP_Post && function_exists('pll_get_post_translations')) {
            foreach ((array) pll_get_post_translations($object->ID) as $slug => $translation_id) {
                $post = get_post((int) $translation_id);

                if (!$post instanceof WP_Post || !$this->resolver->is_post_exposable($post)) {
                    continue;
                }

                $url = get_permalink($post);

                if (is_string($url)) {
                    $entries[ (string) $slug ] = [
                        'locale' => (string) pll_get_post_language($post->ID, 'locale'),
                        'url'    => $url,
                    ];
                }
            }
        } elseif ($object instanceof WP_Term && function_exists('pll_get_term_translations')) {
            foreach ((array) pll_get_term_translations($object->term_id) as $slug => $translation_id) {
                $url = get_term_link((int) $translation_id, $object->taxonomy);

                if (is_string($url)) {
                    $entries[ (string) $slug ] = [
                        'locale' => (string) pll_get_term_language((int) $translation_id, 'locale'),
                        'url'    => $url,
                    ];
                }
            }
        } elseif (is_front_page() && function_exists('pll_home_url')) {
            $slugs   = (array) pll_languages_list(['fields' => 'slug']);
            $locales = (array) pll_languages_list(['fields' => 'locale']);

            foreach (array_values($slugs) as $index => $slug) {
                $entries[ (string) $slug ] = [
                    'locale' => (string) ($locales[ $index ] ?? ''),
                    'url'    => (string) pll_home_url((string) $slug),
                ];
            }
        }

        return $entries;
    }

    /**
     * Collects translations through the WPML API.
     *
     * @return array<string, array{locale: string, url: string}>
     */
    private function wpml(): array
    {
        $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

        if (!is_array($languages)) {
            return [];
        }

        $object  = get_queried_object();
        $entries = [];

        foreach ($languages as $code => $language) {
            $code   = (string) $code;
            $locale = is_array($language) ? (string) ($language['default_locale'] ?? '') : '';
            $url    = null;

            if ($object instanceof WP_Post) {
                $translation_id = apply_filters('wpml_object_id', $object->ID, $object->post_type, false, $code);
                $post           = $translation_id ? get_post((int) $translation_id) : null;

                if ($post instanceof WP_Post && $this->resolver->is_post_exposable($post)) {
                    $url = get_permalink($post);
                }
            } elseif ($object instanceof WP_Term) {
                $translation_id = apply_filters('wpml_object_id', $object->term_id, $object->taxonomy, false, $code);
                $link           = $translation_id ? get_term_link((int) $translation_id, $object->taxonomy) : null;
                $url            = is_string($link) ? $link : null;
            } elseif (is_front_page()) {
                $url = apply_filters('wpml_permalink', home_url('/'), $code);
            }

            if (is_string($url) && '' !== $url) {
                $entries[ $code ] = ['locale' => $locale, 'url' => $url];
            }
        }

        return $entries;
    }
}
