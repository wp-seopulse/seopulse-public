<?php
/**
 * Cache for headless head payloads.
 *
 * @package SEOPulse\Modules\MetaSeo\Headless
 * @since   1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Headless;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transient-backed cache with scoped invalidation.
 *
 * - "global" token: part of every transient name; rotating it drops everything.
 * - "content" token: dependency of listings (archives, home, terms) and of
 *   negative entries; rotated whenever a public post changes.
 * - per-post revision (post meta): dependency of single-post entries.
 *
 * Entries are stored as ['dep' => [...], 'payload' => [...]] and validated on read.
 */
final class HeadCache
{
    /**
     * Option holding the tokens.
     */
    public const VERSION_OPTION = 'seopulse_head_cache_version';

    /**
     * Post meta holding the revision of a post payload.
     */
    public const POST_REV_META = '_seopulse_head_rev';

    private const PREFIX = 'seopulse_head_';

    private const DEFAULT_TTL = 12 * HOUR_IN_SECONDS;

    private const DEFAULT_NEGATIVE_TTL = MINUTE_IN_SECONDS;

    /**
     * Per-request copy of the tokens.
     *
     * @var array{global: string, content: string}|null
     */
    private static ?array $tokens = null;

    /**
     * Whether each token was rotated with nothing cached since (avoids a write per event in bulk operations).
     *
     * @var array<string, bool>
     */
    private static array $rotated = ['global' => false, 'content' => false];

    /**
     * Builds a dependency on a post.
     *
     * @param int $post_id Post ID.
     * @return array<string, mixed>
     */
    public function post_dependency(int $post_id): array
    {
        return ['type' => 'post', 'id' => $post_id, 'rev' => $this->post_revision($post_id)];
    }

    /**
     * Builds a dependency on the public content as a whole.
     *
     * @return array<string, mixed>
     */
    public function content_dependency(): array
    {
        return ['type' => 'content', 'token' => $this->tokens()['content']];
    }

    /**
     * Reads a payload; null on a miss or when its dependency is outdated.
     *
     * @param string $key Logical key.
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $entry = $this->read($key);

        return null === $entry || isset($entry['miss']) ? null : $entry['payload'];
    }

    /**
     * Reads a cached "no public content" result.
     *
     * @param string $key Logical key.
     * @return array{redirect: array<string, mixed>|null}|null Null when nothing is cached for the key.
     */
    public function get_miss(string $key): ?array
    {
        $entry = $this->read($key);

        if (null === $entry || !isset($entry['miss'])) {
            return null;
        }

        return ['redirect' => is_array($entry['redirect'] ?? null) ? $entry['redirect'] : null];
    }

    /**
     * Stores a payload.
     *
     * @param string $key Logical key.
     * @param array<string, mixed> $payload Payload.
     * @param array<string, mixed> $dependency Value from post_dependency() or content_dependency(),
     *                                         captured BEFORE generating the payload.
     * @return void
     */
    public function set(string $key, array $payload, array $dependency): void
    {
        /**
         * Filters the lifetime of cached head payloads.
         *
         * @since 1.8.0
         * @param int $ttl Lifetime in seconds.
         */
        $ttl = (int) apply_filters('seopulse_head_cache_ttl', self::DEFAULT_TTL);

        $this->write($key, ['dep' => $dependency, 'payload' => $payload], $ttl);
    }

    /**
     * Remembers briefly that a URL has no public content (and where it redirects, if anywhere).
     *
     * Only done with a persistent object cache: otherwise every random URL would
     * create database rows (cache pollution). A redirect rule edited in the meantime
     * is picked up when the entry expires.
     *
     * @param string $key Logical key.
     * @param array<string, mixed>|null $redirect Redirect found for the URL.
     * @return void
     */
    public function set_miss(string $key, ?array $redirect = null): void
    {
        if (!wp_using_ext_object_cache()) {
            return;
        }

        /**
         * Filters the lifetime of cached "not found" results.
         *
         * @since 1.8.0
         * @param int $ttl Lifetime in seconds. 0 disables the negative cache.
         */
        $ttl = (int) apply_filters('seopulse_head_negative_cache_ttl', self::DEFAULT_NEGATIVE_TTL);

        if ($ttl > 0) {
            $this->write($key, ['dep' => $this->content_dependency(), 'miss' => true, 'redirect' => $redirect], $ttl);
        }
    }

