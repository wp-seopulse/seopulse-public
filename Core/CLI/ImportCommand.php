<?php

/**
 * WP-CLI command: wp seopulse import
 *
 * Imports previously exported SEOPulse settings from a JSON file.
 *
 * @package SEOPulse\Core\CLI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Traits\ExportableConfigTrait;

/**
 * Import SEOPulse settings from a file.
 */
class ImportCommand extends BaseCommand
{
    use ExportableConfigTrait;

    /**
     * Imports SEOPulse configuration from a JSON file.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the JSON export file.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     # Import from a file
     *     wp seopulse import seopulse-export-2025-01-15.json
     *
     *     # Import without confirmation
     *     wp seopulse import /tmp/backup.json --yes
     *
     * @param array<string> $args Positional arguments
     * @param array<string, mixed> $assoc Associative arguments
     * @return void
     */
    public function __invoke(array $args, array $assoc): void
    {
        $file = $args[0] ?? '';

        if (empty($file)) {
            $this->error('Please provide the path to a JSON export file.');
        }

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
        }

        if (!is_readable($file)) {
            $this->error("File is not readable: {$file}");
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents($file);

        if ($raw === false) {
            $this->error("Failed to read file: {$file}");
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            $this->error('Invalid JSON in the import file.');
        }

        // Validate structure
        foreach (['schema_version', 'plugin_version', 'modules'] as $field) {
            if (!isset($data[ $field ])) {
                $this->error("Missing required field: {$field}");
            }
        }

        if (!is_array($data['modules'])) {
            $this->error('The "modules" field must be an object.');
        }

        // Check schema compatibility
        if (version_compare($data['schema_version'], '1.0.0', '>')) {
            $this->error(
                sprintf(
                    'Schema version %s is newer than supported (1.0.0). Update the plugin first.',
                    $data['schema_version'],
                ),
            );
        }

        // Show what will be imported
        $this->log(sprintf('Source: %s', $data['site_url'] ?? 'unknown'));
        $this->log(sprintf('Plugin version: %s', $data['plugin_version'] ?? 'unknown'));
        $this->log(sprintf('Exported: %s', $data['exported_at'] ?? 'unknown'));
        $this->log(sprintf('Modules: %s', implode(', ', array_keys($data['modules']))));

        if (!isset($assoc['yes'])) {
            $this->confirm('This will overwrite current settings. Continue?');
        }

        // Create backup before import
        $this->createConfigBackup('pre_cli_import');
        $this->log('Backup created.');

        // Apply import
        $exportable = self::getExportableOptions();
        $updated    = [];
        $skipped    = [];

        foreach ($data['modules'] as $key => $value) {
            if (!isset($exportable[ $key ])) {
                $skipped[] = $key;
                continue;
            }

            update_option($exportable[ $key ], $value);
            $updated[] = $key;
        }

        if (!empty($skipped)) {
            $this->warning(sprintf('Skipped unknown modules: %s', implode(', ', $skipped)));
        }

        $this->success(
            sprintf(
                'Imported %d module(s): %s',
                count($updated),
                implode(', ', $updated) ?: 'none',
            ),
        );
    }
}
