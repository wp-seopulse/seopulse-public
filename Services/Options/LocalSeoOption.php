<?php

/**
 * Local SEO options access service
 *
 * @package SEOPulse\Services\Options
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services\Options;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;

/**
 * LocalSeoOption class
 */
class LocalSeoOption
{
    /** @var string Service identifier for the Container */
    public const NAME_SERVICE = 'LocalSeoOption';

    /**
     * Retrieves all Local SEO settings
     *
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        return (array) get_option(Options::LOCAL_SEO, []);
    }

    /**
     * Updates Local SEO settings
     *
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(array $data): bool
    {
        return update_option(Options::LOCAL_SEO, $data);
    }

    /**
     * Checks if JSON-LD is configured
     *
     * @return bool
     */
    public function hasJsonLd(): bool
    {
        $settings = $this->getAll();

        return !empty($settings) && isset($settings['@context']);
    }

    /**
     * Retrieves the business type
     *
     * @return string|null
     */
    public function getBusinessType(): ?string
    {
        $settings = $this->getAll();

        return $settings['@type'] ?? null;
    }

    /**
     * Retrieves the business name
     *
     * @return string|null
     */
    public function getBusinessName(): ?string
    {
        $settings = $this->getAll();

        return $settings['name'] ?? null;
    }
}
