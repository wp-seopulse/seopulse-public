<?php

/**
 * Global variable provider (sep, etc.).
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
 * GlobalProvider — handles bare variables without a namespace prefix.
 */
final class GlobalProvider implements VariableProviderInterface
{
    private const VARIABLES = ['sep'];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        return match ($variable) {
            'sep' => $this->getSeparator(),
            default => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('sep', 'Configurable title separator', '–'),
        ];
    }

    private function getSeparator(): string
    {
        // Priority: global template > legacy option > default
        $templates = get_option('seopulse_meta_templates', []);

        if (is_array($templates) && !empty($templates['global']['separator'])) {
            return (string) $templates['global']['separator'];
        }

        $global = get_option('seopulse_meta_seo_global', []);

        if (is_array($global) && !empty($global['separator'])) {
            return (string) $global['separator'];
        }

        return '–';
    }
}
