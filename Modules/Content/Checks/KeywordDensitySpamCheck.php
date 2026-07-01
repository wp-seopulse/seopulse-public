<?php

/**
 * Flags total combined keyword density > 5% as spam risk
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
 * KeywordDensitySpamCheck class
 */
class KeywordDensitySpamCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'keyword_density_spam';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if (empty($context->focusKeywords) || $context->wordCount === 0) {
            return CheckResult::pass($this->getName(), '');
        }

        $normContent = $context->normalizedTextContent;
        $totalWords  = $context->wordCount;

        $totalDensity = 0;
        foreach ($context->focusKeywords as $kw) {
            $normKw        = AnalysisContext::normalizeForMatch(trim($kw));
            $count         = $normKw !== '' ? mb_substr_count($normContent, $normKw) : 0;
            $totalDensity += ($count / $totalWords) * 100;
        }

        if ($totalDensity > 5.0) {
            return CheckResult::warning(
                $this->getName(),
                /* translators: %.2f: total keyword density percentage */
                sprintf(__('Total keyword density: %.2f%% (spam risk)', 'seopulse'), $totalDensity),
                8,
                [
                    [
                        'type'     => 'total_keyword_density_spam',
                        'severity' => 'medium',
                        'message'  => __('Total keyword density exceeds 5% — spam risk', 'seopulse'),
                    ],
                ],
                [
                    [
                        'type'             => 'keyword_density_total',
                        'priority'         => 'high',
                        'message'          => sprintf(
                            /* translators: %.2f: combined keyword density percentage */
                            __('Combined keyword density is %.2f%%. This may be flagged as keyword stuffing by search engines.', 'seopulse'),
                            $totalDensity,
                        ),
                        'action'           => __('Reduce keyword repetition across all focus keywords', 'seopulse'),
                        'estimated_impact' => 8,
                    ],
                ],
            );
        }

        return CheckResult::pass(
            $this->getName(),
            /* translators: %.2f: total keyword density percentage */
            sprintf(__('Total keyword density: %.2f%% (OK)', 'seopulse'), $totalDensity),
        );
    }
}
