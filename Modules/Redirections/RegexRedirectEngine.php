<?php

/**
 * Regex Redirect Engine
 *
 * Provides centralized regex matching with capture-group replacement.
 * Capture-group replacement ($0, $1…) is gated behind Pro.
 *
 * @package SEOPulse\Modules\Redirections
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Redirections;

if (!defined('ABSPATH')) {
    exit;
}

class RegexRedirectEngine
{
    /**
     * Maximum allowed regex pattern length to prevent ReDoS.
     */
    private const MAX_PATTERN_LENGTH = 500;

    /**
     * Validate a regex pattern for safety and correctness.
     *
     * @param string $pattern Raw regex pattern (without delimiters).
     * @return true|\WP_Error True when valid, WP_Error otherwise.
     */
    public static function validate(string $pattern)
    {
        if ($pattern === '') {
            return new \WP_Error(
                'regex_empty',
                __('Regex pattern cannot be empty.', 'seopulse'),
            );
        }

        if (mb_strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return new \WP_Error(
                'regex_too_long',
                sprintf(
                    /* translators: %d: maximum pattern length */
                    __('Regex pattern exceeds the maximum length of %d characters.', 'seopulse'),
                    self::MAX_PATTERN_LENGTH,
                ),
            );
        }

        // Block known catastrophic back-tracking patterns.
        if (self::is_dangerous($pattern)) {
            return new \WP_Error(
                'regex_dangerous',
                __('This regex pattern could cause excessive processing and has been rejected.', 'seopulse'),
            );
        }

        // Attempt a test match to catch syntax errors.
        $delimited = self::delimit($pattern);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $result = @preg_match($delimited, '');

        if ($result === false) {
            return new \WP_Error(
                'regex_invalid',
                __('The regex pattern is invalid. Please check the syntax.', 'seopulse'),
            );
        }

        return true;
    }

    /**
     * Test whether a URI matches a regex pattern.
     *
     * @param string $pattern Raw regex pattern (without delimiters).
     * @param string $uri Request URI to test against.
     * @return bool
     */
    public static function matches(string $pattern, string $uri): bool
    {
        $delimited = self::delimit($pattern);

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        return (bool) @preg_match($delimited, $uri);
    }

    /**
     * Match a URI and return captured groups.
     *
     * @param string $pattern Raw regex pattern (without delimiters).
     * @param string $uri Request URI.
     * @return array{matched: bool, captures: string[]}
     */
    public static function match(string $pattern, string $uri): array
    {
        $delimited = self::delimit($pattern);
        $matches   = [];

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $result = @preg_match($delimited, $uri, $matches);

        return [
            'matched'  => $result === 1,
            'captures' => $result === 1 ? $matches : [],
        ];
    }

    /**
     * Apply capture-group replacement to a target URL.
     *
     * Replaces $0, $1, $2… in the target with matched groups.
     *
     * @param string $target Target URL template with $N placeholders.
     * @param string[] $captures Captured groups from match().
     * @return string Resolved target URL.
     */
    public static function applyCaptures(string $target, array $captures): string
    {
        foreach ($captures as $index => $value) {
            $target = str_replace('$' . $index, $value, $target);
        }

        return $target;
    }

    /**
     * Whether regex redirects are available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Wrap a raw pattern with delimiters and a case-insensitive flag.
     *
     * @param string $pattern Raw regex.
     * @return string Delimited regex ready for preg_match().
     */
    private static function delimit(string $pattern): string
    {
        return '@' . str_replace('@', '\\@', $pattern) . '@i';
    }

    /**
     * Detect patterns likely to cause catastrophic back-tracking.
     *
     * @param string $pattern Raw regex.
     * @return bool
     */
    private static function is_dangerous(string $pattern): bool
    {
        // Nested quantifiers: (a+)+ , (a*)*  , (a+)*  etc.
        if (preg_match('/\([^)]*[+*][^)]*\)[+*]/', $pattern)) {
            return true;
        }

        // Overlapping alternatives inside repetition: (a|a)+
        if (preg_match('/\(([^|)]+)\|\1\)[+*]/', $pattern)) {
            return true;
        }

        return false;
    }
}
