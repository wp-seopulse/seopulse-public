<?php

/**
 * Content analysis module
 *
 * Executes registry-based checks on a post's content, title, headings,
 * keywords, images and links.
 *
 * @package SEOPulse\Modules\Content
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Content;

use SEOPulse\Core\Abstracts\Module;
use SEOPulse\Core\Attributes\AsModule;
use SEOPulse\Core\Contracts\ModuleInterface;
use SEOPulse\Core\Traits\FocusKeywordTrait;
use SEOPulse\Modules\Content\Checks\AnalysisContext;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ContentAnalyzer class
 */
#[AsModule(
    key: 'content_analysis',
    label: 'Content Analysis',
    description: 'SEO content analysis, meta tags audit and readability scoring.',
    icon: 'dashicons-analytics',
    namespace: 'SEOPulse\\Modules\\Content\\',
)]
class ContentAnalyzer extends Module implements ModuleInterface
{
    use FocusKeywordTrait;

    /**
     * Check visibility tiers for the metabox surface.
     *
     * core      — always visible in the metabox
     * secondary — visible in expanded "Detailed Checks" view
     */
    public const CHECK_TIERS = [
        // Core — checks an editor should see first
        'title_present'          => 'core',
        'title_length'           => 'core',
        'keyword_in_title'       => 'core',
        'content_length'         => 'core',
        'has_h2_headings'        => 'core',
        'keyword_in_content'     => 'core',
        'featured_image'         => 'core',
        'images_alt'             => 'core',
        'internal_links'         => 'core',

        // Secondary — useful but not first-glance
        'keyword_title_position' => 'secondary',
        'keyword_in_headings'    => 'secondary',
        'keyword_density'        => 'secondary',
        'keyword_in_intro'       => 'secondary',
        'no_h1_in_content'       => 'secondary',
        'heading_hierarchy'      => 'secondary',
        'has_images'             => 'secondary',
        'featured_image_size'    => 'secondary',
        'image_filenames'        => 'secondary',
        'external_links'         => 'secondary',
        'keyword_density_spam'   => 'secondary',
        'keyword_similarity'     => 'secondary',
        'keyword_in_slug'        => 'secondary',
        'paragraph_length'       => 'secondary',
        'sentence_length'        => 'secondary',
        'list_usage'             => 'secondary',
        'readability'            => 'secondary',
    ];

    private ContentCheckRegistry $registry;

    /**
     * @var array<string, mixed>
     */
    private array $config = [
        'min_word_count'          => 300,
        'optimal_word_count'      => 600,
        'excellent_word_count'    => 1500,
        'title_min_length'        => 30,
        'title_max_length'        => 65,
        'title_optimal_min'       => 50,
        'title_optimal_max'       => 60,
        'keyword_density_min'     => 0.5,
        'keyword_density_max'     => 2.5,
        'keyword_density_optimal' => 1.0,
        'min_internal_links'      => 2,
        'min_external_links'      => 1,
        'min_images'              => 1,
        'min_h2_count'            => 2,
    ];

    public function __construct(?ContentCheckRegistry $registry = null)
    {
        $this->name     = 'content';
        $this->weight   = 0.35;
        $this->config   = apply_filters('seopulse_content_analyzer_config', $this->config);
        $this->registry = $registry ?? ContentCheckRegistry::withDefaults();
    }

    /**
     * Analyzes a post's content using all registered checks.
     *
     * @param \WP_Post $post WordPress post
     * @return array{score: int, issues: array, recommendations: array, data: array, checks: array}
     */
    public function analyze(\WP_Post $post): array
    {
        $focusKeywords = $this->get_focus_keywords($post);
        $context       = AnalysisContext::fromPost($post, $this->config, $focusKeywords);

        $score           = 100;
        $issues          = [];
        $recommendations = [];
        $checks          = [];

        foreach ($this->registry->all() as $check) {
            $result = $check->run($context);

            if ($result->message === '') {
                continue;
            }

            $score          -= $result->penalty;
            $issues          = array_merge($issues, $result->issues);
            $recommendations = array_merge($recommendations, $result->recommendations);

            $checks[] = [
                'name'    => $result->name,
                'status'  => $result->status,
                'message' => $result->message,
            ];

            if (!empty($result->data['extra_checks'])) {
                foreach ($result->data['extra_checks'] as $extraCheck) {
                    $checks[] = $extraCheck;
                }
            }
        }

        return [
            'score'           => max(0, min(100, $score)),
            'issues'          => $issues,
            'recommendations' => $recommendations,
            'data'            => $this->buildDataBag($context),
            'checks'          => self::tagChecks($checks),
        ];
    }

    /**
     * Builds the data bag to match the legacy output shape.
     */
    private function buildDataBag(AnalysisContext $context): array
    {
        $wordCount = $context->wordCount;

        return [
            'title'    => [
                'text'       => $context->title,
                'length'     => mb_strlen($context->title),
                'word_count' => str_word_count($context->title),
            ],
            'headings' => [
                'h1_count'    => $context->headings['h1']['count'],
                'h2_count'    => $context->headings['h2']['count'],
                'h3_count'    => $context->headings['h3']['count'],
                'h4_count'    => $context->headings['h4']['count'],
                'total_count' => $context->headings['total'],
                'structure'   => $context->headings['structure'],
            ],
            'length'   => [
                'word_count'      => $wordCount,
                'char_count'      => $context->charCount,
                'paragraph_count' => $context->paragraphCount,
                'level'           => $this->getLengthLevel($wordCount),
            ],
            'keywords' => [
                'focus_keywords' => $context->focusKeywords,
                'focus_keyword'  => $context->primaryKeyword,
            ],
            'images'   => [
                'has_featured_image'    => $context->hasFeaturedImage,
                'featured_image_width'  => $context->featuredImageWidth,
                'featured_image_height' => $context->featuredImageHeight,
            ],
        ];
    }

    private function getLengthLevel(int $wordCount): string
    {
        if ($wordCount >= $this->config['excellent_word_count']) {
            return 'excellent';
        } elseif ($wordCount >= $this->config['optimal_word_count']) {
            return 'good';
        } elseif ($wordCount >= $this->config['min_word_count']) {
            return 'acceptable';
        }

        return 'poor';
    }

    /**
     * Enriches checks with their visibility tier.
     */
    private static function tagChecks(array $checks): array
    {
        foreach ($checks as &$check) {
            $check['tier'] = self::CHECK_TIERS[ $check['name'] ?? '' ] ?? 'secondary';
        }
        unset($check);

        return $checks;
    }

    public function getKey(): string
    {
        return 'content_analysis';
    }

    public function onActivate(): void
    {
    }

    public function onDeactivate(): void
    {
    }
}
