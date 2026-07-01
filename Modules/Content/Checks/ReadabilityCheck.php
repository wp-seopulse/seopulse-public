<?php

/**
 * Basic readability score using Flesch-Kincaid reading ease
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
 * ReadabilityCheck class
 */
class ReadabilityCheck implements ContentCheck
{
    public function getName(): string
    {
        return 'readability';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        $text = $context->textContent;
        if ($context->wordCount < 50) {
            return CheckResult::pass($this->getName(), '');
        }

        $sentences     = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $sentences     = array_filter($sentences, fn ($s) => str_word_count(trim($s)) > 0);
        $sentenceCount = count($sentences);

        if ($sentenceCount === 0) {
            return CheckResult::pass($this->getName(), '');
        }

        $wordCount     = $context->wordCount;
        $syllableCount = self::countSyllables($text);

        // Flesch Reading Ease
        $score = 206.835 - (1.015 * ($wordCount / $sentenceCount)) - (84.6 * ($syllableCount / $wordCount));
        $score = max(0, min(100, $score));

        if ($score < 30) {
            return CheckResult::warning(
                $this->getName(),
                /* translators: %.0f: Flesch readability score */
                sprintf(__('Readability score: %.0f (very difficult)', 'seopulse'), $score),
                5,
                [],
                [
                    [
                        'type'             => 'readability',
                        'priority'         => 'medium',
                        'message'          => sprintf(
                            /* translators: %.0f: Flesch readability score */
                            __('Your content has a Flesch readability score of %.0f (very difficult to read). Aim for 60+ for general audiences.', 'seopulse'),
                            $score,
                        ),
                        'action'           => __('Use shorter sentences and simpler words to improve readability', 'seopulse'),
                        'estimated_impact' => 5,
                    ],
                ],
            );
        }

        if ($score < 50) {
            return CheckResult::warning(
                $this->getName(),
                /* translators: %.0f: Flesch readability score */
                sprintf(__('Readability score: %.0f (difficult)', 'seopulse'), $score),
                3,
                [],
                [
                    [
                        'type'             => 'readability',
                        'priority'         => 'low',
                        'message'          => sprintf(
                            /* translators: %.0f: Flesch readability score */
                            __('Your content has a Flesch readability score of %.0f (difficult). Consider simplifying for a wider audience.', 'seopulse'),
                            $score,
                        ),
                        'action'           => __('Break up complex sentences and use more common vocabulary', 'seopulse'),
                        'estimated_impact' => 3,
                    ],
                ],
            );
        }

        return CheckResult::pass(
            $this->getName(),
            /* translators: %.0f: Flesch readability score */
            sprintf(__('Readability score: %.0f (good)', 'seopulse'), $score),
        );
    }

    /**
     * Approximate English syllable count
     */
    private static function countSyllables(string $text): int
    {
        $words = str_word_count(mb_strtolower($text), 1);
        $total = 0;

        foreach ($words as $word) {
            $word = preg_replace('/[^a-z]/', '', $word);
            if (mb_strlen($word) <= 3) {
                $total += 1;
                continue;
            }
            // Remove trailing silent e
            $word = preg_replace('/e$/', '', $word);
            // Count vowel groups
            preg_match_all('/[aeiouy]+/', $word, $m);
            $count  = count($m[0]);
            $total += max(1, $count);
        }

        return $total;
    }
}
