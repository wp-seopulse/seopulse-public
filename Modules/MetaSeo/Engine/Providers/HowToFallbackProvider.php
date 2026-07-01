<?php

/**
 * HowTo fallback provider — heading-based step detection
 *
 * Complements BlockHowToProvider by detecting structured steps from
 * heading patterns (Step 1, Step 2, etc.) when no seopulse/howto block
 * is present. Only activates when at least 3 valid steps are found.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HowToFallbackProvider — heading-based HowTo schema extraction
 */
final class HowToFallbackProvider implements SchemaProvider
{
    /**
     * Minimum number of valid steps required
     *
     * @var int
     */
    private const MIN_STEPS = 3;

    /**
     * Maximum length for step name
     *
     * @var int
     */
    private const MAX_NAME_LENGTH = 110;

    /**
     * Maximum length for step text
     *
     * @var int
     */
    private const MAX_TEXT_LENGTH = 1000;

    /**
     * Last error message
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
        return 'HowTo';
    }

    /**
     * Check if this provider should inject on the current request
     *
     * Only activates when:
     * - Singular post/page
     * - No seopulse/howto block present (BlockHowToProvider handles that)
     * - Content contains at least MIN_STEPS step-headings
     *
     * @return bool
     */
    public function should_inject(): bool
    {
        if (!is_singular()) {
            return false;
        }

        global $post;
        if (!$post instanceof \WP_Post) {
            return false;
        }

        $content = $post->post_content ?? '';

        if (empty($content)) {
            return false;
        }

        // Defer to BlockHowToProvider when block is present
        if (BlockHowToProvider::has_howto_blocks($content)) {
            return false;
        }

        // Quick check: must contain step-like headings
        if (!preg_match('/(?:step|étape|paso|schritt)\s+\d/i', $content)) {
            return false;
        }

        // Full parse to confirm >= MIN_STEPS
        $steps = $this->extract_steps($content);

        return count($steps) >= self::MIN_STEPS;
    }

    /**
     * Build the HowTo schema from heading-based steps
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        global $post;

        if (!$post instanceof \WP_Post) {
            return [];
        }

        $content = $post->post_content ?? '';
        $steps   = $this->extract_steps($content);

        if (count($steps) < self::MIN_STEPS) {
            return [];
        }

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'HowTo',
            'name'        => get_the_title($post),
            'description' => $this->get_description($post),
            'step'        => $this->build_steps($steps),
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
            $this->error = 'Schema is empty (no step headings found)';

            return false;
        }

        if (empty($schema['name'])) {
            $this->error = 'Missing name';

            return false;
        }

        if (empty($schema['step']) || !is_array($schema['step']) || count($schema['step']) < self::MIN_STEPS) {
            $this->error = 'HowTo requires at least ' . self::MIN_STEPS . ' steps';

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

    // ──────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────

    /**
     * Extract steps from rendered HTML content using heading patterns
     *
     * Looks for headings matching "Step N" (or localized variants),
     * then collects following paragraphs/lists as step text.
     *
     * @param string $content Raw post content
     * @return array<int, array{name: string, text: string}>
     */
    private function extract_steps(string $content): array
    {
        // Render blocks/shortcodes to get final HTML
        $html = do_blocks($content);
        $html = do_shortcodes_in_html_tags($html);
        $html = wpautop($html);

        // Split on heading tags (h2-h4)
        $pattern = '/<h[2-4][^>]*>(.*?)<\/h[2-4]>/si';

        $parts = preg_split($pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!is_array($parts) || count($parts) < 3) {
            return [];
        }

        // Step heading pattern: "Step 1", "Step 2:", "Étape 3", "Paso 1", "Schritt 2"
        $step_regex = '/^(?:step|étape|paso|schritt)\s+(\d+)\s*[:.–—-]?\s*(.*)/iu';

        $steps = [];

        // $parts layout: [before-content, heading1-text, after-heading1-content, heading2-text, ...]
        for ($i = 1, $len = count($parts); $i < $len; $i += 2) {
            $heading_text      = trim(wp_strip_all_tags($parts[ $i ]));
            $following_content = $parts[ $i + 1 ] ?? '';

            if (!preg_match($step_regex, $heading_text, $matches)) {
                continue;
            }

            // Step name: remainder of heading after "Step N:" or the full heading if no remainder
            $step_name = trim($matches[2] ?? '');
            if (empty($step_name)) {
                $step_name = $heading_text;
            }

            // Collect text: strip all tags except inline emphasis
            $text = wp_kses(
                $following_content,
                [
                    'em'     => [],
                    'strong' => [],
                    'b'      => [],
                    'i'      => [],
                ],
            );
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

            // Skip steps with no text content
            if (empty($text)) {
                continue;
            }

            // Enforce length limits
            $step_name = mb_substr($step_name, 0, self::MAX_NAME_LENGTH);
            $text      = mb_substr($text, 0, self::MAX_TEXT_LENGTH);

            $steps[] = [
                'name' => $step_name,
                'text' => $text,
            ];
        }

        return $steps;
    }

    /**
     * Build JSON-LD step array
     *
     * @param array<int, array{name: string, text: string}> $steps
     * @return array<int, array<string, mixed>>
     */
    private function build_steps(array $steps): array
    {
        $result   = [];
        $position = 1;

        foreach ($steps as $step) {
            $howto_step = [
                '@type'    => 'HowToStep',
                'position' => $position,
                'name'     => $step['name'],
                'text'     => $step['text'],
                'url'      => get_permalink() . '#step-' . $position,
            ];

            $result[] = $howto_step;
            ++$position;
        }

        return $result;
    }

    /**
     * Get post description
     *
     * @param \WP_Post $post
     * @return string
     */
    private function get_description(\WP_Post $post): string
    {
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags($post->post_excerpt);
        }

        $content = wp_strip_all_tags($post->post_content);

        return wp_trim_words($content, 30, '…');
    }
}
