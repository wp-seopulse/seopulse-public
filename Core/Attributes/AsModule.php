<?php

/**
 * PHP 8.1 Attribute — Declares a class as a SEOPulse module entry point.
 *
 * @package SEOPulse\Core\Attributes
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Attributes;

if (!defined('ABSPATH')) {
    exit;
}

use Attribute;

/**
 * Declares a class as the entry point of a SEOPulse module.
 *
 * Applied to the main class of each module folder. The ModuleDiscovery
 * scanner reads this attribute to auto-register the module without
 * requiring manual mapping in the Kernel.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsModule
{
    /**
     * @param string $key Unique module identifier (e.g. 'meta_seo')
     * @param string $label Human-readable label
     * @param string $description Short description for the admin UI
     * @param string $icon Dashicons class (e.g. 'dashicons-search')
     * @param bool $pro Whether this is a Pro-only module
     * @param bool $default Default enabled state on first install
     * @param string[] $requires Keys of modules this one depends on
     * @param string|null $namespace Namespace prefix that gates all classes in this module
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description = '',
        public readonly string $icon = 'dashicons-admin-generic',
        public readonly bool $pro = false,
        public readonly bool $default = true,
        public readonly array $requires = [],
        public readonly ?string $namespace = null,
    ) {
    }
}
