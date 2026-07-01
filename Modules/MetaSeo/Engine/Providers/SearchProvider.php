<?php

/**
 * Search variable provider (search.*).
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
 * SearchProvider — resolves search.* variables.
 */
final class SearchProvider implements VariableProviderInterface
{
    private const VARIABLES = ['query', 'count', 'label'];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        return match ($variable) {
            'query' => $context->getSearchQuery() ?? '',
            'count' => $this->getResultCount(),
            'label' => $this->getLabel($context),
            default => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('query', 'Search query string', 'wordpress seo', 'search'),
            new VariableDefinition('count', 'Number of search results', '42', 'search'),
            new VariableDefinition('label', 'Formatted label: "X results for Y"', '42 results for "wordpress seo"', 'search'),
        ];
    }

    private function getResultCount(): string
    {
        global $wp_query;

        return ($wp_query instanceof \WP_Query) ? (string) $wp_query->found_posts : '0';
    }

    private function getLabel(ContextBag $context): string
    {
        $query = $context->getSearchQuery() ?? '';
        $count = $this->getResultCount();

        return sprintf(
            /* translators: 1: result count, 2: search query */
            _n(
                '%1$s result for "%2$s"',
                '%1$s results for "%2$s"',
                (int) $count,
                'seopulse',
            ),
            $count,
            $query,
        );
    }
}
