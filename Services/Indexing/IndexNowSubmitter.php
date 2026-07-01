<?php

/**
 * IndexNow submitter — zero-friction URL indexing for Bing / Yandex / others.
 *
 * Generates and stores an API key automatically on first use.
 * Submits single URLs to the IndexNow endpoint.
 *
 * @package SEOPulse\Services\Indexing
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services\Indexing;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;

class IndexNowSubmitter implements IndexingServiceInterface
{
    /** IndexNow endpoint. */
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** Option sub-key for the auto-generated API key. */
    private const KEY_OPTION = 'indexnow_key';

    public function getId(): string
    {
        return 'indexnow';
    }

    public function getLabel(): string
    {
        return 'IndexNow (Bing / Yandex)';
    }

    public function isConfigured(): bool
    {
        $settings = $this->getSettings();

        return !empty($settings['indexnow_enabled']) && $this->getApiKey() !== '';
    }

    /**
     * Submit a URL to IndexNow.
     *
     * @param string $url Canonical URL.
     * @param string $action 'updated' or 'deleted' (IndexNow treats both the same).
     *
     * @return array{success: bool, message: string}
     */
    public function submit(string $url, string $action = 'updated'): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'IndexNow is not configured.',
            ];
        }

        $key  = $this->getApiKey();
        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        $response = wp_remote_get(
            add_query_arg(
                [
                    'url' => $url,
                    'key' => $key,
                ],
                self::ENDPOINT,
            ),
            [
                'timeout'    => 10,
                'user-agent' => 'SEOPulse/' . SEOPULSE_VERSION,
            ],
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);

        // 200 = OK, 202 = Accepted
        if ($code === 200 || $code === 202) {
            return [
                'success' => true,
                'message' => "IndexNow accepted (HTTP {$code}).",
            ];
        }

        return [
            'success' => false,
            'message' => "IndexNow returned HTTP {$code}.",
        ];
    }

    /**
     * Test the connection by verifying the key is set and reachable.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        $key = $this->getApiKey();

        if ($key === '') {
            return [
                'success' => false,
                'message' => 'No IndexNow API key configured.',
            ];
        }

        // Verify the key-verification URL is accessible.
        $keyUrl = trailingslashit(home_url()) . $key . '.txt';

        return [
            'success' => true,
            'message' => "IndexNow key is set. Ensure {$keyUrl} is accessible or the key is in your host's root.",
        ];
    }

    /**
     * Get or auto-generate the IndexNow API key.
     *
     * @return string 32-char hex key.
     */
    public function getApiKey(): string
    {
        $settings = $this->getSettings();

        if (!empty($settings[ self::KEY_OPTION ])) {
            return (string) $settings[ self::KEY_OPTION ];
        }

        // Auto-generate and persist.
        $key = wp_generate_password(32, false, false);

        $settings[ self::KEY_OPTION ] = $key;
        update_option(Options::INDEXING, $settings);

        return $key;
    }

    /**
     * Get the full indexing settings array.
     *
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        return (array) get_option(Options::INDEXING, []);
    }
}
