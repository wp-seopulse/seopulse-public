<?php

/**
 * Common interface for all indexing/submission services.
 *
 * Each provider (IndexNow, Google Indexing API, etc.) implements this
 * contract so the registry can treat them uniformly.
 *
 * @package SEOPulse\Services\Indexing
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services\Indexing;

if (!defined('ABSPATH')) {
    exit;
}

interface IndexingServiceInterface
{
    /**
     * Unique identifier for this provider.
     *
     * @return string e.g. 'indexnow', 'google_indexing'
     */
    public function getId(): string;

    /**
     * Human-readable label.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Whether the provider is configured and ready to accept submissions.
     *
     * @return bool
     */
    public function isConfigured(): bool;

    /**
     * Submit a single URL for indexing (or updated notification).
     *
     * @param string $url Canonical URL to submit.
     * @param string $action One of 'updated' | 'deleted'.
     *
     * @return array{success: bool, message: string}
     */
    public function submit(string $url, string $action = 'updated'): array;

    /**
     * Test the connection / configuration without making a real submission.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array;
}
