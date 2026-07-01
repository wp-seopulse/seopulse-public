<?php

/**
 * Checks title length against configured thresholds
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
 * TitleLengthCheck class
 */
class TitleLengthCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'title_length';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        $length = mb_strlen($context->title);

        if ($length === 0) {
            // Handled by TitlePresenceCheck
            return CheckResult::pass($this->getName(), '');
        }

        $config = $context->config;

        if ($length < $config['title_min_length']) {
            return CheckResult::error(
                $this->getName(),
                /* translators: %d: title character count */
                sprintf(__('Title is too short (%d chars)', 'seopulse'), $length),
                15,
                [
                    [
                        'type'     => 'title_too_short',
                        'severity' => 'high',
                        'message'  => __('Title is too short', 'seopulse'),
                    ],
                ],
                [
                    [
                        'type'             => 'title_length',
                        'priority'         => 'high',
                        'message'          => sprintf(
                            /* translators: %d: title character count */
                            __('Your title has %d characters. Aim for 50-60 characters for optimal display in search results.', 'seopulse'),
                            $length,
                        ),
                        'action'           => __('Expand your title to include more descriptive keywords', 'seopulse'),
                        'estimated_impact' => 15,
                    ],
                ],
            );
        }

        if ($length > $config['title_max_length']) {
            return CheckResult::warning(
                $this->getName(),
                /* translators: %d: title character count */
                sprintf(__('Title is too long (%d chars)', 'seopulse'), $length),
                10,
                [
                    [
                        'type'     => 'title_too_long',
                        'severity' => 'medium',
                        'message'  => __('Title may be truncated in search results', 'seopulse'),
                    ],
                ],
                [
                    [
                        'type'             => 'title_length',
                        'priority'         => 'medium',
                        'message'          => sprintf(
                            /* translators: %d: title character count */
                            __('Your title has %d characters. Titles over 60 characters may be cut off in search results.', 'seopulse'),
                            $length,
                        ),
                        'action'           => __('Shorten your title while keeping the main keywords', 'seopulse'),
                        'estimated_impact' => 10,
                    ],
                ],
            );
        }

        if ($length >= $config['title_optimal_min'] && $length <= $config['title_optimal_max']) {
            return CheckResult::pass(
                $this->getName(),
                /* translators: %d: title character count */
                sprintf(__('Title length is optimal (%d chars)', 'seopulse'), $length),
            );
        }

        return CheckResult::warning(
            $this->getName(),
            /* translators: %d: title character count */
            sprintf(__('Title length is acceptable (%d chars)', 'seopulse'), $length),
        );
    }
}
