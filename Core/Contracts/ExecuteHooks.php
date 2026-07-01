<?php

/**
 * Interface for classes that register WordPress hooks
 *
 * Implemented by Actions (controllers) that need to execute
 * in both admin and frontend.
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
 * ExecuteHooks Interface
 */
interface ExecuteHooks
{
    /**
     * Registers WordPress hooks (actions and filters)
     *
     * @return void
     */
    public function hooks(): void;
}
