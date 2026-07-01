<?php

/**
 * Indexing automation hooks — auto-submit on publish/update/delete.
 *
 * Fires after post transitions and delegates to the registry.
 * Also schedules a daily health-check / log-purge cron.
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Contracts\ExecuteHooks;
use SEOPulse\Services\IndexingLogger;
use SEOPulse\Services\IndexingServiceRegistry;

class IndexingHooks implements ExecuteHooks
{
    /** Cron hook name. */
    public const CRON_HOOK = 'seopulse_indexing_health_check';

    private IndexingServiceRegistry $registry;

    public function __construct()
    {
        $this->registry = new IndexingServiceRegistry();
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks(): void
    {
        // Auto-submit on publish / update.
        add_action('transition_post_status', [$this, 'on_post_transition'], 10, 3);

        // Submit removal on delete.
        add_action('before_delete_post', [$this, 'on_delete'], 10, 2);

        // Daily cron for log purge and health checks.
        add_action(self::CRON_HOOK, [$this, 'run_health_check']);

        // Schedule cron if not scheduled yet.
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Auto-submit when a post transitions to 'publish'.
     *
     * Fires on new publish and on updates (publish → publish).
     *
     * @param string $new_status New post status.
     * @param string $old_status Old post status.
     * @param \WP_Post $post Post object.
     *
     * @return void
     */
    public function on_post_transition(string $new_status, string $old_status, \WP_Post $post): void
    {
        // Only process transitions to 'publish'.
        if ($new_status !== 'publish') {
            return;
        }

        // Skip revisions and auto-drafts.
        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }

        if (!$this->isAutoSubmitEnabled()) {
            return;
        }

        $url = get_permalink($post);

        if (!$url || !is_string($url)) {
            return;
        }

        // Duplicate prevention.
        if (IndexingLogger::wasRecentlySubmitted($url)) {
            return;
        }

        $results = $this->registry->submitAll($url, 'updated');

        foreach ($results as $serviceId => $result) {
            IndexingLogger::log(
                $url,
                $serviceId,
                $result['success'] ? 'success' : 'error',
                $result['message'],
            );
        }
    }

    /**
     * Submit a URL_DELETED notification on post deletion.
     *
     * Only fires for previously-published posts.
     *
     * @param int $post_id Post ID.
     * @param \WP_Post $post Post object.
     *
     * @return void
     */
    public function on_delete(int $post_id, \WP_Post $post): void
    {
        if ($post->post_status !== 'publish') {
            return;
        }

        if (!$this->isAutoSubmitEnabled()) {
            return;
        }

        $url = get_permalink($post);

        if (!$url || !is_string($url)) {
            return;
        }

        $results = $this->registry->submitAll($url, 'deleted');

        foreach ($results as $serviceId => $result) {
            IndexingLogger::log(
                $url,
                $serviceId,
                $result['success'] ? 'success' : 'error',
                $result['message'],
            );
        }
    }

    /**
     * Daily cron: purge old log entries.
     *
     * @return void
     */
    public function run_health_check(): void
    {
        // Purge logs older than 90 days.
        IndexingLogger::purge(90);
    }

    /**
     * Whether auto-submit on publish is enabled.
     *
     * @return bool
     */
    private function isAutoSubmitEnabled(): bool
    {
        $settings = (array) get_option(Options::INDEXING, []);

        // Default to true when the option hasn't been set yet.
        $auto = $settings['auto_submit_on_publish'] ?? true;

        if (!$auto) {
            return false;
        }

        // Must have at least one provider configured.
        return count($this->registry->getConfigured()) > 0;
    }
}
