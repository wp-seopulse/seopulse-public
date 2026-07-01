<?php

/**
 * Provider-agnostic language detection for Polylang, WPML, and fallback
 *
 * Centralizes language resolution so every part of the plugin
 * (schema, focus keywords, meta templates, analysis) uses the
 * same detection logic and priority order.
 *
 * @package SEOPulse\Modules\I18n
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\I18n;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LanguageDetector — static helper (no hooks, no state)
 */
final class LanguageDetector
{
    /**
     * Detects the current front-end / admin language.
     *
     * Priority: Polylang → WPML → WordPress locale.
     *
     * @return string Language slug (e.g. "fr", "en", "de")
     */
    public static function current(): string
    {
        // Polylang
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language('slug');
            if (is_string($lang) && $lang !== '') {
                return $lang;
            }
        }

        // WPML
        if (defined('ICL_LANGUAGE_CODE') && is_string(ICL_LANGUAGE_CODE) && ICL_LANGUAGE_CODE !== '') {
            return ICL_LANGUAGE_CODE;
        }

        return self::site_language();
    }

    /**
     * Detects the language of a specific post.
     *
     * @param int $post_id Post ID
     * @return string Language slug
     */
    public static function for_post(int $post_id): string
    {
        // Polylang
        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id, 'slug');
            if (is_string($lang) && $lang !== '') {
                return $lang;
            }
        }

        // WPML
        if (function_exists('wpml_get_language_information')) {
            $info = wpml_get_language_information(null, $post_id);
            if (is_array($info) && !empty($info['language_code'])) {
                return (string) $info['language_code'];
            }
        }

        return self::site_language();
    }

    /**
     * Returns the full locale for schema `inLanguage` (e.g. "fr_FR").
     *
     * @param int|null $post_id Optionally scope to a specific post
     * @return string Locale string
     */
    public static function locale(?int $post_id = null): string
    {
        if ($post_id !== null) {
            // Polylang locale
            if (function_exists('pll_get_post_language')) {
                $locale = pll_get_post_language($post_id, 'locale');
                if (is_string($locale) && $locale !== '') {
                    return str_replace('-', '_', $locale);
                }
            }

            // WPML locale
            if (function_exists('wpml_get_language_information')) {
                $info = wpml_get_language_information(null, $post_id);
                if (is_array($info) && !empty($info['locale'])) {
                    return str_replace('-', '_', (string) $info['locale']);
                }
            }
        }

        // Current context locale via Polylang
        if (function_exists('pll_current_language')) {
            $locale = pll_current_language('locale');
            if (is_string($locale) && $locale !== '') {
                return str_replace('-', '_', $locale);
            }
        }

        // Current context locale via WPML
        global $sitepress;
        if (isset($sitepress) && method_exists($sitepress, 'get_locale')) {
            $locale = $sitepress->get_locale(defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : '');
            if (is_string($locale) && $locale !== '') {
                return str_replace('-', '_', $locale);
            }
        }

        $lang = get_bloginfo('language');

        return !empty($lang) ? str_replace('-', '_', $lang) : 'en';
    }

    /**
     * Returns true if a multilingual plugin is active.
     *
     * @return bool
     */
    public static function is_multilingual(): bool
    {
        return function_exists('pll_current_language')
            || (defined('ICL_SITEPRESS_VERSION') && defined('ICL_LANGUAGE_CODE'));
    }

    /**
     * Returns the list of active languages (slugs).
     *
     * @return array<string> e.g. ["fr", "en", "de"]
     */
    public static function active_languages(): array
    {
        // Polylang
        if (function_exists('pll_languages_list')) {
            $list = pll_languages_list(['fields' => 'slug']);
            if (is_array($list) && !empty($list)) {
                return $list;
            }
        }

        // WPML
        if (function_exists('wpml_active_languages')) {
            $langs = wpml_active_languages('', ['skip_missing' => 0]);
            if (is_array($langs) && !empty($langs)) {
                return array_keys($langs);
            }
        }

        return [self::site_language()];
    }

    /**
     * Returns the default site language slug.
     *
     * @return string
     */
    private static function site_language(): string
    {
        if (!function_exists('get_locale')) {
            return 'en';
        }

        $locale = get_locale();
        $slug   = substr($locale, 0, 2);

        return !empty($slug) ? $slug : 'en';
    }
}
