<?php

/**
 * Checks that the focus keyword appears in the title, and its position
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
 * TitleKeywordCheck class
 */
class TitleKeywordCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'keyword_in_title';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if (empty($context->focusKeywords)) {
            return CheckResult::pass($this->getName(), '');
        }

        if (AnalysisContext::hasAnyKeywordIn($context->title, $context->focusKeywords)) {
            return CheckResult::pass(
                $this->getName(),
                sprintf(
                    /* translators: %s: focus keyword */
                    __('Focus keyword "%s" is in the title', 'seopulse'),
                    $context->primaryKeyword,
                ),
            );
        }

        return CheckResult::error(
            $this->getName(),
            __('Focus keyword is not in the title', 'seopulse'),
            12,
            [
                [
                    'type'     => 'keyword_not_in_title',
                    'severity' => 'high',
                    'message'  => __('Focus keyword not found in title', 'seopulse'),
                ],
            ],
            [
                [
                    'type'             => 'keyword_title',
                    'priority'         => 'high',
                    'message'          => sprintf(
                        /* translators: %s: focus keyword */
                        __('Your focus keyword "%s" doesn\'t appear in the title.', 'seopulse'),
                        $context->primaryKeyword,
                    ),
                    'action'           => __('Include your focus keyword in the title, preferably near the beginning', 'seopulse'),
                    'estimated_impact' => 12,
                ],
            ],
        );
    }
}
