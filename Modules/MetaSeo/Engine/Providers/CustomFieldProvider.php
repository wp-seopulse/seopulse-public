<?php

/**
 * Custom field (post meta) variable provider (custom_field:meta_key).
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\MetaSeo\Engine\ContextBag;
use SEOPulse\Modules\MetaSeo\Engine\VariableDefinition;
use SEOPulse\Modules\MetaSeo\Engine\VariableProviderInterface;

/**
 * CustomFieldProvider — dynamic provider for arbitrary post meta.
 *
 * Usage: {{custom_field:meta_key}}
 */
final class CustomFieldProvider implements VariableProviderInterface
{
    public function supports(string $variable): bool
    {
        // Dynamic: any meta_key is accepted
        return true;
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        $postId = $context->getPost()?->ID;

        if ($postId === null) {
            return null;
        }

        $value = get_post_meta($postId, $variable, true);

        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return null;
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition(
                'meta_key',
                'Any WordPress post meta by key. Use {{custom_field:your_meta_key}}.',
                'Custom meta value',
                'custom_field',
                dynamic: true,
            ),
        ];
    }
}
