<?php

/**
 * Redirections manager
 *
 * @package SEOPulse\Modules\Redirections
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Redirections;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Traits\CurrentUrlTrait;

/**
 * RedirectionsManager class
 */
class RedirectionsManager
{
    use CurrentUrlTrait;

    /**
     * Option name
     *
     * @var string
     */
    private string $option_name = 'seopulse_redirections';

    /**
     * Applies redirections
     *
     * @return void
     */
    public function apply_redirects(): void
    {
        // Fast path: query SQL table directly when it exists (shared with Pro).
        if ($this->has_redirect_table()) {
            $this->apply_redirects_from_sql();

            return;
        }

        // Fallback: wp_options path (no Pro / SQL table not yet created).
        $redirects = $this->get_all_redirects();

        if (empty($redirects)) {
            return;
        }

        // Extract path only (strip query string) — consistent with the SQL path.
        $current_path = '/' . ltrim(
            (string) strtok(
                sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/')),
                '?',
            ),
            '/',
        );

        foreach ($redirects as $redirect) {
            if (!$this->is_redirect_active($redirect)) {
                continue;
            }

            if ($this->url_matches($current_path, $redirect['source'])) {
                $this->execute_redirect(
                    $redirect['destination'],
                    (int) ($redirect['type'] ?? 301),
                );
                exit;
            }
        }
    }

