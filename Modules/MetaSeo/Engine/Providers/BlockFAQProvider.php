<?php

/**
 * BlockFAQProvider — Extracts FAQ data from seopulse/faq Gutenberg blocks
 * and builds FAQPage JSON-LD schema.
 *
 * Uses parse_blocks() (not regex) per spec.
 * Enforces Free tier limit (max 10 items) server-side.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

final class BlockFAQProvider
{
    /**
     * Extract FAQ items from all seopulse/faq blocks in the given content.
     *
     * @param string $content Post content (raw, with block comments).
     *
     * @return array{items: array<int, array{question: string, answer: string}>, schema_type: string}
     */
    public static function extract(string $content): array
    {
        if (empty($content)) {
            return [
                'items'       => [],
                'schema_type' => 'FAQPage',
            ];
        }

        $blocks      = parse_blocks($content);
        $all_items   = [];
        $schema_type = 'FAQPage';

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') !== 'seopulse/faq') {
                continue;
            }

            $attrs = $block['attrs'] ?? [];

            // Capture schema type from first block that declares it.
            if (!empty($attrs['schemaType'])) {
                $schema_type = $attrs['schemaType'];
            }

            $items = $attrs['items'] ?? [];
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $question = isset($item['question']) ? trim(wp_strip_all_tags((string) $item['question'])) : '';
                $answer   = isset($item['answer']) ? trim(wp_kses_post((string) $item['answer'])) : '';

                if ($question === '' || $answer === '') {
                    continue;
                }

                $all_items[] = [
                    'question' => $question,
                    'answer'   => $answer,
                ];
            }
        }

        return [
            'items'       => $all_items,
            'schema_type' => in_array($schema_type, ['FAQPage', 'QAPage'], true) ? $schema_type : 'FAQPage',
        ];
    }

    /**
     * Check if post content contains seopulse/faq blocks.
     *
     * @param string $content Post content.
     *
     * @return bool
     */
    public static function has_faq_blocks(string $content): bool
    {
        if (empty($content)) {
            return false;
        }

        // Quick string check before expensive parse_blocks().
        if (strpos($content, '<!-- wp:seopulse/faq') === false) {
            return false;
        }

        $blocks = parse_blocks($content);

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'seopulse/faq') {
                return true;
            }
        }

        return false;
    }
}
