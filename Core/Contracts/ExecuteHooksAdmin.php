<?php

/**
 * Marker interface for admin-only hooks
 *
 * Classes implementing this interface will only have their hooks() method
 * called in the admin context (is_admin() === true)
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
 * ExecuteHooksAdmin Interface
 */
interface ExecuteHooksAdmin extends ExecuteHooks
{
}
