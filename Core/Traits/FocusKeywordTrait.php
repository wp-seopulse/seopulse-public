<?php

/**
 * Trait for resolving the focus keyword of a post
 *
 * Checks SEOPulse, Yoast SEO, and Rank Math post meta in priority order.
 * Used by MetaAnalyzer and ContentAnalyzer.
 *
 * @package SEOPulse\Core\Traits
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Traits;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\PostMeta;
use SEOPulse\Modules\I18n\FocusKeywordLocalization;
use SEOPulse\Modules\I18n\LanguageDetector;

/**
 * FocusKeywordTrait
 *
 * Provides methods to resolve and manage focus keywords for a post.
 * Supports both single keyword (legacy) and multiple keywords (v3.0+).
 * Falls back through SEOPulse → Yoast → Rank Math meta keys.
 */
trait FocusKeywordTrait
{
    /**
     * Retrieves the focus keyword for a post (legacy: single keyword)
     *
     * Checks SEOPulse native meta first, then falls back to Yoast and Rank Math.
     * Returns only the first keyword for backwards compatibility.
     *
     * @param \WP_Post $post Post object
     * @return string Focus keyword or empty string
     *
     * @deprecated Use get_focus_keywords() instead for multi-keyword support
     */
    private function get_focus_keyword(\WP_Post $post): string
    {
        $keywords = $this->get_focus_keywords($post);

        return !empty($keywords) ? $keywords[0] : '';
    }

    /**
     * Retrieves all focus keywords for a post (multi-keyword support)
     *
     * Checks SEOPulse native meta first, then falls back to Yoast and Rank Math.
     * Always returns an array (may be empty).
     *
     * @param \WP_Post $post Post object
     * @return array Array of focus keywords or empty array
     */
    protected function get_focus_keywords(\WP_Post $post): array
    {
        // On multilingual sites, try language-specific keywords first
        if (LanguageDetector::is_multilingual()) {
            $localized = FocusKeywordLocalization::get($post->ID);
            if (!empty($localized)) {
                return $localized;
            }
        }

        // SEOPulse v3.0+ multi-keywords
        $keywords = get_post_meta($post->ID, PostMeta::FOCUS_KEYWORDS, true);
        if (is_array($keywords) && !empty($keywords)) {
            // Validate and sanitize
            $keywords = array_filter(
                array_map(
                    fn ($kw) => sanitize_text_field(trim($kw)),
                    $keywords,
                ),
                fn ($kw) => !empty($kw),
            );
            if (!empty($keywords)) {
                return array_values($keywords); // Re-index array
            }
        }

        // SEOPulse legacy single keyword
        $single = get_post_meta($post->ID, PostMeta::FOCUS_KEYWORD, true);
        if (!empty($single)) {
            return [sanitize_text_field(trim($single))];
        }

        // SEOPulse Free metabox keywords field
        $meta_seo = get_post_meta($post->ID, PostMeta::META_SEO, true);
        if (is_array($meta_seo) && !empty($meta_seo['keywords'])) {
            return [sanitize_text_field(trim($meta_seo['keywords']))];
        }

        // Yoast SEO
        $yoast = get_post_meta($post->ID, '_yoast_wpseo_focuskw', true);
        if (!empty($yoast)) {
            return [sanitize_text_field(trim($yoast))];
        }

        // Rank Math
        $rankmath = get_post_meta($post->ID, 'rank_math_focus_keyword', true);
        if (!empty($rankmath)) {
            return [sanitize_text_field(trim($rankmath))];
        }

        return [];
    }

    /**
     * Sets focus keywords for a post
     *
     * Validates keyword count against Free/Pro limits before saving.
     *
     * @param \WP_Post $post Post object
     * @param array $keywords Array of keywords to set
     * @return void
     *
     * @throws \Exception If keyword limit exceeded
     */
    protected function set_focus_keywords(\WP_Post $post, array $keywords): void
    {
        // Sanitize and filter empty keywords
        $keywords = array_filter(
            array_map(
                fn ($kw) => sanitize_text_field(trim($kw)),
                $keywords,
            ),
            fn ($kw) => !empty($kw),
        );

        // Validate keyword count
        $this->validate_keyword_count(count($keywords));

        // Save array
        if (!empty($keywords)) {
            update_post_meta($post->ID, PostMeta::FOCUS_KEYWORDS, array_values($keywords));
        } else {
            delete_post_meta($post->ID, PostMeta::FOCUS_KEYWORDS);
        }
    }

