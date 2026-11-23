<?php

/**
 * Object cache abstraction.
 *
 * Persistent object cache (Redis, Memcached...) when one is installed,
 * transients otherwise, with a per-request layer in front of both.
 * Groups are namespaced `seopulse_*` and can be flushed as a whole.
 *
 * @package SEOPulse\Core\Cache
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Cache;

if (!defined('ABSPATH')) {
    exit;
}

final class ObjectCache
{
    public const GROUP_PREFIX = 'seopulse_';

    private const VERSION_KEY = '__version';

    /** Groups flushed by flush_all(). */
    public const GROUPS = ['dashboard', 'analysis', '404', 'google', 'html_sitemap', 'sitemap', 'images'];

    /**
     * Reads a value.
     *
     * @param bool|null $found Set to whether the key exists, so cached false/null can be told from a miss.
     */
    public static function get(string $key, string $group, ?bool &$found = null): mixed
    {
        $group     = self::group($group);
        $versioned = self::versioned($key, $group);

        $value = wp_cache_get($versioned, $group, false, $hit);

        if ($hit) {
            $found = true;

            return $value;
        }

        if (!wp_using_ext_object_cache()) {
            $stored = get_transient(self::transient_key($versioned, $group));

            if (is_array($stored) && array_key_exists('v', $stored)) {
                wp_cache_set($versioned, $stored['v'], $group);

                $found = true;

                return $stored['v'];
            }
        }

        $found = false;

        return null;
    }

    /**
     * Stores a value. A TTL of 0 means no expiry.
     */
    public static function set(string $key, mixed $value, string $group, int $ttl = 0): bool
    {
        $group     = self::group($group);
        $versioned = self::versioned($key, $group);
        $ttl       = max(0, $ttl);

        $stored = wp_cache_set($versioned, $value, $group, $ttl);

        if (!wp_using_ext_object_cache()) {
            // Wrapped so a cached false/null survives the transient layer.
            $stored = set_transient(self::transient_key($versioned, $group), ['v' => $value], $ttl);
        }

        return (bool) $stored;
    }

    public static function delete(string $key, string $group): bool
    {
        $group     = self::group($group);
        $versioned = self::versioned($key, $group);

        wp_cache_delete($versioned, $group);

        if (!wp_using_ext_object_cache()) {
            delete_transient(self::transient_key($versioned, $group));
        }

        return true;
    }

    /**
     * Returns the cached value or computes, stores and returns it.
     *
     * @param callable():mixed $compute
     */
    public static function remember(string $key, string $group, int $ttl, callable $compute): mixed
    {
        $value = self::get($key, $group, $found);

        if ($found) {
            return $value;
        }

        $value = $compute();
        self::set($key, $value, $group, $ttl);

        return $value;
    }

    /**
     * Invalidates every key of a group at once by changing the group version.
     *
     * Old entries are never read again and expire on their own.
     */
    public static function flush_group(string $group): void
    {
        $group = self::group($group);

        self::write_version($group, self::new_version());
    }

    /**
     * Flushes every known SEOPulse group.
     */
    public static function flush_all(): void
    {
        foreach (self::GROUPS as $group) {
            self::flush_group($group);
        }
    }

    /**
     * True when values survive the request in a real object cache.
     */
    public static function is_persistent(): bool
    {
        return (bool) wp_using_ext_object_cache();
    }

    private static function group(string $group): string
    {
        return str_starts_with($group, self::GROUP_PREFIX) ? $group : self::GROUP_PREFIX . $group;
    }

    private static function versioned(string $key, string $group): string
    {
        return self::read_version($group) . ':' . $key;
    }

    /**
     * Transient names are capped at 172 characters, so the key is hashed.
     * The seopulse_ prefix keeps them covered by the uninstall and cache-clear cleanups.
     */
    private static function transient_key(string $versioned, string $group): string
    {
        return 'seopulse_c_' . md5($group . '|' . $versioned);
    }

    private static function read_version(string $group): string
    {
        $version = wp_cache_get(self::VERSION_KEY, $group, false, $hit);

        if ($hit && is_string($version) && '' !== $version) {
            return $version;
        }

        if (!wp_using_ext_object_cache()) {
            $stored = get_transient(self::version_transient($group));

            if (is_string($stored) && '' !== $stored) {
                wp_cache_set(self::VERSION_KEY, $stored, $group);

                return $stored;
            }
        }

        // Evicted or first use: a new version only costs a cold cache.
        $version = self::new_version();
        self::write_version($group, $version);

        return $version;
    }

    private static function write_version(string $group, string $version): void
    {
        wp_cache_set(self::VERSION_KEY, $version, $group);

        if (!wp_using_ext_object_cache()) {
            set_transient(self::version_transient($group), $version, 0);
        }
    }

    private static function version_transient(string $group): string
    {
        return 'seopulse_cv_' . md5($group);
    }

    private static function new_version(): string
    {
        return bin2hex(random_bytes(4));
    }
}
