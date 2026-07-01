<?php

/**
 * FAQ schema provider for JSON-LD
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Services\FAQParser;

/**
 * FAQProvider — generates FAQPage schema for posts with FAQ data
 */
final class FAQProvider implements SchemaProvider
{
    /**
     * Store error message
     *
     * @var string|null
     */
    private ?string $error = null;

    /**
     * Get the schema type
     *
     * @return string
     */
    public function get_type(): string
    {
        return 'FAQPage';
    }

    /**
     * Check if this provider should inject on the current request
     *
     * Only inject if:
     * 1. On a singular post or page
     * 2. Post contains custom FAQs in post_meta OR Gutenberg FAQ blocks
     *
     * @return bool
     */
    public function should_inject(): bool
    {
        // Check admin toggle (default: enabled)
        $settings = get_option('seopulse_meta_seo_global', []);
        if (isset($settings['schema_faq_enabled']) && !$settings['schema_faq_enabled']) {
            return false;
        }

        // Only inject for singular posts
        if (!is_singular()) {
            return false;
        }

        global $post;
        if (!$post instanceof \WP_Post) {
            return false;
        }

        // Check for custom FAQs in post_meta
        $custom_faqs = get_post_meta($post->ID, '_seopulse_faqs', true);
        if (!empty($custom_faqs) && is_array($custom_faqs)) {
            return true;
        }

        // Check for seopulse/faq Gutenberg blocks in content
        if (!empty($post->post_content) && BlockFAQProvider::has_faq_blocks($post->post_content)) {
            return true;
        }

        return false;
    }

    /**
     * Build the FAQPage schema
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        global $post;

        if (!$post instanceof \WP_Post) {
            return [];
        }

        // Gather FAQs from all sources
        $faqs = $this->get_faqs($post);

        if (empty($faqs)) {
            return [];
        }

        // Build the FAQPage schema
        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $this->build_main_entity($faqs),
        ];

        return $schema;
    }

    /**
     * Validate the schema
     *
     * @return bool
     */
    public function validate(): bool
    {
        $schema = $this->build();

        if (empty($schema)) {
            $this->error = 'Schema is empty (no FAQs found)';

            return false;
        }

        if (empty($schema['mainEntity']) || !is_array($schema['mainEntity'])) {
            $this->error = 'mainEntity is missing or not an array';

            return false;
        }

        if (empty($schema['mainEntity'][0]['@type']) || $schema['mainEntity'][0]['@type'] !== 'Question') {
            $this->error = 'mainEntity must contain Question items';

            return false;
        }

        return true;
    }

    /**
     * Get error message
     *
     * @return string|null
     */
    public function get_error(): ?string
    {
        return $this->error;
    }

    /**
     * Get FAQs from all available sources
     *
     * Priority:
     * 1. Custom FAQs from post_meta (_seopulse_faqs)
     * 2. FAQs from Gutenberg blocks (seopulse/faq)
     *
     * @param \WP_Post $post
     *
     * @return array<int, array<string, string>> Array of FAQs: [['question' => '...', 'answer' => '...'], ...]
     */
    private function get_faqs(\WP_Post $post): array
    {
        // Priority 1: seopulse/faq Gutenberg blocks (take precedence over manual meta)
        if (!empty($post->post_content) && BlockFAQProvider::has_faq_blocks($post->post_content)) {
            $extracted = BlockFAQProvider::extract($post->post_content);
            if (!empty($extracted['items'])) {
                return $extracted['items'];
            }
        }

        // Priority 2: Custom FAQs from post_meta
        $custom_faqs = get_post_meta($post->ID, '_seopulse_faqs', true);
        if (!empty($custom_faqs) && is_array($custom_faqs)) {
            $parsed = FAQParser::parse_custom_faqs($custom_faqs);
            if (!empty($parsed)) {
                return $parsed;
            }
        }

        return [];
    }

    /**
     * Build mainEntity array for FAQPage schema
     *
     * Converts FAQ array to schema.org Question items.
     *
     * @param array<int, array<string, string>> $faqs Array of FAQs
     *
     * @return array<int, array<string, mixed>> Array of Question schema items
     */
    private function build_main_entity(array $faqs): array
    {
        $questions = [];

        foreach ($faqs as $faq) {
            if (empty($faq['question']) || empty($faq['answer'])) {
                continue;
            }

            $questions[] = [
                '@type'          => 'Question',
                'name'           => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $this->strip_tags_preserve_formatting($faq['answer']),
                ],
            ];
        }

        return $questions;
    }

    /**
     * Strip HTML tags but preserve formatting (spaces, paragraphs)
     *
     * Used to clean answer text for FAQPage schema while maintaining
     * paragraph breaks and basic readability.
     *
     * @param string $text Text with possible HTML
     *
     * @return string Cleaned text
     */
    private function strip_tags_preserve_formatting(string $text): string
    {
        // Replace paragraph closing tags with newlines
        $text = str_ireplace('</p>', "\n\n", $text);
        $text = str_ireplace('<br/>', "\n", $text);
        $text = str_ireplace('<br>', "\n", $text);

        // Remove all remaining HTML tags
        $text = wp_strip_all_tags($text);

        // Clean up excessive whitespace
        $text = preg_replace('/\n\n+/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }
}
