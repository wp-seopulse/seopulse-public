<?php

/**
 * Checks that the focus keyword appears in at least one H2 heading
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
 * HeadingKeywordCheck class
 */
class HeadingKeywordCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'keyword_in_headings';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if (empty($context->focusKeywords)) {
            return CheckResult::pass($this->getName(), '');
        }

        foreach ($context->headings['h2']['texts'] as $h2Text) {
            if (AnalysisContext::hasAnyKeywordIn($h2Text, $context->focusKeywords)) {
                return CheckResult::pass(
                    $this->getName(),
                    __('Focus keyword appears in at least one H2 heading', 'seopulse'),
                );
            }
        }

        return CheckResult::warning(
            $this->getName(),
            __('Focus keyword not found in H2 headings', 'seopulse'),
            8,
            [],
            [
                [
                    'type'             => 'keyword_headings',
                    'priority'         => 'medium',
                    'message'          => __('Your focus keyword doesn\'t appear in any H2 headings.', 'seopulse'),
                    'action'           => __('Include your focus keyword in at least one H2 heading', 'seopulse'),
                    'estimated_impact' => 8,
                ],
            ],
        );
    }
}
