<?php

/**
 * Term/taxonomy variable provider (term.*).
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\MetaSeo\Engine\ContextBag;
use SEOPulse\Modules\MetaSeo\Engine\VariableDefinition;
use SEOPulse\Modules\MetaSeo\Engine\VariableProviderInterface;
use WP_Term;

/**
 * TermProvider — resolves term.* variables.
 */
final class TermProvider implements VariableProviderInterface
{
    private const VARIABLES = [
        'name',
        'slug',
        'description',
        'count',
        'post_count',
        'parent',
        'taxonomy',
        'hierarchy',
        'url',
        'child_count',
        'depth',
    ];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        $term = $context->getTerm();

        if (!$term instanceof WP_Term) {
            return null;
        }

        return match ($variable) {
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => wp_strip_all_tags($term->description),
            'count',
            'post_count'  => (string) $term->count,
            'parent'      => $this->getParentName($term),
            'taxonomy'    => $this->getTaxonomyLabel($term->taxonomy),
            'hierarchy'   => $this->getHierarchy($term),
            'url'         => (string) get_term_link($term),
            'child_count' => $this->getChildCount($term),
            'depth'       => (string) count(get_ancestors($term->term_id, $term->taxonomy, 'taxonomy')),
            default       => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('name', 'Term name', 'Technology', 'taxonomy'),
            new VariableDefinition('slug', 'Term slug', 'technology', 'taxonomy'),
            new VariableDefinition('description', 'Term description', 'All about technology', 'taxonomy'),
            new VariableDefinition('count', 'Number of posts in this term', '42', 'taxonomy'),
            new VariableDefinition('post_count', 'Number of posts (alias for count)', '42', 'taxonomy'),
            new VariableDefinition('parent', 'Parent term name', 'Science', 'taxonomy'),
            new VariableDefinition('taxonomy', 'Taxonomy label', 'Categories', 'taxonomy'),
            new VariableDefinition('hierarchy', 'Full breadcrumb path', 'Science > Technology', 'taxonomy'),
            new VariableDefinition('url', 'Term archive URL', 'https://example.com/category/technology/', 'taxonomy'),
            new VariableDefinition('child_count', 'Number of child terms', '5', 'taxonomy'),
            new VariableDefinition('depth', 'Hierarchy depth level (0 = top-level)', '1', 'taxonomy'),
        ];
    }

    private function getParentName(WP_Term $term): string
    {
        if ($term->parent === 0) {
            return '';
        }

        $parent = get_term($term->parent, $term->taxonomy);

        return ($parent instanceof WP_Term) ? $parent->name : '';
    }

    private function getTaxonomyLabel(string $taxonomy): string
    {
        $taxObj = get_taxonomy($taxonomy);

        return $taxObj ? $taxObj->labels->singular_name : $taxonomy;
    }

    private function getHierarchy(WP_Term $term): string
    {
        $ancestors = get_ancestors($term->term_id, $term->taxonomy, 'taxonomy');
        $names     = [];

        foreach (array_reverse($ancestors) as $ancId) {
            $anc = get_term($ancId, $term->taxonomy);
            if ($anc instanceof WP_Term) {
                $names[] = $anc->name;
            }
        }

        $names[] = $term->name;

        return implode(' > ', $names);
    }

    private function getChildCount(WP_Term $term): string
    {
        $children = get_term_children($term->term_id, $term->taxonomy);

        return (string) (is_array($children) ? count($children) : 0);
    }
}
