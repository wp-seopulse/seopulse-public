<?php

/**
 * Error variable provider (error.*).
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
 * ErrorProvider — resolves error.* variables (404, etc.).
 */
final class ErrorProvider implements VariableProviderInterface
{
    private const VARIABLES = ['code', 'url', 'label'];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        return match ($variable) {
            'code'  => '404',
            'url'   => $this->getRequestedUrl(),
            'label' => __('Page not found', 'seopulse'),
            default => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('code', 'HTTP error code', '404', 'error'),
            new VariableDefinition('url', 'Requested URL that triggered the error', '/missing-page/', 'error'),
            new VariableDefinition('label', 'Localised error label', 'Page not found', 'error'),
        ];
    }

    private function getRequestedUrl(): string
    {
        return esc_url_raw(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '/')));
    }
}
