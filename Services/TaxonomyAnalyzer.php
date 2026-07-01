<?php

/**
 * Taxonomy SEO Analyzer Service.
 *
 * Performs deep analysis of taxonomy health:
 *  - Orphan detection
 *  - Thin content detection
 *  - Hierarchy depth analysis
 *  - Tag/category ratio analysis
 *  - Duplicate content risk scoring
 *  - Internal linking suggestions
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Term;

/**
 * TaxonomyAnalyzer — strategic SEO analysis for taxonomies.
 */
final class TaxonomyAnalyzer
{
    public const NAME_SERVICE = 'TaxonomyAnalyzer';

    // ------------------------------------------------------------------
    // Thresholds
    // ------------------------------------------------------------------

    /** Minimum posts to consider a taxonomy term "healthy". */
    private const MIN_POST_COUNT = 3;

    /** Maximum depth recommended for category hierarchy. */
    private const MAX_HIERARCHY_DEPTH = 3;

    /** Ideal ratio: categories per 10 posts. */
    private const IDEAL_CATEGORY_RATIO = 1.5;

    /** Maximum number of tags before recommending cleanup. */
    private const MAX_TAGS_THRESHOLD = 100;

    /** Tags with fewer posts than this are "thin". */
    private const TAG_THIN_THRESHOLD = 2;

    // ------------------------------------------------------------------
    // Full Site Analysis
    // ------------------------------------------------------------------

    /**
     * Run a comprehensive analysis of all taxonomies on the site.
     *
     * @return array{
     *   taxonomies: array<string, array>,
     *   global_score: int,
     *   issues: array,
     *   recommendations: array,
     *   ratios: array
     * }
     */
    public function analyzeAll(): array
    {
        $taxonomies = $this->getPublicTaxonomies();
        $results    = [];
        $allIssues  = [];
        $allRecs    = [];

        foreach ($taxonomies as $taxSlug => $taxObj) {
            $slug             = (string) $taxSlug;
            $analysis         = $this->analyzeTaxonomy($slug);
            $results[ $slug ] = $analysis;

            foreach ($analysis['issues'] as $issue) {
                $allIssues[] = array_merge($issue, ['taxonomy' => $slug]);
            }
            foreach ($analysis['recommendations'] as $rec) {
                $allRecs[] = array_merge($rec, ['taxonomy' => $slug]);
            }
        }

        $ratios = $this->computeGlobalRatios();

        // Ratio-based recommendations
        if ($ratios['categories_per_10_posts'] > self::IDEAL_CATEGORY_RATIO * 2) {
            $allRecs[] = [
                'type'     => 'warning',
                'code'     => 'too_many_categories',
                'message'  => sprintf(
                    /* translators: 1: number of categories, 2: number of posts, 3: ratio per 10 posts */
                    __('You have %1$d categories for %2$d posts (ratio: %3$.1f/10). Consider merging similar categories.', 'seopulse'),
                    $ratios['total_categories'],
                    $ratios['total_posts'],
                    $ratios['categories_per_10_posts'],
                ),
                'taxonomy' => 'category',
            ];
        }

        if ($ratios['total_tags'] > self::MAX_TAGS_THRESHOLD) {
            $allRecs[] = [
                'type'     => 'warning',
                'code'     => 'too_many_tags',
                'message'  => sprintf(
                    /* translators: %d: number of tags */
                    __('You have %d tags. Consider cleaning up tags with very few posts to avoid thin content pages.', 'seopulse'),
                    $ratios['total_tags'],
                ),
                'taxonomy' => 'post_tag',
            ];
        }

        // Score
        $issueCount  = count($allIssues);
        $globalScore = max(0, 100 - ($issueCount * 5));

        return [
            'taxonomies'      => $results,
            'global_score'    => $globalScore,
            'issues'          => $allIssues,
            'recommendations' => $allRecs,
            'ratios'          => $ratios,
        ];
    }

