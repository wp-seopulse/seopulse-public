<?php

/**
 * Indexing automation hooks — auto-submit on publish/update/delete.
 *
 * Fires after post transitions and schedules a background job that
 * delegates to the registry (submissions are never made synchronously
 * inside the request that saves/publishes the post).
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
    /** Cron hook name (daily maintenance). */
    public const CRON_HOOK = 'seopulse_indexing_health_check';

    /** Cron hook name (single, near-immediate background submission job). */
    public const PROCESS_HOOK = 'seopulse_indexing_process_submission';

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

        // Background job that actually performs the (blocking) HTTP
        // submissions, decoupled from the post-save request.
        add_action(self::PROCESS_HOOK, [$this, 'process_submission'], 10, 2);

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
     * Fires on new publish and on updates (publish → publish). Does NOT
     * perform the actual HTTP submissions itself — it only schedules a
     * background job, so publishing/saving a post is never slowed down by
     * external API calls.
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

        if (!$this->isPostTypeIndexable($post->post_type)) {
            return;
        }

        $url = get_permalink($post);

        if (!$url || !is_string($url)) {
            return;
        }

        $this->scheduleSubmission($url, 'updated');
    }

    /**
     * Schedule a URL_DELETED notification on post deletion.
     *
     * Only fires for previously-published posts. The URL is resolved and
     * passed directly to the cron event (rather than the post ID) because
     * by the time the background job runs, the post no longer exists and
     * get_permalink() would fail.
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

        if (!$this->isPostTypeIndexable($post->post_type)) {
            return;
        }

        $url = get_permalink($post);

        if (!$url || !is_string($url)) {
            return;
        }

        $this->scheduleSubmission($url, 'deleted');
    }

    /**
     * Background job: perform the actual (blocking) submissions and log
     * the results. Runs outside of any user-facing request.
     *
     * @param string $url URL to submit.
     * @param string $action 'updated' | 'deleted'.
     *
     * @return void
     */
    public function process_submission(string $url, string $action): void
    {
        $results = $this->registry->submitAllRespectingCooldown($url, $action);

        foreach ($results as $serviceId => $result) {
            $status = 'error';

            if (!empty($result['skipped'])) {
                $status = 'skipped';
            } elseif ($result['success']) {
                $status = 'success';
            }

            IndexingLogger::log($url, $serviceId, $status, $result['message']);
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
     * Schedule a background submission job, de-duplicated against any
     * already-pending job for the same URL + action.
     *
     * @param string $url URL to submit.
     * @param string $action 'updated' | 'deleted'.
     *
     * @return void
     */
    private function scheduleSubmission(string $url, string $action): void
    {
        $args = [$url, $action];

        if (wp_next_scheduled(self::PROCESS_HOOK, $args)) {
            return;
        }

        // A few seconds' delay so it never runs inside the same request
        // that triggered it, and so rapid successive saves collapse into
        // a single scheduled job per URL/action pair.
        wp_schedule_single_event(time() + 5, self::PROCESS_HOOK, $args);
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

    /**
     * Whether a post type should trigger automatic indexing submissions.
     *
     * Prevents submitting URLs for internal/non-public post types (e.g.
     * custom post types with `public => false`).
     *
     * @param string $postType Post type slug.
     *
     * @return bool
     */
    private function isPostTypeIndexable(string $postType): bool
    {
        $type = get_post_type_object($postType);

        if (!$type instanceof \WP_Post_Type) {
            return false;
        }

        /**
         * Filter which post types trigger automatic search-engine
         * indexing submissions. Defaults to the post type's own
         * "public" flag.
         *
         * @param bool $indexable Whether $postType should be submitted.
         * @param string $postType Post type slug.
         */
        return (bool) apply_filters('seopulse_indexing_post_type_indexable', (bool) $type->public, $postType);
    }
}
