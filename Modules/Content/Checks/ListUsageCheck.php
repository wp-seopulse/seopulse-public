<?php

/**
 * Checks for the presence of lists (ul/ol) in content
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
 * ListUsageCheck class
 */
class ListUsageCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'list_usage';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        // Only relevant for longer content
        if ($context->wordCount < 300) {
            return CheckResult::pass($this->getName(), '');
        }

        $ulCount    = preg_match_all('/<ul[\s>]/i', $context->content);
        $olCount    = preg_match_all('/<ol[\s>]/i', $context->content);
        $totalLists = $ulCount + $olCount;

        if ($totalLists === 0) {
            return CheckResult::warning(
                $this->getName(),
                __('No lists found in content', 'seopulse'),
                2,
                [],
                [
                    [
                        'type'             => 'list_usage',
                        'priority'         => 'low',
                        'message'          => __('Your content doesn\'t use any lists. Bulleted or numbered lists improve scannability.', 'seopulse'),
                        'action'           => __('Add at least one list to highlight key points', 'seopulse'),
                        'estimated_impact' => 2,
                    ],
                ],
            );
        }

        return CheckResult::pass(
            $this->getName(),
            /* translators: %d: number of lists */
            sprintf(__('%d list(s) found in content', 'seopulse'), $totalLists),
        );
    }
}