    /**
     * Applies redirections using the shared SQL table.
     *
     * Full matching logic with 5 match types:
     *  1. Exact-match rules (indexed lookup — fastest)
     *  2. Pattern-based rules (contains / starts_with / ends_with / regex)
     *
     * Supports maintenance codes (410/451), scheduled activation/deactivation,
     * query-string passthrough, and redirect debugger (admin-only).
     *
     * @return void
     */
    private function apply_redirects_from_sql(): void
    {
        if (is_admin()) {
            return;
        }

        global $wpdb;

        if (!isset($wpdb->seopulse_redirects)) {
            $wpdb->seopulse_redirects = $wpdb->prefix . 'seopulse_redirects';
        }

        $request_uri      = $this->getRequestUri();
        $request_uri_full = $this->getRequestUriFull();

        // ── 1. Exact match (case-sensitive, indexed) ──────────────────
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $redirect = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->seopulse_redirects}`
                 WHERE source_url = %s
                   AND match_type = 'exact'
                   AND status     = 'active'
                 LIMIT 1",
                $request_uri,
            ),
        );

        // Exact match with ignore_case fallback (LOWER comparison).
        if (!$redirect) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $redirect = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$wpdb->seopulse_redirects}`
                     WHERE LOWER(source_url) = LOWER(%s)
                       AND match_type  = 'exact'
                       AND ignore_case = 1
                       AND status      = 'active'
                     LIMIT 1",
                    $request_uri,
                ),
            );
        }

        if ($redirect) {
            $this->resolveRedirect($redirect, $request_uri_full);

            return;
        }

        // ── 2. Pattern-based rules (contains / starts_with / ends_with / regex)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $pattern_rules = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->seopulse_redirects}`
                 WHERE match_type IN ('contains','starts_with','ends_with','regex')
                   AND status = %s
                 ORDER BY match_type ASC, id ASC",
                'active',
            ),
        );

        foreach ($pattern_rules as $rule) {
            if ($this->matchesPattern($rule, $request_uri)) {
                $target = $rule->target_url;

                // Regex back-reference replacement.
                if ($rule->match_type === 'regex') {
                    $pattern = '@' . str_replace('@', '\\@', $rule->source_url) . '@';
                    $flags   = !empty($rule->ignore_case) ? 'i' : '';
                    if (preg_match($pattern . $flags, $request_uri, $matches)) {
                        foreach ($matches as $i => $match) {
                            $target = str_replace('$' . $i, $match, $target);
                        }
                    }
                }

                $this->resolveRedirect($rule, $request_uri_full, $target);

                return;
            }
        }
    }

    /**
     * Checks if $rule matches the current $uri based on its match_type.
     */
    private function matchesPattern(object $rule, string $uri): bool
    {
        $source = $rule->source_url;
        $ci     = !empty($rule->ignore_case);

        return match ($rule->match_type) {
            'contains'    => $ci
                ? str_contains(strtolower($uri), strtolower($source))
                : str_contains($uri, $source),

            'starts_with' => $ci
                ? str_starts_with(strtolower($uri), strtolower($source))
                : str_starts_with($uri, $source),

            'ends_with'   => $ci
                ? str_ends_with(strtolower($uri), strtolower($source))
                : str_ends_with($uri, $source),

            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            'regex'       => (bool) @preg_match(
                '@' . str_replace('@', '\\@', $source) . '@' . ($ci ? 'i' : ''),
                $uri,
            ),

            default       => false,
        };
    }

    /**
     * Determines whether to perform an HTTP redirect or serve a maintenance code.
     *
     * @param object $redirect Database row.
     * @param string $request_uri_full Full URI including query string.
     * @param string|null $target_override Override target (regex back-refs).
     */
    private function resolveRedirect(object $redirect, string $request_uri_full, ?string $target_override = null): void
    {
        $mc = (int) ($redirect->maintenance_code ?? 0);
        if (in_array($mc, RedirectionsModule::MAINTENANCE_CODES, true)) {
            $this->serveMaintenance($redirect, $mc);

            return;
        }

        $target = $target_override ?? $redirect->target_url;

        // Append query string when pass_query_string is enabled.
        if (!empty($redirect->pass_query_string)) {
            $qs = wp_parse_url($request_uri_full, PHP_URL_QUERY);
            if ($qs) {
                $sep    = str_contains($target, '?') ? '&' : '?';
                $target = $target . $sep . $qs;
            }
        }

        // Debug interstitial (admin-only, when enabled).
        if ($this->isDebugEnabled() && current_user_can('manage_options')) {
            $this->showDebugInterstitial($redirect, $target);

            return;
        }

        $this->executeAdvancedRedirect($redirect, $target);
    }

    /**
     * Serves a maintenance HTTP status (410 Content Deleted, 451 Unavailable for Legal Reasons).
     */
    private function serveMaintenance(object $redirect, int $code): void
    {
        $this->incrementSqlHits($redirect);

        status_header($code);
        nocache_headers();

        $template = locate_template("seopulse-{$code}.php");
        if ($template) {
            include $template;
        } else {
            $messages = [
                410 => __('This content has been permanently deleted.', 'seopulse'),
                451 => __('This content is unavailable for legal reasons.', 'seopulse'),
            ];

            wp_die(
                esc_html($messages[ $code ] ?? __('Content unavailable.', 'seopulse')),
                esc_html((string) $code),
                ['response' => (int) $code],
            );
        }

        exit;
    }

    /**
     * Shows a debug interstitial before performing the redirect.
     * Only visible to administrators, auto-redirects after 5 seconds.
     */
    private function showDebugInterstitial(object $redirect, string $target): void
    {
        $this->incrementSqlHits($redirect);

        $code        = $this->resolveStatusCode($redirect);
        $edit_url    = admin_url('admin.php?page=seopulse-redirections');
        $safe_target = esc_url($target);

        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-SEOPulse-Debug: redirect');

        wp_register_style(
            'seopulse-redirect-debug',
            SEOPULSE_PLUGIN_URL . 'Modules/Redirections/assets/css/redirect-debug.css',
            [],
            SEOPULSE_VERSION,
        );
        wp_enqueue_style('seopulse-redirect-debug');

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<meta http-equiv="refresh" content="5;url=' . esc_attr($safe_target) . '">';
        echo '<title>SEOPulse Redirect Debug</title>';
        wp_print_styles('seopulse-redirect-debug');
        echo '</head><body>';
        echo '<h1>&#128269; SEOPulse — Redirect Debugger</h1>';
        echo '<div class="card">';
        echo '<div class="row"><span class="label">' . esc_html__('Source URL', 'seopulse') . '</span><span class="value">' . esc_html($redirect->source_url) . '</span></div>';
        echo '<div class="row"><span class="label">' . esc_html__('Target URL', 'seopulse') . '</span><span class="value"><a href="' . esc_url($safe_target) . '">' . esc_html($target) . '</a></span></div>';
        echo '<div class="row"><span class="label">' . esc_html__('HTTP Code', 'seopulse') . '</span><span class="value"><span class="badge badge-blue">' . (int) $code . '</span></span></div>';
        echo '<div class="row"><span class="label">' . esc_html__('Match Type', 'seopulse') . '</span><span class="value">' . esc_html($redirect->match_type ?? 'exact') . '</span></div>';
        echo '<div class="row"><span class="label">' . esc_html__('Ignore Case', 'seopulse') . '</span><span class="value">' . (($redirect->ignore_case ?? 1) ? esc_html__('Yes', 'seopulse') : esc_html__('No', 'seopulse')) . '</span></div>';
        echo '<div class="row"><span class="label">' . esc_html__('Group', 'seopulse') . '</span><span class="value">' . esc_html($redirect->group_name ?: '—') . '</span></div>';
        echo '<div class="row"><span class="label">' . esc_html__('Hits', 'seopulse') . '</span><span class="value">' . esc_html(number_format_i18n((int) $redirect->hits)) . '</span></div>';
        echo '<div class="row"><span class="label">' . esc_html__('Rule ID', 'seopulse') . '</span><span class="value">#' . (int) $redirect->id . '</span></div>';
        echo '</div>';
        echo '<p style="text-align:center"><a href="' . esc_url($edit_url) . '">&#9881; ' . esc_html__('Manage Redirects', 'seopulse') . '</a></p>';
        echo '<p class="footer">' . esc_html__('Redirecting in 5 seconds… This page is only visible to administrators.', 'seopulse') . '</p>';
        echo '</body></html>';
        exit;
    }

    /**
     * Checks if the redirect debugger is enabled.
     */
    private function isDebugEnabled(): bool
    {
        return (bool) get_option('seopulse_redirect_debug', false);
    }

    /**
     * Executes a redirect (SQL-based) and updates hit counter.
     */
    private function executeAdvancedRedirect(object $redirect, string $target_url): void
    {
        $this->incrementSqlHits($redirect);

        $status_code = $this->resolveStatusCode($redirect);

        $target = esc_url_raw($target_url);
        $this->allow_redirect_host($target);
        wp_safe_redirect($target, $status_code, 'SEOPulse');
        exit;
    }

    /**
     * Resolves the HTTP status code from a redirect rule.
     */
    private function resolveStatusCode(object $redirect): int
    {
        $code = (int) $redirect->redirect_type;
        if (!in_array($code, RedirectionsModule::REDIRECT_CODES, true)) {
            $code = 301;
        }

        return $code;
    }

    /**
     * Increments the SQL hit counter for the given redirect object.
     */
    private function incrementSqlHits(object $redirect): void
    {
        global $wpdb;

        if (!isset($wpdb->seopulse_redirects)) {
            $wpdb->seopulse_redirects = $wpdb->prefix . 'seopulse_redirects';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->seopulse_redirects} SET hits = hits + 1, last_accessed = %s WHERE id = %d",
                current_time('mysql'),
                $redirect->id,
            ),
        );
    }

    /**
     * Returns the current request URI without query string, normalized.
     */
    private function getRequestUri(): string
    {
        $uri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
            : '/';

        $uri = strtok($uri, '?');

        return '/' . ltrim((string) $uri, '/');
    }

    /**
     * Returns the full request URI including query string.
     */
    private function getRequestUriFull(): string
    {
        return isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
            : '/';
    }

    /**
     * Retrieves all redirections
     *
     * @return array
     */
    public function get_all_redirects(): array
    {
        $redirects = get_option($this->option_name, []);

        return is_array($redirects) ? $redirects : [];
    }

    /**
     * Adds a redirect
     *
     * @param array $redirect Redirect data
     * @return bool
     */
    public function add_redirect(array $redirect): bool
    {
        $redirects = $this->get_all_redirects();

        $redirect['id']         = $this->generate_id();
        $redirect['created_at'] = current_time('mysql');
        $redirect['hits']       = 0;

        $redirects[] = $redirect;

        $result = update_option($this->option_name, $redirects);

        // Mirror to SQL when the shared table is available.
        if ($result) {
            $this->sql_insert($redirect);
        }

        return $result;
    }

    /**
     * Updates a redirect
     *
     * @param string $id Redirect ID
     * @param array $redirect New data
     * @return bool
     */
    public function update_redirect(string $id, array $redirect): bool
    {
        $redirects = $this->get_all_redirects();

        foreach ($redirects as $index => $existing) {
            if ($existing['id'] === $id) {
                $old_source                        = $existing['source'];
                $redirects[ $index ]               = array_merge($existing, $redirect);
                $redirects[ $index ]['updated_at'] = current_time('mysql');

                $result = update_option($this->option_name, $redirects);

                if ($result) {
                    $this->sql_update($old_source, $redirects[ $index ]);
                }

                return $result;
            }
        }

        return false;
    }

    /**
     * Deletes a redirect
     *
     * @param string $id Redirect ID
     * @return bool
     */
    public function delete_redirect(string $id): bool
    {
        $redirects  = $this->get_all_redirects();
        $source_url = null;

        foreach ($redirects as $r) {
            if ($r['id'] === $id) {
                $source_url = $r['source'];
                break;
            }
        }

        $redirects = array_filter(
            $redirects,
            function ($redirect) use ($id) {
                return $redirect['id'] !== $id;
            },
        );

        $result = update_option($this->option_name, array_values($redirects));

        if ($result && $source_url !== null) {
            $this->sql_delete_by_source($source_url);
        }

        return $result;
    }

    /**
     * Deletes all redirections
     *
     * @return bool
     */
    public function delete_all_redirects(): bool
    {
        $result = delete_option($this->option_name);

        if ($result) {
            $this->sql_delete_all();
        }

        return $result;
    }

    /**
     * Checks if a URL matches the pattern
     *
     * @param string $url Current URL
     * @param string $pattern Source pattern
     * @return bool
     */
    private function url_matches(string $url, string $pattern): bool
    {
        // Normalize both to path-only for consistent comparison.
        $url     = untrailingslashit($this->to_path($url));
        $pattern = untrailingslashit($this->to_path($pattern));

        // Wildcard support
        if (strpos($pattern, '*') !== false) {
            $regex = str_replace('*', '.*', preg_quote($pattern, '/'));

            return (bool) preg_match('/^' . $regex . '$/i', $url);
        }

        // Exact comparison (case-insensitive)
        return strcasecmp($url, $pattern) === 0;
    }

    /**
     * Checks if a redirect is active
     *
     * @param array $redirect Redirect data
     * @return bool
     */
    private function is_redirect_active(array $redirect): bool
    {
        return ($redirect['status'] ?? 'active') === 'active';
    }

    /**
     * Extracts the path from a URL or ensures a leading slash for a relative path.
     *
     * @param string $value URL or path
     * @return string Path only (e.g. /old-page)
     */
    private function to_path(string $value): string
    {
        if (preg_match('#^https?://#i', $value)) {
            $path = wp_parse_url($value, PHP_URL_PATH);

            return $path ? '/' . ltrim($path, '/') : '/';
        }

        return '/' . ltrim($value, '/');
    }

    /**
     * Executes the redirect
     *
     * @param string $destination Destination URL
     * @param int $type Redirect type (301 or 302)
     * @return void
     */
    private function execute_redirect(string $destination, int $type = 301): void
    {
        // Increment the hits counter
        $this->increment_hits($destination);

        // Perform the redirect
        $target = esc_url_raw($destination);
        $this->allow_redirect_host($target);
        wp_safe_redirect($target, $type, 'SEOPulse');
        exit;
    }

    /**
     * Temporarily allows the target host in wp_safe_redirect.
     *
     * @param string $url Target redirect URL.
     */
    private function allow_redirect_host(string $url): void
    {
        $host = wp_parse_url($url, PHP_URL_HOST);

        if ($host) {
            add_filter(
                'allowed_redirect_hosts',
                static function (array $hosts) use ($host): array {
                    $hosts[] = $host;

                    return $hosts;
                },
            );
        }
    }

    /**
     * Increments the hits counter
     *
     * @param string $destination Destination URL
     * @return void
     */
    private function increment_hits(string $destination): void
    {
        $redirects = $this->get_all_redirects();

        foreach ($redirects as $index => $redirect) {
            if ($redirect['destination'] === $destination) {
                $redirects[ $index ]['hits']     = ($redirect['hits'] ?? 0) + 1;
                $redirects[ $index ]['last_hit'] = current_time('mysql');
                update_option($this->option_name, $redirects);
                break;
            }
        }
    }

    /**
     * Generates a unique ID
     *
     * @return string
     */
    private function generate_id(): string
    {
        return 'redirect_' . uniqid() . '_' . time();
    }

    // =========================================================================
    // SQL helpers (shared table)
    // =========================================================================

    /**
     * Returns true when the shared SQL table exists (result is cached per request).
     *
     * @return bool
     */
    private function has_redirect_table(): bool
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $cached = (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'seopulse_redirects'),
        );

        return $cached;
    }

    /**
     * Inserts a redirect into the SQL table (if it exists).
     *
     * @param array $redirect Array in Free's format.
     * @return void
     */
    private function sql_insert(array $redirect): void
    {
        if (!$this->has_redirect_table()) {
            return;
        }

        global $wpdb;

        $source      = $this->to_path(sanitize_text_field($redirect['source'] ?? ''));
        $target      = esc_url_raw($redirect['destination'] ?? '');
        $is_wildcard = strpos($source, '*') !== false;

        if ($source === '/' || $target === '') {
            return;
        }

        // Convert Free wildcard (*) → regex compatible with Pro's @ delimiter.
        $sql_source = $is_wildcard
            ? str_replace('\*', '.*', preg_quote($source, '@'))
            : $source;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'seopulse_redirects',
            [
                'source_url'    => $sql_source,
                'target_url'    => $target,
                'redirect_type' => (int) ($redirect['type'] ?? 301),
                'regex'         => $is_wildcard ? 1 : 0,
                'group_name'    => 'free_import',
                'hits'          => (int) ($redirect['hits'] ?? 0),
                'last_accessed' => !empty($redirect['last_hit'])
                    ? sanitize_text_field($redirect['last_hit'])
                    : null,
                'status'        => sanitize_text_field($redirect['status'] ?? 'active'),
                'created_at'    => !empty($redirect['created_at'])
                    ? sanitize_text_field($redirect['created_at'])
                    : current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s'],
        );
    }

    /**
     * Updates the SQL row whose source matches $old_source.
     *
     * @param string $old_source The source URL before any modification.
     * @param array $redirect Updated redirect data in Free's format.
     * @return void
     */
    private function sql_update(string $old_source, array $redirect): void
    {
        if (!$this->has_redirect_table()) {
            return;
        }

        global $wpdb;

        $table       = $wpdb->prefix . 'seopulse_redirects';
        $new_source  = $this->to_path(sanitize_text_field($redirect['source'] ?? $old_source));
        $is_wildcard = strpos($new_source, '*') !== false;
        $sql_source  = $is_wildcard
            ? str_replace('\*', '.*', preg_quote($new_source, '@'))
            : $new_source;

        // Find existing row by old source (try both raw and regex-converted variants).
        $old_path       = $this->to_path($old_source);
        $old_sql_source = strpos($old_path, '*') !== false
            ? str_replace('\*', '.*', preg_quote($old_path, '@'))
            : $old_path;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'source_url'    => $sql_source,
                'target_url'    => esc_url_raw($redirect['destination'] ?? ''),
                'redirect_type' => (int) ($redirect['type'] ?? 301),
                'regex'         => $is_wildcard ? 1 : 0,
                'status'        => sanitize_text_field($redirect['status'] ?? 'active'),
            ],
            [
                'source_url' => $old_sql_source,
                'group_name' => 'free_import',
            ],
            ['%s', '%s', '%d', '%d', '%s'],
            ['%s', '%s'],
        );
    }

    /**
     * Deletes the SQL row matching $source_url.
     *
     * @param string $source_url Raw source URL as stored in Free's option.
     * @return void
     */
    private function sql_delete_by_source(string $source_url): void
    {
        if (!$this->has_redirect_table()) {
            return;
        }

        global $wpdb;

        $source_path = $this->to_path($source_url);
        $is_wildcard = strpos($source_path, '*') !== false;
        $sql_source  = $is_wildcard
            ? str_replace('\*', '.*', preg_quote($source_path, '@'))
            : $source_path;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete(
            $wpdb->prefix . 'seopulse_redirects',
            [
                'source_url' => $sql_source,
                'group_name' => 'free_import',
            ],
            ['%s', '%s'],
        );
    }

    /**
     * Removes all free_import rows from the SQL table.
     *
     * @return void
     */
    private function sql_delete_all(): void
    {
        if (!$this->has_redirect_table()) {
            return;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete(
            $wpdb->prefix . 'seopulse_redirects',
            ['group_name' => 'free_import'],
            ['%s'],
        );
    }

    /**
     * Exports redirections as CSV
     *
     * @return string
     */
    public function export_csv(): string
    {
        $redirects = $this->get_all_redirects();

        $csv = "Source,Destination,Type,Status,Hits,Created\n";

        foreach ($redirects as $redirect) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%d","%s"' . "\n",
                $redirect['source'] ?? '',
                $redirect['destination'] ?? '',
                $redirect['type'] ?? '301',
                $redirect['status'] ?? 'active',
                $redirect['hits'] ?? 0,
                $redirect['created_at'] ?? '',
            );
        }

        return $csv;
    }

    /**
     * Imports redirections from CSV
     *
     * @param string $csv CSV content
     * @return int Number of imported redirections
     */
    public function import_csv(string $csv): int
    {
        $lines = explode("\n", $csv);
        $count = 0;

        // Skip the first line (header)
        array_shift($lines);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $data = str_getcsv($line);

            if (count($data) >= 2) {
                $this->add_redirect(
                    [
                        'source'      => $data[0],
                        'destination' => $data[1],
                        'type'        => $data[2] ?? '301',
                        'status'      => $data[3] ?? 'active',
                    ],
                );
                ++$count;
            }
        }

        return $count;
    }
}
