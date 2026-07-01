<?php

/**
 * Checks for featured image presence and dimensions
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
 * FeaturedImageCheck class
 */
class FeaturedImageCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'featured_image';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if (!$context->hasFeaturedImage) {
            return CheckResult::warning(
                $this->getName(),
                __('No featured image', 'seopulse'),
                10,
                [],
                [
                    [
                        'type'             => 'featured_image',
                        'priority'         => 'high',
                        'message'          => __('No featured image is set.', 'seopulse'),
                        'action'           => __('Add a featured image to improve social sharing and visual appeal', 'seopulse'),
                        'estimated_impact' => 10,
                    ],
                ],
                ['extra_checks' => []],
            );
        }

        // Featured image exists — also check dimensions
        $extraChecks     = [];
        $penalty         = 0;
        $recommendations = [];

        if ($context->featuredImageWidth > 0 && $context->featuredImageHeight > 0) {
            if ($context->featuredImageWidth < 1200 || $context->featuredImageHeight < 630) {
                $penalty          += 4;
                $recommendations[] = [
                    'type'             => 'featured_image_size',
                    'priority'         => 'low',
                    'message'          => sprintf(
                        /* translators: %1$d: image width in pixels, %2$d: image height in pixels */
                        __('Featured image is %1$d×%2$d px — below 1200×630 recommended for social sharing.', 'seopulse'),
                        $context->featuredImageWidth,
                        $context->featuredImageHeight,
                    ),
                    'action'           => __('Use a featured image of at least 1200×630 pixels for optimal display on social networks', 'seopulse'),
                    'estimated_impact' => 4,
                ];
                $extraChecks[]     = [
                    'name'    => 'featured_image_size',
                    'status'  => 'warning',
                    /* translators: %1$d: image width in pixels, %2$d: image height in pixels */
                    'message' => sprintf(__('Featured image too small (%1$d×%2$d)', 'seopulse'), $context->featuredImageWidth, $context->featuredImageHeight),
                ];
            } else {
                $extraChecks[] = [
                    'name'    => 'featured_image_size',
                    'status'  => 'success',
                    /* translators: %1$d: image width in pixels, %2$d: image height in pixels */
                    'message' => sprintf(__('Featured image size OK (%1$d×%2$d)', 'seopulse'), $context->featuredImageWidth, $context->featuredImageHeight),
                ];
            }
        }

        return new CheckResult(
            $this->getName(),
            'success',
            __('Featured image is set', 'seopulse'),
            $penalty,
            [],
            $recommendations,
            ['extra_checks' => $extraChecks],
        );
    }
}
