<?php

/**
 * Centralized structured logger for SEOPulse
 *
 * Writes JSON lines to a dedicated log file with rotation support.
 *
 * @package SEOPulse\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger class
 *
 * Available via the container: seopulse_get_service('Logger')
 */
class Logger
{
    /**
     * Service name for container resolution
     */
    public const NAME_SERVICE = 'Logger';

    /**
     * Log levels (RFC 5424 subset)
     */
    public const LEVEL_DEBUG   = 'debug';
    public const LEVEL_INFO    = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR   = 'error';

    /**
     * Maximum log file size before rotation (10 MB)
     *
     * @var int
     */
    private int $maxFileSize = 10 * 1024 * 1024;

    /**
     * Number of rotated files to keep
     *
     * @var int
     */
    private int $maxFiles = 5;

    /**
     * Resolved log directory path
     *
     * @var string|null
     */
    private ?string $logDir = null;

    /**
     * Resolved log file path
     *
     * @var string|null
     */
    private ?string $logFile = null;

    /**
     * Minimum level to write (allows filtering noisy levels)
     *
     * @var string
     */
    private string $minLevel = self::LEVEL_DEBUG;

    /**
     * Level priority map
     *
     * @var array<string, int>
     */
    private const LEVEL_PRIORITY = [
        self::LEVEL_DEBUG   => 0,
        self::LEVEL_INFO    => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR   => 3,
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        /**
         * Filters the minimum log level written to disk.
         *
         * @since 1.0.0
         * @param string $level One of 'debug', 'info', 'warning', 'error'.
         */
        $this->minLevel = apply_filters('seopulse_log_min_level', self::LEVEL_DEBUG);

        // Load configurable retention settings from options
        $settings = get_option('seopulse_log_settings', []);
        if (!empty($settings['max_file_size'])) {
            $this->maxFileSize = (int) $settings['max_file_size'];
        }
        if (!empty($settings['max_files'])) {
            $this->maxFiles = max(1, min(20, (int) $settings['max_files']));
        }
    }

    // ──────────────────────────────────────────────
    // PUBLIC API
    // ──────────────────────────────────────────────

    /**
     * Log a debug message
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log an informational message
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log a warning
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log an error
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    // ──────────────────────────────────────────────
    // LOG READING (used by viewer / API)
    // ──────────────────────────────────────────────

    /**
     * Read the last N lines from the log file
     *
     * @param int $lines Maximum number of lines to return
     * @param string|null $level Filter by level (null = all)
     * @return array<int, array{timestamp: string, level: string, message: string, context: array}>
     */
    public function readLastLines(int $lines = 500, ?string $level = null): array
    {
        $file = $this->getLogFile();

        if (!is_file($file)) {
            return [];
        }

        $allLines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($allLines === false) {
            return [];
        }

        $entries = [];
        foreach ($allLines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }

            if ($level !== null && ($entry['level'] ?? '') !== $level) {
                continue;
            }

            $entries[] = $entry;
        }

