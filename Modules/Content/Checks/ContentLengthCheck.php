<?php

/**
 * Checks content word count against configured thresholds
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
 * ContentLengthCheck class
 */
class ContentLengthCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'content_length';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        $wordCount = $context->wordCount;
        $config    = $context->config;

        if ($wordCount < $config['min_word_count']) {
            return CheckResult::error(
                $this->getName(),
                /* translators: %d: word count */
                sprintf(__('Content too short: %d words', 'seopulse'), $wordCount),
                20,
                [
                    [
                        'type'     => 'content_too_short',
                        'severity' => 'high',
                        'message'  => __('Content is too short', 'seopulse'),
                    ],
                ],
                [
                    [
                        'type'             => 'content_length',
                        'priority'         => 'high',
                        'message'          => sprintf(
                            /* translators: %1$d: current word count, %2$d: minimum recommended word count */
                            __('Your content has %1$d words. Aim for at least %2$d words for better SEO performance.', 'seopulse'),
                            $wordCount,
                            $config['min_word_count'],
                        ),
                        'action'           => __('Expand your content with valuable information, examples, or details', 'seopulse'),
                        'estimated_impact' => 20,
                    ],
                ],
            );
        }

        if ($wordCount < $config['optimal_word_count']) {
            return CheckResult::warning(
                $this->getName(),
                /* translators: %d: word count */
                sprintf(__('Content length: %d words (good)', 'seopulse'), $wordCount),
                5,
                [],
                [
                    [
                        'type'             => 'content_length',
                        'priority'         => 'medium',
                        'message'          => sprintf(
                            /* translators: %d: word count */
                            __('Your content has %d words. Consider adding more depth for competitive topics.', 'seopulse'),
                            $wordCount,
                        ),
                        'action'           => __('Add more detailed sections, case studies, or examples', 'seopulse'),
                        'estimated_impact' => 5,
                    ],
                ],
            );
        }

        if ($wordCount >= $config['excellent_word_count']) {
            return CheckResult::pass(
                $this->getName(),
                /* translators: %d: word count */
                sprintf(__('Excellent content length: %d words', 'seopulse'), $wordCount),
            );
        }

        return CheckResult::pass(
            $this->getName(),
            /* translators: %d: word count */
            sprintf(__('Good content length: %d words', 'seopulse'), $wordCount),
        );
    }
}
