<?php

/**
 * Checks for sufficient internal links
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
 * InternalLinksCheck class
 */
class InternalLinksCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'internal_links';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/is', $context->content, $linkMatches);

        $homeUrl       = home_url();
        $internalLinks = 0;

        foreach ($linkMatches[1] as $url) {
            if (strpos($url, $homeUrl) !== false || strpos($url, '/') === 0) {
                ++$internalLinks;
            }
        }

        $config = $context->config;

        if ($internalLinks < $config['min_internal_links']) {
            return CheckResult::warning(
                $this->getName(),
                /* translators: %d: number of internal links */
                sprintf(__('Only %d internal link(s)', 'seopulse'), $internalLinks),
                8,
                [],
                [
                    [
                        'type'             => 'internal_links',
                        'priority'         => 'medium',
                        'message'          => sprintf(
                            /* translators: %d: number of internal links */
                            __('Your content has only %d internal link(s).', 'seopulse'),
                            $internalLinks,
                        ),
                        'action'           => sprintf(
                            /* translators: %d: minimum recommended number of internal links */
                            __('Add at least %d internal links to related content on your site', 'seopulse'),
                            $config['min_internal_links'],
                        ),
                        'estimated_impact' => 8,
                    ],
                ],
            );
        }

        return CheckResult::pass(
            $this->getName(),
            /* translators: %d: number of internal links */
            sprintf(__('%d internal link(s) found', 'seopulse'), $internalLinks),
        );
    }
}