    /**
     * Invalidates everything (settings, theme, terms, users, permalinks...).
     *
     * @return bool False when already rotated with nothing cached since.
     */
    public function flush(): bool
    {
        return $this->rotate('global');
    }

    /**
     * Invalidates listings and negative entries (a public post changed).
     *
     * @return bool False when already rotated with nothing cached since.
     */
    public function flush_content(): bool
    {
        return $this->rotate('content');
    }

    /**
     * Invalidates the entries that depend on one post.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function touch_post(int $post_id): void
    {
        update_post_meta($post_id, self::POST_REV_META, wp_generate_password(8, false));
    }

    /**
     * Reads and validates an entry.
     *
     * @param string $key Logical key.
     * @return array<string, mixed>|null
     */
    private function read(string $key): ?array
    {
        $entry = get_transient($this->transient_name($key));

        if (!is_array($entry) || !isset($entry['dep']) || !is_array($entry['dep'])) {
            return null;
        }

        $dep = $entry['dep'];

        $is_valid = match ($dep['type'] ?? '') {
            'post'    => isset($dep['id'], $dep['rev']) && $this->post_revision((int) $dep['id']) === $dep['rev'],
            'content' => ($dep['token'] ?? '') === $this->tokens()['content'],
            default   => false,
        };

        return $is_valid ? $entry : null;
    }

    /**
     * Writes an entry.
     *
     * @param string $key Logical key.
     * @param array<string, mixed> $entry Entry.
     * @param int $ttl Lifetime in seconds.
     * @return void
     */
    private function write(string $key, array $entry, int $ttl): void
    {
        set_transient($this->transient_name($key), $entry, max(1, $ttl));

        self::$rotated = ['global' => false, 'content' => false];
    }

    /**
     * Rotates one token.
     *
     * @param string $scope "global" or "content".
     * @return bool
     */
    private function rotate(string $scope): bool
    {
        if (self::$rotated[$scope]) {
            return false;
        }

        $tokens         = $this->tokens();
        $tokens[$scope] = wp_generate_password(10, false);

        update_option(self::VERSION_OPTION, $tokens, true);

        self::$tokens          = $tokens;
        self::$rotated[$scope] = true;

        return true;
    }

    /**
     * Returns the revision of a post payload.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private function post_revision(int $post_id): string
    {
        $rev = get_post_meta($post_id, self::POST_REV_META, true);

        return is_string($rev) && '' !== $rev ? $rev : '0';
    }

    /**
     * Transient name: global token + hash of the logical key.
     *
     * @param string $key Logical key.
     * @return string
     */
    private function transient_name(string $key): string
    {
        return self::PREFIX . $this->tokens()['global'] . '_' . md5($key);
    }

    /**
     * Returns the tokens, creating them on first use (an old string value is discarded).
     *
     * @return array{global: string, content: string}
     */
    private function tokens(): array
    {
        if (null !== self::$tokens) {
            return self::$tokens;
        }

        $stored = get_option(self::VERSION_OPTION, []);

        if (
            !is_array($stored)
            || !isset($stored['global'], $stored['content'])
            || !is_string($stored['global'])
            || !is_string($stored['content'])
        ) {
            $stored = [
                'global'  => wp_generate_password(10, false),
                'content' => wp_generate_password(10, false),
            ];
            update_option(self::VERSION_OPTION, $stored, true);
        }

        self::$tokens = ['global' => $stored['global'], 'content' => $stored['content']];

        return self::$tokens;
    }
}
