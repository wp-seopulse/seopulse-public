<?php

/**
 * ACF (Advanced Custom Fields) variable provider (acf:field_name).
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
 * AcfProvider — dynamic provider for ACF fields.
 *
 * Usage: {{acf:field_name}} or {{acf:group.subfield}}
 */
final class AcfProvider implements VariableProviderInterface
{
    public function supports(string $variable): bool
    {
        // Dynamic: accepts any variable name when ACF is active
        return function_exists('get_field');
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        if (!function_exists('get_field')) {
            return null;
        }

        $postId = $context->getPost()?->ID;

        // Support dot notation for grouped fields: "group.subfield"
        if (str_contains($variable, '.')) {
            [$group, $field] = explode('.', $variable, 2);
            $groupValue      = get_field($group, $postId);

            if (is_array($groupValue) && isset($groupValue[ $field ])) {
                return $this->toString($groupValue[ $field ]);
            }

            return null;
        }

        $value = get_field($variable, $postId);

        return $this->toString($value);
    }

    public function getDefinitions(): array
    {
        $definitions = [
            new VariableDefinition(
                'field_name',
                'Any ACF field by slug. Use {{acf:your_field_name}}.',
                'Custom value',
                'acf',
                dynamic: true,
            ),
        ];

        // If ACF is active, enumerate known field groups for better autocomplete
        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            /** @var array $groups */
            $groups = acf_get_field_groups();

            foreach ($groups as $group) {
                $fields = acf_get_fields($group['key'] ?? '');

                if (!is_array($fields)) {
                    continue;
                }

                foreach ($fields as $field) {
                    $definitions[] = new VariableDefinition(
                        (string) ($field['name'] ?? ''),
                        ($field['label'] ?? '') . ' (' . ($group['title'] ?? '') . ')',
                        '',
                        'acf',
                        dynamic: true,
                    );
                }
            }
        }

        return $definitions;
    }

    /**
     * Coerce an ACF value to a string.
     */
    private function toString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : '';
        }
        if (is_array($value)) {
            $flat = array_filter(
                array_map(fn ($v) => $this->toString($v), $value),
                static fn ($v) => $v !== null,
            );

            return implode(', ', $flat);
        }

        return null;
    }
}
