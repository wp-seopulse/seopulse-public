<?php

/**
 * Checks for image presence, alt attributes, and filename quality
 *
 * Produces named checks: has_images, images_alt, image_filenames
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
 * ImageAltCheck class
 */
class ImageAltCheck implements ContentCheck
{
    /**
     * Patterns for non-descriptive filenames
     */
    private const BAD_FILENAME_PATTERN = '/^(IMG|DSC|DSCN|DSCF|Screenshot|Capture|image|photo|picture|unnamed|untitled|file|download|media)[\s_\-]?\d*/i';

    public function getName(): string
    {
        return 'images_alt';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        preg_match_all('/<img[^>]+>/i', $context->content, $imgMatches);
        $imageCount = count($imgMatches[0]);

        $imagesWithoutAlt        = 0;
        $nonDescriptiveFilenames = 0;

        foreach ($imgMatches[0] as $imgTag) {
            // Check alt
            if (!preg_match('/alt=["\'][^"\']+["\']/i', $imgTag)) {
                ++$imagesWithoutAlt;
            }

            // Check filename quality
            if (preg_match('/src=["\']([^"\']+)["\']/i', $imgTag, $srcMatch)) {
                $filename = pathinfo(wp_parse_url($srcMatch[1], PHP_URL_PATH) ?? '', PATHINFO_FILENAME);
                if (preg_match(self::BAD_FILENAME_PATTERN, $filename)) {
                    ++$nonDescriptiveFilenames;
                }
            }
        }

        $penalty         = 0;
        $issues          = [];
        $recommendations = [];
        $extraChecks     = [];

        // has_images check
        if ($imageCount < $context->config['min_images']) {
            $penalty          += 5;
            $recommendations[] = [
                'type'             => 'content_images',
                'priority'         => 'low',
                'message'          => __('Your content has no images.', 'seopulse'),
                'action'           => __('Add relevant images to make your content more engaging', 'seopulse'),
                'estimated_impact' => 5,
            ];
            $extraChecks[]     = [
                'name'    => 'has_images',
                'status'  => 'warning',
                'message' => __('No images in content', 'seopulse'),
            ];
        } else {
            $extraChecks[] = [
                'name'    => 'has_images',
                'status'  => 'success',
                /* translators: %d: number of images */
                'message' => sprintf(__('%d image(s) in content', 'seopulse'), $imageCount),
            ];
        }

        // images_alt check (this is the primary check name)
        $altStatus  = 'success';
        $altMessage = __('All images have alt text', 'seopulse');

        if ($imagesWithoutAlt > 0) {
            $penalty  += 8;
            $altStatus = 'error';
            /* translators: %d: number of images missing alt text */
            $altMessage        = sprintf(__('%d image(s) without alt text', 'seopulse'), $imagesWithoutAlt);
            $issues[]          = [
                'type'     => 'missing_alt_tags',
                'severity' => 'medium',
                'message'  => __('Images without alt attributes', 'seopulse'),
            ];
            $recommendations[] = [
                'type'             => 'image_alt',
                'priority'         => 'medium',
                'message'          => sprintf(
                    /* translators: %d: number of images missing alt attributes */
                    _n(
                        '%d image is missing an alt attribute.',
                        '%d images are missing alt attributes.',
                        $imagesWithoutAlt,
                        'seopulse',
                    ),
                    $imagesWithoutAlt,
                ),
                'action'           => __('Add descriptive alt text to all images for better accessibility and SEO', 'seopulse'),
                'estimated_impact' => 8,
            ];
        } elseif ($imageCount === 0) {
            $altStatus  = 'success';
            $altMessage = '';
        }

        // image_filenames check
        if ($nonDescriptiveFilenames > 0) {
            $penalty          += 3;
            $recommendations[] = [
                'type'             => 'image_filenames',
                'priority'         => 'low',
                'message'          => sprintf(
                    /* translators: %d: number of images with non-descriptive filenames */
                    _n(
                        '%d image has a non-descriptive filename (e.g. IMG_001, Screenshot).',
                        '%d images have non-descriptive filenames (e.g. IMG_001, Screenshot).',
                        $nonDescriptiveFilenames,
                        'seopulse',
                    ),
                    $nonDescriptiveFilenames,
                ),
                'action'           => __('Rename image files with descriptive, keyword-rich names before uploading', 'seopulse'),
                'estimated_impact' => 3,
            ];
            $extraChecks[]     = [
                'name'    => 'image_filenames',
                'status'  => 'warning',
                /* translators: %d: number of images with non-descriptive filenames */
                'message' => sprintf(__('%d image(s) with non-descriptive filename', 'seopulse'), $nonDescriptiveFilenames),
            ];
        } elseif ($imageCount > 0) {
            $extraChecks[] = [
                'name'    => 'image_filenames',
                'status'  => 'success',
                'message' => __('All image filenames are descriptive', 'seopulse'),
            ];
        }

        return new CheckResult(
            $this->getName(),
            $altStatus,
            $altMessage,
            $penalty,
            $issues,
            $recommendations,
            ['extra_checks' => $extraChecks],
        );
    }
}
