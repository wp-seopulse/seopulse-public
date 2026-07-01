<?php

/**
 * Central orchestrator for all SEOPulse modules.
 *
 * All modules bundled in this repository are treated as Free.
 * The optional license checker is reserved for external add-on
 * plugins that register their own modules via the
 * `seopulse_container_built` action hook.
 *
 * @package SEOPulse\Core\Module
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Module;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Contracts\ExecuteHooksAdmin;
use SEOPulse\Core\Contracts\ExecuteHooksFrontend;
use SEOPulse\Core\Contracts\ModuleInterface;

/**
 * ModuleManager — Singleton orchestrator.
 *
 * Responsibilities:
 * - Holds the registry of all discovered/registered modules
 * - Resolves module dependencies (topological sort)
 * - Checks enabled state (wp_options) and optional external add-on license
 * - Instantiates and dispatches hooks() on enabled modules
 * - Provides API for enable/disable from admin UI
 */
final class ModuleManager
{
    private static ?self $instance = null;

    /** @var array<string, ModuleDefinition> All discovered module definitions */
    private array $definitions = [];

    /** @var array<string, ModuleInterface> Instantiated (booted) modules */
    private array $instances = [];

    /** @var callable|null Optional external add-on checker */
    private $licenseChecker = null;

    /** @var array<string, string|null> Additional class-to-module namespace mappings */
    private array $extraNamespaceMappings = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * Registers a license checker callback for external add-on plugins.
     *
     * Add-on plugins that register their own modules with `pro: true`
     * via the `seopulse_container_built` hook may call this method to
     * provide their own license validation logic. Bundled modules are
     * not affected — they are always considered Free.
     *
     * @param callable(): bool $checker Returns true if the add-on license is active.
     */
    public function setLicenseChecker(callable $checker): void
    {
        $this->licenseChecker = $checker;
    }

    /**
     * Registers a module definition (from auto-discovery or manually).
     */
    public function registerDefinition(ModuleDefinition $definition): void
    {
        $this->definitions[ $definition->key ] = $definition;
    }

    /**
     * Registers multiple definitions at once.
     *
     * @param ModuleDefinition[] $definitions
     */
    public function registerDefinitions(array $definitions): void
    {
        foreach ($definitions as $definition) {
            $this->registerDefinition($definition);
        }
    }

    /**
     * Registers additional namespace-to-module mappings for non-module classes.
     *
     * This is used for backward compatibility with the old $classModuleMap pattern
     * where Admin pages, API controllers, etc. are gated by their parent module.
     *
     * @param array<string, string|null> $mappings prefix => module_key (null = always loaded)
     */
    public function registerNamespaceMappings(array $mappings): void
    {
        $this->extraNamespaceMappings = array_merge($this->extraNamespaceMappings, $mappings);
    }

    /**
     * Boots all enabled modules: instantiates and calls hooks().
     *
     * This is the main dispatch method, called once on plugins_loaded.
     */
    public function boot(): void
    {
        $enabledStates = $this->getEnabledStates();

        // Sort by dependency order
        $sorted = $this->topologicalSort();

        foreach ($sorted as $key) {
            $definition = $this->definitions[ $key ];

            // Check if module is enabled
            if (!$this->isEnabled($key, $enabledStates)) {
                continue;
            }

            // Check dependencies are satisfied
            if (!$this->areDependenciesMet($definition, $enabledStates)) {
                continue;
            }

            // Gate Pro add-on modules behind the license checker
            if ($definition->pro && !$this->isProLicensed()) {
                continue;
            }

            $this->bootModule($definition);
        }

        /**
         * Fires after all modules have been booted.
         *
         * @since 1.0.0
         * @param ModuleManager $manager
         */
        do_action('seopulse_modules_booted', $this);
    }

