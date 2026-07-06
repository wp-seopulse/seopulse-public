<?php

/**
 * REST controller — admin notification panel
 *
 * GET /seopulse/v1/notifications
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api;

use SEOPulse\Admin\Notifications\AdminNotificationPanel;
use SEOPulse\Core\Abstracts\RestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * NotificationsController class
 */
class NotificationsController extends RestController
{
    public function __construct()
    {
        $this->rest_base = 'notifications';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_notifications'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // POST /seopulse/v1/notifications/dismiss
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/dismiss',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'dismiss_notification'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'id' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ],
        );
    }

    /**
     * GET /seopulse/v1/notifications
     *
     * Returns the serialised notification panel items.
     * Each item shape:
     *   { id, severity, icon, title, message, action_url?, action_label? }
     */
    public function get_notifications(WP_REST_Request $request): WP_REST_Response
    {
        $notifications = AdminNotificationPanel::collect();

        return $this->success($notifications);
    }

    /**
     * POST /seopulse/v1/notifications/dismiss
     *
     * Marks a notification as dismissed for the current user so it no
     * longer appears in subsequent GET /notifications responses.
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function dismiss_notification(WP_REST_Request $request)
    {
        $id = (string) $request->get_param('id');

        if (trim($id) === '') {
            return $this->error(__('A notification ID is required.', 'seopulse'), 400);
        }

        AdminNotificationPanel::dismiss($id);

        return $this->success(['dismissed' => true]);
    }
}
