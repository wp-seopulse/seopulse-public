<?php

/**
 * High-performance template parser using tokenization.
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
 * TemplateParser
 *
 * Single-pass tokenizer that produces an AST for efficient evaluation
 * and caching. Supports nested conditions, function chaining, and
 * fallback variable chains.
 *
 * Architecture decision: Tokenizer > Regex because:
 * - Nested conditions require recursive parsing (regex can't handle).
 * - Function chaining needs proper left-to-right evaluation.
 * - The AST enables caching the parsed structure (only values change).
 * - Better error reporting for malformed templates.
 *
 * @since 1.0.0
 */
final class TemplateParser
{
    private FunctionProcessor $functionProcessor;
    private ConditionalEngine $conditionalEngine;

    public function __construct(
        FunctionProcessor $functionProcessor,
        ConditionalEngine $conditionalEngine,
    ) {
        $this->functionProcessor = $functionProcessor;
        $this->conditionalEngine = $conditionalEngine;
    }

    /**
     * Expose the internal FunctionProcessor (used by MetaEngine).
     */
    public function getFunctionProcessor(): FunctionProcessor
    {
        return $this->functionProcessor;
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Parse a template string into an AST (array of TemplateNode).
     *
     * @param string $template e.g. "%%post.title%% %%sep%% %%site.name%%"
     * @return TemplateNode[]
     */
    public function parse(string $template): array
    {
        $tokens = $this->tokenize($template);

        return $this->buildAst($tokens);
    }

    /**
     * Evaluate an AST against a context using the variable registry.
     *
     * @param TemplateNode[] $ast
     * @param VariableRegistry $registry
     * @param ContextBag $context
     * @return string
     */
    public function evaluate(array $ast, VariableRegistry $registry, ContextBag $context): string
    {
        $output = '';

        foreach ($ast as $node) {
            $output .= match ($node->type) {
                NodeType::TEXT        => $node->value,
                NodeType::VARIABLE    => $this->evaluateVariable($node, $registry, $context),
                NodeType::CONDITIONAL => $this->conditionalEngine->evaluate($node, $registry, $context, $this),
            };
        }

        return $output;
    }

    // ------------------------------------------------------------------
    // Tokenizer — single pass, O(n)
    // ------------------------------------------------------------------

    /**
     * Tokenize input into raw tokens.
     *
     * @return Token[]
     */
    private function tokenize(string $input): array
    {
        $tokens = [];
        $length = strlen($input);
        $pos    = 0;

        while ($pos < $length) {
            $nextOpen = strpos($input, '%%', $pos);

            if ($nextOpen === false) {
                $tokens[] = new Token(TokenType::TEXT, substr($input, $pos));
                break;
            }

            // Text before the tag
            if ($nextOpen > $pos) {
                $tokens[] = new Token(TokenType::TEXT, substr($input, $pos, $nextOpen - $pos));
            }

            // Find closing %%
            $nextClose = strpos($input, '%%', $nextOpen + 2);

            if ($nextClose === false) {
                // Malformed: treat rest as text
                $tokens[] = new Token(TokenType::TEXT, substr($input, $nextOpen));
                break;
            }

            $content  = trim(substr($input, $nextOpen + 2, $nextClose - $nextOpen - 2));
            $tokens[] = $this->classifyToken($content);

            $pos = $nextClose + 2;
        }

        return $tokens;
    }

    /**
     * Classify a token's content into the appropriate TokenType.
     */
    private function classifyToken(string $content): Token
    {
        // Conditional tokens
        if (str_starts_with($content, 'if ') || str_starts_with($content, 'if not ')) {
            return new Token(TokenType::IF_OPEN, $content);
        }

        if ($content === 'else') {
            return new Token(TokenType::ELSE, $content);
        }

        if ($content === 'endif') {
            return new Token(TokenType::IF_CLOSE, $content);
        }

        // Variable (possibly with functions and/or fallbacks)
        return new Token(TokenType::EXPRESSION, $content);
    }

    // ------------------------------------------------------------------
    // AST builder
    // ------------------------------------------------------------------

    /**
     * Build AST from flat token list. Handles nesting of conditionals.
     *
     * @param Token[] $tokens
     * @return TemplateNode[]
     */
    private function buildAst(array $tokens): array
    {
        $nodes      = [];
        $tokenCount = count($tokens);
        $i          = 0;

        while ($i < $tokenCount) {
            $token = $tokens[ $i ];

            switch ($token->type) {
                case TokenType::TEXT:
                    $nodes[] = new TemplateNode(NodeType::TEXT, $token->value);
                    break;

                case TokenType::EXPRESSION:
                    $nodes[] = $this->parseExpression($token->value);
                    break;

                case TokenType::IF_OPEN:
                    [$condNode, $consumed] = $this->parseConditional($tokens, $i);
                    $nodes[]               = $condNode;
                    $i                    += $consumed;
                    break;

                default:
                    // stray ELSE / IF_CLOSE outside a conditional — treat as text
                    $nodes[] = new TemplateNode(NodeType::TEXT, '%%' . $token->value . '%%');
                    break;
            }

            ++$i;
        }

        return $nodes;
    }

    // ------------------------------------------------------------------
    // Expression parsing
    // ------------------------------------------------------------------

    /**
     * Parse a variable expression with optional functions and fallbacks.
     *
     * Supports:
     *   "post.title"                   → simple variable
     *   "uppercase:post.title"         → function applied
     *   "truncate:60:post.title"       → function with arg
     *   "post.excerpt | post.content"  → fallback chain
     *   "acf:field_name"               → parameterised variable
     */
    private function parseExpression(string $expr): TemplateNode
    {
        // --- Fallback chains (pipe separated) ---
        if (str_contains($expr, '|')) {
            $parts     = array_map('trim', explode('|', $expr));
            $fallbacks = array_map(fn (string $p) => $this->parseExpression($p), $parts);

            return new TemplateNode(NodeType::VARIABLE, $expr, fallbacks: $fallbacks);
        }

        // --- Function prefix (contains : and first segment is a known function) ---
        if (str_contains($expr, ':')) {
            $colonParts = explode(':', $expr);

            if ($this->functionProcessor->isFunction($colonParts[0])) {
                return $this->parseFunctionExpression($colonParts);
            }
        }

        // --- Simple variable: "post.title" or "sep" or "acf:field_name" ---
        [$namespace, $variable] = $this->splitVariable($expr);

        return new TemplateNode(
            NodeType::VARIABLE,
            $expr,
            namespace: $namespace,
            variable: $variable,
        );
    }

    /**
     * Parse a function-prefixed expression.
     *
     * "uppercase:post.title"       → fn=uppercase,  arg=[], inner=post.title
     * "truncate:60:post.title"     → fn=truncate,   arg=[60], inner=post.title
     *
     * @param string[] $parts Colon-exploded expression segments.
     */
    private function parseFunctionExpression(array $parts): TemplateNode
    {
        $fnName = array_shift($parts);

        // Last part is always the variable reference.
        $innerExpr = array_pop($parts);
        $fnArgs    = $parts; // remaining parts = function arguments

        $innerNode = $this->parseExpression((string) $innerExpr);

        return new TemplateNode(
            NodeType::VARIABLE,
            $fnName . ':' . implode(':', [...$fnArgs, (string) $innerExpr]),
            namespace: $innerNode->namespace,
            variable: $innerNode->variable,
            functions: [
                [
                    'name' => $fnName,
                    'args' => $fnArgs,
                ],
            ],
            children: [$innerNode],
        );
    }

    /**
     * Split "namespace.variable" or "namespace:param" into [namespace, variable].
     *
     * @return array{0: string, 1: string}
     */
    private function splitVariable(string $expr): array
    {
        // Parameterised form: "acf:field_name", "custom_field:my_key"
        if (str_contains($expr, ':')) {
            $parts = explode(':', $expr, 2);

            return [$parts[0], $parts[1]];
        }

        // Dot notation: "post.title"
        if (str_contains($expr, '.')) {
            $dotPos = strpos($expr, '.');

            return [
                substr($expr, 0, $dotPos),
                substr($expr, $dotPos + 1),
            ];
        }

        // Bare variable: "sep" → namespace=global
        return ['global', $expr];
    }

    // ------------------------------------------------------------------
    // Conditional parsing
    // ------------------------------------------------------------------

    /**
     * Parse a conditional block (if / else / endif) from the token stream.
     *
     * @return array{0: TemplateNode, 1: int} [node, tokens consumed]
     */
    private function parseConditional(array $tokens, int $startIndex): array
    {
        $token      = $tokens[ $startIndex ];
        $condition  = $this->parseConditionString($token->value);
        $thenBranch = [];
        $elseBranch = [];
        $inElse     = false;
        $depth      = 0;
        $consumed   = 0;
        $total      = count($tokens);

        for ($i = $startIndex + 1; $i < $total; $i++) {
            ++$consumed;
            $t = $tokens[ $i ];

            if ($t->type === TokenType::IF_OPEN) {
                ++$depth;
            }

            if ($t->type === TokenType::IF_CLOSE) {
                if ($depth === 0) {
                    break; // End of this conditional
                }
                --$depth;
            }

            if ($t->type === TokenType::ELSE && $depth === 0) {
                $inElse = true;
                continue;
            }

            if ($inElse) {
                $elseBranch[] = $t;
            } else {
                $thenBranch[] = $t;
            }
        }

        return [
            new TemplateNode(
                NodeType::CONDITIONAL,
                $token->value,
                condition: $condition,
                children: $this->buildAst($thenBranch),
                elseChildren: $this->buildAst($elseBranch),
            ),
            $consumed,
        ];
    }

    /**
     * Parse condition string.
     *
     * "if post.category"     → ['negated' => false, 'namespace' => 'post', 'variable' => 'category']
     * "if not post.thumbnail" → ['negated' => true,  ...]
     *
     * @return array{negated: bool, namespace: string, variable: string}
     */
    private function parseConditionString(string $raw): array
    {
        // Strip leading "if "
        $raw     = (string) preg_replace('/^if\s+/', '', $raw);
        $negated = false;

        if (str_starts_with($raw, 'not ')) {
            $negated = true;
            $raw     = substr($raw, 4);
        }

        [$namespace, $variable] = $this->splitVariable(trim($raw));

        return [
            'negated'   => $negated,
            'namespace' => $namespace,
            'variable'  => $variable,
        ];
    }

    // ------------------------------------------------------------------
    // Variable evaluation
    // ------------------------------------------------------------------

    /**
     * Evaluate a VARIABLE node to its string value.
     */
    private function evaluateVariable(
        TemplateNode $node,
        VariableRegistry $registry,
        ContextBag $context,
    ): string {
        // --- Fallback chain ---
        if (!empty($node->fallbacks)) {
            foreach ($node->fallbacks as $fallback) {
                $val = $this->evaluateVariable($fallback, $registry, $context);

                if ($val !== '') {
                    return $val;
                }
            }

            return '';
        }

        // --- Resolve the actual variable ---
        $value = '';

        if ($node->namespace !== null && $node->variable !== null) {
            $value = $registry->resolve($node->namespace, $node->variable, $context) ?? '';
        }

        // --- Apply functions (if any) ---
        foreach ($node->functions as $fn) {
            $value = $this->functionProcessor->apply(
                $fn['name'],
                $value,
                $fn['args'] ?? [],
            );
        }

        return $value;
    }
}
