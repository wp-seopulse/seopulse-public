<?php

/**
 * Polylang / WPML adapter: languages, translations, language-aware URLs.
 *
 * @package SEOPulse\Modules\I18n
 * @since   1.9.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\I18n;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single entry point for everything that differs between Polylang and WPML.
 */
final class Multilingual
{
    public const POLYLANG = 'polylang';
    public const WPML     = 'wpml';

    /**
     * Active multilingual plugin.
     *
     * @return string "polylang", "wpml" or "" on a single-language site.
     */
    public static function provider(): string
    {
        if (function_exists('pll_languages_list')) {
            return self::POLYLANG;
        }

        if (defined('ICL_SITEPRESS_VERSION')) {
            return self::WPML;
        }

        return '';
    }

    /**
     * Whether at least two languages are configured.
     */
    public static function is_active(): bool
    {
        return count(self::languages()) > 1;
    }

    /**
     * Configured languages.
     *
     * @return array<string, array{locale: string, hreflang: string}> Keyed by language slug.
     */
    public static function languages(): array
    {
        $languages = [];

        if (self::POLYLANG === self::provider()) {
            $slugs   = array_values((array) pll_languages_list(['fields' => 'slug']));
            $locales = array_values((array) pll_languages_list(['fields' => 'locale']));

            foreach ($slugs as $index => $slug) {
                $locale                  = (string) ($locales[ $index ] ?? '');
                $languages[ (string) $slug ] = [
                    'locale'   => $locale,
                    'hreflang' => self::hreflang($locale, (string) $slug),
                ];
            }
        } elseif (self::WPML === self::provider()) {
            $active = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

            foreach (is_array($active) ? $active : [] as $code => $language) {
                $language = is_array($language) ? $language : [];
                $locale   = (string) ($language['default_locale'] ?? '');
                $tag      = (string) ($language['tag'] ?? '');

                $languages[ (string) $code ] = [
                    'locale'   => $locale,
                    'hreflang' => '' !== $tag ? $tag : self::hreflang($locale, (string) $code),
                ];
            }
        }

        return $languages;
    }

    /**
     * Default language slug.
     */
    public static function default_language(): string
    {
        if (self::POLYLANG === self::provider()) {
            return (string) pll_default_language('slug');
        }

        if (self::WPML === self::provider()) {
            return (string) apply_filters('wpml_default_language', null);
        }

        return '';
    }

    /**
     * Slug of the language being served (empty when unknown).
     */
    public static function current_language(): string
    {
        if (self::POLYLANG === self::provider() && function_exists('pll_current_language')) {
            return (string) pll_current_language('slug');
        }

        if (self::WPML === self::provider()) {
            return (string) apply_filters('wpml_current_language', null);
        }

        return '';
    }

    /**
     * Builds the hreflang value of a language.
     *
     * @param string $locale WordPress locale such as "fr_FR".
     * @param string $slug Fallback language slug.
     */
    public static function hreflang(string $locale, string $slug): string
    {
        return '' !== $locale ? str_replace('_', '-', $locale) : $slug;
    }

    /**
     * Translations of a post, the post itself included.
     *
     * @param int $post_id Post ID.
     * @return array<string, int> Post ID keyed by language slug.
     */
    public static function post_translations(int $post_id): array
    {
        $translations = [];

        if (self::POLYLANG === self::provider() && function_exists('pll_get_post_translations')) {
            foreach ((array) pll_get_post_translations($post_id) as $slug => $id) {
                $translations[ (string) $slug ] = (int) $id;
            }
        } elseif (self::WPML === self::provider()) {
            $type = (string) get_post_type($post_id);

            foreach (array_keys(self::languages()) as $code) {
                $id = apply_filters('wpml_object_id', $post_id, $type, false, $code);

                if ($id) {
                    $translations[ $code ] = (int) $id;
                }
            }
        }

        return $translations;
    }

