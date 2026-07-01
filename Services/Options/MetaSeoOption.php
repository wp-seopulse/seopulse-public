<?php

/**
 * Meta SEO options access service
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
 * MetaSeoOption class
 */
class MetaSeoOption
{
    /** @var string Service identifier for the Container */
    public const NAME_SERVICE = 'MetaSeoOption';

    /**
     * Retrieves global Meta SEO settings
     *
     * @return array<string, mixed>
     */
    public function getGlobal(): array
    {
        return (array) get_option(Options::META_SEO_GLOBAL, []);
    }

    /**
     * Updates global Meta SEO settings
     *
     * @param array<string, mixed> $data
     * @return bool
     */
    public function updateGlobal(array $data): bool
    {
        return update_option(Options::META_SEO_GLOBAL, $data);
    }

    /**
     * Retrieves tracking settings
     *
     * @deprecated 2.1.0 Use AnalyticsOption::getTracking() — tracking now lives in analytics settings.
     * @return array<string, mixed>
     */
    public function getTracking(): array
    {
        $analytics = (array) get_option(Options::ANALYTICS, []);

        return array_intersect_key($analytics, array_flip(['gtm_enabled', 'gtm_id', 'ga4_enabled', 'ga4_id']));
    }

    /**
     * Updates tracking settings
     *
     * @deprecated 2.1.0 Use AnalyticsOption::updateTracking() — tracking now lives in analytics settings.
     * @param array<string, mixed> $data
     * @return bool
     */
    public function updateTracking(array $data): bool
    {
        $analytics = (array) get_option(Options::ANALYTICS, []);
        $analytics = array_merge($analytics, $data);

        return update_option(Options::ANALYTICS, $analytics);
    }

    /**
     * Default global title
     *
     * @return string
     */
    public function getGlobalTitle(): string
    {
        $global = $this->getGlobal();

        return (string) ($global['title'] ?? '');
    }

    /**
     * Default global description
     *
     * @return string
     */
    public function getGlobalDescription(): string
    {
        $global = $this->getGlobal();

        return (string) ($global['description'] ?? '');
    }

    /**
     * Global robots
     *
     * @return string
     */
    public function getGlobalRobots(): string
    {
        $global = $this->getGlobal();

        return (string) ($global['robots'] ?? '');
    }

    /**
     * Should the WP generator be removed?
     *
     * @return bool
     */
    public function shouldRemoveGenerator(): bool
    {
        $global = $this->getGlobal();

        return !empty($global['remove_generator']);
    }
}
