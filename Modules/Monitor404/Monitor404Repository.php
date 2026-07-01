<?php

/**
 * 404 Monitor – Repository
 *
 * Full-featured database access layer for the 404 logs table.
 * Supports advanced filtering, stats aggregation, bulk operations,
 * severity scoring, CSV/JSON export, and suggestion caching.
 *
 * @package SEOPulse\Modules\Monitor404
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Monitor404;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- Dynamic placeholder counts via array_fill() and spread params; values always go through $wpdb->prepare().

class Monitor404Repository
{
    private string $table;
    private string $statsTable;

    public function __construct()
    {
        global $wpdb;
        $this->table      = $wpdb->prefix . 'seopulse_404_logs';
        $this->statsTable = $wpdb->prefix . 'seopulse_404_stats';

        // Register custom tables on $wpdb so they are recognised as safe
        // identifiers by WordPress coding standards (same as $wpdb->posts).
        $wpdb->seopulse_404_logs  = $this->table;
        $wpdb->seopulse_404_stats = $this->statsTable;
    }

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * Returns paginated 404 log entries with optional filters.
     *
     * @param array{
     *   status?: string,
     *   search?: string,
     *   sort_by?: string,
     *   order?: string,
     *   page?: int,
     *   per_page?: int,
     *   is_bot?: bool|null,
     *   date_from?: string,
     *   date_to?: string,
     * } $args
     * @return array{items: array, total: int, pages: int}
     */
    public function getAll(array $args = []): array
    {
        global $wpdb;

        $status    = sanitize_text_field($args['status'] ?? '');
        $search    = sanitize_text_field($args['search'] ?? '');
        $sort_by   = $args['sort_by'] ?? 'last_hit';
        $order     = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $page      = max(1, (int) ($args['page'] ?? 1));
        $per_page  = min(200, max(1, (int) ($args['per_page'] ?? 25)));
        $offset    = ($page - 1) * $per_page;
        $is_bot    = isset($args['is_bot']) ? (int) $args['is_bot'] : null;
        $date_from = $args['date_from'] ?? '';
        $date_to   = $args['date_to'] ?? '';

        // Allowlisted ORDER BY — fully maps column + direction to hardcoded SQL.
        $allowed_columns = [
            'url'      => 'url',
            'hits'     => 'hits',
            'first_hit' => 'first_hit',
            'last_hit' => 'last_hit',
            'status'   => 'status',
            'is_bot'   => 'is_bot',
        ];
        $safe_column = $allowed_columns[$sort_by] ?? 'last_hit';
        $order_by_sql = $safe_column . ' ' . $order;

        $where  = ['1=1'];
        $params = [];

        if ($status !== '') {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        if ($search !== '') {
            $where[]  = '(url LIKE %s OR referrer LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($is_bot !== null) {
            $where[]  = 'is_bot = %d';
            $params[] = $is_bot;
        }

        if ($date_from !== '') {
            $where[]  = 'last_hit >= %s';
            $params[] = sanitize_text_field($date_from) . ' 00:00:00';
        }

        if ($date_to !== '') {
            $where[]  = 'last_hit <= %s';
            $params[] = sanitize_text_field($date_to) . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_clause is built from hardcoded SQL fragments with placeholders.
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$wpdb->seopulse_404_logs}` WHERE {$where_clause}",
                    ...$params,
                ),
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $total = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->seopulse_404_logs}` WHERE 1=%d", 1),
            );
        }

        $queryParams = array_merge($params, [$per_page, $offset]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_clause is built from hardcoded fragments; $order_by_sql is fully allowlisted.
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->seopulse_404_logs}` WHERE {$where_clause} ORDER BY {$order_by_sql} LIMIT %d OFFSET %d",
                ...$queryParams,
            ),
            ARRAY_A,
        );

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
        ];
    }

    /**
     * Returns a single 404 log by ID.
     */
    public function getById(int $id): ?array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$wpdb->seopulse_404_logs}` WHERE id = %d", $id),
            ARRAY_A,
        );

        return $row ?: null;
    }

    /**
     * Returns top 404 URLs by hit count.
     */
    public function getTopUrls(int $limit = 10, bool $botsOnly = false, bool $humansOnly = false): array
    {
        global $wpdb;

        $conditions = ["status = 'active'"];
        $params     = [];

        if ($botsOnly) {
            $conditions[] = 'is_bot = %d';
            $params[]     = 1;
        } elseif ($humansOnly) {
            $conditions[] = 'is_bot = %d';
            $params[]     = 0;
        }

        $where_clause = implode(' AND ', $conditions);
        $params[]     = $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_clause is built from hardcoded SQL fragments with placeholders.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT url, hits, last_hit, is_bot, bot_name, suggestion, suggestion_score
                 FROM `{$wpdb->seopulse_404_logs}`
                 WHERE {$where_clause}
                 ORDER BY hits DESC
                 LIMIT %d",
                ...$params,
            ),
            ARRAY_A,
        ) ?: [];
    }

    /**
     * Returns top referrers ordered by total hits.
     */
    public function getTopReferrers(int $limit = 10): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT referrer, COUNT(*) as urls, SUM(hits) as total_hits
                 FROM `{$wpdb->seopulse_404_logs}`
                 WHERE referrer != '' AND status = 'active'
                 GROUP BY referrer
                 ORDER BY total_hits DESC
                 LIMIT %d",
                $limit,
            ),
            ARRAY_A,
        ) ?: [];
    }

    /**
     * Returns daily 404 counts for the last N days (for charts).
     */
    public function getDailyStats(int $days = 30): array
    {
        global $wpdb;

        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(last_hit) as date,
                        SUM(hits) as hits,
                        COUNT(*) as unique_urls
                 FROM `{$wpdb->seopulse_404_logs}`
                 WHERE last_hit >= %s
                 GROUP BY DATE(last_hit)
                 ORDER BY date ASC",
                $since,
            ),
            ARRAY_A,
        ) ?: [];
    }

    /**
     * Returns high-level summary counters.
     */
    public function getSummary(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(hits)                         AS total_hits,
                    COUNT(*)                           AS unique_urls,
                    SUM(CASE WHEN status='active'     THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN status='redirected' THEN 1 ELSE 0 END) AS redirected_count,
                    SUM(CASE WHEN status='ignored'    THEN 1 ELSE 0 END) AS ignored_count,
                    SUM(CASE WHEN is_bot=1            THEN hits ELSE 0 END) AS bot_hits
                 FROM `{$wpdb->seopulse_404_logs}`
                 WHERE 1=%d",
                1,
            ),
            ARRAY_A,
        );

        return [
            'total_hits'  => (int) ($row['total_hits'] ?? 0),
            'unique_urls' => (int) ($row['unique_urls'] ?? 0),
            'active'      => (int) ($row['active_count'] ?? 0),
            'redirected'  => (int) ($row['redirected_count'] ?? 0),
            'ignored'     => (int) ($row['ignored_count'] ?? 0),
            'bot_hits'    => (int) ($row['bot_hits'] ?? 0),
        ];
    }

    // =========================================================================
    // SEVERITY SCORING & CLASSIFICATION
    // =========================================================================

    /**
     * Returns 404 entries enriched with severity_score (0-100) and category.
     *
     * Severity factors:
     *  - 35% hit frequency (log-scaled)
     *  - 25% external referrer presence (external > internal)
     *  - 20% recency (last seen in past 7 days = max)
     *  - 10% human ratio (higher = worse)
     *  - 10% recurrence span (first_hit to last_hit spans multiple days)
     */
    public function getAllWithSeverity(array $args = []): array
    {
        $result = $this->getAll($args);

        if (empty($result['items'])) {
            return $result;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $maxHits = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT MAX(hits) FROM `{$wpdb->seopulse_404_logs}` WHERE status = %s", 'active'),
        );
        $maxHits = max($maxHits, 1);

        $homeHost = wp_parse_url(home_url(), PHP_URL_HOST);
        $now      = time();

        $spamPatterns = [
            '/wp-login',
            '/xmlrpc',
            '/.env',
            '/wp-config',
            '/admin/config',
            '/phpmyadmin',
            '/wp-admin/install',
            '/.git',
            '/vendor/',
        ];

        foreach ($result['items'] as &$item) {
            $hits  = max(1, (int) ($item['hits'] ?? 1));
            $isBot = (int) ($item['is_bot'] ?? 0);
            $ref   = $item['referrer'] ?? '';
            $url   = $item['url'] ?? '';

            // Hit score (0-35)
            $hitScore = (log($hits + 1) / log($maxHits + 1)) * 35;

            // Referrer score (0-25)
            $refScore = 0;
            if (!empty($ref)) {
                $refHost  = wp_parse_url($ref, PHP_URL_HOST) ?: '';
                $refScore = ($refHost !== '' && $refHost !== $homeHost) ? 25 : 15;
            }

            // Recency score (0-20)
            $recencyScore = 0;
            if (!empty($item['last_hit'])) {
                $daysSince    = max(0, ($now - strtotime($item['last_hit'])) / 86400);
                $recencyScore = $daysSince <= 7 ? (1 - $daysSince / 7) * 20 : 0;
            }

            // Human ratio score (0-10)
            $humanScore = $isBot ? 0 : 10;

            // Recurrence span (0-10)
            $spanScore = 0;
            if (!empty($item['first_hit']) && !empty($item['last_hit'])) {
                $spanDays  = max(0, (strtotime($item['last_hit']) - strtotime($item['first_hit'])) / 86400);
                $spanScore = min(10, $spanDays);
            }

            $score = (int) min(100, round($hitScore + $refScore + $recencyScore + $humanScore + $spanScore));

            // Classification
            $category = 'unknown';
            $isSpam   = false;
            foreach ($spamPatterns as $pat) {
                if (stripos($url, $pat) !== false) {
                    $isSpam = true;
                    break;
                }
            }

            if ($isSpam) {
                $category = 'spam';
            } elseif ($isBot && empty($ref)) {
                $category = 'crawler_artifact';
            } elseif (!empty($ref)) {
                $refHost  = wp_parse_url($ref, PHP_URL_HOST) ?: '';
                $category = ($refHost === $homeHost) ? 'broken_internal' : 'external_broken';
            } else {
                $category = 'removed_content';
            }

            $item['severity_score'] = $score;
            $item['severity_level'] = $score >= 70 ? 'critical' : ($score >= 40 ? 'warning' : 'low');
            $item['category']       = $category;
        }
        unset($item);

        // Sort by severity if requested
        if (($args['sort_by'] ?? '') === 'severity_score') {
            $order = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 1 : -1;
            usort($result['items'], fn ($a, $b) => ($a['severity_score'] <=> $b['severity_score']) * $order);
        }

        return $result;
    }

    /**
     * Returns top prioritized 404s sorted by severity.
     */
    public function getPrioritized(int $limit = 20, string $category = ''): array
    {
        $result = $this->getAllWithSeverity(
            [
                'status'   => 'active',
                'sort_by'  => 'severity_score',
                'order'    => 'DESC',
                'per_page' => 200,
            ],
        );

        $items = $result['items'] ?? [];

        if ($category !== '') {
            $items = array_filter($items, fn ($i) => ($i['category'] ?? '') === $category);
            $items = array_values($items);
        }

        return array_slice($items, 0, $limit);
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Marks a 404 log as redirected.
     */
    public function markRedirected(int $id, int $redirect_id): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->update(
            $this->table,
            [
                'status'      => 'redirected',
                'redirect_id' => $redirect_id,
            ],
            ['id' => $id],
            ['%s', '%d'],
            ['%d'],
        );
    }

    /**
     * Marks a 404 log as ignored.
     */
    public function markIgnored(int $id, string $reason = ''): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->update(
            $this->table,
            [
                'status'         => 'ignored',
                'ignored_reason' => substr($reason, 0, 255),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Stores a suggestion URL and score for an existing log entry.
     */
    public function storeSuggestion(int $id, string $suggestionUrl, int $score): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->update(
            $this->table,
            [
                'suggestion'       => substr($suggestionUrl, 0, 2048),
                'suggestion_score' => min(100, max(0, $score)),
            ],
            ['id' => $id],
            ['%s', '%d'],
            ['%d'],
        );
    }

    /**
     * Deletes a single 404 log entry.
     */
    public function delete(int $id): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->delete($this->table, ['id' => $id], ['%d']);
    }

    /**
     * Bulk-deletes 404 log entries by IDs.
     */
    public function bulkDelete(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        global $wpdb;

        $ids          = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->seopulse_404_logs}` WHERE id IN ({$placeholders})",
                ...$ids,
            ),
        );
    }

    /**
     * Bulk-marks entries as ignored.
     */
    public function bulkIgnore(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        global $wpdb;

        $ids          = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$wpdb->seopulse_404_logs}` SET status='ignored' WHERE id IN ({$placeholders})",
                ...$ids,
            ),
        );
    }

    /**
     * Deletes all entries older than $days with status != 'redirected'.
     */
    public function deleteOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        global $wpdb;

        $threshold = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->seopulse_404_logs}` WHERE last_hit < %s AND status != 'redirected'",
                $threshold,
            ),
        );
    }

    /**
     * Deletes all entries (full reset).
     */
    public function truncate(): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->query(
            $wpdb->prepare("DELETE FROM `{$wpdb->seopulse_404_logs}` WHERE 1=%d", 1),
        );
    }

    // =========================================================================
    // EXPORT
    // =========================================================================

    /**
     * Exports all active logs as a CSV string.
     */
    public function exportCsv(): string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, referrer, user_agent, hits, first_hit, last_hit, status, is_bot, bot_name, suggestion
                 FROM `{$wpdb->seopulse_404_logs}`
                 WHERE 1=%d
                 ORDER BY hits DESC",
                1,
            ),
            ARRAY_A,
        );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory stream, not a filesystem path.
        $handle = fopen('php://memory', 'rb+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['ID', 'URL', 'Referrer', 'User Agent', 'Hits', 'First Hit', 'Last Hit', 'Status', 'Is Bot', 'Bot Name', 'Suggestion']);

        foreach (($items ?: []) as $row) {
            fputcsv(
                $handle,
                [
                    $row['id'],
                    $row['url'],
                    $row['referrer'],
                    $row['user_agent'],
                    $row['hits'],
                    $row['first_hit'],
                    $row['last_hit'],
                    $row['status'],
                    $row['is_bot'] ? __('yes', 'seopulse') : __('no', 'seopulse'),
                    $row['bot_name'] ?? '',
                    $row['suggestion'] ?? '',
                ],
            );
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing php://memory stream.
        fclose($handle);

        return $csv ?: '';
    }

    /**
     * Exports all active logs as a JSON string.
     */
    public function exportJson(): string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, referrer, hits, first_hit, last_hit, status, is_bot, bot_name, suggestion
                 FROM `{$wpdb->seopulse_404_logs}`
                 WHERE 1=%d
                 ORDER BY hits DESC",
                1,
            ),
            ARRAY_A,
        );

        return wp_json_encode($items ?: []) ?: '[]';
    }

    // =========================================================================
    // DAILY STATS AGGREGATION
    // =========================================================================

    /**
     * Aggregates yesterday's 404 data into the seopulse_404_stats table.
     */
    public function aggregateDailyStats(): void
    {
        global $wpdb;

        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(hits)                                   AS total_hits,
                    COUNT(*)                                     AS unique_urls,
                    SUM(CASE WHEN is_bot = 1 THEN hits ELSE 0 END) AS bot_hits,
                    SUM(CASE WHEN is_bot = 0 THEN hits ELSE 0 END) AS human_hits
                 FROM `{$wpdb->seopulse_404_logs}`
                 WHERE DATE(last_hit) = %s",
                $yesterday,
            ),
            ARRAY_A,
        );

        $totalHits  = (int) ($row['total_hits'] ?? 0);
        $uniqueUrls = (int) ($row['unique_urls'] ?? 0);
        $botHits    = (int) ($row['bot_hits'] ?? 0);
        $humanHits  = (int) ($row['human_hits'] ?? 0);

        // Find top URL for that day
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $topUrl = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT url FROM `{$wpdb->seopulse_404_logs}`
                 WHERE DATE(last_hit) = %s
                 ORDER BY hits DESC LIMIT 1",
                $yesterday,
            ),
        ) ?? '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$wpdb->seopulse_404_stats}`
                    (stat_date, total_hits, unique_urls, bot_hits, human_hits, top_url)
                 VALUES (%s, %d, %d, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE
                    total_hits  = VALUES(total_hits),
                    unique_urls = VALUES(unique_urls),
                    bot_hits    = VALUES(bot_hits),
                    human_hits  = VALUES(human_hits),
                    top_url     = VALUES(top_url)",
                $yesterday,
                $totalHits,
                $uniqueUrls,
                $botHits,
                $humanHits,
                $topUrl,
            ),
        );
    }

    /**
     * Returns daily stats from the pre-aggregated stats table.
     * Falls back to on-the-fly computation for dates not yet aggregated.
     */
    public function getDailyStatsFromAggregated(int $days = 30): array
    {
        global $wpdb;

        $since = gmdate('Y-m-d', strtotime("-{$days} days"));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $aggregated = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT stat_date AS date, total_hits AS hits, unique_urls, bot_hits, human_hits
                 FROM `{$wpdb->seopulse_404_stats}`
                 WHERE stat_date >= %s
                 ORDER BY stat_date ASC",
                $since,
            ),
            ARRAY_A,
        ) ?: [];

        // Get today's live data (not yet aggregated)
        $today = gmdate('Y-m-d');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $todayRow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    DATE(last_hit) AS date,
                    SUM(hits)      AS hits,
                    COUNT(*)       AS unique_urls,
                    SUM(CASE WHEN is_bot = 1 THEN hits ELSE 0 END) AS bot_hits,
                    SUM(CASE WHEN is_bot = 0 THEN hits ELSE 0 END) AS human_hits
                 FROM `{$wpdb->seopulse_404_logs}`
                 WHERE DATE(last_hit) = %s",
                $today,
            ),
            ARRAY_A,
        );

        if ($todayRow && $todayRow['date']) {
            $aggregated[] = $todayRow;
        }

        return $aggregated;
    }

    // =========================================================================
    // WEEKLY REPORT DATA
    // =========================================================================

    /**
     * Returns data for the weekly email report.
     */
    public function getWeeklyReportData(): array
    {
        global $wpdb;

        $since = gmdate('Y-m-d H:i:s', strtotime('-7 days'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $newCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->seopulse_404_logs}` WHERE first_hit >= %s",
                $since,
            ),
        );

        return [
            'new_count'     => $newCount,
            'top_urls'      => $this->getTopUrls(10, false, true),
            'top_referrers' => $this->getTopReferrers(5),
        ];
    }
}
