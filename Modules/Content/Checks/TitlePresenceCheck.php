<?php

/**
 * Checks that the post has a title
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
 * TitlePresenceCheck class
 */
class TitlePresenceCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'title_present';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if (empty($context->title)) {
            return CheckResult::error(
                $this->getName(),
                __('Post title is missing', 'seopulse'),
                30,
                [
                    [
                        'type'     => 'title_missing',
                        'severity' => 'critical',
                        'message'  => __('Title is missing', 'seopulse'),
                    ],
                ],
                [
                    [
                        'type'             => 'title_missing',
                        'priority'         => 'critical',
                        'message'          => __('Your post has no title. This is critical for SEO.', 'seopulse'),
                        'action'           => __('Add a descriptive, keyword-rich title (50-60 characters)', 'seopulse'),
                        'estimated_impact' => 30,
                    ],
                ],
            );
        }

        return CheckResult::pass($this->getName(), __('Post title is present', 'seopulse'));
    }
}
