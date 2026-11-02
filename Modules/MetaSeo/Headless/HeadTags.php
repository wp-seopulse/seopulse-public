<?php
/**
 * Ready-to-render <head> tags for headless payloads.
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
 * Flattens a head payload into a list of tags a frontend can render as is.
 *
 * Each tag is {"tag": "title|meta|link|script", "attrs": {...}, "content": "..."};
 * "attrs" and "content" are omitted when empty. Values are plain text, to be escaped by the renderer.
 */
final class HeadTags
{
    /**
     * Builds the tags of a payload (already rewritten to the frontend origin).
     *
     * @param array<string, mixed> $payload Head payload.
     * @return array<int, array<string, mixed>>
     */
    public static function build(array $payload): array
    {
        $tags = [];
        $og   = (array) ($payload['og'] ?? []);
        $tw   = (array) ($payload['twitter'] ?? []);

        if (self::filled($payload['title'] ?? null)) {
            $tags[] = ['tag' => 'title', 'content' => (string) $payload['title']];
        }

        $tags = array_merge(
            $tags,
            self::meta('name', [
                'description' => $payload['description'] ?? null,
                'robots'      => $payload['robots'] ?? null,
            ]),
            self::links('canonical', [$payload['canonical'] ?? null]),
        );

        $image = is_array($og['image'] ?? null) ? $og['image'] : [];

        $tags = array_merge(
            $tags,
            self::meta('property', [
                'og:title'        => $og['title'] ?? null,
                'og:description'  => $og['description'] ?? null,
                'og:url'          => $og['url'] ?? null,
                'og:site_name'    => $og['site_name'] ?? null,
                'og:type'         => $og['type'] ?? null,
                'og:locale'       => $og['locale'] ?? null,
                'og:image'        => $image['url'] ?? null,
                'og:image:width'  => $image['width'] ?? null,
                'og:image:height' => $image['height'] ?? null,
                'og:image:alt'    => $image['alt'] ?? null,
            ]),
        );

        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];

        $tags = array_merge(
            $tags,
            self::meta('property', [
                'article:published_time' => $article['published_time'] ?? null,
                'article:modified_time'  => $article['modified_time'] ?? null,
                'article:author'         => $article['author'] ?? null,
            ]),
            self::meta('name', [
                'twitter:card'        => $tw['card'] ?? null,
                'twitter:site'        => $tw['site'] ?? null,
                'twitter:creator'     => $tw['creator'] ?? null,
                'twitter:title'       => $tw['title'] ?? null,
                'twitter:description' => $tw['description'] ?? null,
                'twitter:image'       => $tw['image'] ?? null,
            ]),
            self::links('prev', [$payload['pagination']['prev'] ?? null]),
            self::links('next', [$payload['pagination']['next'] ?? null]),
        );

        foreach ((array) ($payload['alternates'] ?? []) as $alternate) {
            if (is_array($alternate) && self::filled($alternate['url'] ?? null)) {
                $tags[] = [
                    'tag'   => 'link',
                    'attrs' => [
                        'rel'      => 'alternate',
                        'hreflang' => (string) ($alternate['hreflang'] ?? ''),
                        'href'     => (string) $alternate['url'],
                    ],
                ];
            }
        }

        if (!empty($payload['schema']['@graph'])) {
            // HEX flags keep "</script>" inside a string from closing the tag when printed as HTML.
            $json = wp_json_encode($payload['schema'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (is_string($json)) {
                $tags[] = ['tag' => 'script', 'attrs' => ['type' => 'application/ld+json'], 'content' => $json];
            }
        }

        return $tags;
    }

    /**
     * Builds meta tags, skipping empty values.
     *
     * @param string $attribute "name" or "property".
     * @param array<string, mixed> $values Tag key => content.
     * @return array<int, array<string, mixed>>
     */
    private static function meta(string $attribute, array $values): array
    {
        $tags = [];

        foreach ($values as $key => $value) {
            if (self::filled($value)) {
                $tags[] = ['tag' => 'meta', 'attrs' => [$attribute => (string) $key, 'content' => (string) $value]];
            }
        }

        return $tags;
    }

    /**
     * Builds link tags, skipping empty values.
     *
     * @param string $rel Link relation.
     * @param array<int, mixed> $urls URLs.
     * @return array<int, array<string, mixed>>
     */
    private static function links(string $rel, array $urls): array
    {
        $tags = [];

        foreach ($urls as $url) {
            if (self::filled($url)) {
                $tags[] = ['tag' => 'link', 'attrs' => ['rel' => $rel, 'href' => (string) $url]];
            }
        }

        return $tags;
    }

    /**
     * Tells whether a value is worth a tag.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function filled(mixed $value): bool
    {
        return is_scalar($value) && '' !== trim((string) $value);
    }
}
