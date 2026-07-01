<?php

/**
 * Token types for the template tokenizer.
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
 * TokenType enum
 *
 * Classifies raw tokens produced by the TemplateParser tokenizer.
 */
enum TokenType
{
    /** Literal text outside {{ }} */
    case TEXT;

    /** A variable expression: "post.title", "uppercase:post.title", "a | b" */
    case EXPRESSION;

    /** Opening conditional: "if post.category", "if not post.thumbnail" */
    case IF_OPEN;

    /** Else branch */
    case ELSE;

    /** Closing conditional */
    case IF_CLOSE;
}
