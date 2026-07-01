<?php

/**
 * AST node for parsed templates.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * TemplateNode value object
 *
 * Represents a single node in the abstract syntax tree produced
 * by TemplateParser::parse().
 *
 * @since 1.0.0
 */
final class TemplateNode
{
    /**
     * @param NodeType $type Node classification
     * @param string $value Raw source text
     * @param string|null $namespace Variable namespace (e.g. "post")
     * @param string|null $variable Variable name (e.g. "title")
     * @param string[] $params Extra parameters for parameterized variables
     * @param array[] $functions List of [{name: string, args: string[]}]
     * @param TemplateNode[] $children Then-branch (conditionals) or inner node (functions)
     * @param TemplateNode[] $elseChildren Else-branch for conditionals
     * @param TemplateNode[] $fallbacks Fallback variable chain (pipe-separated)
     * @param array|null $condition {negated: bool, namespace: string, variable: string, params: string[]}
     */
    public function __construct(
        public readonly NodeType $type,
        public readonly string $value,
        public readonly ?string $namespace = null,
        public readonly ?string $variable = null,
        public readonly array $params = [],
        public readonly array $functions = [],
        public readonly array $children = [],
        public readonly array $elseChildren = [],
        public readonly array $fallbacks = [],
        public readonly ?array $condition = null,
    ) {
    }
}
