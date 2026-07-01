<?php

/**
 * WP-CLI command: wp seopulse dashboard
 *
 * Displays a quick SEO dashboard overview in the terminal.
 *
 * @package SEOPulse\Core\CLI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Services\DashboardSummary;
use WP_CLI;

/**
 * Display SEO dashboard metrics.
 */
class DashboardCommand extends BaseCommand
{
    /**
     * Shows an SEO dashboard summary.
     *
     * Displays configuration score, content stats, sitemap status,
     * indexation settings and image issues.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: table, json. Default: table
     *
     * ## EXAMPLES
     *
     *     # Show dashboard overview
     *     wp seopulse dashboard
     *
     *     # Machine-readable output
     *     wp seopulse dashboard --format=json
     *
     * @param array<string> $args Positional arguments
     * @param array<string, mixed> $assoc Associative arguments
     * @return void
     */
    public function __invoke(array $args, array $assoc): void
    {
        $summary = new DashboardSummary();
        $data    = $summary->get();
        $format  = $this->get_format($assoc);

        if ($format === 'json') {
            WP_CLI::line(wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        // ── Configuration ──
        $this->log('');
        $this->log(WP_CLI::colorize('%GSEO Dashboard — SEOPulse%n'));
        $this->log(str_repeat('─', 50));

        $config_score = $data['configuration_score'] ?? 0;
        $this->log(sprintf('Configuration Score: %s/100', $config_score));

        if (!($data['wizard_complete'] ?? false)) {
            $this->warning('Setup wizard not completed.');
        }

        // ── Content Stats ──
        $stats = $data['content_stats'] ?? [];
        if (!empty($stats)) {
            $this->log('');
            $this->log(WP_CLI::colorize('%YContent Stats%n'));

            $rows = [
                [
                    'Metric' => 'Analyzed posts',
                    'Value'  => $stats['analyzed_count'] ?? 0,
                ],
                [
                    'Metric' => 'Average score',
                    'Value'  => $stats['avg_score'] ?? 0,
                ],
                [
                    'Metric' => 'Needs improvement',
                    'Value'  => $stats['needs_improvement'] ?? 0,
                ],
                [
                    'Metric' => 'Missing meta',
                    'Value'  => $stats['missing_meta'] ?? 0,
                ],
                [
                    'Metric' => 'Missing featured image',
                    'Value'  => $stats['missing_featured_image'] ?? 0,
                ],
                [
                    'Metric' => 'Missing alt text',
                    'Value'  => $stats['missing_alt'] ?? 0,
                ],
            ];

            $this->format_items($rows, ['Metric', 'Value']);
        }

        // ── Sitemap ──
        $sitemap = $data['sitemap_status'] ?? [];
        if (!empty($sitemap)) {
            $this->log('');
            $this->log(WP_CLI::colorize('%YSitemap%n'));
            $this->log(
                sprintf(
                    /* translators: %1$s: module active status, %2$s: configured status */
                    __('Module active: %1$s | Configured: %2$s', 'seopulse'),
                    ($sitemap['module_active'] ?? false) ? __('yes', 'seopulse') : __('no', 'seopulse'),
                    ($sitemap['configured'] ?? false) ? __('yes', 'seopulse') : __('no', 'seopulse'),
                ),
            );
        }

        // ── Indexation ──
        $index = $data['indexation_status'] ?? [];
        if (!empty($index)) {
            $this->log('');
            $this->log(WP_CLI::colorize('%YIndexation%n'));
            $rows = [
                [
                    'Setting' => __('Author archives noindex', 'seopulse'),
                    'Value'   => ($index['author_noindex'] ?? false) ? __('yes', 'seopulse') : __('no', 'seopulse'),
                ],
                [
                    'Setting' => __('Date archives noindex', 'seopulse'),
                    'Value'   => ($index['date_noindex'] ?? false) ? __('yes', 'seopulse') : __('no', 'seopulse'),
                ],
                [
                    'Setting' => __('Search pages noindex', 'seopulse'),
                    'Value'   => ($index['search_noindex'] ?? false) ? __('yes', 'seopulse') : __('no', 'seopulse'),
                ],
            ];
            $this->format_items($rows, ['Setting', 'Value']);
        }

        // ── 404 Tracking ──
        $tracking = $data['404_tracking_status'] ?? [];
        if (!empty($tracking)) {
            $this->log('');
            $this->log(
                sprintf(
                    /* translators: %1$s: tracking status, %2$d: logged count */
                    __('404 Tracking: %1$s (%2$d logged)', 'seopulse'),
                    ($tracking['enabled'] ?? false) ? __('enabled', 'seopulse') : __('disabled', 'seopulse'),
                    $tracking['logged_count'] ?? 0,
                ),
            );
        }

        $this->log('');
        $this->success('Dashboard loaded.');
    }
}
