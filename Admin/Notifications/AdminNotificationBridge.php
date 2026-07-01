<?php

/**
 * PHP → JS bridge for backend notifications.
 *
 * Registers an admin_enqueue_scripts hook that injects notifications
 * from the queue (transient) into the JS script as inline data
 * in `window.seopulseNotifications`.
 *
 * @package SEOPulse
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\Notifications;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Contracts\ExecuteHooksAdmin;

class AdminNotificationBridge implements ExecuteHooksAdmin
{
    /**
     * Registers admin hooks.
     */
    public function hooks(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'localize_notifications'], 99);
    }

    /**
     * Injects backend notifications into the SEOPulse JS script.
     *
     * Uses wp_localize_script to pass data to the frontend.
     * Notifications are flushed after injection.
     *
     * @param string $hook The current admin screen.
     */
    public function localize_notifications(string $hook): void
    {
        // Check that a SEOPulse script is enqueued
        if (!wp_script_is('seopulse-admin', 'enqueued') && !wp_script_is('seopulse-admin-global', 'enqueued')) {
            return;
        }

        $notifications = AdminNotification::flush();

        if (empty($notifications)) {
            return;
        }

        $handle = wp_script_is('seopulse-admin', 'enqueued')
            ? 'seopulse-admin'
            : 'seopulse-admin-global';

        wp_localize_script(
            $handle,
            'seopulseNotifications',
            [
                'queue' => $notifications,
            ],
        );
    }
}
