<?php

/**
 * Raw token produced by the tokenizer.
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
 * Token value object
 *
 * Represents a single token output by the TemplateParser tokenizer.
 */
final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $value,
    ) {
    }
}
