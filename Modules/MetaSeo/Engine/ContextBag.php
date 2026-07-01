<?php

/**
 * ContextBag — immutable context carrier.
 *
 * Built by ContextResolver from the current WordPress query,
 * or from explicit parameters (REST API, headless, preview).
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;
use WP_Term;
use WP_User;

final class ContextBag
{
    /**
     * @param string $type Context type: singular, taxonomy, author, search, 404, home, archive.
     * @param WP_Post|null $post Current post when applicable.
     * @param WP_Term|null $term Current term when applicable.
     * @param WP_User|null $author Current author when applicable.
     * @param string|null $searchQuery Search query string.
     * @param int $page Current pagination page number (1-based).
     * @param int $totalPages Total number of pages.
     * @param array $extra Arbitrary extra key-value pairs.
     */
    public function __construct(
        private readonly string $type,
        private readonly ?WP_Post $post = null,
        private readonly ?WP_Term $term = null,
        private readonly ?WP_User $author = null,
        private readonly ?string $searchQuery = null,
        private readonly int $page = 1,
        private readonly int $totalPages = 1,
        private readonly array $extra = [],
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPost(): ?WP_Post
    {
        return $this->post;
    }

    public function getTerm(): ?WP_Term
    {
        return $this->term;
    }

    public function getAuthor(): ?WP_User
    {
        return $this->author;
    }

    public function getSearchQuery(): ?string
    {
        return $this->searchQuery;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getTotalPages(): int
    {
        return $this->totalPages;
    }

    /**
     * Retrieve an extra value by key.
     */
    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[ $key ] ?? $default;
    }

    /**
     * Return a new ContextBag with additional extra data (immutable clone).
     */
    public function withExtra(string $key, mixed $value): self
    {
        return new self(
            $this->type,
            $this->post,
            $this->term,
            $this->author,
            $this->searchQuery,
            $this->page,
            $this->totalPages,
            array_merge($this->extra, [$key => $value]),
        );
    }

    /**
     * Construct from explicit REST / headless parameters.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // Only expose a post when it is publicly viewable OR the current user
        // has explicit read permission. This prevents the public headless endpoint
        // from leaking meta data for drafts, private, pending, or future posts
        // to unauthenticated callers.
        $post = null;
        if (isset($data['post_id'])) {
            $candidate = get_post((int) $data['post_id']);
            if (
                $candidate instanceof WP_Post
                && (
                    is_post_publicly_viewable($candidate)
                    || current_user_can('read_post', $candidate->ID)
                )
            ) {
                $post = $candidate;
            }
        }

        $term   = isset($data['term_id']) ? get_term((int) $data['term_id']) : null;
        $author = isset($data['author_id']) ? get_userdata((int) $data['author_id']) : null;

        // Auto-derive author from the post when not explicitly provided
        if ($author === null && $post instanceof WP_Post) {
            $derived = get_userdata((int) $post->post_author);
            if ($derived instanceof WP_User) {
                $author = $derived;
            }
        }

        return new self(
            type: $data['type'] ?? 'singular',
            post: ($post instanceof WP_Post) ? $post : null,
            term: ($term instanceof WP_Term) ? $term : null,
            author: ($author instanceof WP_User) ? $author : null,
            searchQuery: $data['search_query'] ?? null,
            page: (int) ($data['page'] ?? 1),
            totalPages: (int) ($data['total_pages'] ?? 1),
            extra: $data['extra'] ?? [],
        );
    }
}
