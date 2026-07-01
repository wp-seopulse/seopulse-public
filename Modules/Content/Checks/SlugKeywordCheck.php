<?php

/**
 * Checks that focus keyword appears in the post slug/permalink
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
 * SlugKeywordCheck class
 */
class SlugKeywordCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'keyword_in_slug';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if (empty($context->focusKeywords)) {
            return CheckResult::pass($this->getName(), '');
        }

        $slug = $context->post->post_name;
        if (empty($slug)) {
            return CheckResult::warning(
                $this->getName(),
                __('Post slug is not set yet', 'seopulse'),
            );
        }

        // Normalize slug: replace dashes with spaces for matching
        $normalizedSlug = str_replace(['-', '_'], ' ', $slug);

        if (AnalysisContext::hasAnyKeywordIn($normalizedSlug, $context->focusKeywords)) {
            return CheckResult::pass(
                $this->getName(),
                __('Focus keyword appears in the URL slug', 'seopulse'),
            );
        }

        return CheckResult::warning(
            $this->getName(),
            __('Focus keyword not found in URL slug', 'seopulse'),
            3,
            [],
            [
                [
                    'type'             => 'keyword_slug',
                    'priority'         => 'low',
                    'message'          => __('Your focus keyword doesn\'t appear in the URL slug.', 'seopulse'),
                    'action'           => __('Edit the permalink to include your focus keyword', 'seopulse'),
                    'estimated_impact' => 3,
                ],
            ],
        );
    }
}
