<?php

/**
 * Immutable value object representing a module's metadata.
 *
 * Built from the #[AsModule] attribute at discovery time.
 *
 * @package SEOPulse\Core\Module
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Module;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Attributes\AsModule;

final class ModuleDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $icon,
        public readonly string $className,
        public readonly bool $pro,
        public readonly bool $default,
        public readonly array $requires,
        public readonly ?string $namespace,
    ) {
    }

    /**
     * Creates a ModuleDefinition from an #[AsModule] attribute and its target class.
     */
    public static function fromAttribute(AsModule $attr, string $className): self
    {
        return new self(
            key: $attr->key,
            label: $attr->label,
            description: $attr->description,
            icon: $attr->icon,
            className: $className,
            pro: $attr->pro,
            default: $attr->default,
            requires: $attr->requires,
            namespace: $attr->namespace ?? self::inferNamespace($className),
        );
    }

    /**
     * Infers the module's namespace from the main class name.
     *
     * e.g.: SEOPulse\Modules\MetaSeo\MetaSeoModule → SEOPulse\Modules\MetaSeo\
     */
    private static function inferNamespace(string $className): string
    {
        $normalized = ltrim($className, '\\');
        $parts      = explode('\\', $normalized);
        array_pop($parts); // Remove class name

        return implode('\\', $parts) . '\\';
    }
}
