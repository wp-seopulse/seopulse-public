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
    /** @var IndexingServiceInterface[] */
    private array $services = [];

    public function __construct()
    {
        $this->register(new IndexNowSubmitter());
        $this->register(new GoogleIndexingSubmitter());
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
     * Submit a URL to all configured providers.
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
