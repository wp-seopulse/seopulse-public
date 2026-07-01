<?php

/**
 * FAQ parser service for extracting and normalizing FAQs
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FAQParser — static service for parsing FAQs from multiple sources
 */
class FAQParser
{
    /**
     * Parse FAQ data from custom FAQ storage
     *
     * Normalizes FAQ array with 'question' and 'answer' keys.
     * Applies sanitization to ensure data integrity.
     *
     * @param array<mixed> $faq_data FAQ array (may contain 'question' and 'answer' keys)
     *
     * @return array<int, array<string, string>> Normalized FAQs: [['question' => '...', 'answer' => '...'], ...]
     */
    public static function parse_custom_faqs(array $faq_data): array
    {
        if (empty($faq_data)) {
            return [];
        }

        $parsed = [];

        foreach ($faq_data as $faq) {
            if (!is_array($faq)) {
                continue;
            }

            $question = isset($faq['question']) ? trim((string) $faq['question']) : '';
            $answer   = isset($faq['answer']) ? trim((string) $faq['answer']) : '';

            // Skip empty Q&A pairs
            if (empty($question) || empty($answer)) {
                continue;
            }

            // Sanitize answer (allow HTML tags for rich content)
            $sanitized_answer = wp_kses_post($answer);

            $parsed[] = [
                'question' => sanitize_text_field($question),
                'answer'   => $sanitized_answer,
            ];
        }

        return $parsed;
    }

    /**
     * Extract FAQs from post content (Gutenberg core/faq blocks)
     *
     * Parses HTML content for Gutenberg FAQ blocks and extracts
     * questions and answers within those blocks.
     *
     * @param string $content Post HTML content
     *
     * @return array<int, array<string, string>> Extracted FAQs: [['question' => '...', 'answer' => '...'], ...]
     */
    public static function extract_faq_blocks(string $content): array
    {
        if (empty($content)) {
            return [];
        }

        $faqs = [];

        // Match Gutenberg FAQ blocks: <!-- wp:faq ... -->...</wp:faq -->
        // We need to be flexible with block attributes
        if (!preg_match_all('#<!-- wp:faq.*?-->(.*?)<!-- /wp:faq -->#is', $content, $matches)) {
            return [];
        }

        // Each match contains the block HTML
        foreach ($matches[1] as $block_content) {
            // Parse individual FAQ items within the block
            // FAQ blocks typically contain nested structures like:
            // <!-- wp:faq-item {"question":"Q?","answer":"A?"} -->
            // or <div class="wp-block-faq-item"> structures

            // First, try to extract from comment attributes
            if (preg_match_all('#<!-- wp:faq.*?{(.*?)}"#is', $block_content, $attr_matches)) {
                foreach ($attr_matches[1] as $attrs) {
                    $faq = self::parse_faq_attributes($attrs);
                    if (!empty($faq['question']) && !empty($faq['answer'])) {
                        $faqs[] = $faq;
                    }
                }
            }

            // Also try to extract from nested question/answer divs or similar structures
            if (preg_match_all('#<dt[^>]*>(.*?)</dt>\s*<dd[^>]*>(.*?)</dd>#is', $block_content, $dt_dd)) {
                for ($i = 0; $i < count($dt_dd[1]); $i++) {
                    $question = wp_strip_all_tags($dt_dd[1][ $i ]);
                    $answer   = wp_strip_all_tags($dt_dd[2][ $i ]);

                    if (!empty($question) && !empty($answer)) {
                        $faqs[] = [
                            'question' => sanitize_text_field(trim($question)),
                            'answer'   => wp_kses_post(trim($answer)),
                        ];
                    }
                }
            }
        }

        return $faqs;
    }

    /**
     * Parse FAQ attributes from Gutenberg block JSON-like string
     *
     * Attempts to extract question and answer from block attribute string.
     *
     * @param string $attr_string Attribute string
     *
     * @return array<string, string> Parsed FAQ: ['question' => '...', 'answer' => '...']
     */
    private static function parse_faq_attributes(string $attr_string): array
    {
        $faq = [];

        // Try to extract "question":"..." pattern
        if (preg_match('/"question"\s*:\s*"([^"]+)"/', $attr_string, $q_match)) {
            $faq['question'] = sanitize_text_field(trim($q_match[1]));
        }

        // Try to extract "answer":"..." pattern
        if (preg_match('/"answer"\s*:\s*"([^"]+)"/', $attr_string, $a_match)) {
            $faq['answer'] = wp_kses_post(trim($a_match[1]));
        }

        return $faq;
    }
}
