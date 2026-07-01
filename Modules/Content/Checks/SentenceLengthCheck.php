<?php

/**
 * Checks for excessively long sentences (readability)
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
 * SentenceLengthCheck class
 */
class SentenceLengthCheck implements ContentCheck
{
    private const MAX_WORDS_PER_SENTENCE  = 25;
    private const LONG_SENTENCE_THRESHOLD = 0.25; // 25% of sentences too long triggers warning

    public function getName(): string
    {
        return 'sentence_length';
    }

    public function run(AnalysisContext $context): CheckResult
    {
        $text = $context->textContent;
        if (mb_strlen($text) < 10) {
            return CheckResult::pass($this->getName(), '');
        }

        // Split on sentence-ending punctuation
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $sentences = array_filter($sentences, fn ($s) => str_word_count(trim($s)) > 2);
        $total     = count($sentences);

        if ($total === 0) {
            return CheckResult::pass($this->getName(), '');
        }

        $longSentences = 0;
        foreach ($sentences as $sentence) {
            if (str_word_count(trim($sentence)) > self::MAX_WORDS_PER_SENTENCE) {
                ++$longSentences;
            }
        }

        $ratio = $longSentences / $total;

        if ($ratio > self::LONG_SENTENCE_THRESHOLD) {
            return CheckResult::warning(
                $this->getName(),
                /* translators: %1$d: number of long sentences, %2$d: total sentences */
                sprintf(__('%1$d of %2$d sentences are too long', 'seopulse'), $longSentences, $total),
                3,
                [],
                [
                    [
                        'type'             => 'sentence_length',
                        'priority'         => 'low',
                        'message'          => sprintf(
                            /* translators: %1$d: number of long sentences, %2$d: total sentences */
                            __('%1$d of %2$d sentences exceed 25 words. Aim for concise sentences to improve readability.', 'seopulse'),
                            $longSentences,
                            $total,
                        ),
                        'action'           => __('Split long sentences into shorter, clearer ones', 'seopulse'),
                        'estimated_impact' => 3,
                    ],
                ],
            );
        }

        return CheckResult::pass(
            $this->getName(),
            __('Sentence lengths are reasonable', 'seopulse'),
        );
    }
}
