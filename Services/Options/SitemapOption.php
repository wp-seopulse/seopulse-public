<?php

/**
 * Sitemap options access service
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
 * SitemapOption class
 */
class SitemapOption
{
    /** @var string Service identifier for the Container */
    public const NAME_SERVICE = 'SitemapOption';

    /**
     * Retrieves all Sitemap settings
     *
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        return (array) get_option(Options::SITEMAP, []);
    }

    /**
     * Updates Sitemap settings
     *
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(array $data): bool
    {
        return update_option(Options::SITEMAP, $data);
    }

    /**
     * Checks if a post type is enabled in the sitemap
     *
     * @param string $postType
     * @return bool
     */
    public function isPostTypeEnabled(string $postType): bool
    {
        $options = $this->getAll();

        return !empty($options[ "enable_{$postType}" ]);
    }

    /**
     * Checks if WP core sitemaps should be disabled
     *
     * @return bool
     */
    public function shouldDisableWpCoreSitemaps(): bool
    {
        $options = $this->getAll();

        return !empty($options['disable_wp_core_sitemaps']);
    }

    /**
     * Checks if images should be included in the sitemap
     *
     * @return bool
     */
    public function shouldIncludeImages(): bool
    {
        $options = $this->getAll();

        return !empty($options['include_images']);
    }

    /**
     * Checks if the physical robots.txt should be created
     *
     * @return bool
     */
    public function shouldCreatePhysicalRobots(): bool
    {
        $options = $this->getAll();

        return !empty($options['create_physical_robots']);
    }
}
