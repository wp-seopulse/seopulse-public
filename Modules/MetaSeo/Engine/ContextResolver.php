<?php

/**
 * ContextResolver
 *
 * Inspects the current WordPress query and builds an immutable
 * ContextBag that carries all the information the variable providers
 * need to resolve their values.
 *
 * For REST / headless consumers use ContextBag::fromArray() directly.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

final class ContextResolver
{
    /**
     * Detect context from the current WordPress main query.
     */
    public function detect(): ContextBag
    {
        global $post, $wp_query;

        $page       = max(1, (int) get_query_var('paged', 1));
        $totalPages = ($wp_query instanceof \WP_Query) ? (int) $wp_query->max_num_pages : 1;

        // Singular (post, page, CPT single, attachment)
        if (is_singular() && $post instanceof \WP_Post) {
            $authorData = get_userdata((int) $post->post_author);

            return new ContextBag(
                type: 'singular',
                post: $post,
                author: ($authorData instanceof \WP_User) ? $authorData : null,
                page: $page,
                totalPages: $totalPages,
            );
        }

        // Taxonomy archive (category, tag, custom taxonomy)
        if (is_category() || is_tag() || is_tax()) {
            $obj  = get_queried_object();
            $term = ($obj instanceof \WP_Term) ? $obj : null;

            return new ContextBag(
                type: 'taxonomy',
                term: $term,
                page: $page,
                totalPages: $totalPages,
                extra: [
                    'taxonomy' => $term?->taxonomy ?? '',
                ],
            );
        }

        // Author archive
        if (is_author()) {
            $obj = get_queried_object();

            return new ContextBag(
                type: 'author',
                author: ($obj instanceof \WP_User) ? $obj : null,
                page: $page,
                totalPages: $totalPages,
            );
        }

        // Search results
        if (is_search()) {
            return new ContextBag(
                type: 'search',
                searchQuery: get_search_query(),
                page: $page,
                totalPages: $totalPages,
            );
        }

        // 404
        if (is_404()) {
            return new ContextBag(type: '404');
        }

        // Post-type archive
        if (is_post_type_archive()) {
            return new ContextBag(
                type: 'archive',
                page: $page,
                totalPages: $totalPages,
                extra: ['post_type' => get_query_var('post_type')],
            );
        }

        // Date archive
        if (is_date()) {
            return new ContextBag(
                type: 'archive',
                page: $page,
                totalPages: $totalPages,
                extra: [
                    'archive_type' => 'date',
                    'year'         => (string) get_query_var('year'),
                    'month'        => (string) get_query_var('monthnum'),
                    'day'          => (string) get_query_var('day'),
                ],
            );
        }

        // Home / front-page / fallback
        return new ContextBag(
            type: 'home',
            page: $page,
            totalPages: $totalPages,
        );
    }
}
