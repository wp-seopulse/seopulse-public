<?php

/**
 * Pagination variable provider (page.*).
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
 * PageProvider — resolves page.* variables (pagination).
 */
final class PageProvider implements VariableProviderInterface
{
    private const VARIABLES = ['number', 'total', 'label', 'next_url', 'prev_url'];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        return match ($variable) {
            'number'   => (string) $context->getPage(),
            'total'    => (string) $context->getTotalPages(),
            'label'    => $this->getLabel($context),
            'next_url' => $this->getNextUrl($context),
            'prev_url' => $this->getPrevUrl($context),
            default    => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('number', 'Current page number', '2', 'pagination'),
            new VariableDefinition('total', 'Total number of pages', '5', 'pagination'),
            new VariableDefinition('label', 'Formatted label: "Page X of Y"', 'Page 2 of 5', 'pagination'),
            new VariableDefinition('next_url', 'URL of the next page', 'https://example.com/page/3/', 'pagination'),
            new VariableDefinition('prev_url', 'URL of the previous page', 'https://example.com/page/1/', 'pagination'),
        ];
    }

    private function getLabel(ContextBag $context): string
    {
        return sprintf(
            /* translators: 1: current page number, 2: total pages */
            __('Page %1$d of %2$d', 'seopulse'),
            $context->getPage(),
            max(1, $context->getTotalPages()),
        );
    }

    private function getNextUrl(ContextBag $context): string
    {
        if ($context->getPage() >= $context->getTotalPages()) {
            return '';
        }

        return (string) get_pagenum_link($context->getPage() + 1);
    }

    private function getPrevUrl(ContextBag $context): string
    {
        if ($context->getPage() <= 1) {
            return '';
        }

        return (string) get_pagenum_link($context->getPage() - 1);
    }
}
