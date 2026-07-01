<?php

/**
 * Shared context object for content analysis checks
 *
 * Pre-computed data extracted from the post so individual checks
 * don't need to re-parse HTML or query WordPress.
 *
 * @package SEOPulse\Modules\Content\Checks
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Content\Checks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AnalysisContext — immutable data bag
 */
final class AnalysisContext
{
    /** @var string Post title */
    public readonly string $title;

    /** @var string Raw HTML content */
    public readonly string $content;

    /** @var string Plain text content (tags stripped) */
    public readonly string $textContent;

    /** @var int Word count */
    public readonly int $wordCount;

    /** @var int Character count */
    public readonly int $charCount;

    /** @var int Paragraph count */
    public readonly int $paragraphCount;

    /** @var array Focus keywords (may be empty) */
    public readonly array $focusKeywords;

    /** @var string First focus keyword (empty if none) */
    public readonly string $primaryKeyword;

    /** @var \WP_Post Post object */
    public readonly \WP_Post $post;

    /** @var array Pre-extracted heading data */
    public readonly array $headings;

    /** @var array Configuration thresholds */
    public readonly array $config;

    /** @var bool Has featured image */
    public readonly bool $hasFeaturedImage;

    /** @var int Featured image width */
    public readonly int $featuredImageWidth;

    /** @var int Featured image height */
    public readonly int $featuredImageHeight;

    /** @var string First paragraph text (stripped) */
    public readonly string $firstParagraphText;

    /** @var string Normalized text content for keyword matching */
    public readonly string $normalizedTextContent;

    /**
     * Build context from a WP_Post and config array
     */
    public static function fromPost(\WP_Post $post, array $config, array $focusKeywords): self
    {
        $ctx = new self();

        $ref         = new \ReflectionClass($ctx);
        $setReadonly = function (string $prop, mixed $value) use ($ctx, $ref) {
            $p = $ref->getProperty($prop);
            $p->setValue($ctx, $value);
        };

        $content     = $post->post_content;
        $textContent = wp_strip_all_tags($content);

        $setReadonly('title', $post->post_title);
        $setReadonly('content', $content);
        $setReadonly('textContent', $textContent);
        $setReadonly('wordCount', str_word_count($textContent));
        $setReadonly('charCount', mb_strlen($textContent));
        $setReadonly('paragraphCount', substr_count($content, '</p>'));
        $setReadonly('focusKeywords', $focusKeywords);
        $setReadonly('primaryKeyword', !empty($focusKeywords) ? $focusKeywords[0] : '');
        $setReadonly('post', $post);
        $setReadonly('headings', self::extractHeadings($content));
        $setReadonly('config', $config);

        // Featured image
        $hasFeatured = has_post_thumbnail($post->ID);
        $width       = 0;
        $height      = 0;
        if ($hasFeatured) {
            $thumbId = (int) get_post_thumbnail_id($post->ID);
            if ($thumbId > 0) {
                $metadata = wp_get_attachment_metadata($thumbId);
                if (is_array($metadata)) {
                    $width  = (int) ($metadata['width'] ?? 0);
                    $height = (int) ($metadata['height'] ?? 0);
                }
            }
        }
        $setReadonly('hasFeaturedImage', $hasFeatured);
        $setReadonly('featuredImageWidth', $width);
        $setReadonly('featuredImageHeight', $height);

        // First paragraph
        $firstPara = '';
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $content, $m)) {
            $firstPara = wp_strip_all_tags($m[1]);
        }
        $setReadonly('firstParagraphText', $firstPara);

        // Normalized text
        $norm = mb_strtolower($textContent);
        $norm = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $norm);
        $norm = preg_replace('/\s+/', ' ', $norm);
        $setReadonly('normalizedTextContent', trim($norm));

        return $ctx;
    }

    /**
     * Extract headings from HTML
     *
     * @return array{h1: array, h2: array, h3: array, h4: array, total: int, structure: array}
     */
    private static function extractHeadings(string $content): array
    {
        $headings = [
            'h1'        => [
                'count' => 0,
                'texts' => [],
            ],
            'h2'        => [
                'count' => 0,
                'texts' => [],
            ],
            'h3'        => [
                'count' => 0,
                'texts' => [],
            ],
            'h4'        => [
                'count' => 0,
                'texts' => [],
            ],
            'h5'        => [
                'count' => 0,
                'texts' => [],
            ],
            'h6'        => [
                'count' => 0,
                'texts' => [],
            ],
            'total'     => 0,
            'structure' => [],
        ];

        for ($i = 1; $i <= 6; $i++) {
            $pattern = '/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/is';
            if (preg_match_all($pattern, $content, $matches)) {
                $key                       = 'h' . $i;
                $headings[ $key ]['count'] = count($matches[1]);
                $headings[ $key ]['texts'] = array_map('wp_strip_all_tags', $matches[1]);
                $headings['total']        += count($matches[1]);

                foreach ($matches[1] as $text) {
                    $headings['structure'][] = [
                        'level' => $i,
                        'text'  => wp_strip_all_tags($text),
                    ];
                }
            }
        }

        return $headings;
    }

    /**
     * Normalize a string for keyword matching
     */
    public static function normalizeForMatch(string $str): string
    {
        $str = mb_strtolower($str);
        $str = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $str);
        $str = preg_replace('/\s+/', ' ', $str);

        return trim($str);
    }

    /**
     * Check if any keyword is found in text
     */
    public static function hasAnyKeywordIn(string $text, array $keywords): bool
    {
        $normText = self::normalizeForMatch($text);

        foreach ($keywords as $keyword) {
            $kwList = array_filter(array_map('trim', explode(',', $keyword)), fn ($k) => $k !== '');
            foreach ($kwList as $kw) {
                $normKw = self::normalizeForMatch($kw);
                if ($normKw !== '' && mb_strpos($normText, $normKw) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
