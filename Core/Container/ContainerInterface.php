<?php

/**
 * Service container interface
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
 * ContainerInterface Interface
 */
interface ContainerInterface
{
    /**
     * Retrieves all registered actions (hook controllers)
     *
     * @return array<string, string|object>
     */
    public function getActions(): array;

    /**
     * Retrieves all registered services
     *
     * @return array<string, string|object>
     */
    public function getServices(): array;

    /**
     * Retrieves a service by name
     *
     * @param string $name Service name
     * @return object|null
     */
    public function getServiceByName(string $name): ?object;
}
