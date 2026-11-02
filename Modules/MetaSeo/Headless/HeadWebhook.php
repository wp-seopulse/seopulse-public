<?php
/**
 * Revalidation webhook for headless frontends.
 *
 * @package SEOPulse\Modules\MetaSeo\Headless
 * @since   1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Headless;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;

/**
 * Tells the frontend which head payloads changed.
 *
 * Invalidations are collected during the request and sent as one signed,
 * non-blocking POST on "shutdown". Delivery is best effort (no retry).
 */
final class HeadWebhook
{
    public const EVENT = 'seopulse.head.invalidated';

    /**
     * Scopes ordered by breadth; the broadest one queued wins.
     *
     * @var array<string, int>
     */
    private const RANK = ['post' => 1, 'content' => 2, 'global' => 3];

    /**
     * Broadest scope queued in this request.
     *
     * @var string|null
     */
    private static ?string $scope = null;

    /**
     * Frontend URLs queued in this request.
     *
     * @var string[]
     */
    private static array $urls = [];

    /**
     * Queues an invalidation.
     *
     * @param string $scope "post", "content" or "global".
     * @param string|null $url Public CMS URL of the affected page, if any.
     * @return void
     */
    public function queue(string $scope, ?string $url = null): void
    {
        if (!isset(self::RANK[$scope]) || '' === $this->endpoint()) {
            return;
        }

        if (null === self::$scope || self::RANK[$scope] > self::RANK[self::$scope]) {
            self::$scope = $scope;
        }

        if (null !== $url && '' !== $url) {
            self::$urls[] = (new FrontendUrl())->rewrite(['url' => $url])['url'];
        }
    }

    /**
     * Sends what was queued.
     *
     * @return void
     */
    public function flush(): void
    {
        $endpoint = $this->endpoint();
        $secret   = $this->secret();

        if (null === self::$scope || '' === $endpoint || '' === $secret) {
            return;
        }

        $body = (string) wp_json_encode($this->build_body(self::$scope, array_values(array_unique(self::$urls))));

        self::$scope = null;
        self::$urls  = [];

        // Safe variant: refuses private and loopback targets (SSRF).
        wp_safe_remote_post(
            $endpoint,
            [
                'blocking'    => false,
                'timeout'     => 5,
                'redirection' => 0,
                'headers'     => [
                    'Content-Type'         => 'application/json',
                    'X-SEOPulse-Event'     => self::EVENT,
                    'X-SEOPulse-Signature' => $this->sign($body, $secret),
                ],
                'body'        => $body,
            ],
        );
    }

    /**
     * Builds the request body.
     *
     * @param string $scope Scope.
     * @param string[] $urls Frontend URLs.
     * @return array<string, mixed>
     */
    public function build_body(string $scope, array $urls): array
    {
        return [
            'event'   => self::EVENT,
            'scope'   => $scope,
            'urls'    => $urls,
            'sent_at' => time(),
        ];
    }

    /**
     * Computes the signature header value: "sha256=" . HMAC-SHA256 of the raw body.
     *
     * @param string $body Raw request body.
     * @param string $secret Shared secret.
     * @return string
     */
    public function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * Returns the webhook URL, or '' when not configured or not http(s).
     *
     * @return string
     */
    private function endpoint(): string
    {
        /**
         * Filters the revalidation webhook URL.
         *
         * @since 1.8.0
         * @param string $url Webhook URL, empty to disable.
         */
        $url = apply_filters('seopulse_head_webhook_url', $this->setting('headless_webhook_url'));

        return is_string($url) ? esc_url_raw(trim($url), ['http', 'https']) : '';
    }

    /**
     * Returns the shared secret.
     *
     * @return string
     */
    private function secret(): string
    {
        /**
         * Filters the shared secret used to sign webhook requests.
         *
         * @since 1.8.0
         * @param string $secret Secret.
         */
        $secret = apply_filters('seopulse_head_webhook_secret', $this->setting('headless_webhook_secret'));

        return is_string($secret) ? $secret : '';
    }

    /**
     * Reads a string setting.
     *
     * @param string $key Setting key.
     * @return string
     */
    private function setting(string $key): string
    {
        $settings = get_option(Options::META_SEO_GLOBAL, []);

        return is_array($settings) && is_string($settings[$key] ?? null) ? $settings[$key] : '';
    }
}
