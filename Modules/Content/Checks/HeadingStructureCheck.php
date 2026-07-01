<?php

/**
 * Checks H2 presence, H1-in-content, and heading hierarchy
 *
 * Produces multiple named checks: has_h2_headings, no_h1_in_content, heading_hierarchy.
 * Returns the most severe result; extra checks are bundled as data.
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
 * HeadingStructureCheck class
 */
class HeadingStructureCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'has_h2_headings';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        $headings        = $context->headings;
        $penalty         = 0;
        $issues          = [];
        $recommendations = [];

        // H2 check
        if ($headings['h2']['count'] === 0 && $context->wordCount > 300) {
            $penalty          += 15;
            $issues[]          = [
                'type'     => 'no_h2_headings',
                'severity' => 'high',
                'message'  => __('No H2 headings found', 'seopulse'),
            ];
            $recommendations[] = [
                'type'             => 'heading_structure',
                'priority'         => 'high',
                'message'          => __('Your content lacks H2 headings. Headings help structure your content for readers and search engines.', 'seopulse'),
                'action'           => __('Break your content into sections with descriptive H2 headings', 'seopulse'),
                'estimated_impact' => 15,
            ];
            $status            = 'error';
            $message           = __('No H2 headings found in content', 'seopulse');
        } elseif ($headings['h2']['count'] >= $context->config['min_h2_count']) {
            $status = 'success';
            /* translators: %d: number of H2 headings */
            $message = sprintf(__('%d H2 headings found', 'seopulse'), $headings['h2']['count']);
        } else {
            $penalty += 5;
            $status   = 'warning';
            /* translators: %d: number of H2 headings */
            $message           = sprintf(__('Only %d H2 heading(s) found', 'seopulse'), $headings['h2']['count']);
            $recommendations[] = [
                'type'             => 'heading_structure',
                'priority'         => 'medium',
                'message'          => __('Add more H2 headings to better structure your content.', 'seopulse'),
                /* translators: %d: minimum recommended number of H2 headings */
                'action'           => sprintf(__('Add at least %d H2 headings', 'seopulse'), $context->config['min_h2_count']),
                'estimated_impact' => 5,
            ];
        }

        // H1 in content
        $extraChecks = [];
        if ($headings['h1']['count'] > 0) {
            $penalty          += 5;
            $issues[]          = [
                'type'     => 'multiple_h1',
                'severity' => 'low',
                'message'  => __('Multiple H1 tags detected in content', 'seopulse'),
            ];
            $recommendations[] = [
                'type'             => 'heading_structure',
                'priority'         => 'low',
                'message'          => __('Avoid using H1 tags in your content. The post title already serves as H1.', 'seopulse'),
                'action'           => __('Replace H1 tags with H2 or H3', 'seopulse'),
                'estimated_impact' => 5,
            ];
            $extraChecks[]     = [
                'name'    => 'no_h1_in_content',
                'status'  => 'warning',
                /* translators: %d: number of H1 tags */
                'message' => sprintf(__('%d H1 tag(s) found in content', 'seopulse'), $headings['h1']['count']),
            ];
        } else {
            $extraChecks[] = [
                'name'    => 'no_h1_in_content',
                'status'  => 'success',
                'message' => __('No H1 tags in content (good)', 'seopulse'),
            ];
        }

        // Heading hierarchy
        if (!self::isHeadingStructureValid($headings['structure'])) {
            $penalty          += 5;
            $recommendations[] = [
                'type'             => 'heading_hierarchy',
                'priority'         => 'low',
                'message'          => __('Your heading hierarchy is not optimal. Headings should follow a logical order (H2, then H3, etc.).', 'seopulse'),
                'action'           => __('Reorganize headings to follow a proper hierarchy', 'seopulse'),
                'estimated_impact' => 5,
            ];
            $extraChecks[]     = [
                'name'    => 'heading_hierarchy',
                'status'  => 'warning',
                'message' => __('Heading hierarchy needs improvement', 'seopulse'),
            ];
        } else {
            $extraChecks[] = [
                'name'    => 'heading_hierarchy',
                'status'  => 'success',
                'message' => __('Heading hierarchy is correct', 'seopulse'),
            ];
        }

        return new CheckResult(
            $this->getName(),
            $status,
            $message,
            $penalty,
            $issues,
            $recommendations,
            ['extra_checks' => $extraChecks],
        );
    }

    private static function isHeadingStructureValid(array $structure): bool
    {
        if (empty($structure)) {
            return true;
        }

        $previousLevel = 1;
        foreach ($structure as $heading) {
            if ($heading['level'] > $previousLevel + 1) {
                return false;
            }
            $previousLevel = $heading['level'];
        }

        return true;
    }
}