    /**
     * Translations of a term, the term itself included.
     *
     * @param int $term_id Term ID.
     * @param string $taxonomy Taxonomy name.
     * @return array<string, int> Term ID keyed by language slug.
     */
    public static function term_translations(int $term_id, string $taxonomy): array
    {
        $translations = [];

        if (self::POLYLANG === self::provider() && function_exists('pll_get_term_translations')) {
            foreach ((array) pll_get_term_translations($term_id) as $slug => $id) {
                $translations[ (string) $slug ] = (int) $id;
            }
        } elseif (self::WPML === self::provider()) {
            foreach (array_keys(self::languages()) as $code) {
                $id = apply_filters('wpml_object_id', $term_id, $taxonomy, false, $code);

                if ($id) {
                    $translations[ $code ] = (int) $id;
                }
            }
        }

        return $translations;
    }

    /**
     * Language of a post.
     *
     * @param int $post_id Post ID.
     * @return string Slug, empty when the post has no language.
     */
    public static function post_language(int $post_id): string
    {
        if (self::POLYLANG === self::provider() && function_exists('pll_get_post_language')) {
            return (string) pll_get_post_language($post_id, 'slug');
        }

        if (self::WPML === self::provider()) {
            $details = apply_filters('wpml_post_language_details', null, $post_id);

            return is_array($details) ? (string) ($details['language_code'] ?? '') : '';
        }

        return '';
    }

    /**
     * Rewrites a URL so it points to the given language (WPML links depend on the current language).
     *
     * @param string $url Absolute URL.
     * @param string $lang Language slug.
     */
    public static function localize_url(string $url, string $lang): string
    {
        if (self::WPML === self::provider()) {
            $localized = apply_filters('wpml_permalink', $url, $lang);

            return is_string($localized) && '' !== $localized ? $localized : $url;
        }

        return $url;
    }

    /**
     * Home URL of a language.
     *
     * @param string $lang Language slug.
     */
    public static function home_url(string $lang): string
    {
        if (self::POLYLANG === self::provider() && function_exists('pll_home_url')) {
            return (string) pll_home_url($lang);
        }

        return self::localize_url(home_url('/'), $lang);
    }

    /**
     * Query arguments that restrict a get_posts()/WP_Query call to one language.
     *
     * With WPML the restriction only applies inside {@see self::with_language()}.
     *
     * @param string $lang Language slug.
     * @return array<string, mixed>
     */
    public static function query_args(string $lang): array
    {
        if (self::POLYLANG === self::provider()) {
            return ['lang' => $lang];
        }

        if (self::WPML === self::provider()) {
            return ['suppress_filters' => false];
        }

        return [];
    }

    /**
     * Runs a callback with the given language active, then restores the previous one.
     *
     * @param string $lang Language slug.
     * @param callable $callback Callback.
     * @return mixed Callback result.
     */
    public static function with_language(string $lang, callable $callback): mixed
    {
        if (self::WPML !== self::provider() || '' === $lang) {
            return $callback();
        }

        $previous = apply_filters('wpml_current_language', null);

        do_action('wpml_switch_language', $lang);

        try {
            return $callback();
        } finally {
            do_action('wpml_switch_language', $previous);
        }
    }

    /**
     * Splits a request path into its language prefix and the rest.
     *
     * Only languages served from a directory ("/fr/") have a prefix; the default
     * language served from the site root and per-domain languages return no prefix.
     *
     * @param string $path Request path with a leading slash, without query string.
     * @return array{0: string, 1: string} Path without the prefix, then the prefix ("" when none).
     */
    public static function split_path(string $path): array
    {
        if ('' === self::provider()) {
            return [$path, ''];
        }

        $root     = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $best     = '';
        $own_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

        foreach (array_keys(self::languages()) as $slug) {
            $url = self::home_url($slug);

            if ((string) wp_parse_url($url, PHP_URL_HOST) !== $own_host) {
                continue;
            }

            $prefix = rtrim((string) wp_parse_url($url, PHP_URL_PATH), '/');

            if ('' === $prefix || $prefix === $root || strlen($prefix) <= strlen($best)) {
                continue;
            }

            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $best = $prefix;
            }
        }

        if ('' === $best) {
            return [$path, ''];
        }

        $rest = $root . substr($path, strlen($best));

        return ['' === $rest ? '/' : $rest, substr($best, strlen($root))];
    }
}
