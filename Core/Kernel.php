<?php

/**
 * Kernel SEOPulse — Central plugin bootstrap
 *
 * Manages class auto-discovery, container building,
 * module discovery, and hook dispatching by interface.
 *
 * Module boot is delegated to ModuleManager
 *
 * @package SEOPulse\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Container\Container;
use SEOPulse\Core\Contracts\ActivationHook;
use SEOPulse\Core\Contracts\DeactivationHook;
use SEOPulse\Core\Contracts\ExecuteHooks;
use SEOPulse\Core\Contracts\ExecuteHooksAdmin;
use SEOPulse\Core\Contracts\ExecuteHooksFrontend;
use SEOPulse\Core\Module\ModuleDiscovery;
use SEOPulse\Core\Module\ModuleManager;

/**
 * Kernel class (abstract, static)
 */
abstract class Kernel
{
    /**
     * Container instance
     *
     * @var Container|null
     */
    private static ?Container $container = null;

    /**
     * Plugin configuration data
     *
     * @var array{file: string, slug: string, main_file: string, root: string}
     */
    private static array $data = [
        'file'      => '',
        'slug'      => '',
        'main_file' => '',
        'root'      => '',
    ];

    /**
     * Retrieves the container (creates a default instance if needed)
     *
     * @return Container
     */
    public static function getContainer(): Container
    {
        if (self::$container === null) {
            self::$container = new Container();
        }

        return self::$container;
    }

    // ---------------------------------------------------------------
    // Container building
    // ---------------------------------------------------------------

    /**
     * Builds the container by scanning class directories.
     *
     * Registers ALL classes (services + actions) in the container.
     * Module entry points are later booted by ModuleManager instead
     * of the container dispatch loop.
     *
     * @return void
     */
    public static function buildContainer(): void
    {
        $root = rtrim(self::$data['root'], '/\\');

        // Register services (business logic / data access)
        self::buildClasses($root . '/Services', 'services', 'Services\\');
        self::buildClasses($root . '/Services/Options', 'services', 'Services\\Options\\');
        self::buildClasses($root . '/Services/Indexing', 'services', 'Services\\Indexing\\');

        // Register core actions (Assets, Installer)
        self::buildClasses($root . '/Core', 'actions', 'Core\\');

        // Register the centralized logger as a service (not an action)
        self::getContainer()->setService(Logger::class);

        // Register Meta Engine services (variable engine)
        self::buildClasses($root . '/Modules/MetaSeo/Engine', 'services', 'Modules\\MetaSeo\\Engine\\');
        self::buildClasses($root . '/Modules/MetaSeo/Engine/Providers', 'services', 'Modules\\MetaSeo\\Engine\\Providers\\');

        // Register actions (hook controllers) — includes module sub-classes
        self::buildClasses($root . '/Modules/MetaSeo', 'actions', 'Modules\\MetaSeo\\');
        self::buildClasses($root . '/Modules/MetaSeo/Archives', 'actions', 'Modules\\MetaSeo\\Archives\\');
        self::buildClasses($root . '/Modules/Redirections', 'actions', 'Modules\\Redirections\\');
        self::buildClasses($root . '/Modules/Sitemap', 'actions', 'Modules\\Sitemap\\');
        self::buildClasses($root . '/Modules/Content', 'actions', 'Modules\\Content\\');
        self::buildClasses($root . '/Modules/Analytics', 'actions', 'Modules\\Analytics\\');
        self::buildClasses($root . '/Modules/LocalSeo', 'actions', 'Modules\\LocalSeo\\');
        self::buildClasses($root . '/Modules/I18n', 'actions', 'Modules\\I18n\\');
        self::buildClasses($root . '/Modules/Monitor404', 'actions', 'Modules\\Monitor404\\');
        self::buildClasses($root . '/Admin', 'actions', 'Admin\\');
        self::buildClasses($root . '/Admin/Settings', 'actions', 'Admin\\Settings\\');
        self::buildClasses($root . '/Admin/Notifications', 'actions', 'Admin\\Notifications\\');
        self::buildClasses($root . '/Admin/Columns', 'actions', 'Admin\\Columns\\');
        self::buildClasses($root . '/Admin/MetaBox', 'actions', 'Admin\\MetaBox\\');
        self::buildClasses($root . '/Admin/Images', 'actions', 'Admin\\Images\\');
        self::buildClasses($root . '/Admin/DashboardWidgets', 'actions', 'Admin\\DashboardWidgets\\');
        self::buildClasses($root . '/Api', 'actions', 'Api\\');
        self::buildClasses($root . '/Api/MetaSeo', 'actions', 'Api\\MetaSeo\\');
        self::buildClasses($root . '/Api/Content', 'actions', 'Api\\Content\\');
        self::buildClasses($root . '/Api/Images', 'actions', 'Api\\Images\\');

        // Register WP-CLI commands when running in CLI context
        if (defined('WP_CLI') && \WP_CLI) {
            self::registerCliCommands();
        }
    }

