<?php

/**
 * SEOPulse dependency injection container
 *
 * Manages lazy registration and resolution of services and actions.
 * Inspired by the SEOPress architecture, adapted for strict PHP 8.1.
 *
 * @package SEOPulse\Core\Container
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Container;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Container class
 *
 * Stores services and actions as class name strings
 * and instantiates them on first access (lazy loading).
 */
class Container implements ContainerInterface
{
    /**
     * Registry of WordPress actions (hook controllers)
     *
     * @var array<string, string|object>
     */
    private array $actions = [];

    /**
     * Registry of services (business logic / data access)
     *
     * @var array<string, string|object>
     */
    private array $services = [];

    /**
     * Registers an action (class implementing ExecuteHooks)
     *
     * @param string $className Full class name
     * @return self
     */
    public function setAction(string $className): self
    {
        $className                   = ltrim($className, '\\');
        $this->actions[ $className ] = $className;

        return $this;
    }

    /**
     * Registers multiple actions
     *
     * @param array<string> $actions
     * @return self
     */
    public function setActions(array $actions): self
    {
        foreach ($actions as $action) {
            $this->setAction($action);
        }

        return $this;
    }

    /**
     * Retrieves all registered actions
     *
     * @return array<string, string|object>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * Retrieves an action and instantiates it if needed (lazy loading)
     *
     * @param string $name Full class name
     * @return object|null
     */
    public function getAction(string $name): ?object
    {
        $name = ltrim($name, '\\');
        if (!array_key_exists($name, $this->actions)) {
            return null;
        }

        try {
            if (is_string($this->actions[ $name ]) && class_exists($this->actions[ $name ])) {
                $this->actions[ $name ] = new $this->actions[ $name ]();
            }

            return is_object($this->actions[ $name ]) ? $this->actions[ $name ] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Registers a service
     *
     * The short name (class name without namespace) is used as access key.
     * If the class defines a NAME_SERVICE constant, it is used instead.
     *
     * @param string $className Full class name
     * @return self
     */
    public function setService(string $className): self
    {
        $className = ltrim($className, '\\');

        // Determine the short service name
        if (defined($className . '::NAME_SERVICE')) {
            $shortName = $className::NAME_SERVICE;
        } else {
            $parts     = explode('\\', $className);
            $shortName = end($parts);
        }

        $this->services[ $shortName ] = $className;

        return $this;
    }

    /**
     * Registers multiple services
     *
     * @param array<string> $services
     * @return self
     */
    public function setServices(array $services): self
    {
        foreach ($services as $service) {
            $this->setService($service);
        }

        return $this;
    }

    /**
     * Retrieves all registered services
     *
     * @return array<string, string|object>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Retrieves a service by its short name and instantiates it if needed (lazy loading)
     *
     * @param string $name Short service name (e.g.: 'CacheManager', 'GeneralOption')
     * @return object|null
     */
    public function getServiceByName(string $name): ?object
    {
        if (!isset($this->services[ $name ])) {
            return null;
        }

        try {
            if (is_string($this->services[ $name ]) && class_exists($this->services[ $name ])) {
                $this->services[ $name ] = new $this->services[ $name ]();
            }

            return is_object($this->services[ $name ]) ? $this->services[ $name ] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
