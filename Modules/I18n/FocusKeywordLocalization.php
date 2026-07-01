<?php

/**
 * Per-language focus keyword storage and retrieval
 *
 * On multilingual sites stores keywords under a language-suffixed
 * meta key (`_seopulse_focus_keywords_<lang>`). Falls back to the
 * default key when no multilingual plugin is active.
 *
 * @package SEOPulse\Modules\I18n
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\I18n;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\PostMeta;
use SEOPulse\Core\Contracts\ExecuteHooks;

/**
 * FocusKeywordLocalization — hooks into keyword save/load via filters
 */
class FocusKeywordLocalization implements ExecuteHooks
{
    /**
     * Registers WordPress hooks.
     *
     * @return void
     */
    public function hooks(): void
    {
        // No hooks needed — this class exposes static helpers called
        // directly by the analysis pipeline and the REST controller.
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Returns the meta key for focus keywords in the given language.
     *
     * On monolingual sites this returns the default key unchanged.
     *
     * @param string|null $lang Language slug (null = auto-detect current)
     * @return string Meta key
     */
    public static function meta_key(?string $lang = null): string
    {
        if (!LanguageDetector::is_multilingual()) {
            return PostMeta::FOCUS_KEYWORDS;
        }

        $lang = $lang ?? LanguageDetector::current();

        return PostMeta::FOCUS_KEYWORDS . '_' . $lang;
    }

    /**
     * Retrieves focus keywords for a post in a specific language.
     *
     * Falls back to the base (non-suffixed) key when the language-specific
     * key is empty to ease the migration path.
     *
     * @param int $post_id Post ID
     * @param string|null $lang Language slug (null = post language)
     * @return array<string>
     */
    public static function get(int $post_id, ?string $lang = null): array
    {
        $lang = $lang ?? LanguageDetector::for_post($post_id);
        $key  = self::meta_key($lang);

        $keywords = get_post_meta($post_id, $key, true);

        if (is_array($keywords) && !empty($keywords)) {
            return array_values(
                array_filter(
                    array_map('sanitize_text_field', $keywords),
                    fn (string $kw): bool => $kw !== '',
                ),
            );
        }

        // Fallback to default key (monolingual or pre-migration data)
        if ($key !== PostMeta::FOCUS_KEYWORDS) {
            $fallback = get_post_meta($post_id, PostMeta::FOCUS_KEYWORDS, true);
            if (is_array($fallback) && !empty($fallback)) {
                return array_values(
                    array_filter(
                        array_map('sanitize_text_field', $fallback),
                        fn (string $kw): bool => $kw !== '',
                    ),
                );
            }
        }

        return [];
    }

    /**
     * Saves focus keywords for a post in a specific language.
     *
     * @param int $post_id Post ID
     * @param array $keywords Keywords array
     * @param string|null $lang Language slug (null = post language)
     * @return void
     */
    public static function save(int $post_id, array $keywords, ?string $lang = null): void
    {
        $lang = $lang ?? LanguageDetector::for_post($post_id);
        $key  = self::meta_key($lang);

        $clean = array_values(
            array_filter(
                array_map('sanitize_text_field', $keywords),
                fn (string $kw): bool => $kw !== '',
            ),
        );

        if (!empty($clean)) {
            update_post_meta($post_id, $key, $clean);
        } else {
            delete_post_meta($post_id, $key);
        }

        // Also update the default key so non-multilingual code paths
        // (metabox, CLI, etc.) always see the latest keywords.
        update_post_meta($post_id, PostMeta::FOCUS_KEYWORDS, $clean ?: []);
    }

    /**
     * Returns keywords for all languages of a post.
     *
     * Useful for admin UIs that show a language switcher.
     *
     * @param int $post_id Post ID
     * @return array<string, array<string>> lang => keywords
     */
    public static function get_all_languages(int $post_id): array
    {
        $result = [];

        foreach (LanguageDetector::active_languages() as $lang) {
            $key      = PostMeta::FOCUS_KEYWORDS . '_' . $lang;
            $keywords = get_post_meta($post_id, $key, true);

            if (is_array($keywords) && !empty($keywords)) {
                $result[ $lang ] = array_values(
                    array_filter(
                        array_map('sanitize_text_field', $keywords),
                        fn (string $kw): bool => $kw !== '',
                    ),
                );
            }
        }

        return $result;
    }
}
