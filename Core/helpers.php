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

if (!function_exists(__NAMESPACE__ . '\\seopulse_breadcrumbs')) {
    /**
     * Renders the SEOPulse breadcrumb trail (namespaced version).
     *
     * This is the **internal / plugin-code** version of the breadcrumb helper.
     * It lives in the `SEOPulse\` namespace and is intended to be called from
     * within the plugin itself or from other namespaced PHP code:
     *
     *     \SEOPulse\seopulse_breadcrumbs();
     *     // or, inside the SEOPulse namespace:
     *     seopulse_breadcrumbs();
     *
     * For **theme integration**, use the global-namespace template tag defined
     * in `Core/template-tags.php` instead — it does not require any `use` import
     * and is safe to call from any theme file.
     *
     * Both functions delegate to the same `BreadcrumbRenderer::render()` implementation
     * and produce identical output. The two declarations exist to satisfy two distinct
     * calling conventions; there is no conflict because the namespaces are different.
     *
     * @since 1.0.0
     *
     * @param bool $echo Whether to echo (true) or return (false) the HTML.
     * @return string Breadcrumb HTML.
     */
    function seopulse_breadcrumbs(bool $echo = true): string
    {
        $renderer = new Modules\MetaSeo\BreadcrumbRenderer();

        return $renderer->render($echo);
    }
}
