<?php

/**
 * Marker interface for frontend-only hooks
 *
 * Classes implementing this interface will only have their hooks() method
 * called in the frontend context (is_admin() === false)
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
 * ExecuteHooksFrontend Interface
 */
interface ExecuteHooksFrontend extends ExecuteHooks
{
}
