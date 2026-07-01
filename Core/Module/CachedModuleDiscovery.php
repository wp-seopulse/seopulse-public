<?php

/**
 * Cached module discovery.
 *
 * On first run (or after cache clear), scans directories and writes
 * a compiled PHP file that returns the definitions array directly.
 * Subsequent loads skip the filesystem scan entirely.
 *
 * @package SEOPulse\Core\Module
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Module;

if (!defined('ABSPATH')) {
    exit;
}

final class CachedModuleDiscovery
{
    private string $cacheFile;
    private ModuleDiscovery $discovery;

    public function __construct(string $cacheDir)
    {
        $this->cacheFile = rtrim($cacheDir, '/\\') . '/module-definitions.php';
        $this->discovery = new ModuleDiscovery();
    }

    /**
     * Returns module definitions, using cache if available.
     *
     * @param array<string, string> $directories
     * @return ModuleDefinition[]
     */
    public function discover(array $directories): array
    {
        // In development mode, always re-scan
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return $this->discovery->discover($directories);
        }

        // Try cached definitions
        if (file_exists($this->cacheFile)) {
            $cached = include $this->cacheFile;
            if (is_array($cached)) {
                return $this->hydrate($cached);
            }
        }

        // Discover and cache
        $definitions = $this->discovery->discover($directories);
        $this->writeCache($definitions);

        return $definitions;
    }

    /**
     * Clears the cache (called on plugin activation/update).
     */
    public function clear(): void
    {
        if (file_exists($this->cacheFile)) {
            wp_delete_file($this->cacheFile);
        }
    }

    /**
     * @param ModuleDefinition[] $definitions
     */
    private function writeCache(array $definitions): void
    {
        $data = [];
        foreach ($definitions as $key => $def) {
            $data[ $key ] = [
                'key'         => $def->key,
                'label'       => $def->label,
                'description' => $def->description,
                'icon'        => $def->icon,
                'className'   => $def->className,
                'pro'         => $def->pro,
                'default'     => $def->default,
                'requires'    => $def->requires,
                'namespace'   => $def->namespace,
            ];
        }

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
        $content = '<?php return ' . var_export($data, true) . ';';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($this->cacheFile, $content, LOCK_EX);
    }

    /**
     * Rebuilds ModuleDefinition objects from cached array.
     *
     * @return ModuleDefinition[]
     */
    private function hydrate(array $cached): array
    {
        $definitions = [];
        foreach ($cached as $key => $data) {
            $definitions[ $key ] = new ModuleDefinition(
                key: $data['key'],
                label: $data['label'],
                description: $data['description'],
                icon: $data['icon'],
                className: $data['className'],
                pro: $data['pro'],
                default: $data['default'],
                requires: $data['requires'],
                namespace: $data['namespace'],
            );
        }

        return $definitions;
    }
}
