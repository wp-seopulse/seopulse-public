<?php

/**
 * PHP utility class for triggering snackbar notifications
 * from the WordPress backend.
 *
 * Notifications are passed to the frontend via wp_localize_script
 * and are automatically displayed when the admin page loads.
 *
 * @package SEOPulse
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin\Notifications;

if (!defined('ABSPATH')) {
    exit;
}

class AdminNotification
{
    /**
     * Transient key for storing queued notifications.
     */
    private const TRANSIENT_KEY = 'seopulse_admin_notifications';

    /**
     * Transient lifetime in seconds (5 minutes).
     */
    private const TRANSIENT_EXPIRY = 300;

    /**
     * Maximum number of queued notifications.
     */
    private const MAX_QUEUE_SIZE = 10;

    /**
     * Valid notification types.
     */
    private const VALID_TYPES = ['success', 'error', 'warning', 'info'];

    /**
     * Valid priorities.
     */
    private const VALID_PRIORITIES = ['low', 'normal', 'high', 'critical'];

    /*
    -------------------------------------------------------------- */
    /*
        Public API — Static methods                              */
    /* -------------------------------------------------------------- */

    /**
     * Add a success notification.
     *
     * @param string $message Message text.
     * @param array $options Additional options.
     */
    public static function success(string $message, array $options = []): void
    {
        self::add('success', $message, $options);
    }

    /**
     * Add an error notification.
     *
     * @param string $message Message text.
     * @param array $options Additional options.
     */
    public static function error(string $message, array $options = []): void
    {
        $options['duration'] = $options['duration'] ?? 6000;
        self::add('error', $message, $options);
    }

    /**
     * Add a warning notification.
     *
     * @param string $message Message text.
     * @param array $options Additional options.
     */
    public static function warning(string $message, array $options = []): void
    {
        $options['duration'] = $options['duration'] ?? 5000;
        self::add('warning', $message, $options);
    }

    /**
     * Add an info notification.
     *
     * @param string $message Message text.
     * @param array $options Additional options.
     */
    public static function info(string $message, array $options = []): void
    {
        self::add('info', $message, $options);
    }

    /**
     * Add a notification with explicit type.
     *
     * @param string $type Notification type (success|error|warning|info).
     * @param string $message Message text.
     * @param array $options {
     *                       Additional options.
     *
     * @type int $duration   Display duration in ms (default: 4000).
     * @type string $priority   Priority (low|normal|high|critical).
     * @type string $dedupeKey  Deduplication key.
     * @type array $actions    Actions [{label: string, url: string}].
     *             }
     */
    public static function add(string $type, string $message, array $options = []): void
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            $type = 'info';
        }

        $priority = $options['priority'] ?? 'normal';
        if (!in_array($priority, self::VALID_PRIORITIES, true)) {
            $priority = 'normal';
        }

        $notification = [
            'type'     => $type,
            'message'  => sanitize_text_field($message),
            'duration' => absint($options['duration'] ?? 4000),
            'priority' => $priority,
        ];

        if (!empty($options['dedupeKey'])) {
            $notification['dedupeKey'] = sanitize_key($options['dedupeKey']);
        }

        if (!empty($options['actions']) && is_array($options['actions'])) {
            $notification['actions'] = array_map(
                function ($action) {
                    return [
                        'label' => sanitize_text_field($action['label'] ?? ''),
                        'url'   => esc_url($action['url'] ?? ''),
                    ];
                },
                $options['actions'],
            );
        }

        $queue   = self::get_queue();
        $queue[] = $notification;

        // Limit the queue size
        if (count($queue) > self::MAX_QUEUE_SIZE) {
            $queue = array_slice($queue, -self::MAX_QUEUE_SIZE);
        }

        set_transient(self::user_transient_key(), $queue, self::TRANSIENT_EXPIRY);
    }

    /**
     * Retrieve and flush the notification queue for the current user.
     *
     * @return array<int, array> List of notifications.
     */
    public static function flush(): array
    {
        $queue = self::get_queue();
        if (!empty($queue)) {
            delete_transient(self::user_transient_key());
        }

        return $queue;
    }

    /**
     * Retrieve the queue without flushing it.
     *
     * @return array<int, array>
     */
    public static function get_queue(): array
    {
        $queue = get_transient(self::user_transient_key());

        return is_array($queue) ? $queue : [];
    }

    /*
    -------------------------------------------------------------- */
    /*
        Internal helpers                                                */
    /* -------------------------------------------------------------- */

    /**
     * Transient key for the current user.
     *
     * This prevents one admin from seeing another's notifications.
     *
     * @return string
     */
    private static function user_transient_key(): string
    {
        $user_id = get_current_user_id();

        return self::TRANSIENT_KEY . '_' . ($user_id > 0 ? $user_id : 0);
    }
}
