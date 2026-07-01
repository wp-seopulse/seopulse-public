<?php

/**
 * Checks for excessively long paragraphs (readability)
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
 * ParagraphLengthCheck class
 */
class ParagraphLengthCheck implements ContentCheck
{
    private const MAX_WORDS_PER_PARAGRAPH = 150;

    public function getName(): string
    {
        return 'paragraph_length';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        if ($context->paragraphCount === 0) {
            return CheckResult::pass($this->getName(), '');
        }

        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $context->content, $matches);

        $longParagraphs = 0;
        foreach ($matches[1] as $paraHtml) {
            $text = wp_strip_all_tags($paraHtml);
            if (str_word_count($text) > self::MAX_WORDS_PER_PARAGRAPH) {
                ++$longParagraphs;
            }
        }

        if ($longParagraphs > 0) {
            return CheckResult::warning(
                $this->getName(),
                /* translators: %d: number of long paragraphs */
                sprintf(__('%d paragraph(s) are too long', 'seopulse'), $longParagraphs),
                3,
                [],
                [
                    [
                        'type'             => 'paragraph_length',
                        'priority'         => 'low',
                        'message'          => sprintf(
                            /* translators: %d: number of paragraphs exceeding the word limit */
                            __('%d paragraph(s) exceed 150 words. Shorter paragraphs improve readability.', 'seopulse'),
                            $longParagraphs,
                        ),
                        'action'           => __('Break long paragraphs into shorter ones', 'seopulse'),
                        'estimated_impact' => 3,
                    ],
                ],
            );
        }

        return CheckResult::pass(
            $this->getName(),
            __('All paragraphs have a reasonable length', 'seopulse'),
        );
    }
}
