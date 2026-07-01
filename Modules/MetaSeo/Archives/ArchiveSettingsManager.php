<?php

/**
 * Archive Settings Manager.
 *
 * Central service for storing, retrieving and providing defaults
 * for all archive-type SEO settings (author, date, search, 404).
 *
 * @package SEOPulse\Modules\MetaSeo\Archives
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Archives;

if (!defined('ABSPATH')) {
    exit;
}

final class ArchiveSettingsManager
{
    /** @var string Option key for archive settings. */
    public const OPTION_KEY = 'seopulse_archive_settings';

    // ------------------------------------------------------------------
    // Default configuration per archive type
    // ------------------------------------------------------------------

    /**
     * Get full default settings structure.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getDefaults(): array
    {
        return [
            'author'    => [
                'enabled'               => true,
                'title'                 => '{{author.name}} {{sep}} {{site.name}}',
                'description'           => '{{truncate:160:author.bio | site.tagline}}',
                'robots'                => 'index,follow',
                'noindex_single_author' => true,
                'noindex_empty_authors' => true,
                'disable_archives'      => false,
                'redirect_target'       => 'homepage', // homepage | blog | custom
                'redirect_custom_url'   => '',
                'redirect_type'         => 301,
            ],
            'date'      => [
                'enabled'             => true,
                'title_year'          => '{{archive.year}} {{sep}} {{site.name}}',
                'title_month'         => '{{archive.month}} {{archive.year}} {{sep}} {{site.name}}',
                'title_day'           => '{{archive.day}} {{archive.month}} {{archive.year}} {{sep}} {{site.name}}',
                'description'         => '{{truncate:160:archive.description | site.tagline}}',
                'robots'              => 'noindex,follow',
                'disable_archives'    => false,
                'redirect_target'     => 'homepage',
                'redirect_custom_url' => '',
                'redirect_type'       => 301,
            ],
            'search'    => [
                'title'            => '{{search.label}} {{sep}} {{site.name}}',
                'description'      => '',
                'robots'           => 'noindex,follow',
                'block_robots_txt' => false,
                'add_nofollow'     => false,
            ],
            'error_404' => [
                'title'           => '{{error.label}} {{sep}} {{site.name}}',
                'description'     => '',
                'show_popular'    => true,
                'show_search'     => true,
                'show_strategic'  => true,
                'popular_count'   => 5,
                'strategic_pages' => [],
                'track_404'       => true,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    /**
     * Get all archive settings (merged with defaults).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAll(): array
    {
        $saved = get_option(self::OPTION_KEY, []);

        if (!is_array($saved)) {
            $saved = [];
        }

        return $this->mergeDefaults($saved);
    }

    /**
     * Get settings for a specific archive type.
     *
     * @param string $type author|date|search|error_404
     * @return array<string, mixed>
     */
    public function get(string $type): array
    {
        $all = $this->getAll();

        return $all[ $type ] ?? [];
    }

    /**
     * Update all archive settings.
     *
     * @param array<string, array<string, mixed>> $settings
     */
    public function updateAll(array $settings): bool
    {
        $sanitized = $this->sanitize($settings);

        return update_option(self::OPTION_KEY, $sanitized, false);
    }

    /**
     * Update settings for a specific archive type.
     *
     * @param string $type
     * @param array<string, mixed> $settings
     */
    public function update(string $type, array $settings): bool
    {
        $all      = $this->getAll();
        $defaults = self::getDefaults();

        if (!isset($defaults[ $type ])) {
            return false;
        }

        $all[ $type ] = array_merge($all[ $type ], $settings);

        return $this->updateAll($all);
    }

    /**
     * Reset a specific archive type to defaults.
     */
    public function reset(string $type): bool
    {
        $defaults = self::getDefaults();

        if (!isset($defaults[ $type ])) {
            return false;
        }

        $all          = $this->getAll();
        $all[ $type ] = $defaults[ $type ];

        return update_option(self::OPTION_KEY, $all, false);
    }

    /**
     * Reset all archive settings to defaults.
     */
    public function resetAll(): bool
    {
        return update_option(self::OPTION_KEY, self::getDefaults(), false);
    }

    // ------------------------------------------------------------------
    // Template integration helpers
    // ------------------------------------------------------------------

    /**
     * Get the resolved template key for archive contexts.
     *
     * Maps ContextBag data to the appropriate archive setting key.
     *
     * @param string $contextType Context type from ContextBag.
     * @param array<string, mixed> $extra Extra context data.
     * @return array{type: string, templates: array<string, string>}|null
     */
    public function getArchiveTemplates(string $contextType, array $extra = []): ?array
    {
        $settings = $this->getAll();

        switch ($contextType) {
            case 'author':
                if (!($settings['author']['enabled'] ?? true)) {
                    return null; // Disabled — redirect will handle
                }

                return [
                    'type'      => 'author',
                    'templates' => [
                        'title'       => $settings['author']['title'] ?? '',
                        'description' => $settings['author']['description'] ?? '',
                        'robots'      => $settings['author']['robots'] ?? 'index,follow',
                    ],
                ];

            case 'search':
                return [
                    'type'      => 'search',
                    'templates' => [
                        'title'       => $settings['search']['title'] ?? '',
                        'description' => $settings['search']['description'] ?? '',
                        'robots'      => $settings['search']['robots'] ?? 'noindex,follow',
                    ],
                ];

            case '404':
                return [
                    'type'      => 'error_404',
                    'templates' => [
                        'title'       => $settings['error_404']['title'] ?? '',
                        'description' => $settings['error_404']['description'] ?? '',
                        'robots'      => 'noindex,nofollow',
                    ],
                ];

            case 'archive':
                $archiveType = $extra['archive_type'] ?? '';

                if ($archiveType === 'date') {
                    if (!($settings['date']['enabled'] ?? true)) {
                        return null;
                    }

                    $day   = $extra['day'] ?? '';
                    $month = $extra['month'] ?? '';

                    // Determine granularity for title template
                    $titleKey = 'title_year';
                    if (!empty($month) && $month !== '0') {
                        $titleKey = 'title_month';
                    }
                    if (!empty($day) && $day !== '0') {
                        $titleKey = 'title_day';
                    }

                    return [
                        'type'      => 'date',
                        'templates' => [
                            'title'       => $settings['date'][ $titleKey ] ?? '',
                            'description' => $settings['date']['description'] ?? '',
                            'robots'      => $settings['date']['robots'] ?? 'noindex,follow',
                        ],
                    ];
                }

                return null; // Post-type archives handled by standard templates
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Deep-merge saved settings with defaults.
     *
     * @param array<string, mixed> $saved
     * @return array<string, array<string, mixed>>
     */
    private function mergeDefaults(array $saved): array
    {
        $defaults = self::getDefaults();
        $merged   = [];

        foreach ($defaults as $type => $typeDefaults) {
            $merged[ $type ] = array_merge(
                $typeDefaults,
                is_array($saved[ $type ] ?? null) ? $saved[ $type ] : [],
            );
        }

        return $merged;
    }

    /**
     * Sanitize the full settings array.
     *
     * @param array<string, mixed> $settings
     * @return array<string, array<string, mixed>>
     */
    private function sanitize(array $settings): array
    {
        $defaults  = self::getDefaults();
        $sanitized = [];

        foreach ($defaults as $type => $typeDefaults) {
            if (!isset($settings[ $type ]) || !is_array($settings[ $type ])) {
                $sanitized[ $type ] = $typeDefaults;
                continue;
            }

            $sanitized[ $type ] = [];

            foreach ($typeDefaults as $key => $defaultValue) {
                $value = $settings[ $type ][ $key ] ?? $defaultValue;

                $sanitized[ $type ][ $key ] = match (true) {
                    is_bool($defaultValue)   => (bool) $value,
                    is_int($defaultValue)    => (int) $value,
                    is_array($defaultValue)  => is_array($value) ? array_map('sanitize_text_field', $value) : $defaultValue,
                    is_string($defaultValue) => wp_kses((string) $value, []),
                    default                  => $value,
                };
            }
        }

        return $sanitized;
    }
}
