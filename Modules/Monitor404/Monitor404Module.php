<?php

/**
 * 404 Monitor – Module Orchestrator
 *
 * Wires all sub-systems together and registers WordPress hooks:
 *
 * - Advanced 404 logging via Monitor404Logger
 * - Automatic cleanup cron via Monitor404Repository::deleteOlderThan()
 * - Weekly email reports via Monitor404EmailReporter
 * - Global 404 redirect option
 * - redirect_canonical disabling
 * - DB migration on first run / version bump
 *
 * @package SEOPulse\Modules\Monitor404
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Monitor404;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\Module;
use SEOPulse\Core\Attributes\AsModule;
use SEOPulse\Core\Contracts\ExecuteHooksFrontend;
use SEOPulse\Core\Contracts\ModuleInterface;
use WP_Post;

#[AsModule(
    key: 'monitor_404',
    label: '404 Monitor',
    description: 'Track and manage 404 errors with automatic redirect suggestions.',
    icon: 'dashicons-warning',
    pro: false,
    default: true,
    requires: [],
    namespace: 'SEOPulse\\Modules\\Monitor404\\',
)]
class Monitor404Module extends Module implements ExecuteHooksFrontend, ModuleInterface
{
    private Monitor404Logger $logger;
    private Monitor404EmailReporter $emailReporter;
    private Monitor404Repository $repo;

    public function __construct()
    {
        $this->name   = 'monitor_404';
        $this->weight = 0.0; // No weight in the global SEO score

        require_once __DIR__ . '/Monitor404Database.php';
        require_once __DIR__ . '/Monitor404Logger.php';
        require_once __DIR__ . '/Monitor404Repository.php';
        require_once __DIR__ . '/Monitor404SuggestionEngine.php';
        require_once __DIR__ . '/Monitor404EmailReporter.php';

        $this->logger        = new Monitor404Logger();
        $this->emailReporter = new Monitor404EmailReporter();
        $this->repo          = new Monitor404Repository();
    }

    // =========================================================================
    // ModuleInterface
    // =========================================================================

    public function getKey(): string
    {
        return 'monitor_404';
    }

    public function onActivate(): void
    {
        Monitor404Database::migrate();
        $this->scheduleCleanupCron();
        $this->scheduleStatsCron();
    }

    public function onDeactivate(): void
    {
        wp_clear_scheduled_hook('seopulse_404_cleanup_cron');
        wp_clear_scheduled_hook('seopulse_404_stats_cron');
        $this->emailReporter->clearCron();
    }

    // =========================================================================
    // Hook registration
    // =========================================================================

    public function hooks(): void
    {
        // Run DB migration once
        Monitor404Database::migrate();

        // ---- Frontend: log 404s (priority 99 to run after WP resolves the query)
        add_action('template_redirect', [$this->logger, 'maybeLog'], 99);

        // ---- Cleanup cron
        add_action('seopulse_404_cleanup_cron', [$this, 'runCleanup']);
        $this->scheduleCleanupCron();

        // ---- Daily stats aggregation cron
        add_action('seopulse_404_stats_cron', [$this, 'runStatsAggregation']);
        $this->scheduleStatsCron();

        // ---- Weekly email cron
        add_action('seopulse_404_weekly_report', [$this->emailReporter, 'send']);
        $this->emailReporter->scheduleCron();

        // ---- Early redirect for global 404 option (priority 1 = before anything else)
        add_action('template_redirect', [$this, 'maybeGlobalRedirect'], 1);

        // ---- Optional: disable redirect_canonical
        add_action('init', [$this, 'maybeDisableRedirectCanonical']);
    }

    // =========================================================================
    // Cleanup cron
    // =========================================================================

    public function runCleanup(): void
    {
        $days = (int) apply_filters(
            'seopulse_404_retention_days',
            (int) $this->getOption('retention_days', 90),
        );

        $this->repo->deleteOlderThan($days);
    }

    private function scheduleCleanupCron(): void
    {
        if (!wp_next_scheduled('seopulse_404_cleanup_cron')) {
            wp_schedule_event(time(), 'daily', 'seopulse_404_cleanup_cron');
        }
    }

    // =========================================================================
    // Stats aggregation cron
    // =========================================================================

    public function runStatsAggregation(): void
    {
        $this->repo->aggregateDailyStats();
    }

    private function scheduleStatsCron(): void
    {
        if (!wp_next_scheduled('seopulse_404_stats_cron')) {
            $nextRun = strtotime('tomorrow 00:15:00');
            wp_schedule_event($nextRun, 'daily', 'seopulse_404_stats_cron');
        }
    }

    // =========================================================================
    // Global 404 redirect
    // =========================================================================

    public function maybeGlobalRedirect(): void
    {
        if (!is_404()) {
            return;
        }

        if (!$this->getOption('global_redirect_enabled', false)) {
            return;
        }

        $loggedIn = is_user_logged_in();

        if ($loggedIn) {
            $to = $this->getOption('global_redirect_url_logged_in', '') ?: $this->getOption('global_redirect_url', '');
        } else {
            $to = $this->getOption('global_redirect_url', '');
        }

        $target = !empty($to) ? esc_url_raw($to) : home_url('/');

        wp_safe_redirect($target, 301);
        exit;
    }

    // =========================================================================
    // Optional WordPress cleanups
    // =========================================================================

    public function maybeDisableRedirectCanonical(): void
    {
        if ($this->getOption('disable_redirect_canonical', false)) {
            remove_action('template_redirect', 'redirect_canonical');
        }
    }

    // =========================================================================
    // Analysis (required by Module abstract)
    // =========================================================================

    public function analyze(WP_Post $post): array
    {
        $total404s = $this->repo->getSummary()['active'] ?? 0;

        $score           = 100;
        $issues          = [];
        $recommendations = [];

        if ($total404s > 100) {
            $score             = 70;
            $issues[]          = [
                'type'     => 'high_404_rate',
                'severity' => 'medium',
                'message'  => sprintf(
                    /* translators: %d: number of active 404 errors */
                    __('Your site has %d active 404 errors tracked.', 'seopulse'),
                    $total404s,
                ),
            ];
            $recommendations[] = [
                'type'     => 'fix_404s',
                'priority' => 'medium',
                'message'  => __('High number of 404 errors detected.', 'seopulse'),
                'action'   => __('Review and create redirections for broken URLs.', 'seopulse'),
            ];
        }

        return compact('score', 'issues', 'recommendations');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function getOption(string $key, mixed $default = null): mixed
    {
        static $cache = null;

        if ($cache === null) {
            $cache = get_option('seopulse_404_settings', []);
            if (!is_array($cache)) {
                $cache = [];
            }
        }

        return $cache[ $key ] ?? $default;
    }
}
