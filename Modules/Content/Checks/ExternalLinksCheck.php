<?php

/**
 * Checks for at least one external link
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
 * ExternalLinksCheck class
 */
class ExternalLinksCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'external_links';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/is', $context->content, $linkMatches);

        $homeUrl       = home_url();
        $externalLinks = 0;

        foreach ($linkMatches[1] as $url) {
            if (strpos($url, $homeUrl) === false && strpos($url, '/') !== 0 && strpos($url, 'http') === 0) {
                ++$externalLinks;
            }
        }

        $config = $context->config;

        if ($externalLinks < $config['min_external_links']) {
            return CheckResult::warning(
                $this->getName(),
                __('No external links', 'seopulse'),
                3,
                [],
                [
                    [
                        'type'             => 'external_links',
                        'priority'         => 'low',
                        'message'          => __('Your content has no external links.', 'seopulse'),
                        'action'           => __('Link to authoritative external sources to add credibility', 'seopulse'),
                        'estimated_impact' => 3,
                    ],
                ],
            );
        }

        return CheckResult::pass(
            $this->getName(),
            /* translators: %d: number of external links */
            sprintf(__('%d external link(s) found', 'seopulse'), $externalLinks),
        );
    }
}
