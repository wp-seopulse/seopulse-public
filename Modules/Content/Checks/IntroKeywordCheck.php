<?php

/**
 * Checks that focus keyword appears in the first paragraph / introduction
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
 * IntroKeywordCheck class
 */
class IntroKeywordCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'keyword_in_intro';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if (empty($context->focusKeywords) || $context->firstParagraphText === '') {
            return CheckResult::pass($this->getName(), '');
        }

        if (AnalysisContext::hasAnyKeywordIn($context->firstParagraphText, $context->focusKeywords)) {
            return CheckResult::pass(
                $this->getName(),
                __('Focus keyword appears in the introduction', 'seopulse'),
            );
        }

        return CheckResult::warning(
            $this->getName(),
            __('Focus keyword not in first paragraph', 'seopulse'),
            5,
            [],
            [
                [
                    'type'             => 'keyword_intro',
                    'priority'         => 'low',
                    'message'          => __('Your focus keyword doesn\'t appear in the first paragraph.', 'seopulse'),
                    'action'           => __('Add your focus keyword in the introduction for better relevance', 'seopulse'),
                    'estimated_impact' => 5,
                ],
            ],
        );
    }
}
