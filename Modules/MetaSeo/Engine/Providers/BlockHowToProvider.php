<?php

/**
 * BlockHowToProvider — Extracts HowTo data from seopulse/howto Gutenberg blocks
 * and builds HowTo JSON-LD schema.
 *
 * Uses parse_blocks() (not regex) per spec.
 * Implements SchemaProvider interface for direct registration in SchemaFactory.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

final class BlockHowToProvider implements SchemaProvider
{
    /**
     * Store error message.
     *
     * @var string|null
     */
    private ?string $error = null;

    /**
     * Get the schema type.
     *
     * @return string
     */
    public function get_type(): string
    {
        return 'HowTo';
    }

    /**
     * Check if this provider should inject on the current request.
     *
     * Only inject if on a singular post/page containing seopulse/howto blocks.
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

        return self::has_howto_blocks($post->post_content ?? '');
    }

    /**
     * Build the HowTo JSON-LD schema.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        global $post;

        if (!$post instanceof \WP_Post) {
            return [];
        }

        $data = self::extract($post->post_content ?? '');

        if (empty($data['steps'])) {
            return [];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
        ];

        if (!empty($data['name'])) {
            $schema['name'] = $data['name'];
        }

        if (!empty($data['description'])) {
            $schema['description'] = $data['description'];
        }

        if (!empty($data['estimatedTime'])) {
            $schema['estimatedCost'] = [
                '@type'    => 'MonetaryAmount',
                'currency' => 'USD',
                'value'    => '0',
            ];
            $schema['performTime']   = $data['estimatedTime'];
        }

        if (!empty($data['totalTime'])) {
            $schema['totalTime'] = $data['totalTime'];
        }

        if (!empty($data['yield'])) {
            $schema['yield'] = $data['yield'];
        }

        $schema['step'] = $this->build_steps($data['steps']);

        return $schema;
    }

    /**
     * Validate the schema structure.
     *
     * @return bool
     */
    public function validate(): bool
    {
        $schema = $this->build();

        if (empty($schema)) {
            $this->error = 'Schema is empty (no HowTo blocks found)';

            return false;
        }

        if (empty($schema['step']) || !is_array($schema['step'])) {
            $this->error = 'step is missing or not an array';

            return false;
        }

        if (empty($schema['step'][0]['@type']) || $schema['step'][0]['@type'] !== 'HowToStep') {
            $this->error = 'step must contain HowToStep items';

            return false;
        }

        return true;
    }

    /**
     * Get error message if validation failed.
     *
     * @return string|null
     */
    public function get_error(): ?string
    {
        return $this->error;
    }

    /**
     * Extract HowTo data from all seopulse/howto blocks in the given content.
     *
     * Uses the first seopulse/howto block found (only one HowTo schema per page).
     *
     * @param string $content Post content (raw, with block comments).
     *
     * @return array{name: string, description: string, steps: array, estimatedTime: string, totalTime: string, yield: string}
     */
    public static function extract(string $content): array
    {
        $empty = [
            'name'          => '',
            'description'   => '',
            'steps'         => [],
            'estimatedTime' => '',
            'totalTime'     => '',
            'yield'         => '',
        ];

        if (empty($content)) {
            return $empty;
        }

        $blocks = parse_blocks($content);

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') !== 'seopulse/howto') {
                continue;
            }

            $attrs = $block['attrs'] ?? [];

            $name        = isset($attrs['name']) ? trim(wp_strip_all_tags((string) $attrs['name'])) : '';
            $description = isset($attrs['description']) ? trim(wp_kses_post((string) $attrs['description'])) : '';

            $raw_steps = $attrs['steps'] ?? [];
            if (!is_array($raw_steps)) {
                $raw_steps = [];
            }

            $steps = [];
            foreach ($raw_steps as $step) {
                if (!is_array($step)) {
                    continue;
                }

                $step_name = isset($step['name']) ? trim(wp_strip_all_tags((string) $step['name'])) : '';
                $step_desc = isset($step['description']) ? trim(wp_kses_post((string) $step['description'])) : '';
                $step_img  = isset($step['image']) ? esc_url_raw((string) $step['image']) : '';

                // Ignore incomplete steps where both name and description are empty.
                if ($step_name === '' && $step_desc === '') {
                    continue;
                }

                $steps[] = [
                    'name'        => $step_name,
                    'description' => $step_desc,
                    'image'       => $step_img,
                ];
            }

            $estimatedTime = isset($attrs['estimatedTime']) ? trim((string) $attrs['estimatedTime']) : '';
            $totalTime     = isset($attrs['totalTime']) ? trim((string) $attrs['totalTime']) : '';
            $yield         = isset($attrs['yield']) ? trim(wp_strip_all_tags((string) $attrs['yield'])) : '';

            // Validate ISO 8601 duration format.
            if ($estimatedTime !== '' && !preg_match('/^P(?:\d+Y)?(?:\d+M)?(?:\d+W)?(?:\d+D)?(?:T(?:\d+H)?(?:\d+M)?(?:\d+S)?)?$/', $estimatedTime)) {
                $estimatedTime = '';
            }
            if ($totalTime !== '' && !preg_match('/^P(?:\d+Y)?(?:\d+M)?(?:\d+W)?(?:\d+D)?(?:T(?:\d+H)?(?:\d+M)?(?:\d+S)?)?$/', $totalTime)) {
                $totalTime = '';
            }

            return [
                'name'          => $name,
                'description'   => $description,
                'steps'         => $steps,
                'estimatedTime' => $estimatedTime,
                'totalTime'     => $totalTime,
                'yield'         => $yield,
            ];
        }

        return $empty;
    }

    /**
     * Check if post content contains seopulse/howto blocks.
     *
     * @param string $content Post content.
     *
     * @return bool
     */
    public static function has_howto_blocks(string $content): bool
    {
        if (empty($content)) {
            return false;
        }

        // Quick string check before expensive parse_blocks().
        if (strpos($content, '<!-- wp:seopulse/howto') === false) {
            return false;
        }

        $blocks = parse_blocks($content);

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'seopulse/howto') {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the step array for JSON-LD.
     *
     * @param array<int, array{name: string, description: string, image: string}> $steps
     *
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
            ];

            if (!empty($step['name'])) {
                $howto_step['name'] = $step['name'];
            }

            if (!empty($step['description'])) {
                $howto_step['text'] = $step['description'];
            }

            if (!empty($step['image'])) {
                $howto_step['image'] = $step['image'];
            }

            $howto_step['url'] = get_permalink() . '#step-' . $position;

            $result[] = $howto_step;
            ++$position;
        }

        return $result;
    }
}