    /**
     * Registers WP-CLI commands under the `wp seopulse` namespace
     *
     * @return void
     */
    private static function registerCliCommands(): void
    {
        $commands = [
            'seopulse analyze'   => CLI\AnalyzeCommand::class,
            'seopulse dashboard' => CLI\DashboardCommand::class,
            'seopulse migrate'   => CLI\MigrateCommand::class,
            'seopulse export'    => CLI\ExportCommand::class,
            'seopulse import'    => CLI\ImportCommand::class,
        ];

        foreach ($commands as $name => $class) {
            if (class_exists($class)) {
                \WP_CLI::add_command($name, $class);
            }
        }
    }

    /**
     * Recursively scans a directory and registers found classes
     *
     * @param string $path Absolute directory path
     * @param string $type Registration type: 'services' or 'actions'
     * @param string $namespace Relative namespace (e.g.: 'Services\\')
     * @return void
     */
    public static function buildClasses(string $path, string $type, string $namespace = ''): void
    {
        if (!is_dir($path)) {
            return;
        }

        try {
            $files = array_diff(scandir($path), ['..', '.']);

            foreach ($files as $filename) {
                $fullPath = $path . '/' . $filename;

                // Ignore subdirectories to avoid duplicates
                // (subdirectories are registered explicitly in buildContainer)
                if (is_dir($fullPath)) {
                    continue;
                }

                $pathInfo = pathinfo($filename);
                if (!isset($pathInfo['extension']) || $pathInfo['extension'] !== 'php') {
                    continue;
                }

                $className = '\\SEOPulse\\' . $namespace . str_replace('.php', '', $filename);

                // Check that the class exists and is instantiable
                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                match ($type) {
                    'services' => self::getContainer()->setService($className),
                    'actions'  => self::getContainer()->setAction($className),
                    default    => null,
                };
            }
        } catch (\Throwable $e) {
            // Silently ignore scan errors
        }
    }

    // ---------------------------------------------------------------
    // Module discovery
    // ---------------------------------------------------------------

    /**
     * Auto-discovers modules via #[AsModule] attributes.
     *
     * @return void
     */
    private static function discoverModules(): void
    {
        $root      = rtrim(self::$data['root'], '/\\');
        $discovery = new ModuleDiscovery();

        $definitions = $discovery->discover(
            [
                $root . '/Modules' => 'SEOPulse\\Modules',
            ],
        );

        ModuleManager::instance()->registerDefinitions($definitions);
    }

