<?php

/**
 * Google Indexing API submitter.
 *
 * Authenticates via a service account JSON key file uploaded by the admin.
 * Uses the Google Indexing API v3 to notify Google about URL changes.
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

class GoogleIndexingSubmitter implements IndexingServiceInterface
{
    /** Google Indexing API endpoint. */
    private const ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

    /** Token endpoint for service account. */
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Required OAuth scope. */
    private const SCOPE = 'https://www.googleapis.com/auth/indexing';

    /** Option sub-key for the stored credentials. */
    private const CRED_OPTION = 'google_indexing_credentials';

    public function getId(): string
    {
        return 'google_indexing';
    }

    public function getLabel(): string
    {
        return 'Google Indexing API';
    }

    public function isConfigured(): bool
    {
        $settings = $this->getSettings();

        return !empty($settings['google_indexing_enabled'])
            && !empty($settings[ self::CRED_OPTION ])
            && is_array($settings[ self::CRED_OPTION ])
            && !empty($settings[ self::CRED_OPTION ]['client_email'])
            && !empty($settings[ self::CRED_OPTION ]['private_key']);
    }

    /**
     * Submit a URL notification to Google.
     *
     * @param string $url Canonical URL.
     * @param string $action 'updated' or 'deleted'.
     *
     * @return array{success: bool, message: string}
     */
    public function submit(string $url, string $action = 'updated'): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Google Indexing API is not configured.',
            ];
        }

        $token = $this->getAccessToken();

        if ($token === null) {
            return [
                'success' => false,
                'message' => 'Failed to obtain Google access token.',
            ];
        }

        $type = $action === 'deleted' ? 'URL_DELETED' : 'URL_UPDATED';

        $response = wp_remote_post(
            self::ENDPOINT,
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode(
                    [
                        'url'  => $url,
                        'type' => $type,
                    ],
                ),
            ],
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            return [
                'success' => true,
                'message' => 'Google accepted URL notification.',
            ];
        }

        $error = $body['error']['message'] ?? "HTTP {$code}";

        return [
            'success' => false,
            'message' => "Google API error: {$error}",
        ];
    }

    /**
     * Test the connection by obtaining an access token.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Google Indexing API credentials are not configured.',
            ];
        }

        $token = $this->getAccessToken();

        if ($token === null) {
            return [
                'success' => false,
                'message' => 'Failed to obtain access token. Check your service account JSON.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Successfully authenticated with Google.',
        ];
    }

    /**
     * Store validated credentials.
     *
     * @param array $credentials Parsed service account JSON.
     *
     * @return void
     */
    public function saveCredentials(array $credentials): void
    {
        $settings = $this->getSettings();

        // Only store the minimum required fields.
        $settings[ self::CRED_OPTION ] = [
            'client_email' => sanitize_email($credentials['client_email'] ?? ''),
            'private_key'  => $credentials['private_key'] ?? '',
            'project_id'   => sanitize_text_field($credentials['project_id'] ?? ''),
        ];

        // This settings blob holds a private key — never let WordPress
        // autoload it into memory on every single page request.
        update_option(Options::INDEXING, $settings, false);
    }

    /**
     * Validate a service account JSON structure.
     *
     * Beyond just checking the two required fields are present, this now
     * also checks the document looks like an actual Google service-account
     * key (type, email domain, PEM markers) so a malformed/unrelated JSON
     * fails fast with a clear error instead of being accepted and only
     * failing later at JWT-signing time.
     *
     * @param array $json Parsed JSON data.
     *
     * @return bool
     */
    public static function validateCredentials(array $json): bool
    {
        if (empty($json['client_email']) || !is_string($json['client_email'])) {
            return false;
        }

        if (empty($json['private_key']) || !is_string($json['private_key'])) {
            return false;
        }

        if (($json['type'] ?? '') !== 'service_account') {
            return false;
        }

        if (!str_ends_with($json['client_email'], '.iam.gserviceaccount.com')) {
            return false;
        }

        if (!str_starts_with($json['private_key'], '-----BEGIN') || !str_contains($json['private_key'], '-----END')) {
            return false;
        }

        return true;
    }

    /**
     * Obtain an OAuth2 access token using the service account JWT flow.
     *
     * Tokens are cached for 55 minutes via transient.
     *
     * @return string|null Bearer token or null on failure.
     */
    private function getAccessToken(): ?string
    {
        $cached = get_transient('seopulse_google_idx_token');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $settings    = $this->getSettings();
        $credentials = $settings[ self::CRED_OPTION ] ?? [];
        $email       = $credentials['client_email'] ?? '';
        $privateKey  = $credentials['private_key'] ?? '';

        if ($email === '' || $privateKey === '') {
            return null;
        }

        $jwt = $this->createJwt($email, $privateKey);

        if ($jwt === null) {
            return null;
        }

        $response = wp_remote_post(
            self::TOKEN_URL,
            [
                'timeout' => 10,
                'body'    => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ],
            ],
        );

        if (is_wp_error($response)) {
            $this->logTokenError('HTTP error: ' . $response->get_error_message());

            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            $this->logTokenError(
                'No access_token in response: ' . ($body['error_description'] ?? wp_remote_retrieve_body($response)),
            );

            return null;
        }

        $token   = $body['access_token'];
        $expires = (int) ($body['expires_in'] ?? 3600);

        // Cache for slightly less than the token lifetime.
        set_transient('seopulse_google_idx_token', $token, max($expires - 300, 60));

        return $token;
    }

    /**
     * Log a token-retrieval failure when WP_DEBUG is enabled, since these
     * failures were previously swallowed entirely, making it very hard to
     * diagnose bad credentials vs. transient network issues.
     *
     * @param string $reason
     *
     * @return void
     */
    private function logTokenError(string $reason): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional, gated behind WP_DEBUG; not leftover debug code (see method docblock).
            error_log('[SEOPulse] Google Indexing token request failed: ' . $reason);
        }
    }

    /**
     * Create a signed JWT for the service account.
     *
     * @param string $email Service account email.
     * @param string $privateKey PEM-encoded private key.
     *
     * @return string|null Encoded JWT or null on failure.
     */
    private function createJwt(string $email, string $privateKey): ?string
    {
        $now = time();

        $header = $this->base64UrlEncode(
            wp_json_encode(
                [
                    'alg' => 'RS256',
                    'typ' => 'JWT',
                ],
            ),
        );

        $payload = $this->base64UrlEncode(
            wp_json_encode(
                [
                    'iss'   => $email,
                    'scope' => self::SCOPE,
                    'aud'   => self::TOKEN_URL,
                    'iat'   => $now,
                    'exp'   => $now + 3600,
                ],
            ),
        );

        $unsigned = $header . '.' . $payload;

        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            $this->logTokenError('openssl_pkey_get_private() failed — private key may be malformed.');

            return null;
        }

        $signature = '';

        if (!openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            $this->logTokenError('openssl_sign() failed.');

            return null;
        }

        return $unsigned . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Base64-URL encode (no padding).
     *
     * @param string $data Raw data.
     *
     * @return string
     */
    private function base64UrlEncode(string $data): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
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
