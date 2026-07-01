<?php

/**
 * Schema provider interface for JSON-LD schema generation
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SchemaProvider interface — all schema providers must implement this
 */
interface SchemaProvider
{
    /**
     * Get the schema type (e.g., 'Article', 'BlogPosting', 'Organization')
     *
     * @return string
     */
    public function get_type(): string;

    /**
     * Build and return the JSON-LD schema array
     *
     * @return array<string, mixed>
     */
    public function build(): array;

    /**
     * Validate the schema structure
     *
     * @return bool Whether the schema is valid
     */
    public function validate(): bool;

    /**
     * Get error message if validation failed
     *
     * @return string|null
     */
    public function get_error(): ?string;

    /**
     * Check if this provider should inject on the current request
     *
     * @return bool
     */
    public function should_inject(): bool;
}