    /**
     * Instantiates a single module and calls hooks() with context dispatch.
     */
    private function bootModule(ModuleDefinition $definition): void
    {
        $className = $definition->className;

        if (!class_exists($className)) {
            return;
        }

        try {
            /** @var ModuleInterface $module */
            $module                              = new $className();
            $this->instances[ $definition->key ] = $module;

            // Dispatch by interface (same pattern as existing Kernel)
            if ($module instanceof ExecuteHooksAdmin) {
                if (is_admin()) {
                    $module->hooks();
                }
            } elseif ($module instanceof ExecuteHooksFrontend) {
                if (!is_admin()) {
                    $module->hooks();
                }
            } else {
                $module->hooks();
            }
        } catch (\Throwable $e) {
            // Log error but don't break the plugin
            if (function_exists('seopulse_get_service')) {
                $logger = seopulse_get_service('Logger');
                if ($logger) {
                    $logger->error(
                        'Module boot failed',
                        [
                            'module' => $definition->key,
                            'error'  => $e->getMessage(),
                        ],
                    );
                }
            }
        }
    }

    /**
     * Returns all module definitions.
     *
     * @return array<string, ModuleDefinition>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * Returns the definitions formatted for the admin dashboard UI.
     *
     * @return array<string, array{label: string, description: string, icon: string, pro: bool, enabled: bool, requires: string[]}>
     */
    public function getDefinitionsForUI(): array
    {
        $enabledStates = $this->getEnabledStates();
        $result        = [];

        foreach ($this->definitions as $key => $def) {
            $enabled = $this->isEnabled($key, $enabledStates);

            $result[ $key ] = [
                'label'       => $def->label,
                'description' => $def->description,
                'icon'        => $def->icon,
                'pro'         => $def->pro,
                'enabled'     => $enabled,
                'effective'   => $enabled
                    && $this->areDependenciesMet($def, $enabledStates)
                    && (!$def->pro || $this->isProLicensed()),
                'requires'    => $def->requires,
            ];
        }

        return $result;
    }

    /**
     * Returns a booted module instance by key.
     */
    public function get(string $key): ?ModuleInterface
    {
        return $this->instances[ $key ] ?? null;
    }

