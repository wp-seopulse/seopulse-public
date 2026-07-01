<?php

/**
 * Contract for all SEOPulse modules (Free and Pro).
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
 * Every toggleable module MUST implement this interface.
 *
 * The ModuleManager uses it to orchestrate the module lifecycle:
 * registration → boot → hooks.
 */
interface ModuleInterface
{
    /**
     * Returns the unique module key (must match #[AsModule] key).
     */
    public function getKey(): string;

    /**
     * Registers WordPress hooks for this module.
     * Only called when the module is enabled.
     */
    public function hooks(): void;

    /**
     * Called when the module is activated by the user.
     * Use for initial setup (create tables, set defaults, etc.).
     */
    public function onActivate(): void;

    /**
     * Called when the module is deactivated by the user.
     * Use for cleanup (unschedule crons, etc.).
     */
    public function onDeactivate(): void;
}