    /**
     * Registers namespace-to-module mappings for non-module classes.
     *
     * Admin pages, API controllers, and always-loaded classes that live
     * outside their module's namespace need explicit gating rules.
     *
     * @return void
     */
    private static function registerNamespaceMappings(): void
    {
        ModuleManager::instance()->registerNamespaceMappings(
            [
                // Always-loaded features (specific overrides before namespace wildcards)
                'Modules\\Content\\FreezeModifiedDate'     => null,
                // Admin settings pages — always loaded so menus stay visible
                'Admin\\Settings\\MetaSeoSettings'         => null,
                'Admin\\Settings\\RedirectionsSettings'    => null,
                'Admin\\Settings\\SitemapSettings'         => null,
                'Admin\\Settings\\AnalyticsSettings'       => null,
                'Admin\\Images\\ImageAltWizard'            => null,
                'Admin\\Images\\ImageDiagnosticPage'       => null,
                'Admin\\Monitor404Page'                    => null,
                // Admin components scoped to their module
                'Admin\\MetaBox\\MetaBoxAnalysis'          => 'content_analysis',
                // Associated API controllers
                'Api\\MetaSeo\\MetaSeoController'          => 'meta_seo',
                'Api\\MetaSeo\\MetaEngineController'       => 'meta_seo',
                'Api\\MetaSeo\\ArchiveSettingsController'  => 'meta_seo',
                'Api\\MetaSeo\\TaxonomySettingsController' => 'meta_seo',
                'Api\\MetaSeo\\FAQController'              => 'meta_seo',
                'Api\\RedirectionsController'              => 'redirections',
                'Api\\SitemapController'                   => 'sitemap',
                'Api\\Content\\AnalysisController'         => 'content_analysis',
                'Api\\Content\\RecommendationsController'  => 'content_analysis',
                'Api\\AnalyticsController'                 => 'analytics',
                'Api\\Images\\ImageAltController'          => 'meta_seo',
                'Api\\Images\\ImageDiagnosticController'   => 'meta_seo',
                'Api\\Monitor404Controller'                => 'monitor_404',
                // Core API controllers — always loaded
                'Api\\DashboardWidgetController'           => null,
                'Api\\DashboardController'                 => null,
                'Api\\GoogleSuggestController'             => null,
                'Api\\IndexingController'                  => null,
                'Api\\LogController'                       => null,
                'Api\\MigrationController'                 => null,
                'Api\\ModulesController'                   => null,
                'Api\\NotificationsController'             => null,
                'Api\\SetupWizardController'               => null,
                'Api\\ToolsController'                     => null,
                // Module-specific API controller (was unmapped)
                'Api\\LocalSeoController'                  => 'local_seo',
            ],
        );
    }

    // ---------------------------------------------------------------
    // Lifecycle hooks
    // ---------------------------------------------------------------

    /**
     * Main entry point — initializes and starts the plugin
     *
     * @param array{file: string, slug: string, main_file: string, root: string} $data
     * @return void
     */
    public static function execute(array $data): void
    {
        self::$data = array_merge(self::$data, $data);

        // Phase 1: Build the container (class auto-discovery)
        self::buildContainer();

        // Phase 2: Discover modules via #[AsModule] attributes
        self::discoverModules();

        // Phase 3: Register namespace mappings for Admin/Api routing
        self::registerNamespaceMappings();

        /**
         * Fires after the core container and module registry are built.
         *
         * Allows add-on plugins to register their own modules and services.
         *
         * @since 1.0.0
         * @param Container $container The DI container instance.
         * @param ModuleManager $moduleManager The module manager instance.
         */
        do_action('seopulse_container_built', self::getContainer(), ModuleManager::instance());

        // Phase 4: Hook into WordPress lifecycle
        add_action('plugins_loaded', [self::class, 'onPluginsLoaded'], 20);

        // Register activation/deactivation hooks
        register_activation_hook($data['file'], [self::class, 'onActivation']);
        register_deactivation_hook($data['file'], [self::class, 'onDeactivation']);
    }

    /**
     * plugins_loaded: Boot all enabled modules and dispatch non-module actions.
     *
     * @return void
     */
    public static function onPluginsLoaded(): void
    {
        // Boot modules via ModuleManager
        ModuleManager::instance()->boot();

        // Dispatch non-module container actions (admin pages, API controllers, etc.)
        self::dispatchNonModuleActions();
    }