        // Return only the last $lines entries (most recent)
        return array_slice($entries, -$lines);
    }

    /**
     * Get the full log file contents as a string (for export)
     *
     * @return string
     */
    public function getContents(): string
    {
        $file = $this->getLogFile();

        if (!is_file($file)) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $contents = file_get_contents($file);

        return $contents !== false ? $contents : '';
    }

    /**
     * Clear the current log file
     *
     * @return bool
     */
    public function clear(): bool
    {
        $file = $this->getLogFile();

        if (!is_file($file)) {
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        return file_put_contents($file, '') !== false;
    }

    /**
     * Get the log file size in bytes
     *
     * @return int
     */
    public function getFileSize(): int
    {
        $file = $this->getLogFile();

        if (!is_file($file)) {
            return 0;
        }

        $size = filesize($file);

        return $size !== false ? $size : 0;
    }

    /**
     * Count entries by level (from the last N lines)
     *
     * @param int $lines Maximum lines to scan
     * @return array{error: int, warning: int, info: int, debug: int, total: int}
     */
    public function countByLevel(int $lines = 2000): array
    {
        $entries = $this->readLastLines($lines);
        $counts  = [
            'error'   => 0,
            'warning' => 0,
            'info'    => 0,
            'debug'   => 0,
            'total'   => 0,
        ];

        foreach ($entries as $entry) {
            $lvl = $entry['level'] ?? '';
            if (isset($counts[ $lvl ])) {
                ++$counts[ $lvl ];
            }
            ++$counts['total'];
        }

        return $counts;
    }

    /**
     * Get daily entry counts for the last N days (for sparkline/trend chart)
     *
     * @param int $days Number of days (default 7)
     * @return array<int, array{date: string, total: int, error: int, warning: int, info: int, debug: int}>
     */
    public function getDailyCounts(int $days = 7): array
    {
        $entries = $this->readLastLines(2000);

        // Build date-keyed map
        $map = [];
        foreach ($entries as $entry) {
            $ts   = $entry['timestamp'] ?? '';
            $date = substr($ts, 0, 10); // 'YYYY-MM-DD'
            if ($date === '' || strlen($date) !== 10) {
                continue;
            }

            if (!isset($map[ $date ])) {
                $map[ $date ] = [
                    'date'    => $date,
                    'total'   => 0,
                    'error'   => 0,
                    'warning' => 0,
                    'info'    => 0,
                    'debug'   => 0,
                ];
            }

            $lvl = $entry['level'] ?? '';
            if (isset($map[ $date ][ $lvl ])) {
                ++$map[ $date ][ $lvl ];
            }
            ++$map[ $date ]['total'];
        }

        // Build result for last N days
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = gmdate('Y-m-d', strtotime("-{$i} days"));
            $result[] = $map[ $date ] ?? [
                'date'    => $date,
                'total'   => 0,
                'error'   => 0,
                'warning' => 0,
                'info'    => 0,
                'debug'   => 0,
            ];
        }

        return $result;
    }

    /**
     * Get distinct source values from log context
     *
     * @return string[]
     */
    public function getDistinctSources(): array
    {
        $entries = $this->readLastLines(2000);
        $sources = [];

        foreach ($entries as $entry) {
            $source = $entry['context']['source'] ?? '';
            if ($source !== '' && !isset($sources[ $source ])) {
                $sources[ $source ] = true;
            }
        }

        return array_keys($sources);
    }

    /**
     * Delete entries matching given timestamps
     *
     * Rewrites the log file without the matching lines.
     *
     * @param string[] $timestamps Timestamps to delete (exact match)
     * @return int Number of entries deleted
     */
    public function deleteEntries(array $timestamps): int
    {
        $file = $this->getLogFile();

        if (!is_file($file)) {
            return 0;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return 0;
        }

        $tsMap   = array_flip($timestamps);
        $kept    = [];
        $deleted = 0;

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && isset($tsMap[ $entry['timestamp'] ?? '' ])) {
                ++$deleted;
                // Remove from map so each timestamp can match multiple entries
                continue;
            }
            $kept[] = $line;
        }

        if ($deleted > 0) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($file, implode("\n", $kept) . ($kept !== [] ? "\n" : ''), LOCK_EX);
        }

        return $deleted;
    }

    /**
     * Get current retention settings
     *
     * @return array{max_file_size: int, max_files: int}
     */
    public function getRetentionSettings(): array
    {
        return [
            'max_file_size' => $this->maxFileSize,
            'max_files'     => $this->maxFiles,
        ];
    }

    /**
     * Get the resolved log file path
     *
     * @return string
     */
    public function getLogFile(): string
    {
        if ($this->logFile === null) {
            $this->logFile = $this->getLogDir() . '/seopulse.log';
        }

        return $this->logFile;
    }

    // ──────────────────────────────────────────────
    // INTERNAL
    // ──────────────────────────────────────────────

    /**
     * Write a log entry
     *
     * @param string $level
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    private function log(string $level, string $message, array $context): void
    {
        // Skip if below minimum level
        if ((self::LEVEL_PRIORITY[ $level ] ?? 0) < (self::LEVEL_PRIORITY[ $this->minLevel ] ?? 0)) {
            return;
        }

        $dir = $this->getLogDir();
        if (!$this->ensureDirectory($dir)) {
            return;
        }

        $file = $this->getLogFile();

        // Rotate if needed
        $this->rotateIfNeeded($file);

        $entry = wp_json_encode(
            [
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'level'     => $level,
                'message'   => $message,
                'context'   => $context,
            ],
            JSON_UNESCAPED_SLASHES,
        );

        if ($entry === false) {
            return;
        }

        // Append with exclusive lock
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($file, $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Resolve the log directory path
     *
     * @return string
     */
    private function getLogDir(): string
    {
        if ($this->logDir === null) {
            $upload       = wp_upload_dir(null, false);
            $this->logDir = rtrim($upload['basedir'], '/\\') . '/seopulse-logs';
        }

        return $this->logDir;
    }

    /**
     * Ensure the log directory exists with security protections
     *
     * @param string $dir
     * @return bool
     */
    private function ensureDirectory(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        if (!wp_mkdir_p($dir)) {
            return false;
        }

        // Protect the directory from direct HTTP access
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($htaccess, "Deny from all\n");
        }

        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        return true;
    }

    /**
     * Rotate the log file if it exceeds the size threshold
     *
     * @param string $file
     * @return void
     */
    private function rotateIfNeeded(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $size = filesize($file);
        if ($size === false || $size < $this->maxFileSize) {
            return;
        }

        // Shift existing rotated files (.5 → delete, .4 → .5, …, .1 → .2)
        for ($i = $this->maxFiles; $i >= 1; $i--) {
            $rotated = $file . '.' . $i;
            if ($i === $this->maxFiles) {
                if (is_file($rotated)) {
                    wp_delete_file($rotated);
                }
            } else {
                $next = $file . '.' . ($i + 1);
                if (is_file($rotated)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Low-level log rotation; WP_Filesystem not available here.
                    @rename($rotated, $next);
                }
            }
        }

        // Current log → .1
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Low-level log rotation; WP_Filesystem not available here.
        @rename($file, $file . '.1');
    }
}
