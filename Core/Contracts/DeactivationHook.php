<?php

/**
 * Interface for plugin deactivation hooks
 *
 * Classes implementing this interface will have their deactivate() method
 * called on plugin deactivation.
 *
 * @package SEOPulse\Core\Contracts
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * DeactivationHook Interface
 */
interface DeactivationHook
{
    /**
     * Deactivation logic
     *
     * @return void
     */
    public function deactivate(): void;
}
