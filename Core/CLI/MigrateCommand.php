<?php

/**
 * WP-CLI command: wp seopulse migrate
 *
 * Triggers data migration from Yoast, Rank Math or SEOPress.
 *
 * @package SEOPulse\Core\CLI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Services\RankMathMigrator;
use SEOPulse\Services\SeoPressMigrator;
use SEOPulse\Services\YoastMigrator;
use WP_CLI;

/**
 * Migrate SEO data from other plugins.
 */
class MigrateCommand extends BaseCommand
{
    /**
     * Supported source plugins
     *
     * @var array<string, class-string>
     */
    private const SOURCES = [
        'yoast'    => YoastMigrator::class,
        'rankmath' => RankMathMigrator::class,
        'seopress' => SeoPressMigrator::class,
    ];

    /**
     * Human-readable labels
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'yoast'    => 'Yoast SEO',
        'rankmath' => 'Rank Math SEO',
        'seopress' => 'SEOPress',
    ];

    /**
     * Migrates SEO data from another plugin.
     *
     * ## OPTIONS
     *
     * --from=<source>
     * : Source plugin. Options: yoast, rankmath, seopress
     *
     * [--overwrite]
     * : Overwrite existing SEOPulse data if it exists.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * [--format=<format>]
     * : Output format. Options: table, json. Default: table
     *
     * ## EXAMPLES
     *
     *     # Migrate from Yoast
     *     wp seopulse migrate --from=yoast
     *
     *     # Migrate from Rank Math, overwriting existing data
     *     wp seopulse migrate --from=rankmath --overwrite --yes
     *
     * @param array<string> $args Positional arguments
     * @param array<string, mixed> $assoc Associative arguments
     * @return void
     */
    public function __invoke(array $args, array $assoc): void
    {
        $source = $assoc['from'] ?? '';

        if (!isset(self::SOURCES[ $source ])) {
            $this->error(
                sprintf(
                    'Unknown source "%s". Supported: %s',
                    $source,
                    implode(', ', array_keys(self::SOURCES)),
                ),
            );
        }

        $label    = self::LABELS[ $source ];
        $class    = self::SOURCES[ $source ];
        $migrator = new $class();

        // Detect source data
        $detection = $migrator->detect();

        if (!$detection['has_data']) {
            $this->error("No {$label} data found in the database.");
        }

        $this->log(sprintf('Source: %s (version: %s)', $label, $detection['version'] ?: 'unknown'));
        $this->log(sprintf(
            /* translators: %s: active status (yes/no) */
            __('Active: %s', 'seopulse'),
            $detection['active'] ? __('yes', 'seopulse') : __('no', 'seopulse'),
        ));

        // Scan before import
        $scan = $migrator->scan();
        $this->log(sprintf('Posts with meta: %d | Meta entries: %d', $scan['total_posts'], $scan['post_meta']));

        if (!empty($scan['modules'])) {
            $this->log('Modules found:');
            foreach ($scan['modules'] as $mod => $count) {
                $this->log(sprintf('  - %s: %d entries', $mod, $count));
            }
        }

        // Confirm
        if (!isset($assoc['yes'])) {
            $this->confirm("Proceed with migration from {$label}?");
        }

        $overwrite = isset($assoc['overwrite']);

        // Run the import
        $this->log('Starting migration…');
        $result = $migrator->import($overwrite);

        $format = $this->get_format($assoc);

        if ($format === 'json') {
            WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        // Display results
        $this->log('');
        $this->log(WP_CLI::colorize('%GMigration Results%n'));

        $rows = [
            [
                'Metric' => 'Options imported',
                'Value'  => implode(', ', $result['options_imported'] ?: ['-']),
            ],
            [
                'Metric' => 'Posts processed',
                'Value'  => (string) $result['posts_processed'],
            ],
            [
                'Metric' => 'Meta imported',
                'Value'  => (string) $result['post_meta_imported'],
            ],
        ];
        $this->format_items($rows, ['Metric', 'Value']);

        if (!empty($result['warnings'])) {
            $this->log('');
            foreach ($result['warnings'] as $w) {
                $this->warning($w);
            }
        }

        if (!empty($result['errors'])) {
            $this->log('');
            foreach ($result['errors'] as $e) {
                WP_CLI::error_multi_line([$e]);
            }
        }

        $this->success(sprintf('Migration from %s completed.', $label));
    }
}
