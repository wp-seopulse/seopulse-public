<?php

/**
 * AST node types for parsed templates.
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
 * NodeType enum
 *
 * Classifies nodes in the abstract syntax tree produced by TemplateParser.
 */
enum NodeType
{
    /** Literal text node */
    case TEXT;

    /** Variable reference node (possibly with functions and fallbacks) */
    case VARIABLE;

    /** Conditional block node (if / else / endif) */
    case CONDITIONAL;
}
