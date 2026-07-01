<?php

/**
 * General options access service
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
 * GeneralOption class
 */
class GeneralOption
{
    /** @var string Service identifier for the Container */
    public const NAME_SERVICE = 'GeneralOption';

    /**
     * Retrieves all general options
     *
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        return (array) get_option(Options::GENERAL, []);
    }

    /**
     * Updates general options
     *
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(array $data): bool
    {
        return update_option(Options::GENERAL, $data);
    }

    /**
     * Retrieves a specific option with default value
     *
     * @param string $key Option key
     * @param mixed $default Default value
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->getAll();

        return $all[ $key ] ?? $default;
    }

    public function isAiEnabled(): bool
    {
        return (bool) $this->get('ai_enabled', false);
    }

    public function getAiProvider(): string
    {
        return (string) $this->get('ai_provider', 'openai');
    }

    public function getTargetScore(): int
    {
        return (int) $this->get('target_score', 80);
    }

    public function getCacheDuration(): int
    {
        return (int) $this->get('cache_duration', 3600);
    }

    public function isAnalyzeOnSave(): bool
    {
        return (bool) $this->get('analyze_on_save', true);
    }

    public function isShowAdminBarScore(): bool
    {
        return (bool) $this->get('show_admin_bar_score', true);
    }

    public function getContentMinWords(): int
    {
        return (int) $this->get('content_min_words', 300);
    }

    public function getReadabilityTarget(): int
    {
        return (int) $this->get('readability_target', 60);
    }
}
