<?php

/**
 * Penalizes when focus keywords overlap too much (Jaccard > 0.7)
 *
 * @package SEOPulse\Modules\Content\Checks
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Content\Checks;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\Content\CheckResult;

/**
 * KeywordSimilarityCheck class
 */
class KeywordSimilarityCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'keyword_similarity';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        $keywords = $context->focusKeywords;

        if (count($keywords) < 2) {
            return CheckResult::pass($this->getName(), '');
        }

        $normalized = array_map(
            fn ($kw) => explode(' ', AnalysisContext::normalizeForMatch($kw)),
            $keywords,
        );

        $similarPairs = 0;
        $count        = count($normalized);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $wordsA = array_filter($normalized[ $i ], fn ($w) => mb_strlen($w) > 1);
                $wordsB = array_filter($normalized[ $j ], fn ($w) => mb_strlen($w) > 1);

                if (empty($wordsA) || empty($wordsB)) {
                    continue;
                }

                $intersection = array_intersect($wordsA, $wordsB);
                $union        = array_unique(array_merge($wordsA, $wordsB));
                $jaccard      = count($union) > 0 ? count($intersection) / count($union) : 0;

                if ($jaccard > 0.7) {
                    ++$similarPairs;
                }
            }
        }

        if ($similarPairs > 0) {
            $penalty = min($similarPairs * 5, 15);

            return CheckResult::warning(
                $this->getName(),
                /* translators: %d: number of similar keyword pairs */
                sprintf(__('%d keyword pair(s) are too similar', 'seopulse'), $similarPairs),
                $penalty,
                [],
                [
                    [
                        'type'             => 'keyword_similarity',
                        'priority'         => 'medium',
                        'message'          => __('Some focus keywords are very similar to each other. Use more distinct keywords for broader SEO coverage.', 'seopulse'),
                        'action'           => __('Diversify your focus keywords to target different search intents', 'seopulse'),
                        'estimated_impact' => $penalty,
                    ],
                ],
            );
        }

        return CheckResult::pass(
            $this->getName(),
            __('Focus keywords are sufficiently distinct', 'seopulse'),
        );
    }
}
