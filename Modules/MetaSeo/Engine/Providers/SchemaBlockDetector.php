<?php

/**
 * Detects JSON-LD printed by seopulse/schema blocks inside post content.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SchemaBlockDetector — a validated seopulse/schema block wins over the automatic providers.
 */
final class SchemaBlockDetector
{
    /**
     * Whether a seopulse/schema block outputs a node of the given schema.org type.
     *
     * @param string $content Post content.
     * @param string $type Schema.org type, e.g. "FAQPage".
     *
     * @return bool
     */
    public static function has_type(string $content, string $type): bool
    {
        return !empty(self::nodes($content, [$type]));
    }

    /**
     * Nodes printed by validated seopulse/schema blocks, optionally filtered by type.
     *
     * Mirrors Assets::render_schema_block(): only validated blocks holding a JSON object print anything.
     *
     * @param string $content Post content.
     * @param array<string> $types Types to keep; empty keeps all.
     *
     * @return array<int, array<string, mixed>> Nodes without @context.
     */
    public static function nodes(string $content, array $types = []): array
    {
        // Quick string check before the expensive parse_blocks().
        if ($content === '' || strpos($content, '<!-- wp:seopulse/schema') === false) {
            return [];
        }

        $nodes = [];

        foreach (self::collect(parse_blocks($content)) as $block) {
            $attrs = $block['attrs'] ?? [];

            if (empty($attrs['isValidated'])) {
                continue;
            }

            $decoded = json_decode((string) ($attrs['schemaJson'] ?? ''), true);

            if (!is_array($decoded) || empty($decoded) || isset($decoded[0])) {
                continue;
            }

            $candidates = isset($decoded['@graph']) && is_array($decoded['@graph']) ? $decoded['@graph'] : [$decoded];

            foreach ($candidates as $node) {
                if (!is_array($node)) {
                    continue;
                }

                unset($node['@context']);

                if (empty($types) || self::matches($node, $types)) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $node Schema node.
     * @param array<string> $types Accepted types.
     *
     * @return bool
     */
    private static function matches(array $node, array $types): bool
    {
        $node_types = (array) ($node['@type'] ?? []);

        return !empty(array_intersect($types, array_map('strval', $node_types)));
    }

    /**
     * Flattens parsed blocks (including nested ones) down to seopulse/schema blocks.
     *
     * @param array<int, array<string, mixed>> $blocks Parsed blocks.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function collect(array $blocks): array
    {
        $found = [];

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'seopulse/schema') {
                $found[] = $block;
            }

            if (!empty($block['innerBlocks'])) {
                $found = array_merge($found, self::collect($block['innerBlocks']));
            }
        }

        return $found;
    }
}
