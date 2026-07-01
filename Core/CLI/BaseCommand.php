<?php

/**
 * Base WP-CLI command for SEOPulse
 *
 * Provides shared helpers for progress bars, tables,
 * confirmations, and error formatting.
 *
 * @package SEOPulse\Core\CLI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;
use WP_CLI\Utils;

/**
 * BaseCommand class
 */
abstract class BaseCommand
{
    /**
     * Creates a WP-CLI progress bar
     *
     * @param string $message Label
     * @param int $count Total items
     * @return \cli\progress\Bar|WP_CLI\NoOp
     */
    protected function make_progress(string $message, int $count)
    {
        return Utils\make_progress_bar($message, $count);
    }

    /**
     * Outputs data as a WP-CLI table
     *
     * @param array<array<string, mixed>> $items Rows
     * @param array<string> $fields Column names
     * @param array<string, mixed> $assoc Assoc args (may contain --format)
     * @return void
     */
    protected function format_items(array $items, array $fields, array $assoc = []): void
    {
        $format = $assoc['format'] ?? 'table';
        Utils\format_items($format, $items, $fields);
    }

    /**
     * Asks the user for confirmation before a destructive operation
     *
     * @param string $message Prompt
     * @return void Exits if the user answers no
     */
    protected function confirm(string $message): void
    {
        WP_CLI::confirm($message);
    }

    /**
     * Outputs an error and exits
     *
     * @param string $message Error message
     * @return never
     */
    protected function error(string $message): void
    {
        WP_CLI::error($message);
    }

    /**
     * Outputs a success message
     *
     * @param string $message Success message
     * @return void
     */
    protected function success(string $message): void
    {
        WP_CLI::success($message);
    }

    /**
     * Outputs a warning message
     *
     * @param string $message Warning message
     * @return void
     */
    protected function warning(string $message): void
    {
        WP_CLI::warning($message);
    }

    /**
     * Outputs a standard log line
     *
     * @param string $message Log message
     * @return void
     */
    protected function log(string $message): void
    {
        WP_CLI::log($message);
    }

    /**
     * Returns the output format from assoc args, defaulting to 'table'
     *
     * @param array<string, mixed> $assoc Assoc args
     * @return string
     */
    protected function get_format(array $assoc): string
    {
        return $assoc['format'] ?? 'table';
    }

    /**
     * Resolves a list of post IDs from common CLI arguments
     *
     * Supports: single post_id positional arg, --post_type, --post_status filters.
     *
     * @param array<string> $args Positional args
     * @param array<string, mixed> $assoc Assoc args
     * @return array<int> Post IDs
     */
    protected function resolve_post_ids(array $args, array $assoc): array
    {
        // Single post ID provided as positional arg
        if (!empty($args[0])) {
            $post_id = (int) $args[0];
            $post    = get_post($post_id);

            if (!$post) {
                $this->error("Post #{$post_id} not found.");
            }

            return [$post_id];
        }

        // Bulk query via filters
        $query_args = [
            'post_type'      => $assoc['post_type'] ?? 'post',
            'post_status'    => $assoc['post_status'] ?? 'publish',
            'posts_per_page' => (int) ($assoc['limit'] ?? -1),
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];

        $ids = get_posts($query_args);

        if (empty($ids)) {
            $this->error('No posts found matching the given criteria.');
        }

        return array_map('intval', $ids);
    }
}
