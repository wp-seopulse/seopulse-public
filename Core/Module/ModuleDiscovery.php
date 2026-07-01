<?php

/**
 * Scans module directories to find classes annotated with #[AsModule].
 *
 * Each discovered class produces a ModuleDefinition that is registered
 * with the ModuleManager. This eliminates the need for manual mapping.
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

final class ModuleDiscovery
{
    /**
     * Scans one or more directories for module entry points.
     *
     * @param array<string, string> $directories Map of directory path => namespace prefix
     *                                           e.g. ['/path/to/Modules' => 'SEOPulse\\Modules']
     * @return ModuleDefinition[]
     */
    public function discover(array $directories): array
    {
        $definitions = [];

        foreach ($directories as $path => $namespacePrefix) {
            if (!is_dir($path)) {
                continue;
            }

            $subdirs = array_diff(scandir($path), ['..', '.']);

            foreach ($subdirs as $subdir) {
                $subdirPath = $path . DIRECTORY_SEPARATOR . $subdir;
                if (!is_dir($subdirPath)) {
                    continue;
                }

                // Look for *Module.php files in each subdirectory
                $files = glob($subdirPath . '/*Module.php');
                if (empty($files)) {
                    continue;
                }

                foreach ($files as $file) {
                    $className  = $namespacePrefix . '\\' . $subdir . '\\' . basename($file, '.php');
                    $definition = $this->resolveDefinition($className);
                    if ($definition !== null) {
                        $definitions[ $definition->key ] = $definition;
                    }
                }
            }
        }

        return $definitions;
    }

    /**
     * Resolves a class name to a ModuleDefinition via its #[AsModule] attribute.
     */
    private function resolveDefinition(string $className): ?ModuleDefinition
    {
        if (!class_exists($className)) {
            return null;
        }

        $reflection = new \ReflectionClass($className);

        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return null;
        }

        $attributes = $reflection->getAttributes(AsModule::class);

        if (empty($attributes)) {
            return null;
        }

        /** @var AsModule $attr */
        $attr = $attributes[0]->newInstance();

        return ModuleDefinition::fromAttribute($attr, $className);
    }
}
