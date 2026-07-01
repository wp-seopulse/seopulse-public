<?php

/**
 * Interface for all individual content analysis checks
 *
 * Each check evaluates one specific SEO criterion and returns a CheckResult.
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
 * ContentCheck interface
 */
interface ContentCheck
{
    /**
     * Unique check identifier (e.g. 'title_present', 'keyword_density')
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Run the check against the analysis context
     *
     * @param AnalysisContext $context Shared data for all checks
     * @return CheckResult
     */
    public function run(AnalysisContext $context): CheckResult;
}
