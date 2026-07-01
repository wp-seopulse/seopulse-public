<?php

/**
 * Interface for plugin activation hooks
 *
 * Classes implementing this interface will have their activate() method
 * called on plugin activation.
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
 * ActivationHook Interface
 */
interface ActivationHook
{
    /**
     * Activation logic
     *
     * @return void
     */
    public function activate(): void;
}