    /**
     * Dispatches hooks for non-module action classes.
     *
     * Skips module entry points (already booted by ModuleManager)
     * and gates remaining classes via ModuleManager::shouldLoadClass().
     *
     * @return void
     */
    private static function dispatchNonModuleActions(): void
    {
        // Collect module entry point FQCNs to skip
        $moduleClasses = [];
        foreach (ModuleManager::instance()->getDefinitions() as $def) {
            $moduleClasses[] = ltrim($def->className, '\\');
        }

        foreach (self::getContainer()->getActions() as $key => $className) {
            try {
                if (is_object($className)) {
                    $instance = $className;
                    $fqcn     = get_class($instance);
                } elseif (is_string($className) && class_exists($className)) {
                    $fqcn = $className;
                } else {
                    continue;
                }

                $normalizedFqcn = ltrim($fqcn, '\\');

                // Skip module entry points (already dispatched by ModuleManager)
                if (in_array($normalizedFqcn, $moduleClasses, true)) {
                    continue;
                }

                // Filter by module namespace mapping
                if (!ModuleManager::instance()->shouldLoadClass($fqcn)) {
                    continue;
                }

                if (!is_object($className)) {
                    $instance = new $className();
                }

                // Dispatch by interface type
                if ($instance instanceof ExecuteHooksAdmin) {
                    if (is_admin()) {
                        $instance->hooks();
                    }
                } elseif ($instance instanceof ExecuteHooksFrontend) {
                    if (!is_admin()) {
                        $instance->hooks();
                    }
                } elseif ($instance instanceof ExecuteHooks) {
                    $instance->hooks();
                }
            } catch (\Throwable $e) {
                // Continue silently
            }
        }
    }

    /**
     * Plugin activation hook.
     *
     * @return void
     */
    public static function onActivation(): void
    {
        foreach (self::getContainer()->getActions() as $className) {
            try {
                if (!is_string($className) || !class_exists($className)) {
                    continue;
                }
                $instance = new $className();
                if ($instance instanceof ActivationHook) {
                    $instance->activate();
                }
            } catch (\Throwable $e) {
                // Continue silently
            }
        }
    }

    /**
     * Plugin deactivation hook.
     *
     * @return void
     */
    public static function onDeactivation(): void
    {
        foreach (self::getContainer()->getActions() as $className) {
            try {
                if (!is_string($className) || !class_exists($className)) {
                    continue;
                }
                $instance = new $className();
                if ($instance instanceof DeactivationHook) {
                    $instance->deactivate();
                }
            } catch (\Throwable $e) {
                // Continue silently
            }
        }
    }

    // ---------------------------------------------------------------
    // Backward compatibility — delegates to ModuleManager
    // ---------------------------------------------------------------

    /**
     * Checks if a module is enabled.
     *
     * @deprecated 1.5.0 Use ModuleManager::instance()->isModuleEnabled() instead.
     * @param string $moduleKey Module key
     * @return bool
     */
    public static function isModuleEnabled(string $moduleKey): bool
    {
        return ModuleManager::instance()->isModuleEnabled($moduleKey);
    }

    /**
     * Checks if a class should be loaded based on module state.
     *
     * @deprecated 1.5.0 Use ModuleManager::instance()->shouldLoadClass() instead.
     * @param string $className Class FQCN
     * @return bool
     */
    public static function shouldLoadClass(string $className): bool
    {
        return ModuleManager::instance()->shouldLoadClass($className);
    }

    /**
     * Returns the definition of all togglable modules.
     *
     * @deprecated 1.5.0 Use ModuleManager::instance()->getDefinitionsForUI() instead.
     * @return array<string, array{label: string, description: string, icon: string, pro: bool}>
     */
    public static function getModulesDefinition(): array
    {
        /**
         * Filters the module definitions.
         *
         * @since 1.0.0
         * @param array<string, array{label: string, description: string, icon: string, pro: bool}> $modules
         */
        return apply_filters('seopulse_modules_definition', ModuleManager::instance()->getDefinitionsForUI());
    }

    /**
     * Registers additional class-to-module mappings.
     *
     * @deprecated 1.5.0 Use ModuleManager::instance()->registerNamespaceMappings() instead.
     * @param array<string, string|null> $mappings
     * @return void
     */
    public static function registerModuleMappings(array $mappings): void
    {
        ModuleManager::instance()->registerNamespaceMappings($mappings);
    }
}
