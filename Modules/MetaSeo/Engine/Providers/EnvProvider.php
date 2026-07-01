<?php

/**
 * Environment variable provider (env.*).
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
 * EnvProvider — resolves env.* variables (URL, domain, language, etc.).
 */
final class EnvProvider implements VariableProviderInterface
{
    private const VARIABLES = [
        'url',
        'domain',
        'protocol',
        'path',
        'query_string',
        'language',
        'is_mobile',
    ];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        return match ($variable) {
            'url'          => $this->getCurrentUrl(),
            'domain'       => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            'protocol'     => is_ssl() ? 'https' : 'http',
            'path'         => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/')),
            'query_string' => sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING'] ?? '')),
            'language'     => $this->getCurrentLanguage(),
            'is_mobile'    => wp_is_mobile() ? 'true' : '',
            default        => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('url', 'Current full URL', 'https://example.com/blog/my-post/', 'environment'),
            new VariableDefinition('domain', 'Site domain', 'example.com', 'environment'),
            new VariableDefinition('protocol', 'HTTP protocol', 'https', 'environment'),
            new VariableDefinition('path', 'URI path', '/blog/my-post/', 'environment'),
            new VariableDefinition('query_string', 'Query string', 'utm_source=google', 'environment'),
            new VariableDefinition('language', 'Current language (WPML/Polylang aware)', 'fr', 'environment'),
            new VariableDefinition('is_mobile', 'Whether the visitor is on mobile (truthy/empty)', 'true', 'environment'),
        ];
    }

    private function getCurrentUrl(): string
    {
        $protocol = is_ssl() ? 'https' : 'http';
        $host     = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        }
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));

        return $protocol . '://' . $host . $uri;
    }

    private function getCurrentLanguage(): string
    {
        // Polylang
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language('slug');
            if (is_string($lang) && $lang !== '') {
                return $lang;
            }
        }

        // WPML
        if (defined('ICL_LANGUAGE_CODE') && is_string(ICL_LANGUAGE_CODE)) {
            return ICL_LANGUAGE_CODE;
        }

        // WordPress default
        return substr(get_locale(), 0, 2);
    }
}
