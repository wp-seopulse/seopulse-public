<?php

/**
 * Central registry for all template variables.
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
 * VariableRegistry
 *
 * Providers register their variables via namespaces.
 * Variables are lazy-resolved: the provider callback is only called
 * when the variable is actually referenced in a template.
 *
 * Third-party providers can be registered via the
 * `seopulse/meta_engine/register_providers` action.
 *
 * @since 1.0.0
 */
final class VariableRegistry
{
    /** @var array<string, VariableProviderInterface> namespace → provider */
    private array $providers = [];

    /** @var bool Whether external providers have been collected */
    private bool $externalCollected = false;

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    /**
     * Register a variable provider for a namespace.
     *
     * @param string $namespace e.g. 'post', 'site', 'woo'
     * @param VariableProviderInterface $provider
     */
    public function registerProvider(string $namespace, VariableProviderInterface $provider): void
    {
        $this->providers[ $namespace ] = $provider;
    }

    // ------------------------------------------------------------------
    // Querying
    // ------------------------------------------------------------------

    /**
     * Check if a variable exists in the registry.
     */
    public function has(string $namespace, string $variable): bool
    {
        $this->collectExternal();

        if (!isset($this->providers[ $namespace ])) {
            return false;
        }

        return $this->providers[ $namespace ]->supports($variable);
    }

    /**
     * Resolve a variable value given a context.
     *
     * @return string|null Null if variable not found or provider returns null.
     */
    public function resolve(string $namespace, string $variable, ContextBag $context): ?string
    {
        $this->collectExternal();

        if (!isset($this->providers[ $namespace ])) {
            return null;
        }

        return $this->providers[ $namespace ]->resolve($variable, $context);
    }

    // ------------------------------------------------------------------
    // Introspection (autocomplete / docs)
    // ------------------------------------------------------------------

    /**
     * Get all registered variable definitions.
     *
     * @param string|null $namespace Filter by namespace, or null for all.
     * @return VariableDefinition[]
     */
    public function getDefinitions(?string $namespace = null): array
    {
        $this->collectExternal();

        if ($namespace !== null) {
            if (!isset($this->providers[ $namespace ])) {
                return [];
            }

            return array_map(
                static fn (VariableDefinition $d) => $d->withNamespace($namespace),
                $this->providers[ $namespace ]->getDefinitions(),
            );
        }

        $all = [];

        foreach ($this->providers as $ns => $provider) {
            foreach ($provider->getDefinitions() as $def) {
                $all[] = $def->withNamespace($ns);
            }
        }

        return $all;
    }

    /**
     * Get all registered namespace names.
     *
     * @return string[]
     */
    public function getNamespaces(): array
    {
        $this->collectExternal();

        return array_keys($this->providers);
    }

    // ------------------------------------------------------------------
    // External collection (once, lazily)
    // ------------------------------------------------------------------

    private function collectExternal(): void
    {
        if ($this->externalCollected) {
            return;
        }

        $this->externalCollected = true;

        /**
         * Allow third-party plugins to register variable providers.
         *
         * @since 1.0.0
         *
         * @param VariableRegistry $registry
         */
        do_action('seopulse/meta_engine/register_providers', $this);
    }
}
