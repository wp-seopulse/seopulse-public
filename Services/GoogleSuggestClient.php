<?php

/**
 * Google Suggest autocomplete client.
 *
 * Fetches keyword suggestions from Google's autocomplete API,
 * caches results for 1 hour, and enforces server-side rate limiting.
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

class GoogleSuggestClient
{
    /** Google autocomplete endpoint. */
    private const ENDPOINT = 'https://www.google.com/complete/search';

    /** Cache TTL in seconds (1 hour). */
    private const CACHE_TTL = 3600;

    /** Max upstream requests per client fingerprint per minute. */
    private const RATE_LIMIT = 2;

    /**
     * Fetch keyword suggestions.
     *
     * @param string $keyword Search query (min 2 chars).
     * @param string $language Two-letter language code (e.g. 'en', 'fr').
     *
     * @return string[] List of suggestions (max 10).
     */
    public function getSuggestions(string $keyword, string $language = 'en'): array
    {
        $keyword = trim($keyword);

        if (mb_strlen($keyword) < 2) {
            return [];
        }

        // 1. Check cache first (never counts against rate limit).
        $cache_key = $this->buildCacheKey($keyword, $language);
        $cached    = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        // 2. Enforce rate limit before hitting Google.
        if ($this->isRateLimited()) {
            return [];
        }

        // 3. Fetch from Google.
        $url = add_query_arg(
            [
                'q'      => $keyword,
                'client' => 'firefox',
                'hl'     => $language,
            ],
            self::ENDPOINT,
        );

        $response = wp_remote_get(
            $url,
            [
                'timeout'    => 5,
                'user-agent' => 'Mozilla/5.0 (compatible; SEOPulse/' . SEOPULSE_VERSION . ')',
                'headers'    => [
                    'Accept' => 'application/json',
                ],
            ],
        );

        if (is_wp_error($response)) {
            return [];
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status !== 200) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);

        $suggestions = $this->parseResponse($body);

        // 4. Cache valid results.
        set_transient($cache_key, $suggestions, self::CACHE_TTL);

        return $suggestions;
    }

    /**
     * Detect the current language from Polylang, WPML, or WordPress locale.
     *
     * @return string Two-letter language code.
     */
    public static function detectLanguage(): string
    {
        // Polylang
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language('slug');
            if ($lang) {
                return $lang;
            }
        }

        // WPML
        if (defined('ICL_LANGUAGE_CODE') && is_string(ICL_LANGUAGE_CODE)) {
            return ICL_LANGUAGE_CODE;
        }

        // Fallback: WordPress locale → 2-letter code.
        $locale = get_locale();

        return substr($locale, 0, 2);
    }

    /**
     * Parse the Google Suggest JSON response.
     *
     * Format: ["query", ["suggestion1", "suggestion2", ...]]
     *
     * @param string $body Raw response body.
     *
     * @return string[]
     */
    private function parseResponse(string $body): array
    {
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data[1]) || !is_array($data[1])) {
            return [];
        }

        // Sanitize and limit to 10 items.
        $suggestions = [];

        foreach (array_slice($data[1], 0, 10) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $suggestions[] = sanitize_text_field($item);
            }
        }

        return $suggestions;
    }

    /**
     * Build a transient cache key for a keyword+language pair.
     *
     * @param string $keyword Search query.
     * @param string $language Language code.
     *
     * @return string Transient key (max 172 chars).
     */
    private function buildCacheKey(string $keyword, string $language): string
    {
        return 'seopulse_gsuggest_' . md5($language . ':' . mb_strtolower($keyword));
    }

    /**
     * Server-side rate limiting per client fingerprint.
     *
     * Uses a transient keyed by hashed IP + user-agent + minute bucket.
     * Returns true if the client has exceeded the rate limit.
     *
     * @return bool
     */
    private function isRateLimited(): bool
    {
        $fingerprint = $this->getClientFingerprint();
        $key         = 'seopulse_gsrl_' . $fingerprint;
        $count       = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT) {
            return true;
        }

        set_transient($key, $count + 1, 60);

        return false;
    }

    /**
     * Build a short hash identifying the current client.
     *
     * @return string 12-char hex hash.
     */
    private function getClientFingerprint(): string
    {
        $ip = '';

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        $ua     = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $minute = (string) intdiv(time(), 60);

        return substr(md5($ip . '|' . $ua . '|' . $minute), 0, 12);
    }
}
