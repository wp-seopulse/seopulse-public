<?php
/**
 * Frontend origin used to rewrite page URLs of headless payloads.
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

/**
 * Resolves the public frontend URL (for example "https://www.example.com") and
 * rewrites page URLs of a payload from the CMS origin to the frontend origin.
 * Media URLs (uploads, wp-content, wp-includes) always stay on the CMS.
 */
final class FrontendUrl
{
    /**
     * Returns the frontend base URL without trailing slash, or '' when unset.
     *
     * @return string
     */
    public function base(): string
    {
        $settings = get_option(Options::META_SEO_GLOBAL, []);
        $url      = is_array($settings) && is_string($settings['headless_frontend_url'] ?? null)
            ? $settings['headless_frontend_url']
            : '';

        /**
         * Filters the public frontend URL of a headless site.
         *
         * @since 1.8.0
         * @param string $url Frontend base URL, empty to disable the rewrite.
         */
        $url = apply_filters('seopulse_head_frontend_url', $url);

        if (!is_string($url) || '' === trim($url)) {
            return '';
        }

        return untrailingslashit(esc_url_raw(trim($url), ['http', 'https']));
    }

    /**
     * Returns the frontend host in lower case, or null when unset.
     *
     * @return string|null
     */
    public function host(): ?string
    {
        $host = wp_parse_url($this->base(), PHP_URL_HOST);

        return is_string($host) && '' !== $host ? strtolower($host) : null;
    }

    /**
     * Rewrites page URLs of a payload from the CMS origin to the frontend origin.
     *
     * @param array<string, mixed> $payload Payload in CMS origin.
     * @return array<string, mixed>
     */
    public function rewrite(array $payload): array
    {
        $front = $this->base();
        $home  = untrailingslashit(home_url());

        if ('' === $front || $front === $home) {
            return $payload;
        }

        $uploads = wp_get_upload_dir();
        $keep    = array_filter([
            untrailingslashit(content_url()),
            untrailingslashit(includes_url()),
            untrailingslashit((string) ($uploads['baseurl'] ?? '')),
        ]);

        return $this->map($payload, $home, $front, $keep);
    }

    /**
     * Recursively rewrites string values.
     *
     * @param array<mixed> $data Data.
     * @param string $home CMS base URL.
     * @param string $front Frontend base URL.
     * @param string[] $keep URL prefixes that must not be rewritten.
     * @return array<mixed>
     */
    private function map(array $data, string $home, string $front, array $keep): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->map($value, $home, $front, $keep);
            } elseif (is_string($value)) {
                $data[$key] = $this->swap($value, $home, $front, $keep);
            }
        }

        return $data;
    }

    /**
     * Rewrites one value when it is a page URL of the CMS.
     *
     * @param string $value Value.
     * @param string $home CMS base URL.
     * @param string $front Frontend base URL.
     * @param string[] $keep URL prefixes that must not be rewritten.
     * @return string
     */
    private function swap(string $value, string $home, string $front, array $keep): string
    {
        if (!str_starts_with($value, $home)) {
            return $value;
        }

        $rest = substr($value, strlen($home));

        if ('' !== $rest && !in_array($rest[0], ['/', '#', '?'], true)) {
            return $value;
        }

        foreach ($keep as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $value;
            }
        }

        return $front . $rest;
    }
}