    /**
     * Adds a single keyword to a post's keyword list
     *
     * Prevents duplicates (case-insensitive) and validates total count.
     *
     * @param \WP_Post $post Post object
     * @param string $keyword Keyword to add
     * @return void
     *
     * @throws \Exception If keyword limit exceeded
     */
    protected function add_focus_keyword(\WP_Post $post, string $keyword): void
    {
        $keyword = sanitize_text_field(trim($keyword));
        if (empty($keyword)) {
            return;
        }

        $keywords = $this->get_focus_keywords($post);

        // Check for duplicate (case-insensitive)
        $keyword_lower = mb_strtolower($keyword);
        foreach ($keywords as $existing) {
            if (mb_strtolower($existing) === $keyword_lower) {
                return; // Already exists
            }
        }

        // Add and validate
        $keywords[] = $keyword;
        $this->validate_keyword_count(count($keywords));

        // Save
        update_post_meta($post->ID, PostMeta::FOCUS_KEYWORDS, $keywords);
    }

    /**
     * Removes a keyword from a post's keyword list
     *
     * @param \WP_Post $post Post object
     * @param string $keyword Keyword to remove
     * @return void
     */
    protected function remove_focus_keyword(\WP_Post $post, string $keyword): void
    {
        $keyword = sanitize_text_field(trim($keyword));
        if (empty($keyword)) {
            return;
        }

        $keywords      = $this->get_focus_keywords($post);
        $keyword_lower = mb_strtolower($keyword);

        // Filter out matching keyword
        $keywords = array_filter($keywords, fn ($kw) => mb_strtolower($kw) !== $keyword_lower);

        if (!empty($keywords)) {
            update_post_meta($post->ID, PostMeta::FOCUS_KEYWORDS, array_values($keywords));
        } else {
            delete_post_meta($post->ID, PostMeta::FOCUS_KEYWORDS);
        }
    }

    /**
     * Validates keyword count.
     *
     * All features are unlocked in the wordpress.org version,
     * so this method is a no-op.
     *
     * @param int $count Keyword count to validate.
     * @return void
     */
    protected function validate_keyword_count(int $count): void
    {
        // All features unlocked — no limit enforced.
    }

    /**
     * Normalize text for keyword matching: lowercase, strip punctuation, collapse whitespace.
     */
    private static function normalizeForMatch(string $str): string
    {
        $str = mb_strtolower($str);
        $str = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $str);
        $str = preg_replace('/\s+/', ' ', $str);

        return trim($str);
    }

    /**
     * Check if any of the focus keywords is found in text
     *
     * Checks if ANY keyword from the array is present in the text.
     * Uses normalized matching (strips punctuation, case-insensitive).
     *
     * @param string $text Text to search in
     * @param array $keywords Array of keywords to check
     * @return bool True if ANY keyword is found
     */
    protected static function hasAnyKeywordIn(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (self::hasKeywordIn($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if keyword is found in text.
     *
     * Supports comma-separated keywords (passes if ANY keyword matches).
     * Uses normalized matching (strips punctuation, case-insensitive).
     */
    private static function hasKeywordIn(string $text, string $keyword): bool
    {
        $normText = self::normalizeForMatch($text);

        $kwList = array_filter(array_map('trim', explode(',', $keyword)), fn ($k) => $k !== '');
        if (empty($kwList)) {
            return false;
        }

        foreach ($kwList as $singleKw) {
            $normKw = self::normalizeForMatch($singleKw);
            if (empty($normKw)) {
                continue;
            }
            // Exact phrase match
            if (str_contains($normText, $normKw)) {
                return true;
            }
            // Fallback: all significant words present
            $kwWords = array_filter(explode(' ', $normKw), fn ($w) => mb_strlen($w) > 1);
            if (empty($kwWords)) {
                continue;
            }
            $allPresent = true;
            foreach ($kwWords as $w) {
                if (!str_contains($normText, $w)) {
                    $allPresent = false;
                    break;
                }
            }
            if ($allPresent) {
                return true;
            }
        }

        return false;
    }
}