    /**
     * Analyze a single taxonomy.
     *
     * @param string $taxonomy Taxonomy slug.
     * @return array{
     *   taxonomy: string,
     *   label: string,
     *   hierarchical: bool,
     *   total_terms: int,
     *   score: int,
     *   terms: array,
     *   orphans: array,
     *   thin_terms: array,
     *   empty_terms: array,
     *   deep_terms: array,
     *   issues: array,
     *   recommendations: array,
     *   merge_suggestions: array
     * }
     */
    public function analyzeTaxonomy(string $taxonomy): array
    {
        $taxObj = get_taxonomy($taxonomy);

        if (!$taxObj) {
            return $this->emptyResult($taxonomy);
        }

        $terms = get_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ],
        );

        if (is_wp_error($terms)) {
            return $this->emptyResult($taxonomy);
        }

        $issues   = [];
        $recs     = [];
        $orphans  = [];
        $thin     = [];
        $empty    = [];
        $deep     = [];
        $termData = [];
        $merges   = [];

        foreach ($terms as $term) {
            $termInfo   = $this->analyzeTermDetail($term, $taxonomy);
            $termData[] = $termInfo;

            if ($termInfo['is_orphan']) {
                $orphans[] = $termInfo;
            }
            if ($termInfo['is_thin']) {
                $thin[] = $termInfo;
            }
            if ($termInfo['is_empty']) {
                $empty[] = $termInfo;
            }
            if ($termInfo['depth'] > self::MAX_HIERARCHY_DEPTH) {
                $deep[] = $termInfo;
            }
        }

        // Build issues
        if (count($empty) > 0) {
            $issues[] = [
                'type'    => 'error',
                'code'    => 'empty_terms',
                'message' => sprintf(
                    /* translators: 1: number of empty terms, 2: taxonomy name */
                    __('%1$d empty %2$s found. Empty taxonomy pages create thin content.', 'seopulse'),
                    count($empty),
                    $taxObj->labels->name,
                ),
                'count'   => count($empty),
            ];
        }

        if (count($thin) > 0) {
            $issues[] = [
                'type'    => 'warning',
                'code'    => 'thin_terms',
                'message' => sprintf(
                    /* translators: 1: number of thin terms, 2: taxonomy name, 3: minimum post count */
                    __('%1$d %2$s have very few posts (<%3$d). Consider noindexing or merging them.', 'seopulse'),
                    count($thin),
                    strtolower($taxObj->labels->name),
                    self::MIN_POST_COUNT,
                ),
                'count'   => count($thin),
            ];
        }

        if (count($orphans) > 0 && $taxObj->hierarchical) {
            $issues[] = [
                'type'    => 'warning',
                'code'    => 'orphan_terms',
                'message' => sprintf(
                    /* translators: 1: number of orphan terms, 2: taxonomy name */
                    __('%1$d orphan %2$s detected (top-level with no children and few posts). Consider reorganizing.', 'seopulse'),
                    count($orphans),
                    strtolower($taxObj->labels->name),
                ),
                'count'   => count($orphans),
            ];
        }

        if (count($deep) > 0) {
            $issues[] = [
                'type'    => 'warning',
                'code'    => 'deep_hierarchy',
                'message' => sprintf(
                    /* translators: 1: number of deep terms, 2: taxonomy name, 3: max hierarchy depth */
                    __('%1$d %2$s have a hierarchy depth exceeding %3$d levels. Flatten for better crawlability.', 'seopulse'),
                    count($deep),
                    strtolower($taxObj->labels->name),
                    self::MAX_HIERARCHY_DEPTH,
                ),
                'count'   => count($deep),
            ];
        }

        // Merge suggestions: terms with similar names
        $merges = $this->findMergeSuggestions($terms);

        if (count($merges) > 0) {
            $recs[] = [
                'type'    => 'info',
                'code'    => 'merge_suggestions',
                'message' => sprintf(
                    /* translators: 1: number of merge candidates, 2: taxonomy name */
                    __('%1$d potential merge candidates found among your %2$s.', 'seopulse'),
                    count($merges),
                    strtolower($taxObj->labels->name),
                ),
            ];
        }

        // Recommendations
        if (count($empty) > 3) {
            $recs[] = [
                'type'    => 'action',
                'code'    => 'noindex_empty',
                'message' => __('Enable automatic noindex for empty taxonomy terms to prevent thin content indexation.', 'seopulse'),
            ];
        }

        if (!$taxObj->hierarchical && count($terms) > 50 && count($thin) > count($terms) * 0.5) {
            $recs[] = [
                'type'    => 'action',
                'code'    => 'noindex_tags',
                'message' => __('More than 50% of tags have thin content. Consider enabling noindex for all tag archives by default.', 'seopulse'),
            ];
        }

        // Score
        $totalTerms   = count($terms);
        $healthyTerms = $totalTerms - count($empty) - count($thin);
        $score        = $totalTerms > 0
            ? (int) round(($healthyTerms / $totalTerms) * 100)
            : 100;

        return [
            'taxonomy'          => $taxonomy,
            'label'             => $taxObj->labels->singular_name ?? $taxonomy,
            'plural_label'      => $taxObj->labels->name ?? $taxonomy,
            'hierarchical'      => $taxObj->hierarchical,
            'total_terms'       => $totalTerms,
            'score'             => $score,
            'terms'             => $termData,
            'orphans'           => $orphans,
            'thin_terms'        => $thin,
            'empty_terms'       => $empty,
            'deep_terms'        => $deep,
            'issues'            => $issues,
            'recommendations'   => $recs,
            'merge_suggestions' => $merges,
        ];
    }

    /**
     * Analyze a single term in detail.
     *
     * @param WP_Term $term Term object.
     * @param string $taxonomy Taxonomy slug.
     * @return array
     */
    public function analyzeTermDetail(WP_Term $term, string $taxonomy): array
    {
        $depth      = count(get_ancestors($term->term_id, $taxonomy, 'taxonomy'));
        $children   = get_term_children($term->term_id, $taxonomy);
        $childCount = is_array($children) ? count($children) : 0;

        $isEmpty  = $term->count === 0;
        $isThin   = $term->count > 0 && $term->count < self::MIN_POST_COUNT;
        $isOrphan = $term->parent === 0
            && $childCount === 0
            && $term->count < self::MIN_POST_COUNT;

        $hasDescription = !empty(trim($term->description));

        // Check if term meta already has custom SEO overrides
        $termMeta      = get_term_meta($term->term_id, '_seopulse_term_meta_seo', true);
        $hasCustomMeta = is_array($termMeta) && !empty(array_filter($termMeta));

        return [
            'term_id'         => $term->term_id,
            'name'            => $term->name,
            'slug'            => $term->slug,
            'count'           => $term->count,
            'depth'           => $depth,
            'parent'          => $term->parent,
            'child_count'     => $childCount,
            'has_description' => $hasDescription,
            'has_custom_meta' => $hasCustomMeta,
            'is_empty'        => $isEmpty,
            'is_thin'         => $isThin,
            'is_orphan'       => $isOrphan,
            'url'             => get_term_link($term),
            'edit_url'        => get_edit_term_link($term->term_id, $taxonomy),
            'seo_status'      => $this->getTermSeoStatus($term, $hasDescription, $hasCustomMeta),
        ];
    }

    // ------------------------------------------------------------------
    // Ratio Analysis
    // ------------------------------------------------------------------

    /**
     * Compute global content-to-taxonomy ratios.
     *
     * @return array
     */
    public function computeGlobalRatios(): array
    {
        $totalPosts = (int) wp_count_posts('post')->publish;

        $catTerms = get_terms(
            [
                'taxonomy'   => 'category',
                'hide_empty' => false,
                'fields'     => 'count',
            ],
        );
        $tagTerms = get_terms(
            [
                'taxonomy'   => 'post_tag',
                'hide_empty' => false,
                'fields'     => 'count',
            ],
        );

        $totalCategories = is_wp_error($catTerms) ? 0 : (int) $catTerms;
        $totalTags       = is_wp_error($tagTerms) ? 0 : (int) $tagTerms;

        $categoriesPerTenPosts = $totalPosts > 0
            ? round(($totalCategories / $totalPosts) * 10, 1)
            : 0;

        $tagsPerTenPosts = $totalPosts > 0
            ? round(($totalTags / $totalPosts) * 10, 1)
            : 0;

        return [
            'total_posts'             => $totalPosts,
            'total_categories'        => $totalCategories,
            'total_tags'              => $totalTags,
            'categories_per_10_posts' => $categoriesPerTenPosts,
            'tags_per_10_posts'       => $tagsPerTenPosts,
            'health_grade'            => $this->computeHealthGrade($categoriesPerTenPosts, $totalTags, $totalPosts),
        ];
    }

    // ------------------------------------------------------------------
    // Merge Suggestions
    // ------------------------------------------------------------------

    /**
     * Find terms that could potentially be merged (similar names).
     *
     * @param WP_Term[] $terms Array of terms.
     * @return array Array of merge suggestion pairs.
     */
    private function findMergeSuggestions(array $terms): array
    {
        $suggestions = [];
        $names       = [];

        foreach ($terms as $term) {
            $names[ $term->term_id ] = strtolower(trim($term->name));
        }

        $ids = array_keys($names);

        for ($i = 0, $len = count($ids); $i < $len; $i++) {
            for ($j = $i + 1; $j < $len; $j++) {
                $a = $names[ $ids[ $i ] ];
                $b = $names[ $ids[ $j ] ];

                // Check for near-duplicates (Levenshtein distance <= 2 or one contains the other)
                if (
                    levenshtein($a, $b) <= 2
                    || str_contains($a, $b)
                    || str_contains($b, $a)
                ) {
                    $termA = $this->findTermById($terms, $ids[ $i ]);
                    $termB = $this->findTermById($terms, $ids[ $j ]);

                    if ($termA && $termB) {
                        $suggestions[] = [
                            'term_a' => [
                                'id'    => $termA->term_id,
                                'name'  => $termA->name,
                                'count' => $termA->count,
                            ],
                            'term_b' => [
                                'id'    => $termB->term_id,
                                'name'  => $termB->name,
                                'count' => $termB->count,
                            ],
                            'reason' => levenshtein($a, $b) <= 2 ? 'similar_name' : 'name_contains',
                        ];
                    }
                }
            }
        }

        return $suggestions;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Get all public taxonomies.
     *
     * @return \WP_Taxonomy[]
     */
    public function getPublicTaxonomies(): array
    {
        $taxonomies = get_taxonomies(
            [
                'public'  => true,
                'show_ui' => true,
            ],
            'objects',
        );

        // Filter out post_format and similar internal taxonomies
        $exclude = ['post_format', 'nav_menu', 'link_category', 'wp_theme'];

        return array_filter(
            $taxonomies,
            static fn ($tax) => !in_array($tax->name, $exclude, true),
        );
    }

    /**
     * Determine the SEO status of a term.
     */
    private function getTermSeoStatus(WP_Term $term, bool $hasDescription, bool $hasCustomMeta): string
    {
        if ($term->count === 0) {
            return 'critical';
        }

        if ($hasCustomMeta && $hasDescription) {
            return 'optimized';
        }

        if ($hasDescription || $hasCustomMeta) {
            return 'partial';
        }

        if ($term->count >= self::MIN_POST_COUNT) {
            return 'default';
        }

        return 'needs_attention';
    }

    /**
     * Compute a letter grade for overall taxonomy health.
     */
    private function computeHealthGrade(float $catRatio, int $totalTags, int $totalPosts): string
    {
        $score = 100;

        // Penalize extreme category ratio
        if ($catRatio > self::IDEAL_CATEGORY_RATIO * 3) {
            $score -= 30;
        } elseif ($catRatio > self::IDEAL_CATEGORY_RATIO * 2) {
            $score -= 15;
        }

        // Penalize too many tags
        if ($totalTags > self::MAX_TAGS_THRESHOLD * 2) {
            $score -= 30;
        } elseif ($totalTags > self::MAX_TAGS_THRESHOLD) {
            $score -= 15;
        }

        // Penalize if tags outnumber posts significantly
        if ($totalPosts > 0 && $totalTags > $totalPosts * 2) {
            $score -= 20;
        }

        if ($score >= 85) {
            return 'A';
        }
        if ($score >= 70) {
            return 'B';
        }
        if ($score >= 50) {
            return 'C';
        }
        if ($score >= 30) {
            return 'D';
        }

        return 'F';
    }

    /**
     * Find a term by ID in an array.
     */
    private function findTermById(array $terms, int $id): ?WP_Term
    {
        foreach ($terms as $term) {
            if ($term->term_id === $id) {
                return $term;
            }
        }

        return null;
    }

    /**
     * Return an empty result structure.
     */
    private function emptyResult(string $taxonomy): array
    {
        return [
            'taxonomy'          => $taxonomy,
            'label'             => $taxonomy,
            'plural_label'      => $taxonomy,
            'hierarchical'      => false,
            'total_terms'       => 0,
            'score'             => 100,
            'terms'             => [],
            'orphans'           => [],
            'thin_terms'        => [],
            'empty_terms'       => [],
            'deep_terms'        => [],
            'issues'            => [],
            'recommendations'   => [],
            'merge_suggestions' => [],
        ];
    }
}
