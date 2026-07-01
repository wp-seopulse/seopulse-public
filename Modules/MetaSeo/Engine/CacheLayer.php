<?php

/**
 * Multi-level cache layer for the Meta Engine.
 *
 * L1: In-memory (per-request, fastest).
 * L2: WordPress transients (cross-request, persistent).
 * L3: Object cache if available (Redis / Memcached) — transparently
 *     via the WordPress transient API.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

final class CacheLayer
{
    private const TTL_DEFAULT = 3600; // 1 hour
    private const PREFIX      = 'seopulse_me_';

    /** @var array<string, mixed> L1 in-memory cache */
    private array $memory = [];

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Get a value from cache (checks L1 → L2).
     *
     * @return mixed|null Null on miss.
     */
    public function get(string $key): mixed
    {
        // L1: memory
        if (array_key_exists($key, $this->memory)) {
            return $this->memory[ $key ];
        }

        // L2 / L3: transient
        $value = get_transient(self::PREFIX . $key);

        if ($value !== false) {
            $this->memory[ $key ] = $value; // Promote to L1

            return $value;
        }

        return null;
    }

    /**
     * Set a value into all cache layers.
     */
    public function set(string $key, mixed $value, int $ttl = self::TTL_DEFAULT): void
    {
        $this->memory[ $key ] = $value;
        set_transient(self::PREFIX . $key, $value, $ttl);
    }

    /**
     * Delete a specific cache key.
     */
    public function delete(string $key): void
    {
        unset($this->memory[ $key ]);
        delete_transient(self::PREFIX . $key);
    }

    /**
     * Invalidate all cache entries related to a specific post.
     */
    public function invalidatePost(int $postId): void
    {
        $suffixes = ['title', 'description', 'og', 'twitter', 'full'];

        foreach ($suffixes as $suffix) {
            $this->delete("post_{$postId}_{$suffix}");
        }
    }

    /**
     * Flush every Meta Engine transient.
     */
    public function flush(): void
    {
        global $wpdb;

        $this->memory = [];

        if ($wpdb instanceof \wpdb) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    '_transient_' . self::PREFIX . '%',
                    '_transient_timeout_' . self::PREFIX . '%',
                ),
            );
        }
    }

    // ------------------------------------------------------------------
    // Key generation
    // ------------------------------------------------------------------

    /**
     * Generate a deterministic cache key from a template string + context.
     */
    public static function key(string $template, ContextBag $context): string
    {
        $contextHash = md5(
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            serialize(
                [
                    $context->getType(),
                    $context->getPost()?->ID,
                    $context->getTerm()?->term_id,
                    $context->getAuthor()?->ID,
                    $context->getSearchQuery(),
                    $context->getPage(),
                ],
            ),
        );

        return md5($template) . '_' . $contextHash;
    }
}
