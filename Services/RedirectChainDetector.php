<?php

/**
 * Redirect Chain Detector
 *
 * Detects multi-hop redirect chains (A → B → C) and circular redirects
 * (A → B → A). Used during validation to warn users and prevent loops.
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

class RedirectChainDetector
{
    /**
     * Maximum traversal depth to prevent infinite loops on corrupt data.
     */
    private const MAX_DEPTH = 20;

    /**
     * Adjacency list: normalized source → normalized target.
     *
     * @var array<string, string>
     */
    private array $graph = [];

    /**
     * Build the detector from a flat list of redirects.
     *
     * Accepts both Free-format (source/destination keys) and SQL-format
     * (source_url/target_url keys).
     *
     * @param array<int, array<string, mixed>> $redirects
     */
    public function __construct(array $redirects = [])
    {
        foreach ($redirects as $r) {
            $source = $this->normalize($r['source'] ?? $r['source_url'] ?? '');
            $target = $this->normalize($r['destination'] ?? $r['target_url'] ?? '');

            if ($source !== '' && $target !== '') {
                $this->graph[ $source ] = $target;
            }
        }
    }

    /**
     * Build from the current Free-tier option.
     *
     * @return self
     */
    public static function fromOption(): self
    {
        $redirects = get_option('seopulse_redirections', []);

        return new self(is_array($redirects) ? $redirects : []);
    }

    /**
     * Build from the shared SQL table (if it exists).
     *
     * @return self
     */
    public static function fromSql(): self
    {
        global $wpdb;

        $table = $wpdb->prefix . 'seopulse_redirects';

        if (!isset($wpdb->seopulse_redirects)) {
            $wpdb->seopulse_redirects = $table;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table),
        );

        if (!$exists) {
            return self::fromOption();
        }

        // Only load exact-match active redirects (regex patterns can't
        // be reliably resolved in a graph).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT source_url, target_url FROM `{$wpdb->seopulse_redirects}`
                 WHERE status = %s AND regex = %d",
                'active',
                0,
            ),
            ARRAY_A,
        );

        return new self(is_array($rows) ? $rows : []);
    }

    /**
     * Check whether adding a new redirect would create a chain or loop.
     *
     * @param string $source New redirect source.
     * @param string $target New redirect target.
     * @return array{ok: bool, chain: string[], circular: bool}
     */
    public function check(string $source, string $target): array
    {
        $source = $this->normalize($source);
        $target = $this->normalize($target);

        // Direct loop: source → source.
        if ($source === $target) {
            return [
                'ok'       => false,
                'chain'    => [$source, $target],
                'circular' => true,
            ];
        }

        // Temporarily add the edge.
        $previous               = $this->graph[ $source ] ?? null;
        $this->graph[ $source ] = $target;

        // Walk forward from `target` to detect chains and cycles.
        $chain    = [$source];
        $visited  = [$source => true];
        $current  = $target;
        $circular = false;
        $depth    = 0;

        while ($current !== null && $depth < self::MAX_DEPTH) {
            $chain[] = $current;

            if (isset($visited[ $current ])) {
                $circular = true;
                break;
            }

            $visited[ $current ] = true;
            $current             = $this->graph[ $current ] ?? null;
            ++$depth;
        }

        // Restore the graph.
        if ($previous !== null) {
            $this->graph[ $source ] = $previous;
        } else {
            unset($this->graph[ $source ]);
        }

        $isChain = count($chain) > 2;

        return [
            'ok'       => !$circular && !$isChain,
            'chain'    => $chain,
            'circular' => $circular,
        ];
    }

    /**
     * Detect ALL existing chains and loops in the current graph.
     *
     * @return array{chains: array<int, string[]>, loops: array<int, string[]>}
     */
    public function detectAll(): array
    {
        $chains = [];
        $loops  = [];
        $seen   = [];

        foreach ($this->graph as $source => $target) {
            if (isset($seen[ $source ])) {
                continue;
            }

            $path    = [$source];
            $visited = [$source => true];
            $current = $target;
            $depth   = 0;

            while ($current !== null && $depth < self::MAX_DEPTH) {
                $path[] = $current;

                if (isset($visited[ $current ])) {
                    // Circular loop detected.
                    $loops[] = $path;
                    break;
                }

                $visited[ $current ] = true;
                $seen[ $current ]    = true;
                $current             = $this->graph[ $current ] ?? null;
                ++$depth;
            }

            // A chain has more than 2 nodes (source → intermediate → final).
            if (count($path) > 2 && !isset($visited[ $current ?? '' ])) {
                $chains[] = $path;
            }
        }

        return [
            'chains' => $chains,
            'loops'  => $loops,
        ];
    }

    /**
     * Suggest a flattened version: redirect directly from first to last node.
     *
     * @param string[] $chain Chain path [A, B, C].
     * @return array{source: string, target: string}|null Null if chain is circular.
     */
    public function flatten(array $chain): ?array
    {
        if (count($chain) < 2) {
            return null;
        }

        $first = reset($chain);
        $last  = end($chain);

        // Cannot flatten a loop.
        if ($first === $last) {
            return null;
        }

        return [
            'source' => $first,
            'target' => $last,
        ];
    }

    /**
     * Normalize a URL or path for comparison.
     *
     * @param string $value URL or path.
     * @return string Lower-case, untrailed path.
     */
    private function normalize(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Extract path from full URLs.
        if (preg_match('#^https?://#i', $value)) {
            $path  = wp_parse_url($value, PHP_URL_PATH);
            $value = $path ?: '/';
        }

        $value = '/' . ltrim($value, '/');

        return strtolower(rtrim($value, '/')) ?: '/';
    }
}
