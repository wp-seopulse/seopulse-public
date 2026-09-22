<?php

/**
 * Indexing Service Registry — orchestrates enabled indexing providers.
 *
 * Routes URL submissions to all configured providers and collects
 * per-provider result arrays for logging and UI feedback.
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Services\Indexing\GoogleIndexingSubmitter;
use SEOPulse\Services\Indexing\IndexingServiceInterface;
use SEOPulse\Services\Indexing\IndexNowSubmitter;

class IndexingServiceRegistry
{
    /**
     * @var array<string, IndexingServiceInterface> Keyed by provider ID
     *                                              (see IndexingServiceInterface::getId()). The shorthand
     *                                              `IndexingServiceInterface[]` notation is deliberately NOT used
     *                                              here — static analysers interpret it as `array<int, ...>`,
     *                                              which made `$id` in the foreach loops below infer as `int`
     *                                              even though it is always the string returned by getId().
     */
    private array $services = [];

    public function __construct()
    {
        $this->register(new IndexNowSubmitter());
        $this->register(new GoogleIndexingSubmitter());

        /**
         * Allow third-party code to register additional indexing providers
         * without having to modify this class.
         *
         * Example:
         *   add_action('seopulse_indexing_register_services', function ($registry) {
         *       $registry->register(new MyCustomSubmitter());
         *   });
         *
         * @param IndexingServiceRegistry $registry
         */
        do_action('seopulse_indexing_register_services', $this);
    }

    /**
     * Register a provider.
     *
     * @param IndexingServiceInterface $service Provider instance.
     *
     * @return void
     */
    public function register(IndexingServiceInterface $service): void
    {
        $this->services[ $service->getId() ] = $service;
    }

    /**
     * Submit a URL to all configured providers, regardless of cooldown.
     *
     * Prefer submitAllRespectingCooldown() for anything triggered by user
     * action or automation — this raw variant is mainly useful for tests
     * or explicit "force resubmit" flows.
     *
     * @param string $url Canonical URL.
     * @param string $action 'updated' | 'deleted'.
     *
     * @return array<string, array{success: bool, message: string}> Keyed by provider ID.
     */
    public function submitAll(string $url, string $action = 'updated'): array
    {
        $results = [];

        foreach ($this->services as $id => $service) {
            if (!$service->isConfigured()) {
                $results[ $id ] = [
                    'success' => false,
                    'message' => 'Not configured.',
                ];
                continue;
            }

            $results[ $id ] = $service->submit($url, $action);
        }

        return $results;
    }

    /**
     * Submit a URL to all configured providers, skipping any provider that
     * already has a successful submission for this exact URL within the
     * cooldown window.
     *
     * Unlike the previous "global" cooldown check that used to happen
     * before calling submitAll(), this checks the cooldown PER PROVIDER:
     * if e.g. IndexNow succeeded five minutes ago but Google Indexing has
     * never been tried (or failed), Google will still be attempted here.
     *
     * @param string $url Canonical URL.
     * @param string $action 'updated' | 'deleted'.
     *
     * @return array<string, array{success: bool, message: string, skipped?: bool}> Keyed by provider ID.
     */
    public function submitAllRespectingCooldown(string $url, string $action = 'updated'): array
    {
        $results = [];

        foreach ($this->services as $id => $service) {
            if (!$service->isConfigured()) {
                $results[ $id ] = [
                    'success' => false,
                    'message' => 'Not configured.',
                ];
                continue;
            }

            if (IndexingLogger::wasRecentlySubmitted($url, $id)) {
                $results[ $id ] = [
                    'success' => true,
                    'skipped' => true,
                    'message' => 'Skipped: already submitted successfully to this provider recently.',
                ];
                continue;
            }

            $results[ $id ] = $service->submit($url, $action);
        }

        return $results;
    }

    /**
     * Get a specific provider by ID.
     *
     * @param string $id Provider identifier.
     *
     * @return IndexingServiceInterface|null
     */
    public function get(string $id): ?IndexingServiceInterface
    {
        return $this->services[ $id ] ?? null;
    }

    /**
     * Get all registered providers.
     *
     * @return IndexingServiceInterface[]
     */
    public function getAll(): array
    {
        return $this->services;
    }

    /**
     * Get only configured (ready) providers.
     *
     * @return IndexingServiceInterface[]
     */
    public function getConfigured(): array
    {
        return array_filter(
            $this->services,
            static fn (IndexingServiceInterface $s): bool => $s->isConfigured(),
        );
    }

    /**
     * Test all configured providers.
     *
     * @return array<string, array{success: bool, message: string}>
     */
    public function testAll(): array
    {
        $results = [];

        foreach ($this->getConfigured() as $id => $service) {
            $results[ $id ] = $service->testConnection();
        }

        return $results;
    }
}
