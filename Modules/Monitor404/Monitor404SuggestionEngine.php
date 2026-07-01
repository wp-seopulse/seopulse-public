<?php

/**
 * 404 Monitor – Redirect Suggestion Engine
 *
 * Analyses a 404'd URL and returns the most likely redirect destination
 * using a multi-strategy scoring approach:
 *
 * 1. Exact slug match against published posts/pages
 * 2. Levenshtein distance against post slugs
 * 3. Partial path segment similarity
 * 4. Full-text search (post title / content excerpt)
 *
 * Scores are normalised to 0-100 and the top candidate is returned.
 *
 * @package SEOPulse\Modules\Monitor404
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Monitor404;

if (!defined('ABSPATH')) {
    exit;
}

class Monitor404SuggestionEngine
{
    /** Minimum similarity score (0-100) to be considered a valid suggestion */
    private const MIN_SCORE = 20;

    /** Minimum score for `suggest()` (stricter, used for auto-suggest) */
    private const MIN_SCORE_STRICT = 30;

    /** Maximum posts to compare against (performance guard) */
    private const MAX_CANDIDATES = 500;

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Returns the best redirect suggestion for a 404 URL.
     *
     * @param string $url Request path, e.g. '/blog/my-artcle/'
     * @return array{url: string, score: int, strategy: string}|null
     */
    public function suggest(string $url): ?array
    {
        $slug = $this->extractSlug($url);

        if (empty($slug)) {
            return null;
        }

        $cacheKey = 'sp404_sugg_' . md5($url);
        $cached   = wp_cache_get($cacheKey, 'seopulse_404');

        if ($cached !== false) {
            return $cached ?: null;
        }

        $candidates = $this->loadCandidates();
        $best       = null;
        $bestScore  = 0;
        $bestStrat  = '';

        foreach ($candidates as $post) {
            [$score, $strat] = $this->score($slug, $url, $post);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $post;
                $bestStrat = $strat;
            }
        }

        $result = null;
        if ($best && $bestScore >= self::MIN_SCORE_STRICT) {
            $result = [
                'url'      => get_permalink($best->ID) ?: '',
                'score'    => $bestScore,
                'strategy' => $bestStrat,
            ];
        }

        wp_cache_set($cacheKey, $result ?? false, 'seopulse_404', 3600);

        return $result;
    }

    /**
     * Returns up to $limit suggestions for a 404 URL.
     */
    public function suggestMultiple(string $url, int $limit = 5): array
    {
        $slug = $this->extractSlug($url);

        if (empty($slug)) {
            return [];
        }

        $candidates = $this->loadCandidates();
        $results    = [];

        foreach ($candidates as $post) {
            [$score, $strat] = $this->score($slug, $url, $post);

            if ($score >= self::MIN_SCORE) {
                $results[] = [
                    'url'      => get_permalink($post->ID) ?: '',
                    'title'    => $post->post_title,
                    'score'    => $score,
                    'strategy' => $strat,
                ];
            }
        }

        // Fallback: use WP search if no candidate matched above threshold
        if (empty($results)) {
            $results = $this->searchFallback($slug, $url, $limit);
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    // ── Scoring ────────────────────────────────────────────────────────────────

    /**
     * Scores a candidate post against the 404 URL.
     *
     * @return array{int, string} [score, strategy]
     */
    private function score(string $slug404, string $url404, \WP_Post $post): array
    {
        $candidateSlug = $post->post_name;
        $maxScore      = 0;
        $strategy      = '';

        // 1. Exact slug match
        if ($candidateSlug === $slug404) {
            return [100, 'exact_slug'];
        }

        // 2. Levenshtein distance
        $levenScore = $this->levenshteinScore($slug404, $candidateSlug);
        if ($levenScore > $maxScore) {
            $maxScore = $levenScore;
            $strategy = 'levenshtein';
        }

        // 3. Partial path overlap
        $pathScore = $this->pathOverlapScore($url404, $post);
        if ($pathScore > $maxScore) {
            $maxScore = $pathScore;
            $strategy = 'path_overlap';
        }

        // 4. Title similarity
        $titleScore = $this->titleSimilarityScore($slug404, $post->post_title);
        if ($titleScore > $maxScore) {
            $maxScore = $titleScore;
            $strategy = 'title_similarity';
        }

        return [(int) $maxScore, $strategy];
    }

    /**
     * Normalizes Levenshtein distance to a 0-95 score.
     */
    private function levenshteinScore(string $a, string $b): int
    {
        if (empty($a) || empty($b)) {
            return 0;
        }

        $distance  = levenshtein($a, $b);
        $maxLength = max(strlen($a), strlen($b));

        if ($maxLength === 0) {
            return 0;
        }

        $similarity = 1 - ($distance / $maxLength);

        return (int) round($similarity * 95);
    }

    /**
     * Scores based on how many path segments overlap.
     */
    private function pathOverlapScore(string $url404, \WP_Post $post): int
    {
        $parts404  = array_filter(explode('/', trim($url404, '/')));
        $postLink  = get_permalink($post->ID) ?: '';
        $partsPost = array_filter(explode('/', trim(wp_parse_url($postLink, PHP_URL_PATH) ?: '', '/')));

        if (empty($parts404) || empty($partsPost)) {
            return 0;
        }

        $intersection = count(array_intersect($parts404, $partsPost));
        $union        = count(array_unique(array_merge($parts404, $partsPost)));

        if ($union === 0) {
            return 0;
        }

        return (int) round(($intersection / $union) * 85);
    }

    /**
     * Compares the 404 slug against the post title (word overlap).
     */
    private function titleSimilarityScore(string $slug404, string $postTitle): int
    {
        $titleSlug  = sanitize_title($postTitle);
        $wordsSlug  = array_filter(explode('-', $slug404));
        $wordsTitle = array_filter(explode('-', $titleSlug));

        if (empty($wordsSlug) || empty($wordsTitle)) {
            return 0;
        }

        $intersection = count(array_intersect($wordsSlug, $wordsTitle));
        $union        = count(array_unique(array_merge($wordsSlug, $wordsTitle)));

        if ($union === 0) {
            return 0;
        }

        return (int) round(($intersection / $union) * 80);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Extracts the last meaningful path segment (slug).
     */
    private function extractSlug(string $url): string
    {
        $path  = strtok($url, '?') ?: $url;
        $parts = array_filter(explode('/', trim($path, '/')));

        $last = (string) (end($parts) ?: '');

        // Remove file extension if present
        $last = preg_replace('/\.(html?|php|asp|aspx|jsp)$/i', '', $last) ?: $last;

        return sanitize_title($last);
    }

    /**
     * Loads all published content as candidates.
     *
     * @return \WP_Post[]
     */
    private function loadCandidates(): array
    {
        $cacheKey   = 'sp404_candidates';
        $candidates = wp_cache_get($cacheKey, 'seopulse_404');

        if ($candidates !== false) {
            return $candidates;
        }

        $publicTypes = get_post_types(['public' => true], 'names');
        $types       = array_values($publicTypes);
        $types       = array_filter($types, fn ($t) => $t !== 'attachment');

        if (empty($types)) {
            $types = ['post', 'page'];
        }

        $candidates = get_posts(
            [
                'post_type'      => $types,
                'post_status'    => 'publish',
                'posts_per_page' => self::MAX_CANDIDATES,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ],
        );

        wp_cache_set($cacheKey, $candidates, 'seopulse_404', 300);

        return $candidates;
    }

    /**
     * WordPress search-based fallback when no slug-matching candidate is found.
     */
    private function searchFallback(string $slug, string $url, int $limit): array
    {
        $searchTerms = str_replace('-', ' ', $slug);

        if (mb_strlen($searchTerms) < 3) {
            return [];
        }

        $publicTypes = get_post_types(['public' => true], 'names');
        $types       = array_values(array_filter($publicTypes, fn ($t) => $t !== 'attachment'));

        $posts = get_posts(
            [
                's'              => $searchTerms,
                'post_type'      => !empty($types) ? $types : ['post', 'page'],
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'no_found_rows'  => true,
            ],
        );

        $results = [];

        foreach ($posts as $i => $post) {
            $baseScore  = max(15, 50 - ($i * 8));
            $titleScore = $this->titleSimilarityScore($slug, $post->post_title);
            $score      = max($baseScore, $titleScore);

            $permalink = get_permalink($post->ID);

            if ($permalink) {
                $results[] = [
                    'url'      => $permalink,
                    'title'    => $post->post_title,
                    'score'    => (int) $score,
                    'strategy' => 'search_fallback',
                ];
            }
        }

        return $results;
    }
}
