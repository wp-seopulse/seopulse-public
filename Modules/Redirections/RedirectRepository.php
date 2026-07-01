<?php

/**
 * Redirect Repository — Full-featured database access layer
 *
 * Supports match types (exact, contains, starts_with, ends_with, regex),
 * maintenance codes (410/451), scheduled activation/deactivation,
 * groups, categories, query-string passthrough, descriptions,
 * chain/loop detection, CSV export/import, and .htaccess/.nginx generation.
 *
 * Migrated from SEOPulse Pro RedirectManager module.
 *
 * @package SEOPulse\Modules\Redirections
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Redirections;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- Dynamic placeholder counts via array_fill() and spread params; values always go through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Repository class: direct DB access is intentional; caching is handled at the service layer.

class RedirectRepository
{
    private string $table;

    /** Valid match types. */
    private const MATCH_TYPES = ['exact', 'contains', 'starts_with', 'ends_with', 'regex'];

    /** Valid status values. */
    private const STATUSES = ['active', 'inactive', 'disabled'];

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'seopulse_redirects';

        // Register custom table on $wpdb so it is recognised as a safe
        // identifier by WordPress coding standards (same as $wpdb->posts).
        $wpdb->seopulse_redirects = $this->table;
    }

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * Gets all redirects with optional filters.
     *
     * @param array{
     *   status?: string,
     *   group?: string,
     *   category?: string,
     *   match_type?: string,
     *   search?: string,
     *   sort_by?: string,
     *   order?: string,
     *   page?: int,
     *   per_page?: int,
     * } $args
     * @return array{items: array, total: int, pages: int}
     */
    public function getAll(array $args = []): array
    {
        global $wpdb;

        $status     = sanitize_text_field($args['status'] ?? '');
        $group      = sanitize_text_field($args['group'] ?? '');
        $category   = sanitize_text_field($args['category'] ?? '');
        $match_type = sanitize_text_field($args['match_type'] ?? '');
        $search     = sanitize_text_field($args['search'] ?? '');
        $sort_by    = $args['sort_by'] ?? 'created_at';
        $order      = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $page       = max(1, (int) ($args['page'] ?? 1));
        $per_page   = min(100, max(1, (int) ($args['per_page'] ?? 25)));
        $offset     = ($page - 1) * $per_page;

        // Allowlisted ORDER BY — maps column + direction to hardcoded SQL.
        $allowed_columns = [
            'source_url'    => 'source_url',
            'target_url'    => 'target_url',
            'redirect_type' => 'redirect_type',
            'match_type'    => 'match_type',
            'hits'          => 'hits',
            'last_accessed' => 'last_accessed',
            'status'        => 'status',
            'created_at'    => 'created_at',
            'updated_at'    => 'updated_at',
            'group_name'    => 'group_name',
            'category'      => 'category',
        ];
        $safe_column = $allowed_columns[$sort_by] ?? 'created_at';
        $order_by_sql = $safe_column . ' ' . $order;

        $where  = ['1=1'];
        $params = [];

        if ($status !== '') {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        if ($group !== '') {
            $where[]  = 'group_name = %s';
            $params[] = $group;
        }

        if ($category !== '') {
            $where[]  = 'category = %s';
            $params[] = $category;
        }

        if ($match_type !== '' && in_array($match_type, self::MATCH_TYPES, true)) {
            $where[]  = 'match_type = %s';
            $params[] = $match_type;
        }

        if ($search !== '') {
            $where[]  = '(source_url LIKE %s OR target_url LIKE %s OR description LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_clause = implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if (empty($params)) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->seopulse_redirects}` WHERE 1=%d", 1),
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_clause is built from hardcoded SQL fragments with placeholders.
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$wpdb->seopulse_redirects}` WHERE {$where_clause}",
                    ...$params,
                ),
            );
        }

        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_clause is built from hardcoded fragments; $order_by_sql is fully allowlisted.
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->seopulse_redirects}`
                 WHERE {$where_clause}
                 ORDER BY {$order_by_sql}
                 LIMIT %d OFFSET %d",
                ...$params,
            ),
            ARRAY_A,
        );

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    /**
     * Gets a single redirect by ID.
     */
    public function getById(int $id): ?array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$wpdb->seopulse_redirects}` WHERE id = %d", $id),
            ARRAY_A,
        );

        return $row ?: null;
    }

    /**
     * Finds redirects by source URL (for chain / duplicate detection).
     *
     * @return array[]
     */
    public function findBySource(string $source): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->seopulse_redirects}` WHERE source_url = %s",
                $source,
            ),
            ARRAY_A,
        );

        return $rows ?: [];
    }

    /**
     * Returns aggregate statistics.
     *
     * @return array{total: int, active: int, disabled: int, scheduled: int, total_hits: int}
     */
    public function getStats(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*)                                                 AS total,
                    SUM(CASE WHEN status = 'active'    THEN 1 ELSE 0 END)   AS active,
                    SUM(CASE WHEN status = 'disabled'  THEN 1 ELSE 0 END)   AS disabled,
                    COALESCE(SUM(hits), 0)                                   AS total_hits
                 FROM `{$wpdb->seopulse_redirects}` WHERE 1=%d",
                1,
            ),
            ARRAY_A,
        );

        return $row ? array_map('intval', $row) : [
            'total'      => 0,
            'active'     => 0,
            'disabled'   => 0,
            'total_hits' => 0,
        ];
    }

    // =========================================================================
    // IMPACT SCORING
    // =========================================================================

    /**
     * Returns all redirects enriched with an impact_score (0-100).
     *
     * Score formula:
     *  - 70% hit volume (log-scaled relative to max)
     *  - 30% recency (last_accessed within 30 days)
     *
     * @param array $args Same filters as getAll() + sort_by can be 'impact_score'
     * @return array{items: array, total: int, pages: int}
     */
    public function getAllWithImpact(array $args = []): array
    {
        $result = $this->getAll($args);

        if (empty($result['items'])) {
            return $result;
        }

        global $wpdb;

        // Get max hits for normalization.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $maxHits = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT MAX(hits) FROM `{$wpdb->seopulse_redirects}` WHERE 1=%d", 1),
        );
        $maxHits = max($maxHits, 1);

        $now = time();

        foreach ($result['items'] as &$item) {
            $hits = max(0, (int) ($item['hits'] ?? 0));

            // Hit score (log-scaled, 0-70).
            $hitScore = $hits > 0 ? (log($hits + 1) / log($maxHits + 1)) * 70 : 0;

            // Recency score (0-30): full points if accessed today, decays over 30 days.
            $recencyScore = 0;
            if (!empty($item['last_accessed'])) {
                $lastTs       = strtotime($item['last_accessed']);
                $daysSince    = max(0, ($now - $lastTs) / 86400);
                $recencyScore = $daysSince <= 30 ? (1 - $daysSince / 30) * 30 : 0;
            }

            $item['impact_score'] = (int) min(100, round($hitScore + $recencyScore));
            $item['impact_level'] = $item['impact_score'] >= 70 ? 'high'
                : ($item['impact_score'] >= 40 ? 'medium' : 'low');
        }
        unset($item);

        // Sort by impact_score if requested.
        if (($args['sort_by'] ?? '') === 'impact_score') {
            $order = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 1 : -1;
            usort($result['items'], fn ($a, $b) => ($a['impact_score'] <=> $b['impact_score']) * $order);
        }

        return $result;
    }

    // =========================================================================
    // CREATE
    // =========================================================================

    /**
     * Creates a new redirect.
     *
     * @return int|false Insert ID or false on failure
     */
    public function create(array $data): int|false
    {
        global $wpdb;

        $match_type = sanitize_text_field($data['match_type'] ?? 'exact');
        if (!in_array($match_type, self::MATCH_TYPES, true)) {
            $match_type = 'exact';
        }

        $status = sanitize_text_field($data['status'] ?? 'active');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'active';
        }

        $maintenance_code = isset($data['maintenance_code']) ? (int) $data['maintenance_code'] : null;
        if ($maintenance_code !== null && !in_array($maintenance_code, [410, 451], true)) {
            $maintenance_code = null;
        }

        $insert = [
            'source_url'        => sanitize_text_field($data['source_url']),
            'target_url'        => $maintenance_code ? '' : esc_url_raw($data['target_url'] ?? ''),
            'redirect_type'     => (int) ($data['redirect_type'] ?? 301),
            'regex'             => $match_type === 'regex' ? 1 : (int) (bool) ($data['regex'] ?? false),
            'match_type'        => $match_type,
            'ignore_case'       => (int) ($data['ignore_case'] ?? 1),
            'maintenance_code'  => $maintenance_code,
            'group_name'        => sanitize_text_field($data['group_name'] ?? ''),
            'category'          => sanitize_text_field($data['category'] ?? ''),
            'description'       => sanitize_textarea_field($data['description'] ?? ''),
            'pass_query_string' => (int) ($data['pass_query_string'] ?? 1),
            'status'            => $status,
        ];

        $formats = ['%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s'];

        $result = $wpdb->insert($this->table, $insert, $formats);

        return $result ? (int) $wpdb->insert_id : false;
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    /**
     * Updates an existing redirect — supports all fields.
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $map = [
            'source_url'        => [
                'sanitize' => 'sanitize_text_field',
                'format'   => '%s',
            ],
            'target_url'        => [
                'sanitize' => 'esc_url_raw',
                'format'   => '%s',
            ],
            'redirect_type'     => [
                'sanitize' => 'intval',
                'format'   => '%d',
            ],
            'regex'             => [
                'sanitize' => fn ($v) => (int) (bool) $v,
                'format'   => '%d',
            ],
            'match_type'        => [
                'sanitize' => 'sanitize_text_field',
                'format'   => '%s',
                'allowed'  => self::MATCH_TYPES,
            ],
            'ignore_case'       => [
                'sanitize' => 'intval',
                'format'   => '%d',
            ],
            'maintenance_code'  => [
                'sanitize' => 'intval',
                'format'   => '%d',
                'allowed'  => [0, 410, 451],
            ],
            'group_name'        => [
                'sanitize' => 'sanitize_text_field',
                'format'   => '%s',
            ],
            'category'          => [
                'sanitize' => 'sanitize_text_field',
                'format'   => '%s',
            ],
            'description'       => [
                'sanitize' => 'sanitize_textarea_field',
                'format'   => '%s',
            ],
            'pass_query_string' => [
                'sanitize' => 'intval',
                'format'   => '%d',
            ],
            'status'            => [
                'sanitize' => 'sanitize_text_field',
                'format'   => '%s',
                'allowed'  => self::STATUSES,
            ],
        ];

        $update_data = [];
        $formats     = [];

        foreach ($map as $field => $cfg) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = is_callable($cfg['sanitize'])
                ? call_user_func($cfg['sanitize'], $data[ $field ])
                : $data[ $field ];

            if (isset($cfg['allowed']) && !in_array($value, $cfg['allowed'], true)) {
                continue;
            }

            $update_data[ $field ] = $value;
            $formats[]             = $cfg['format'];
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update($this->table, $update_data, ['id' => $id], $formats, ['%d']);

        return $result !== false;
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    /**
     * Deletes a redirect by ID.
     */
    public function delete(int $id): bool
    {
        global $wpdb;

        return (bool) $wpdb->delete($this->table, ['id' => $id], ['%d']);
    }

    /**
     * Bulk deletes redirects by ID list.
     *
     * @param int[] $ids
     * @return int Number of deleted rows
     */
    public function bulkDelete(array $ids): int
    {
        global $wpdb;

        $ids = array_map('intval', array_filter($ids));
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM `{$wpdb->seopulse_redirects}` WHERE id IN ({$placeholders})", ...$ids),
        );
    }

    /**
     * Bulk updates the status of redirect IDs.
     *
     * @param int[] $ids
     * @param string $status One of: active, disabled
     * @return int Number of modified rows
     */
    public function bulkUpdateStatus(array $ids, string $status): int
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            return 0;
        }

        global $wpdb;

        $ids = array_map('intval', array_filter($ids));
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$wpdb->seopulse_redirects}` SET status = %s, updated_at = %s WHERE id IN ({$placeholders})",
                $status,
                current_time('mysql'),
                ...$ids,
            ),
        );
    }

    // =========================================================================
    // GROUPS & CATEGORIES
    // =========================================================================

    /**
     * Gets all distinct group names.
     *
     * @return string[]
     */
    public function getGroups(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $groups = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT group_name FROM `{$wpdb->seopulse_redirects}` WHERE group_name != '' AND 1=%d ORDER BY group_name",
                1,
            ),
        );

        return $groups ?: [];
    }

    /**
     * Gets all distinct categories.
     *
     * @return string[]
     */
    public function getCategories(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $cats = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT category FROM `{$wpdb->seopulse_redirects}` WHERE category != '' AND 1=%d ORDER BY category",
                1,
            ),
        );

        return $cats ?: [];
    }

    // =========================================================================
    // IMPORT / EXPORT
    // =========================================================================

    /**
     * Imports an array of redirect rules.
     *
     * @param array<array{source_url: string, target_url: string, redirect_type?: int}> $rules
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import(array $rules): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        foreach ($rules as $rule) {
            if (empty($rule['source_url'])) {
                ++$stats['skipped'];
                continue;
            }

            // Check for existing redirect with same source.
            $existing = $this->findBySource(sanitize_text_field($rule['source_url']));

            if (!empty($existing)) {
                // Update the first match.
                $this->update((int) $existing[0]['id'], $rule);
                ++$stats['updated'];
            } else {
                if (empty($rule['target_url']) && empty($rule['maintenance_code'])) {
                    ++$stats['skipped'];
                    continue;
                }
                $result = $this->create($rule);
                if ($result !== false) {
                    ++$stats['created'];
                } else {
                    ++$stats['skipped'];
                }
            }
        }

        return $stats;
    }

    /**
     * Exports all (or filtered) redirects as CSV string.
     *
     * @param bool $include_disabled Include disabled redirections in export.
     * @return string CSV content
     */
    public function exportCsv(bool $include_disabled = true): string
    {
        global $wpdb;

        $where = $include_disabled ? '' : "WHERE status = 'active'";

        $query = $include_disabled
            ? $wpdb->prepare("SELECT * FROM `{$wpdb->seopulse_redirects}` WHERE 1=%d ORDER BY created_at DESC", 1)
            : $wpdb->prepare("SELECT * FROM `{$wpdb->seopulse_redirects}` WHERE status = %s ORDER BY created_at DESC", 'active');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $query,
            ARRAY_A,
        );

        $headers = [
            'id',
            'source_url',
            'target_url',
            'redirect_type',
            'match_type',
            'ignore_case',
            'maintenance_code',
            'group_name',
            'category',
            'description',
            'pass_query_string',
            'status',
            'hits',
            'last_accessed',
            'created_at',
            'updated_at',
        ];

        $csv = implode(',', $headers) . "\n";

        foreach (($rows ?: []) as $row) {
            $line = [];
            foreach ($headers as $h) {
                $val    = $row[ $h ] ?? '';
                $line[] = '"' . str_replace('"', '""', (string) $val) . '"';
            }
            $csv .= implode(',', $line) . "\n";
        }

        return $csv;
    }

    /**
     * Imports redirections from a CSV string.
     *
     * @param string $csv CSV content with header row.
     * @return array{created: int, updated: int, deleted: int, skipped: int}
     */
    public function importCsv(string $csv): array
    {
        $lines = explode("\n", $csv);
        $stats = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
        ];

        if (empty($lines)) {
            return $stats;
        }

        // Parse header.
        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', $header);
        $header = array_map('strtolower', $header);

        $src_idx  = array_search('source_url', $header, true);
        $dest_idx = array_search('target_url', $header, true);

        // Also support 'source' / 'destination' as header aliases.
        if ($src_idx === false) {
            $src_idx = array_search('source', $header, true);
        }
        if ($dest_idx === false) {
            $dest_idx = array_search('destination', $header, true);
        }
        if ($dest_idx === false) {
            $dest_idx = array_search('target', $header, true);
        }

        if ($src_idx === false) {
            return $stats;
        }

        $id_idx = array_search('id', $header, true);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $cols = str_getcsv($line);

            $source = sanitize_text_field($cols[ $src_idx ] ?? '');
            $target = $dest_idx !== false ? sanitize_text_field($cols[ $dest_idx ] ?? '') : '';

            if ($source === '') {
                ++$stats['skipped'];
                continue;
            }

            // DELETE keyword support.
            if (strtoupper($target) === 'DELETE') {
                $existing = $this->findBySource($source);
                foreach ($existing as $ex) {
                    $this->delete((int) $ex['id']);
                    ++$stats['deleted'];
                }
                continue;
            }

            $row_data = [
                'source_url' => $source,
                'target_url' => $target,
            ];

            // Map remaining header columns.
            $field_map = [
                'redirect_type'     => 'redirect_type',
                'type'              => 'redirect_type',
                'match_type'        => 'match_type',
                'matching'          => 'match_type',
                'ignore_case'       => 'ignore_case',
                'maintenance_code'  => 'maintenance_code',
                'group_name'        => 'group_name',
                'group'             => 'group_name',
                'category'          => 'category',
                'description'       => 'description',
                'pass_query_string' => 'pass_query_string',
                'status'            => 'status',
            ];

            foreach ($field_map as $csv_key => $db_key) {
                $idx = array_search($csv_key, $header, true);
                if ($idx !== false && isset($cols[ $idx ]) && $cols[ $idx ] !== '') {
                    $row_data[ $db_key ] = $cols[ $idx ];
                }
            }

            // If we have an ID in the CSV, attempt update.
            if ($id_idx !== false && !empty($cols[ $id_idx ]) && is_numeric($cols[ $id_idx ])) {
                $existing = $this->getById((int) $cols[ $id_idx ]);
                if ($existing) {
                    $this->update((int) $cols[ $id_idx ], $row_data);
                    ++$stats['updated'];
                    continue;
                }
            }

            // Check for existing source.
            $existing = $this->findBySource($source);
            if (!empty($existing)) {
                $this->update((int) $existing[0]['id'], $row_data);
                ++$stats['updated'];
            } else {
                if ($target === '' && empty($row_data['maintenance_code'])) {
                    ++$stats['skipped'];
                    continue;
                }
                $r = $this->create($row_data);
                ++$stats[ $r !== false ? 'created' : 'skipped' ];
            }
        }

        return $stats;
    }

    // =========================================================================
    // HTACCESS EXPORT
    // =========================================================================

    /**
     * Generates Apache .htaccess redirect rules from active redirects.
     */
    public function exportHtaccess(): string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->seopulse_redirects}` WHERE status = %s ORDER BY match_type ASC, id ASC",
                'active',
            ),
            ARRAY_A,
        );

        $lines = [
            '# BEGIN SEOPulse Redirects',
            '# Generated: ' . current_time('mysql'),
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
        ];

        foreach (($rows ?: []) as $row) {
            $mc = (int) ($row['maintenance_code'] ?? 0);

            // Maintenance codes.
            if (in_array($mc, [410, 451], true)) {
                $pattern = $this->toHtaccessPattern($row);
                $lines[] = "RewriteRule {$pattern} - [R={$mc},L]";
                continue;
            }

            $target = $row['target_url'] ?? '';
            if ($target === '') {
                continue;
            }

            $pattern = $this->toHtaccessPattern($row);
            $code    = (int) ($row['redirect_type'] ?? 301);
            $flags   = $code === 301 ? 'R=301,L' : "R={$code},L";

            $lines[] = "RewriteRule {$pattern} {$target} [{$flags}]";
        }

        $lines[] = '</IfModule>';
        $lines[] = '# END SEOPulse Redirects';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Generates Nginx redirect rules from active redirects.
     */
    public function exportNginx(): string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->seopulse_redirects}` WHERE status = %s ORDER BY match_type ASC, id ASC",
                'active',
            ),
            ARRAY_A,
        );

        $lines = [
            '# SEOPulse Redirects for Nginx',
            '# Generated: ' . current_time('mysql'),
        ];

        foreach (($rows ?: []) as $row) {
            $mc     = (int) ($row['maintenance_code'] ?? 0);
            $source = $row['source_url'] ?? '';
            $target = $row['target_url'] ?? '';
            $type   = $row['match_type'] ?? 'exact';

            if (in_array($mc, [410, 451], true)) {
                if ($type === 'exact') {
                    $lines[] = "location = {$source} { return {$mc}; }";
                } else {
                    $pattern = $this->toNginxPattern($row);
                    $lines[] = "location ~ {$pattern} { return {$mc}; }";
                }
                continue;
            }

            if ($target === '') {
                continue;
            }

            $code = (int) ($row['redirect_type'] ?? 301);

            if ($type === 'exact') {
                $lines[] = "location = {$source} { return {$code} {$target}; }";
            } else {
                $pattern = $this->toNginxPattern($row);
                $lines[] = "location ~ {$pattern} { return {$code} {$target}; }";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    // =========================================================================
    // CHAIN / LOOP DETECTION
    // =========================================================================

    /**
     * Detects redirect chains and loops.
     *
     * A chain is when redirect A -> B -> C (multiple hops).
     * A loop is when the chain eventually points back to A.
     *
     * @return array{chains: array[], loops: array[]}
     */
    public function detectChainsAndLoops(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $all = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, source_url, target_url, match_type FROM `{$wpdb->seopulse_redirects}`
                 WHERE status = %s AND maintenance_code IS NULL AND match_type = %s
                 ORDER BY id ASC",
                'active',
                'exact',
            ),
            ARRAY_A,
        );

        if (empty($all)) {
            return [
                'chains' => [],
                'loops'  => [],
            ];
        }

        // Build source->target lookup (exact match only for reliable detection).
        $map = [];
        foreach ($all as $row) {
            $map[ $row['source_url'] ] = $row;
        }

        $chains = [];
        $loops  = [];

        foreach ($all as $row) {
            $visited = [$row['source_url']];
            $current = $row['target_url'];
            $path    = [(int) $row['id']];

            while (isset($map[ $current ])) {
                if (in_array($current, $visited, true)) {
                    // Loop detected.
                    $loops[] = [
                        'ids'  => $path,
                        'path' => $visited,
                    ];
                    break;
                }

                $visited[] = $current;
                $path[]    = (int) $map[ $current ]['id'];
                $current   = $map[ $current ]['target_url'];
            }

            if (count($path) > 1 && !in_array($row['target_url'], array_column($loops, 'path'), true)) {
                $chains[] = [
                    'ids'  => $path,
                    'path' => $visited,
                ];
            }
        }

        return [
            'chains' => $chains,
            'loops'  => $loops,
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Sanitizes a datetime string for SQL storage.
     *
     * @return string|null
     */
    private function sanitizeDatetime(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $ts = strtotime((string) $value);

        return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * Converts a redirect row into an Apache RewriteRule pattern.
     */
    private function toHtaccessPattern(array $row): string
    {
        $source = ltrim($row['source_url'] ?? '', '/');
        $type   = $row['match_type'] ?? 'exact';
        $ci     = !empty($row['ignore_case']) ? ' [NC]' : '';

        return match ($type) {
            'exact'       => "^{$source}/?$" . $ci,
            'contains'    => ".*{$source}.*" . $ci,
            'starts_with' => "^{$source}" . $ci,
            'ends_with'   => "{$source}$" . $ci,
            'regex'       => $source . $ci,
            default       => "^{$source}/?$" . $ci,
        };
    }

    /**
     * Converts a redirect row into an Nginx location pattern.
     */
    private function toNginxPattern(array $row): string
    {
        $source = $row['source_url'] ?? '';
        $type   = $row['match_type'] ?? 'exact';
        $ci     = !empty($row['ignore_case']) ? '(?i)' : '';

        return match ($type) {
            'contains'    => $ci . '.*' . preg_quote($source, '/') . '.*',
            'starts_with' => $ci . '^' . preg_quote($source, '/'),
            'ends_with'   => $ci . preg_quote($source, '/') . '$',
            'regex'       => $ci . $source,
            default       => $ci . '^' . preg_quote($source, '/') . '/?$',
        };
    }
}
