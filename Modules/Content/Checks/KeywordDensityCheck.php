<?php

/**
 * Checks average keyword density across all focus keywords
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
 * KeywordDensityCheck class
 */
class KeywordDensityCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'keyword_density';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if (empty($context->focusKeywords)) {
            return CheckResult::pass($this->getName(), '');
        }

        $totalWords = $context->wordCount;
        if ($totalWords === 0) {
            return CheckResult::pass($this->getName(), '');
        }

        $normContent = $context->normalizedTextContent;

        $densities = [];
        foreach ($context->focusKeywords as $kw) {
            $normKw      = AnalysisContext::normalizeForMatch(trim($kw));
            $count       = $normKw !== '' ? mb_substr_count($normContent, $normKw) : 0;
            $densities[] = ($count / $totalWords) * 100;
        }

        $avgDensity = count($densities) > 0 ? array_sum($densities) / count($densities) : 0;

        $config = $context->config;

        if ($avgDensity < $config['keyword_density_min']) {
            return CheckResult::warning(
                $this->getName(),
                /* translators: %.2f: keyword density percentage */
                sprintf(__('Keyword density: %.2f%% (low)', 'seopulse'), $avgDensity),
                8,
                [],
                [
                    [
                        'type'             => 'keyword_density',
                        'priority'         => 'medium',
                        'message'          => sprintf(
                            /* translators: %1$.2f: current keyword density, %2$.1f: minimum recommended density */
                            __('Keyword density is %1$.2f%%. Aim for at least %2$.1f%% for better optimization.', 'seopulse'),
                            $avgDensity,
                            $config['keyword_density_min'],
                        ),
                        'action'           => __('Use your focus keyword more often in the content', 'seopulse'),
                        'estimated_impact' => 8,
                    ],
                ],
            );
        }

        if ($avgDensity > $config['keyword_density_max']) {
            return CheckResult::warning(
                $this->getName(),
                /* translators: %.2f: keyword density percentage */
                sprintf(__('Keyword density: %.2f%% (too high)', 'seopulse'), $avgDensity),
                10,
                [
                    [
                        'type'     => 'keyword_density_high',
                        'severity' => 'medium',
                        'message'  => __('Keyword density is too high', 'seopulse'),
                    ],
                ],
                [
                    [
                        'type'             => 'keyword_density',
                        'priority'         => 'medium',
                        'message'          => sprintf(
                            /* translators: %.2f: keyword density percentage */
                            __('Keyword density is %.2f%%. This might be considered keyword stuffing. Aim for 0.5-2.5%%.', 'seopulse'),
                            $avgDensity,
                        ),
                        'action'           => __('Reduce keyword usage and use synonyms and related terms', 'seopulse'),
                        'estimated_impact' => 10,
                    ],
                ],
            );
        }

        return CheckResult::pass(
            $this->getName(),
            /* translators: %.2f: keyword density percentage */
            sprintf(__('Keyword density: %.2f%% (optimal)', 'seopulse'), $avgDensity),
        );
    }
}
