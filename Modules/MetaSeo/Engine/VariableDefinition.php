<?php

/**
 * Variable definition metadata for autocomplete and documentation.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * VariableDefinition value object
 *
 * Describes a single variable that a provider can resolve.
 * Used by the REST API for autocomplete and the admin UI for documentation.
 *
 * @since 1.0.0
 */
final class VariableDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $example = '',
        public readonly string $group = 'general',
        public readonly bool $dynamic = false,
        public readonly ?string $namespace = null,
    ) {
    }

    /**
     * Return a new instance with $namespace set.
     */
    public function withNamespace(string $ns): self
    {
        return new self(
            $this->name,
            $this->description,
            $this->example,
            $this->group,
            $this->dynamic,
            $ns,
        );
    }

    /**
     * Full variable syntax ready for display / insertion.
     *
     * @return string e.g. "%%post.title%%"
     */
    public function getFullSyntax(): string
    {
        if ($this->namespace !== null) {
            if ($this->dynamic) {
                return '%%' . $this->namespace . ':' . $this->name . '%%';
            }

            return '%%' . $this->namespace . '.' . $this->name . '%%';
        }

        return '%%' . $this->name . '%%';
    }

    /**
     * Serialise for REST / JSON responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'namespace'   => $this->namespace,
            'name'        => $this->name,
            'syntax'      => $this->getFullSyntax(),
            'description' => $this->description,
            'example'     => $this->example,
            'group'       => $this->group,
            'dynamic'     => $this->dynamic,
        ];
    }
}
