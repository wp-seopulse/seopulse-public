<?php

/**
 * Site variable provider (site.*).
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
 * SiteProvider — resolves site.* variables.
 */
final class SiteProvider implements VariableProviderInterface
{
    private const VARIABLES = [
        'name',
        'tagline',
        'url',
        'language',
        'locale',
        'charset',
        'admin_email',
        'description',
        'default_image',
    ];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        return match ($variable) {
            'name'          => get_bloginfo('name'),
            'tagline'       => get_bloginfo('description'),
            'description'   => get_bloginfo('description'),
            'url'           => home_url('/'),
            'language'      => get_bloginfo('language'),
            'locale'        => get_locale(),
            'charset'       => get_bloginfo('charset'),
            'admin_email'   => get_bloginfo('admin_email'),
            'default_image' => $this->getDefaultImage(),
            default         => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('name', 'Site name', 'My Website', 'site'),
            new VariableDefinition('tagline', 'Site tagline / description', 'Just another WordPress site', 'site'),
            new VariableDefinition('description', 'Site description (alias of tagline)', 'Just another WordPress site', 'site'),
            new VariableDefinition('url', 'Site home URL', 'https://example.com/', 'site'),
            new VariableDefinition('language', 'Site language', 'en-US', 'site'),
            new VariableDefinition('locale', 'WordPress locale', 'en_US', 'site'),
            new VariableDefinition('charset', 'Site character set', 'UTF-8', 'site'),
            new VariableDefinition('admin_email', 'Admin email address', 'admin@example.com', 'site'),
            new VariableDefinition('default_image', 'Default Open Graph fallback image', 'https://example.com/default.jpg', 'site'),
        ];
    }

    private function getDefaultImage(): string
    {
        $global = get_option('seopulse_meta_seo_global', []);

        if (is_array($global) && !empty($global['default_og_image'])) {
            return (string) $global['default_og_image'];
        }

        // Fallback: site icon
        $siteIcon = get_site_icon_url(512);

        return $siteIcon ?: '';
    }
}
