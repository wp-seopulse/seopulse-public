<?php

/**
 * Sitemap media extraction: images and videos found in post content.
 *
 * @package SEOPulse\Modules\Sitemap
 * @since 1.1.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Sitemap;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Cache\ObjectCache;
use WP_Post;

/**
 * Finds the images and videos of a post for the XML sitemaps.
 */
final class SitemapMedia
{
    public const VIDEO_POSTS_PER_PAGE = 200;

    private const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'webm', 'mov', 'ogv', 'mpg', 'mpeg', 'avi', 'wmv', 'flv'];
    private const YOUTUBE_PATTERN  = '~(?:youtube(?:-nocookie)?\.com/(?:embed/|v/|watch\?(?:[^"\'\s<>]*?[&;])?v=)|youtu\.be/)([A-Za-z0-9_-]{11})~i';
    private const VIMEO_PATTERN    = '~vimeo\.com/(?:video/|channels/[^/"\'\s]+/|groups/[^/"\'\s]+/videos/)?(\d{6,12})~i';
    private const VIDEO_MARKERS    = ['youtube.com', 'youtu.be', 'vimeo.com', '<video', 'wp:video', '[video'];

    /**
     * Images of a post: featured image, WooCommerce gallery, content images (including lazy-loaded) and galleries.
     *
     * @return array<int, array{loc: string, title: string, caption: string}>
     */
    public function images(WP_Post $post): array
    {
        $images = [];
        $add    = function (string $url, int $attachment_id = 0) use (&$images): void {
            $url = $this->absoluteUrl($url);

            if ($url === '' || isset($images[ $url ])) {
                return;
            }

            if ($attachment_id === 0) {
                $attachment_id = (int) attachment_url_to_postid($url);
            }

            $images[ $url ] = [
                'loc'     => $url,
                'title'   => $attachment_id ? get_the_title($attachment_id) : '',
                'caption' => $attachment_id ? (string) wp_get_attachment_caption($attachment_id) : '',
            ];
        };

        $thumb_id = (int) get_post_thumbnail_id($post->ID);

        if ($thumb_id) {
            $add((string) wp_get_attachment_url($thumb_id), $thumb_id);
        }

        $gallery = (string) get_post_meta($post->ID, '_product_image_gallery', true);

        foreach ($this->ids($gallery) as $id) {
            $add((string) wp_get_attachment_url($id), $id);
        }

        $content = $post->post_content;

        foreach ($this->galleryIds($content) as $id) {
            $add((string) wp_get_attachment_url($id), $id);
        }

        if (preg_match_all('/<img\b[^>]*>/i', $content, $tags)) {
            foreach ($tags[0] as $tag) {
                $src = $this->attribute($tag, ['src', 'data-src', 'data-lazy-src', 'data-original']);

                if ($src === '') {
                    continue;
                }

                $id = preg_match('/\bwp-image-(\d+)\b/', $tag, $m) ? (int) $m[1] : 0;

                // The src of a resized image points to a derivative; prefer the original upload.
                $add($id ? (string) (wp_get_attachment_url($id) ?: $src) : $src, $id);
            }
        }

        /**
         * Filters the images listed for a post in the XML sitemap.
         *
         * @param array<int, array{loc: string, title: string, caption: string}> $images
         */
        $images = apply_filters('seopulse_sitemap_post_images', array_values($images), $post);

        $max = (int) apply_filters('seopulse_sitemap_max_images_per_url', 50, $post);

        return array_slice(is_array($images) ? $images : [], 0, max(1, min(1000, $max)));
    }

    /**
     * Videos of a post: YouTube, Vimeo and self-hosted files (HTML5 tag, core block, [video] shortcode).
     *
     * @return array<int, array<string, mixed>>
     */
    public function videos(WP_Post $post): array
    {
        $content = $post->post_content;
        $videos  = [];

        if (preg_match_all(self::YOUTUBE_PATTERN, $content, $m)) {
            foreach (array_unique($m[1]) as $id) {
                $videos[ 'yt:' . $id ] = [
                    'player_loc'    => 'https://www.youtube.com/embed/' . $id,
                    'thumbnail_loc' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
                ];
            }
        }

        if (preg_match_all(self::VIMEO_PATTERN, $content, $m)) {
            foreach (array_unique($m[1]) as $id) {
                $info = $this->vimeoInfo($id);

                if ($info['thumbnail'] === '') {
                    continue;
                }

                $videos[ 'vm:' . $id ] = [
                    'player_loc'    => 'https://player.vimeo.com/video/' . $id,
                    'thumbnail_loc' => $info['thumbnail'],
                    'duration'      => $info['duration'],
                ];
            }
        }

        foreach ($this->selfHosted($content) as $file) {
            $thumbnail = $file['poster'] !== '' ? $file['poster'] : (string) get_the_post_thumbnail_url($post, 'large');

            if ($thumbnail === '') {
                continue;
            }

            $videos[ 'file:' . $file['src'] ] = [
                'content_loc'   => $file['src'],
                'thumbnail_loc' => $this->absoluteUrl($thumbnail),
                'duration'      => $this->attachmentDuration($file['src']),
            ];
        }

        if ($videos === []) {
            return [];
        }

        $shared = [
            'title'            => $this->limit(wp_strip_all_tags(get_the_title($post)), 100),
            'description'      => $this->description($post),
            'publication_date' => (string) get_post_time('c', true, $post),
        ];

        $result = [];

        foreach ($videos as $video) {
            if (($video['thumbnail_loc'] ?? '') === '') {
                continue;
            }

            $result[] = array_merge($shared, $video);
        }

        $max = (int) apply_filters('seopulse_sitemap_max_videos_per_url', 10, $post);

        return array_slice($result, 0, max(1, $max));
    }

    /**
     * Number of published posts that may contain a video.
     *
     * @param string[] $post_types
     */
    public function countVideoPosts(array $post_types): int
    {
        global $wpdb;

        if ($post_types === []) {
            return 0;
        }

        [$where, $params] = $this->videoWhere($post_types);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE {$where}", ...$params));
    }

    /**
     * IDs of published posts that may contain a video, newest first.
     *
     * @param string[] $post_types
     * @return int[]
     */
    public function videoPostIds(array $post_types, int $paged): array
    {
        global $wpdb;

        if ($post_types === []) {
            return [];
        }

        [$where, $params] = $this->videoWhere($post_types);

        $params[] = self::VIDEO_POSTS_PER_PAGE;
        $params[] = max(0, $paged - 1) * self::VIDEO_POSTS_PER_PAGE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE {$where} ORDER BY post_modified_gmt DESC LIMIT %d OFFSET %d", ...$params));

        return array_map('intval', $ids);
    }

    /**
     * @param string[] $post_types
     * @return array{0: string, 1: array<int, string>}
     */
    private function videoWhere(array $post_types): array
    {
        global $wpdb;

        $likes  = array_fill(0, count(self::VIDEO_MARKERS), 'post_content LIKE %s');
        $params = array_values($post_types);

        foreach (self::VIDEO_MARKERS as $marker) {
            $params[] = '%' . $wpdb->esc_like($marker) . '%';
        }

        $where = "post_status = 'publish' AND post_password = '' AND post_type IN ("
            . implode(',', array_fill(0, count($post_types), '%s'))
            . ') AND (' . implode(' OR ', $likes) . ')';

        return [$where, $params];
    }

    /**
     * @return array<int, array{src: string, poster: string}>
     */
    private function selfHosted(string $content): array
    {
        $files = [];
        $add   = function (string $src, string $poster) use (&$files): void {
            $src = $this->absoluteUrl($src);
            $ext = strtolower((string) pathinfo((string) wp_parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION));

            if ($src !== '' && in_array($ext, self::VIDEO_EXTENSIONS, true)) {
                $files[ $src ] = ['src' => $src, 'poster' => $poster !== '' ? $this->absoluteUrl($poster) : ''];
            }
        };

        if (preg_match_all('~<video\b([^>]*)>(.*?)</video>~is', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $poster = $this->attribute($match[1], ['poster']);
                $src    = $this->attribute($match[1], ['src']);

                if ($src === '' && preg_match('~<source\b[^>]*>~i', $match[2], $source)) {
                    $src = $this->attribute($source[0], ['src']);
                }

                $add($src, $poster);
            }
        }

        if (preg_match_all('/\[video\b([^\]]*)\]/i', $content, $m)) {
            foreach ($m[1] as $raw) {
                $atts = shortcode_parse_atts($raw);
                $atts = is_array($atts) ? $atts : [];

                foreach (array_merge(['src'], self::VIDEO_EXTENSIONS) as $key) {
                    if (!empty($atts[ $key ]) && is_string($atts[ $key ])) {
                        $add($atts[ $key ], isset($atts['poster']) && is_string($atts['poster']) ? $atts['poster'] : '');
                        break;
                    }
                }
            }
        }

        return array_values($files);
    }

    /**
     * @return array{thumbnail: string, duration: int}
     */
    private function vimeoInfo(string $id): array
    {
        $key    = 'vimeo_' . $id;
        $cached = ObjectCache::get($key, 'images');

        if (is_array($cached) && isset($cached['thumbnail'], $cached['duration'])) {
            return $cached;
        }

        $info     = ['thumbnail' => '', 'duration' => 0];
        $response = wp_remote_get(
            'https://vimeo.com/api/oembed.json?url=' . rawurlencode('https://vimeo.com/' . $id),
            ['timeout' => 3],
        );

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (is_array($data) && !empty($data['thumbnail_url']) && is_string($data['thumbnail_url'])) {
                $info['thumbnail'] = esc_url_raw($data['thumbnail_url']);
                $info['duration']  = isset($data['duration']) ? absint($data['duration']) : 0;
            }
        }

        ObjectCache::set($key, $info, 'images', $info['thumbnail'] !== '' ? WEEK_IN_SECONDS : DAY_IN_SECONDS);

        return $info;
    }

    private function attachmentDuration(string $url): int
    {
        $id = (int) attachment_url_to_postid($url);

        if (!$id) {
            return 0;
        }

        $meta = wp_get_attachment_metadata($id);

        return is_array($meta) && isset($meta['length']) ? min(28800, absint($meta['length'])) : 0;
    }

    private function description(WP_Post $post): string
    {
        $seo = get_post_meta($post->ID, '_seopulse_meta_seo', true);

        if (is_array($seo) && !empty($seo['description']) && is_string($seo['description']) && !str_contains($seo['description'], '%%')) {
            return $this->limit(wp_strip_all_tags($seo['description']), 2048);
        }

        $text = $post->post_excerpt !== ''
            ? $post->post_excerpt
            : wp_strip_all_tags(strip_shortcodes(excerpt_remove_blocks($post->post_content)));
        $text = trim((string) preg_replace(['~https?://\S+~', '/\s+/u'], ['', ' '], $text));

        return $this->limit($text !== '' ? $text : get_the_title($post), 2048);
    }

    /**
     * @return int[]
     */
    private function galleryIds(string $content): array
    {
        $ids = [];

        if (preg_match_all('/\[gallery\b[^\]]*\bids=["\']([\d,\s]+)["\']/i', $content, $m)) {
            foreach ($m[1] as $list) {
                $ids = array_merge($ids, $this->ids($list));
            }
        }

        if (preg_match_all('/<!--\s*wp:gallery\s+\{[^}]*"ids":\s*\[([\d,\s]+)\]/', $content, $m)) {
            foreach ($m[1] as $list) {
                $ids = array_merge($ids, $this->ids($list));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int[]
     */
    private function ids(string $list): array
    {
        return array_values(array_filter(array_map('absint', explode(',', $list))));
    }

    /**
     * @param string[] $names Attribute names, by priority.
     */
    private function attribute(string $tag, array $names): string
    {
        foreach ($names as $name) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m) && trim($m[2]) !== '') {
                return html_entity_decode(trim($m[2]), ENT_QUOTES);
            }
        }

        return '';
    }

    /**
     * Makes a URL absolute; returns an empty string for data URIs and non-HTTP schemes.
     */
    private function absoluteUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, 'data:')) {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = set_url_scheme($url);
        } elseif (str_starts_with($url, '/')) {
            $url = home_url($url);
        }

        return preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private function limit(string $text, int $length): string
    {
        return mb_substr($text, 0, $length);
    }
}
