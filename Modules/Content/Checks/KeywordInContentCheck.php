<?php

/**
 * Checks that focus keywords appear somewhere in the content
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
 * KeywordInContentCheck class
 */
class KeywordInContentCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'keyword_in_content';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if (empty($context->focusKeywords)) {
            return CheckResult::pass($this->getName(), '');
        }

        if (AnalysisContext::hasAnyKeywordIn($context->textContent, $context->focusKeywords)) {
            return CheckResult::pass(
                $this->getName(),
                __('Focus keyword found in content', 'seopulse'),
            );
        }

        return CheckResult::error(
            $this->getName(),
            __('Focus keyword not found in content', 'seopulse'),
            15,
            [
                [
                    'type'     => 'keyword_not_in_content',
                    'severity' => 'high',
                    'message'  => __('Focus keywords not found in content', 'seopulse'),
                ],
            ],
            [
                [
                    'type'             => 'keyword_usage',
                    'priority'         => 'high',
                    'message'          => sprintf(
                        /* translators: %s: focus keyword */
                        __('Your focus keyword "%s" doesn\'t appear in the content.', 'seopulse'),
                        $context->primaryKeyword,
                    ),
                    'action'           => __('Naturally incorporate your focus keyword throughout the text', 'seopulse'),
                    'estimated_impact' => 15,
                ],
            ],
        );
    }
}
