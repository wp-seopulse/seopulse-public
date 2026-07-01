<?php

/**
 * Template storage and priority resolution.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Constants\PostMeta;
use SEOPulse\Modules\I18n\LanguageDetector;
use SEOPulse\Modules\MetaSeo\Archives\ArchiveSettingsManager;

/**
 * @since 1.0.0
 */

/**
 * TemplateStore
 *
 * Manages template persistence and implements the five-level
 * priority system:
 *
 *  1. Term-specific / Post-specific meta override  (term_meta / post_meta)
 *  2. Content-type template                         (option: post / page / product …)
 *  2.5. Taxonomy-type template                      (option: taxonomy_category / taxonomy_post_tag …)
 *  3. Global template                               (option: global)
 *  4. Built-in fallback                             (constant, always valid)
 *
 * @since 1.0.0
 */
final class TemplateStore
{
    // ------------------------------------------------------------------
    // Default fallback templates (level 4 — always produce output)
    // ------------------------------------------------------------------

    /** @var array<string, string> */
    private const FALLBACKS = [
        'title'               => '%%post.title%% %%sep%% %%site.name%%',
        'description'         => '%%truncate:160:post.excerpt | site.tagline%%',
        'og_title'            => '%%post.title%%',
        'og_description'      => '%%truncate:200:post.excerpt | site.tagline%%',
        'og_image'            => '%%post.thumbnail%%',
        'og_type'             => 'article',
        'twitter_title'       => '%%post.title%%',
        'twitter_description' => '%%truncate:200:post.excerpt | site.tagline%%',
        'twitter_image'       => '%%post.thumbnail%%',
        'twitter_card'        => 'summary_large_image',
        'canonical'           => '%%post.url%%',
        'robots'              => 'index,follow',
    ];

    /** @var array<string, array<string, string>> */
    private const CONTEXT_FALLBACKS = [
        'home'     => [
            'title'       => '%%site.name%% %%sep%% %%site.tagline%%',
            'description' => '%%site.tagline%%',
            'og_type'     => 'website',
            'canonical'   => '%%site.url%%',
        ],
        'taxonomy' => [
            'title'       => '%%term.name%% %%sep%% %%site.name%%',
            'description' => '%%truncate:160:term.description | site.tagline%%',
            'canonical'   => '%%term.url%%',
        ],
        'author'   => [
            'title'       => '%%author.name%% %%sep%% %%site.name%%',
            'description' => '%%truncate:160:author.bio | site.tagline%%',
            'canonical'   => '%%author.url%%',
        ],
        'search'   => [
            'title'       => '%%search.label%% %%sep%% %%site.name%%',
            'description' => '',
            'robots'      => 'noindex,follow',
            'canonical'   => '',
        ],
        '404'      => [
            'title'       => '%%error.label%% %%sep%% %%site.name%%',
            'description' => '',
            'robots'      => 'noindex,nofollow',
            'canonical'   => '',
        ],
        'archive'  => [
            'title'       => '%%archive.title%% %%sep%% %%site.name%%',
            'description' => '%%truncate:160:archive.description%%',
            'canonical'   => '%%env.url%%',
        ],
    ];

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Get the resolved template map for a given context, applying priority.
     *
     * @return array<string, string> field → template string
     */
    public function getTemplatesForContext(ContextBag $context): array
    {
        $contextType = $context->getType();

        // Level 4: Built-in fallbacks
        $templates = array_merge(
            self::FALLBACKS,
            self::CONTEXT_FALLBACKS[ $contextType ] ?? [],
        );

        // Level 3.5: Archive-specific templates from ArchiveSettingsManager
        $templates = $this->mergeArchiveTemplates($templates, $context);

        // Level 3: User-configured global templates
        $globalTemplates = get_option(Options::META_TEMPLATES, []);

        if (is_array($globalTemplates) && !empty($globalTemplates['global'])) {
            $templates = $this->mergeNonEmpty($templates, $globalTemplates['global']);
        }

        // Level 3 (language override): per-language global templates
        if (LanguageDetector::is_multilingual()) {
            $lang         = LanguageDetector::current();
            $langOption   = Options::META_TEMPLATES . '_' . $lang;
            $langOverride = get_option($langOption, []);

            if (is_array($langOverride) && !empty($langOverride['global'])) {
                $templates = $this->mergeNonEmpty($templates, $langOverride['global']);
            }
        }

        // Global robots + OG/Twitter fields from Global Settings tab
        // (saved by OpenGraphPanel / TwitterPanel to META_SEO_GLOBAL option)
        $legacyGlobal = get_option(Options::META_SEO_GLOBAL, []);

        if (is_array($legacyGlobal)) {
            if (!empty($legacyGlobal['robots'])) {
                $templates['robots'] = (string) $legacyGlobal['robots'];
            }

            // Bridge OG & Twitter global defaults into the template map
            $og_twitter_fields = [
                'og_title', 'og_description', 'og_image', 'og_type',
                'twitter_card', 'twitter_title', 'twitter_description', 'twitter_image',
            ];
            foreach ($og_twitter_fields as $field) {
                if (!empty($legacyGlobal[ $field ])) {
                    $templates[ $field ] = (string) $legacyGlobal[ $field ];
                }
            }
        }

        // Level 2.5: Taxonomy-type templates (e.g. taxonomy_category, taxonomy_post_tag)
        $taxonomySlug = $this->getTaxonomyFromContext($context);

        if ($taxonomySlug !== null && is_array($globalTemplates)) {
            $taxKey = 'taxonomy_' . $taxonomySlug;

            if (!empty($globalTemplates[ $taxKey ])) {
                $templates = $this->mergeNonEmpty($templates, $globalTemplates[ $taxKey ]);
            }
        }

        // Apply taxonomy-specific noindex/nofollow settings
        $templates = $this->applyTaxonomyRobotsSettings($templates, $context, $taxonomySlug);

        // Level 2: Content-type templates
        $postType = $this->getPostTypeFromContext($context);

        if ($postType !== null && is_array($globalTemplates) && !empty($globalTemplates[ $postType ])) {
            $templates = $this->mergeNonEmpty($templates, $globalTemplates[ $postType ]);
        }

        // Level 1: Post-specific meta overrides
        if ($context->getPost() !== null) {
            $postMeta = get_post_meta($context->getPost()->ID, PostMeta::META_SEO, true);

            if (is_array($postMeta)) {
                $templates = $this->mergeNonEmpty($templates, $postMeta);
            }
        }

        // Term-specific overrides
        if ($context->getTerm() !== null) {
            $termMeta = get_term_meta($context->getTerm()->term_id, '_seopulse_term_meta_seo', true);

            if (is_array($termMeta)) {
                $templates = $this->mergeNonEmpty($templates, $termMeta);
            }
        }

        // Pagination suffix for titles
        if ($context->getPage() > 1) {
            $templates['title'] .= ' %%sep%% %%page.label%%';
        }

        return $templates;
    }