    /**
     * Creates a module instance for lifecycle callbacks (onActivate / onDeactivate).
     *
     * Unlike get(), this method instantiates the module even if it was not
     * booted during the normal boot sequence (e.g. when activating a module
     * that was previously disabled via the admin toggle).
     *
     * @param string $key Module key
     * @return ModuleInterface|null
     */
    public function instantiateModule(string $key): ?ModuleInterface
    {
        if (isset($this->instances[ $key ])) {
            return $this->instances[ $key ];
        }

        $def = $this->definitions[ $key ] ?? null;
        if ($def === null || !class_exists($def->className)) {
            return null;
        }

        try {
            $className = $def->className;

            return new $className();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Returns a module definition by key.
     */
    public function getDefinition(string $key): ?ModuleDefinition
    {
        return $this->definitions[ $key ] ?? null;
    }

    /**
     * Enables a module.
     *
     * @return bool True if the module was enabled, false if it doesn't exist
     *              or dependencies are not met.
     */
    public function enable(string $key): bool
    {
        if (!isset($this->definitions[ $key ])) {
            return false;
        }

        $def = $this->definitions[ $key ];

        // Block enabling add-on Pro modules without a registered checker
        if ($def->pro && !$this->isProLicensed()) {
            return false;
        }

        $enabled         = $this->getEnabledStates();
        $enabled[ $key ] = true;
        update_option(Options::MODULES_ENABLED, $enabled);

        /**
         * Fires when a module is enabled.
         *
         * @since 1.0.0
         * @param string $key Module key
         * @param ModuleDefinition $definition Module definition
         */
        do_action('seopulse_module_enabled', $key, $def);

        return true;
    }

    /**
     * Disables a module.
     *
     * Also disables any modules that depend on this one.
     */
    public function disable(string $key): bool
    {
        if (!isset($this->definitions[ $key ])) {
            return false;
        }

        $enabled         = $this->getEnabledStates();
        $enabled[ $key ] = false;

        // Cascade: disable modules that require this one
        foreach ($this->definitions as $depKey => $depDef) {
            if (in_array($key, $depDef->requires, true)) {
                $enabled[ $depKey ] = false;
            }
        }

        update_option(Options::MODULES_ENABLED, $enabled);

        /**
         * Fires when a module is disabled.
         *
         * @since 1.0.0
         * @param string $key Module key
         * @param ModuleDefinition $definition Module definition
         */
        do_action('seopulse_module_disabled', $key, $this->definitions[ $key ]);

        return true;
    }

    /**
     * Checks if a specific class belongs to an enabled module.
     *
     * Used by the Container/Kernel for gating of non-module classes
     * (admin pages, API controllers, services) that belong to a module namespace.
     */
    public function shouldLoadClass(string $className): bool
    {
        $normalized    = ltrim($className, '\\');
        $enabledStates = $this->getEnabledStates();

        // Check extra namespace mappings first (admin pages, API controllers, etc.)
        foreach ($this->extraNamespaceMappings as $prefix => $moduleKey) {
            // Remove root namespace for matching
            $relative = preg_replace('/^SEOPulse(Pro)?\\\\/', '', $normalized);
            if (str_starts_with($relative, $prefix)) {
                // null = always load
                if ($moduleKey === null) {
                    return true;
                }

                return $this->isEnabled($moduleKey, $enabledStates)
                    && (!($this->definitions[ $moduleKey ]->pro ?? false) || $this->isProLicensed());
            }
        }

        // Check module namespaces from definitions
        foreach ($this->definitions as $def) {
            if ($def->namespace !== null && str_starts_with($normalized, ltrim($def->namespace, '\\'))) {
                return $this->isEnabled($def->key, $enabledStates)
                    && (!$def->pro || $this->isProLicensed());
            }
        }

        // Fail-closed for API controllers: unmapped controllers must not load.
        $relative = preg_replace('/^SEOPulse(Pro)?\\\\/', '', $normalized);
        if (str_starts_with($relative, 'Api\\')) {
            return false;
        }

        // Class not mapped to any module → always load
        return true;
    }

    /**
     * Checks whether a module is enabled (public access).
     */
    public function isModuleEnabled(string $key): bool
    {
        return $this->isEnabled($key, $this->getEnabledStates());
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * Reads the module enabled states from wp_options.
     *
     * @return array<string, bool>
     */
    private function getEnabledStates(): array
    {
        $raw = get_option(Options::MODULES_ENABLED, []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Determines if a module is enabled.
     * Default: enabled (unless explicitly set to false).
     */
    private function isEnabled(string $key, array $enabledStates): bool
    {
        if (!isset($enabledStates[ $key ])) {
            return $this->definitions[ $key ]->default ?? true;
        }

        return (bool) $enabledStates[ $key ];
    }

    /**
     * Checks the license status via the registered checker.
     *
     * Returns true by default so all bundled modules (which are Free)
     * are never blocked. An external add-on plugin may override this
     * via setLicenseChecker() for its own Pro modules.
     */
    private function isProLicensed(): bool
    {
        if ($this->licenseChecker === null) {
            return true;
        }

        return ($this->licenseChecker)();
    }

    /**
     * Checks that all required modules are enabled.
     */
    private function areDependenciesMet(ModuleDefinition $def, array $enabledStates): bool
    {
        foreach ($def->requires as $reqKey) {
            if (!$this->isEnabled($reqKey, $enabledStates)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Topological sort of module keys by dependency order.
     *
     * Uses Kahn's algorithm. Modules without dependencies come first.
     *
     * @return string[]
     */
    private function topologicalSort(): array
    {
        $inDegree  = [];
        $adjacency = [];

        foreach ($this->definitions as $key => $def) {
            $inDegree[ $key ]  = $inDegree[ $key ] ?? 0;
            $adjacency[ $key ] = $adjacency[ $key ] ?? [];

            foreach ($def->requires as $reqKey) {
                if (isset($this->definitions[ $reqKey ])) {
                    $adjacency[ $reqKey ][] = $key;
                    $inDegree[ $key ]       = ($inDegree[ $key ] ?? 0) + 1;
                }
            }
        }

        $queue = [];
        foreach ($inDegree as $key => $degree) {
            if ($degree === 0) {
                $queue[] = $key;
            }
        }

        $sorted = [];
        while (!empty($queue)) {
            $current  = array_shift($queue);
            $sorted[] = $current;

            foreach ($adjacency[ $current ] as $dependent) {
                --$inDegree[ $dependent ];
                if ($inDegree[ $dependent ] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Append any remaining (circular dependency fallback)
        foreach (array_keys($this->definitions) as $key) {
            if (!in_array($key, $sorted, true)) {
                $sorted[] = $key;
            }
        }

        return $sorted;
    }
}
