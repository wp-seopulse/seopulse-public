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

use SEOPulse\Modules\PageBuilders\BuilderDetector;

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
     * Per-request cache of the expensive, content-only derived data from
     * fromPost() — keyed by post ID + post_modified_gmt.
     *
     * Several analyzer modules (ContentAnalyzer, MetaAnalyzer,
     * ReadabilityAnalyzer, ...) each independently call
     * AnalysisContext::fromPost() for the same post within a single
     * ScoreCalculator::calculate() run, via the generic
     * `$module->analyze($post)` interface. That interface is polymorphic
     * and filterable (`seopulse_score_modules`), so it can't be changed
     * to accept a pre-built context without risking breaking third-party
     * modules — caching here instead is fully transparent to every
     * caller.
     *
     * Only the fields that depend solely on $post (never on $config or
     * $focusKeywords, which are cheap to (re)assign per call) are cached:
     * resolveContent() in particular can trigger a full `the_content`
     * render pass through Elementor/Kubio/etc., which is the expensive
     * part this avoids repeating.
     *
     * Safe as a static property: a fresh PHP process/request (mod_php,
     * php-fpm) starts with this empty. Under a persistent-worker runtime
     * (e.g. RoadRunner, Swoole) this would need clearing between
     * requests — not a concern for a standard WordPress deployment.
     *
     * @var array<string, array>
     */
    private static array $contentCache = [];

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

        $cacheKey = $post->ID . ':' . $post->post_modified_gmt;

        if (!isset(self::$contentCache[ $cacheKey ])) {
            $content     = self::resolveContent($post);
            $textContent = wp_strip_all_tags($content);

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

            // First paragraph
            $firstPara = '';
            if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $content, $m)) {
                $firstPara = wp_strip_all_tags($m[1]);
            } else {
                $blocks    = self::splitPlainTextParagraphs($textContent);
                $firstPara = $blocks[0] ?? '';
            }

            // Normalized text
            $norm = mb_strtolower($textContent);
            $norm = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $norm);
            $norm = preg_replace('/\s+/', ' ', $norm);

            self::$contentCache[ $cacheKey ] = [
                'content'               => $content,
                'textContent'           => $textContent,
                'wordCount'             => str_word_count($textContent),
                'charCount'             => mb_strlen($textContent),
                'paragraphCount'        => self::countParagraphs($content, $textContent),
                'headings'              => self::extractHeadings($content),
                'hasFeaturedImage'      => $hasFeatured,
                'featuredImageWidth'    => $width,
                'featuredImageHeight'   => $height,
                'firstParagraphText'    => $firstPara,
                'normalizedTextContent' => trim($norm),
            ];
        }

        $cached = self::$contentCache[ $cacheKey ];

        $setReadonly('title', $post->post_title);
        $setReadonly('content', $cached['content']);
        $setReadonly('textContent', $cached['textContent']);
        $setReadonly('wordCount', $cached['wordCount']);
        $setReadonly('charCount', $cached['charCount']);
        $setReadonly('paragraphCount', $cached['paragraphCount']);
        $setReadonly('focusKeywords', $focusKeywords);
        $setReadonly('primaryKeyword', !empty($focusKeywords) ? $focusKeywords[0] : '');
        $setReadonly('post', $post);
        $setReadonly('headings', $cached['headings']);
        $setReadonly('config', $config);
        $setReadonly('hasFeaturedImage', $cached['hasFeaturedImage']);
        $setReadonly('featuredImageWidth', $cached['featuredImageWidth']);
        $setReadonly('featuredImageHeight', $cached['featuredImageHeight']);
        $setReadonly('firstParagraphText', $cached['firstParagraphText']);
        $setReadonly('normalizedTextContent', $cached['normalizedTextContent']);

        return $ctx;
    }

    /**
     * Resolves the content that will actually be rendered for this post,
     * not just what's stored in `post_content`.
     *
     * Non-Gutenberg page builders (Elementor, Divi, Live Composer, etc.)
     * commonly generate their final markup through the `the_content`
     * filter rather than storing it verbatim in `post_content` — see
     * LiveComposerIntegration.php for a documented example of exactly
     * this split for Live Composer specifically. Reading
     * `$post->post_content` directly only ever sees what's in the
     * database column, which for those builders can be a near-empty
     * shortcode/JSON placeholder — invisible to word counts, heading
     * extraction, keyword density, etc.
     *
     * `the_content` filters commonly guard themselves with
     * `in_the_loop()` / `is_main_query()` checks meant to prevent
     * double-rendering in feeds, excerpts, previews, etc. Outside of a
     * real front-end request (this runs from a REST request or WP-CLI)
     * there is no natural "loop" running, so the global `$post` is
     * temporarily pointed at the analyzed post via `setup_postdata()`
     * to satisfy those checks, then restored.
     *
     * NOT independently confirmed against Live Composer's own
     * `the_content` usage — should be verified on a live install with
     * Live Composer active before relying on this for that builder.
     *
     * Elementor is handled separately below (resolveElementorContent):
     * applying `the_content` on an Elementor post never returns
     * Elementor's actual design, only the plain-text sync it writes to
     * `post_content` on save.
     *
     * @param \WP_Post $post Post to resolve content for.
     * @return string Rendered HTML, or the raw stored content if
     *                filtering produced nothing usable.
     */
    public static function resolveContent(\WP_Post $post): string
    {
        $elementorContent = self::resolveElementorContent($post);
        if ($elementorContent !== null) {
            return $elementorContent;
        }

        $previousGlobalPost = $GLOBALS['post'] ?? null;

        $GLOBALS['post'] = $post;
        setup_postdata($post);

        // Kubio's block base class (BlockBase::canRender(), confirmed via
        // the plugin's own source) deliberately renders an empty string
        // for any request where REST_REQUEST is defined and true, unless
        // the route contains "/block-renderer/" (Gutenberg's own preview
        // endpoint). AnalysisContext runs from SEOPulse's own REST route,
        // so that guard trips for every kubio/* container block. Kubio
        // exposes exactly one escape hatch for this — the
        // "kubio/can_render_block" filter — so it's forced on only for
        // the duration of this call. Note this is REST-specific, not a
        // WP-CLI issue: WP-CLI never defines REST_REQUEST, so canRender()
        // already returns true there by default.
        $forceKubioRender = static fn () => true;

        if (BuilderDetector::isKubioActive()) {
            add_filter('kubio/can_render_block', $forceKubioRender);
        }

        $rendered = apply_filters('the_content', $post->post_content);

        if (BuilderDetector::isKubioActive()) {
            remove_filter('kubio/can_render_block', $forceKubioRender);
        }

        if ($previousGlobalPost instanceof \WP_Post) {
            $GLOBALS['post'] = $previousGlobalPost;
            setup_postdata($previousGlobalPost);
        } else {
            unset($GLOBALS['post']);
            wp_reset_postdata();
        }

        return $rendered !== '' ? $rendered : $post->post_content;
    }

    /**
     * Elementor doesn't route its real design through `the_content` at
     * all — applying that filter on an Elementor post returns only the
     * plain-text fallback Elementor itself syncs into `post_content` on
     * save (its own Db::save_plain_text(), which strips tags for
     * third-party compatibility — search/RSS/SEO tools that read
     * `post_content` directly), confirmed by Elementor's own team on
     * https://github.com/elementor/elementor/discussions/19061. The
     * actual rendered HTML (real heading/paragraph markup from the
     * Heading/Text Editor widgets, etc.) is only available through
     * Elementor's own frontend renderer.
     *
     * NOT independently confirmed on a live install that
     * get_builder_content() is safe to call outside of a real front-end
     * request (this runs from REST/WP-CLI) — should be verified before
     * treating this as fully confirmed.
     *
     * @param \WP_Post $post Post to resolve content for.
     * @return string|null Rendered HTML, or null if this post isn't
     *                     built with Elementor (falls through to the
     *                     generic `the_content` path above).
     */
    private static function resolveElementorContent(\WP_Post $post): ?string
    {
        if (!BuilderDetector::isElementorActive() || !class_exists(\Elementor\Plugin::class)) {
            return null;
        }

        $document = \Elementor\Plugin::instance()->documents->get($post->ID);

        if (!$document || !$document->is_built_with_elementor()) {
            return null;
        }

        $html = \Elementor\Plugin::instance()->frontend->get_builder_content($post->ID, true);

        return $html !== '' ? $html : null;
    }

    /**
     * Counts paragraphs, falling back to a builder-agnostic heuristic
     * when the content has no `<p>` tags at all.
     *
     * Some page-builder modules (e.g. Live Composer's "Content" module,
     * confirmed on a live install) wrap free-form text in a single
     * container — a `<div>`, not a `<p>` — with no paragraph-level
     * markup inside it at all. Counting `</p>` tags alone would read
     * that as zero paragraphs regardless of how much text it actually
     * contains. Rather than special-casing any one builder's specific
     * markup, this falls back to the same blank-line heuristic
     * `wpautop()` itself uses to decide where paragraph breaks belong,
     * applied to the already-stripped plain text.
     */
    private static function countParagraphs(string $content, string $textContent): int
    {
        $tagCount = substr_count($content, '</p>');
        if ($tagCount > 0) {
            return $tagCount;
        }

        return count(self::splitPlainTextParagraphs($textContent));
    }

    /**
     * Splits plain text into paragraph-like blocks on blank lines —
     * see countParagraphs() for why this fallback exists.
     *
     * @return string[] Non-empty trimmed blocks.
     */
    private static function splitPlainTextParagraphs(string $textContent): array
    {
        if (trim($textContent) === '') {
            return [];
        }

        $blocks = preg_split('/\n\s*\n/', trim($textContent)) ?: [];

        return array_values(array_filter(
            array_map('trim', $blocks),
            static fn (string $b) => $b !== '',
        ));
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
