<?php

/**
 * Checks keyword position in title (bonus if near the beginning)
 *
 * Produces the secondary "keyword_title_position" check.
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
 * TitleKeywordPositionCheck class
 */
class TitleKeywordPositionCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'keyword_title_position';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if (empty($context->focusKeywords)) {
            return CheckResult::pass($this->getName(), '');
        }

        if (!AnalysisContext::hasAnyKeywordIn($context->title, $context->focusKeywords)) {
            // No keyword in title — position check is moot
            return CheckResult::warning(
                $this->getName(),
                __('Cannot check keyword position — keyword not in title', 'seopulse'),
            );
        }

        $normTitle = AnalysisContext::normalizeForMatch($context->title);
        $normKw    = AnalysisContext::normalizeForMatch(trim($context->primaryKeyword));
        $pos       = $normKw !== '' ? mb_strpos($normTitle, $normKw) : false;

        if ($pos !== false && $pos < 10) {
            return CheckResult::pass(
                $this->getName(),
                __('Focus keyword is at the beginning of the title (optimal)', 'seopulse'),
            );
        }

        return CheckResult::warning(
            $this->getName(),
            __('Focus keyword is not at the beginning of the title', 'seopulse'),
        );
    }
}
