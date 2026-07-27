<?php

/**
 * SEOPulse global helper functions
 *
 * Provides shortcuts to access the Container and services.
 *
 * @package SEOPulse
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse;

if (!defined('ABSPATH')) {
    return;
}

use SEOPulse\Core\Kernel;

if (!function_exists(__NAMESPACE__ . '\\seopulse_get_service')) {
    /**
     * Retrieves a service by name from the Container
     *
     * @param string $name Service name (e.g.: 'GeneralOption', 'CacheManager')
     * @return object|null
     */
    function seopulse_get_service(string $name): ?object
    {
        return Kernel::getContainer()->getServiceByName($name);
    }
}

if (!function_exists(__NAMESPACE__ . '\\seopulse_get_action')) {
    /**
     * Retrieves an action (hooked class) by its FQCN from the Container
     *
     * @param string $className Full class name
     * @return object|null
     */
    function seopulse_get_action(string $className): ?object
    {
        return Kernel::getContainer()->getAction($className);
    }
}

if (!function_exists(__NAMESPACE__ . '\\seopulse_notify')) {
    /**
     * Shortcut to add an admin snackbar notification.
     *
     * @since 1.0.0
     *
     * @param string $type Notification type (success|error|warning|info).
     * @param string $message Message text.
     * @param array $options Additional options (duration, priority, dedupeKey, actions).
     */
    function seopulse_notify(string $type, string $message, array $options = []): void
    {
        Admin\Notifications\AdminNotification::add($type, $message, $options);
    }
}
