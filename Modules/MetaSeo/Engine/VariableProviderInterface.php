<?php

/**
 * Contract for variable providers.
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
 * VariableProviderInterface
 *
 * Each provider handles one namespace (e.g. 'post', 'site', 'woo')
 * and knows how to resolve its variables given a ContextBag.
 *
 * @since 1.0.0
 */
interface VariableProviderInterface
{
    /**
     * Whether this provider can resolve the given variable name.
     *
     * @param string $variable Variable name without namespace prefix.
     * @return bool
     */
    public function supports(string $variable): bool;

    /**
     * Resolve the variable to a string value.
     *
     * @param string $variable Variable name without namespace prefix.
     * @param ContextBag $context Current resolution context.
     * @return string|null Null if the variable cannot be resolved.
     */
    public function resolve(string $variable, ContextBag $context): ?string;

    /**
     * Return all variable definitions this provider supports.
     *
     * Used for autocomplete, documentation and validation.
     *
     * @return VariableDefinition[]
     */
    public function getDefinitions(): array;
}
