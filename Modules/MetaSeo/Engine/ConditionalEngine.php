<?php

/**
 * ConditionalEngine
 *
 * Evaluates conditional AST nodes by resolving the referenced variable
 * and selecting the appropriate branch.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

final class ConditionalEngine
{
    /**
     * Evaluate a conditional node.
     *
     * @param TemplateNode $node The CONDITIONAL node.
     * @param VariableRegistry $registry Variable registry for resolution.
     * @param ContextBag $context Current context.
     * @param TemplateParser $parser Parser instance (for recursive evaluation).
     * @return string Resolved output of the selected branch.
     */
    public function evaluate(
        TemplateNode $node,
        VariableRegistry $registry,
        ContextBag $context,
        TemplateParser $parser,
    ): string {
        $condition = $node->condition;

        if ($condition === null) {
            return '';
        }

        $value = $registry->resolve(
            $condition['namespace'] ?? 'global',
            $condition['variable'] ?? '',
            $context,
        );

        // Truthiness: non-empty, not "0", not "false"
        $truthy = $value !== null && $value !== '' && $value !== '0' && strtolower($value) !== 'false';

        if (!empty($condition['negated'])) {
            $truthy = !$truthy;
        }

        $branch = $truthy ? $node->children : $node->elseChildren;

        return $parser->evaluate($branch, $registry, $context);
    }
}