    /**
     * Get the raw default fallback templates (for admin UI display).
     *
     * @return array<string, string>
     */
    public function getDefaultTemplates(string $contextType = 'singular'): array
    {
        return array_merge(
            self::FALLBACKS,
            self::CONTEXT_FALLBACKS[ $contextType ] ?? [],
        );
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Merge arrays, only overriding with non-empty string values.
     *
     * @param array<string, string> $base
     * @param array<string, mixed> $override
     * @return array<string, string>
     */
    private function mergeNonEmpty(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_string($value) && $value !== '') {
                $base[ $key ] = $value;
            }
        }

        return $base;
    }

    /**
     * Extract post type from context.
     */
    private function getPostTypeFromContext(ContextBag $context): ?string
    {
        if ($context->getPost() !== null) {
            return $context->getPost()->post_type;
        }

        $postType = $context->getExtra('post_type');

        return is_string($postType) ? $postType : null;
    }

    /**
     * Extract taxonomy slug from context.
     */
    private function getTaxonomyFromContext(ContextBag $context): ?string
    {
        if ($context->getType() !== 'taxonomy') {
            return null;
        }

        // First check extras
        $taxSlug = $context->getExtra('taxonomy');

        if (is_string($taxSlug) && $taxSlug !== '') {
            return $taxSlug;
        }

        // Fallback: derive from term object
        $term = $context->getTerm();

        if ($term instanceof \WP_Term) {
            return $term->taxonomy;
        }

        return null;
    }

    /**
     * Apply taxonomy-specific robots settings (noindex_empty, noindex_thin, nofollow).
     *
     * @param array<string, string> $templates Current template map.
     * @param ContextBag $context Current context.
     * @param string|null $taxonomySlug Taxonomy slug.
     * @return array<string, string>
     */
    private function applyTaxonomyRobotsSettings(array $templates, ContextBag $context, ?string $taxonomySlug): array
    {
        if ($taxonomySlug === null || $context->getType() !== 'taxonomy') {
            return $templates;
        }

        $term = $context->getTerm();

        if (!$term instanceof \WP_Term) {
            return $templates;
        }

        $taxSettings = get_option('seopulse_taxonomy_settings', []);
        $settings    = $taxSettings[ $taxonomySlug ] ?? [];

        if (empty($settings)) {
            return $templates;
        }

        $shouldNoindex = false;

        // Noindex empty terms
        if (!empty($settings['noindex_empty']) && $term->count === 0) {
            $shouldNoindex = true;
        }

        // Noindex thin terms
        if (!empty($settings['noindex_thin'])) {
            $threshold = (int) ($settings['thin_threshold'] ?? 3);
            if ($term->count > 0 && $term->count < $threshold) {
                $shouldNoindex = true;
            }
        }

        if ($shouldNoindex) {
            $nofollow            = !empty($settings['nofollow']) ? 'nofollow' : 'follow';
            $templates['robots'] = 'noindex,' . $nofollow;
        } elseif (!empty($settings['nofollow'])) {
            // Apply nofollow even if not noindex
            $currentRobots       = $templates['robots'] ?? 'index,follow';
            $templates['robots'] = str_replace('follow', 'nofollow', $currentRobots);
            // Avoid double-nofollow
            $templates['robots'] = str_replace('nonofollow', 'nofollow', $templates['robots']);
        }

        return $templates;
    }

    /**
     * Merge archive-specific templates from ArchiveSettingsManager.
     *
     * Applies customised templates for author, date, search and 404
     * contexts. These override the built-in CONTEXT_FALLBACKS but are
     * still overridden by user-configured global/CPT templates (Level 3/2).
     *
     * @param array<string, string> $templates Current template map.
     * @param ContextBag $context Current context.
     * @return array<string, string>
     */
    private function mergeArchiveTemplates(array $templates, ContextBag $context): array
    {
        try {
            $manager  = new ArchiveSettingsManager();
            $resolved = $manager->getArchiveTemplates(
                $context->getType(),
                [
                    'archive_type' => $context->getExtra('archive_type', ''),
                    'year'         => $context->getExtra('year', ''),
                    'month'        => $context->getExtra('month', ''),
                    'day'          => $context->getExtra('day', ''),
                ],
            );

            if ($resolved !== null && !empty($resolved['templates'])) {
                $templates = $this->mergeNonEmpty($templates, $resolved['templates']);
            }
        } catch (\Throwable $e) {
            // Silently fallback to built-in defaults
        }

        return $templates;
    }
}
