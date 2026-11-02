<?php
/**
 * Dimensions of images that are not in the media library.
 *
 * @package SEOPulse\Modules\MetaSeo\Headless
 * @since   1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Headless;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads the size of an image from its first bytes, then caches it.
 */
class ImageSize
{
    /**
     * Number of bytes requested: enough for the header of common formats.
     */
    private const MAX_BYTES = 131072;

    /**
     * Finds the dimensions of an image URL.
     *
     * @param string $url Image URL.
     * @return array{0: int|null, 1: int|null} Width and height, null when unknown (SVG, unreachable, not an image...).
     */
    public function lookup(string $url): array
    {
        /**
         * Filters whether dimensions of images outside the media library are fetched over HTTP.
         *
         * @since 1.8.0
         * @param bool $enabled Whether to fetch.
         * @param string $url Image URL.
         */
        if (1 !== preg_match('#^https?://#i', $url) || !apply_filters('seopulse_head_remote_image_size', true, $url)) {
            return [null, null];
        }

        $key    = 'seopulse_head_img_' . md5($url);
        $cached = get_transient($key);

        if (is_array($cached)) {
            return [($cached[0] ?? 0) ?: null, ($cached[1] ?? 0) ?: null];
        }

        $size = $this->fetch($url);

        // Failures are retried after an hour, successes kept for a week.
        set_transient($key, [(int) $size[0], (int) $size[1]], null === $size[0] ? HOUR_IN_SECONDS : WEEK_IN_SECONDS);

        return $size;
    }

    /**
     * Reads dimensions from the beginning of an image file.
     *
     * @param string $bytes Beginning of the file.
     * @return array{0: int|null, 1: int|null}
     */
    public static function from_bytes(string $bytes): array
    {
        $info = '' === $bytes ? false : @getimagesizefromstring($bytes);

        if (!is_array($info)) {
            return [null, null];
        }

        return [((int) $info[0]) ?: null, ((int) $info[1]) ?: null];
    }

    /**
     * Downloads the beginning of the image.
     *
     * Uses the "safe" HTTP functions, which refuse private and loopback addresses.
     *
     * @param string $url Image URL.
     * @return array{0: int|null, 1: int|null}
     */
    private function fetch(string $url): array
    {
        $response = wp_safe_remote_get(
            $url,
            [
                'timeout'             => 3,
                'redirection'         => 2,
                'limit_response_size' => self::MAX_BYTES,
                'headers'             => ['Range' => 'bytes=0-' . (self::MAX_BYTES - 1)],
            ],
        );

        if (is_wp_error($response) || !in_array(wp_remote_retrieve_response_code($response), [200, 206], true)) {
            return [null, null];
        }

        return self::from_bytes((string) wp_remote_retrieve_body($response));
    }
}
