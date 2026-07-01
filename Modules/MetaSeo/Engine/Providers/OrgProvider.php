<?php

/**
 * Organisation variable provider (org.*).
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;
use SEOPulse\Modules\MetaSeo\Engine\ContextBag;
use SEOPulse\Modules\MetaSeo\Engine\VariableDefinition;
use SEOPulse\Modules\MetaSeo\Engine\VariableProviderInterface;

/**
 * OrgProvider — resolves org.* variables from Local SEO / organisation settings.
 */
final class OrgProvider implements VariableProviderInterface
{
    private const VARIABLES = [
        'name',
        'logo',
        'address',
        'city',
        'country',
        'phone',
        'email',
    ];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        $settings = $this->getSettings();

        return match ($variable) {
            'name'    => $settings['company_name'] ?? $settings['name'] ?? get_bloginfo('name'),
            'logo'    => $settings['logo'] ?? '',
            'address' => $settings['address'] ?? $settings['street_address'] ?? '',
            'city'    => $settings['city'] ?? '',
            'country' => $settings['country'] ?? '',
            'phone'   => $settings['phone'] ?? $settings['telephone'] ?? '',
            'email'   => $settings['email'] ?? get_bloginfo('admin_email'),
            default   => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('name', 'Organisation name', 'Acme Corp', 'organisation'),
            new VariableDefinition('logo', 'Organisation logo URL', 'https://example.com/logo.png', 'organisation'),
            new VariableDefinition('address', 'Street address', '123 Main St', 'organisation'),
            new VariableDefinition('city', 'City', 'Paris', 'organisation'),
            new VariableDefinition('country', 'Country', 'France', 'organisation'),
            new VariableDefinition('phone', 'Phone number', '+33 1 23 45 67 89', 'organisation'),
            new VariableDefinition('email', 'Contact email', 'contact@example.com', 'organisation'),
        ];
    }

    /**
     * Read organisation settings from the Local SEO option.
     *
     * @return array<string, string>
     */
    private function getSettings(): array
    {
        $geo = get_option(Options::LOCAL_SEO, []);

        return is_array($geo) ? $geo : [];
    }
}
