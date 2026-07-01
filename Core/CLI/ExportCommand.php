<?php

/**
 * WP-CLI command: wp seopulse export
 *
 * Exports SEOPulse settings as JSON or CSV.
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
use WP_CLI;

/**
 * Export SEOPulse settings and SEO data.
 */
class ExportCommand extends BaseCommand
{
    use ExportableConfigTrait;

    /**
     * Exports SEOPulse configuration to a file.
     *
     * ## OPTIONS
     *
     * [--file=<path>]
     * : Output file path. Defaults to seopulse-export-{date}.json
     *
     * [--format=<format>]
     * : Output format. Options: json, csv. Default: json
     *
     * [--stdout]
     * : Print to stdout instead of writing a file.
     *
     * ## EXAMPLES
     *
     *     # Export to default file
     *     wp seopulse export
     *
     *     # Export to specific file
     *     wp seopulse export --file=/tmp/seo-backup.json
     *
     *     # Export to stdout for piping
     *     wp seopulse export --stdout
     *
     *     # Export as CSV (flat key-value pairs)
     *     wp seopulse export --format=csv --file=settings.csv
     *
     * @param array<string> $args Positional arguments
     * @param array<string, mixed> $assoc Associative arguments
     * @return void
     */
    public function __invoke(array $args, array $assoc): void
    {
        $format  = $assoc['format'] ?? 'json';
        $payload = $this->buildExportPayload();

        if ($format === 'csv') {
            $content = $this->payload_to_csv($payload);
        } else {
            $content = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        // Stdout mode
        if (isset($assoc['stdout'])) {
            WP_CLI::line($content);

            return;
        }

        // File mode
        $ext  = $format === 'csv' ? 'csv' : 'json';
        $file = $assoc['file'] ?? sprintf('seopulse-export-%s.%s', gmdate('Y-m-d-His'), $ext);

        // Resolve relative paths against CWD
        if (!$this->is_absolute_path($file)) {
            $cwd = getcwd();
            if ($cwd !== false) {
                $file = $cwd . DIRECTORY_SEPARATOR . $file;
            }
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            $this->error("Directory does not exist: {$dir}");
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $bytes = file_put_contents($file, $content);

        if ($bytes === false) {
            $this->error("Failed to write to {$file}");
        }

        $this->success(sprintf('Exported to %s (%s bytes).', $file, number_format($bytes)));
    }

    /**
     * Converts the export payload to CSV (flat key=value)
     *
     * @param array<string, mixed> $payload Export payload
     * @return string CSV content
     */
    private function payload_to_csv(array $payload): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing to php://temp stream, not filesystem.
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Module', 'Key', 'Value']);

        foreach ($payload['modules'] as $module => $settings) {
            if (!is_array($settings)) {
                fputcsv($handle, [$module, '', (string) $settings]);
                continue;
            }
            foreach ($settings as $key => $value) {
                fputcsv($handle, [$module, $key, is_array($value) ? wp_json_encode($value) : (string) $value]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://temp stream, not filesystem.
        fclose($handle);

        return $csv;
    }

    /**
     * Checks if a path is absolute
     *
     * @param string $path File path
     * @return bool
     */
    private function is_absolute_path(string $path): bool
    {
        // Unix absolute or Windows absolute (C:\... or \\...)
        return str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1;
    }
}
