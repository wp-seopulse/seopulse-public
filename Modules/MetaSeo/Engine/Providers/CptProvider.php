<?php

/**
 * Custom Post Type variable provider (cpt.*).
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

/**
 * CptProvider — resolves cpt.* variables.
 */
final class CptProvider implements VariableProviderInterface
{
    private const VARIABLES = ['singular', 'plural', 'slug', 'count', 'description'];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        $postType = $this->getPostType($context);

        if ($postType === null) {
            return null;
        }

        $obj = get_post_type_object($postType);

        if ($obj === null) {
            return null;
        }

        return match ($variable) {
            'singular'    => $obj->labels->singular_name ?? $postType,
            'plural'      => $obj->labels->name ?? $postType,
            'slug'        => $obj->rewrite['slug'] ?? $postType,
            'count'       => $this->getCount($postType),
            'description' => (string) $obj->description,
            default       => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('singular', 'CPT singular name', 'Product', 'cpt'),
            new VariableDefinition('plural', 'CPT plural name', 'Products', 'cpt'),
            new VariableDefinition('slug', 'CPT archive slug', 'products', 'cpt'),
            new VariableDefinition('count', 'Total published posts in this CPT', '150', 'cpt'),
            new VariableDefinition('description', 'CPT description', 'All products', 'cpt'),
        ];
    }

    private function getPostType(ContextBag $context): ?string
    {
        if ($context->getPost() !== null) {
            return $context->getPost()->post_type;
        }

        $extra = $context->getExtra('post_type');

        return is_string($extra) && $extra !== '' ? $extra : null;
    }

    private function getCount(string $postType): string
    {
        $counts = wp_count_posts($postType);

        return (string) ($counts->publish ?? 0);
    }
}
