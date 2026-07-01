<?php

/**
 * 404 Monitor – Advanced Logger
 *
 * Handles high-performance 404 detection with:
 * - Bot detection & classification (SEO crawlers, search engines, suspicious)
 * - IP privacy modes (disabled / anonymised / full hashed)
 * - Rate limiting per IP to prevent DB flooding
 * - Aggregated upsert (one row per URL, incrementing hits)
 *
 * @package SEOPulse\Modules\Monitor404
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Monitor404;

if (!defined('ABSPATH')) {
    exit;
}

class Monitor404Logger
{
    // ── Bot signatures ────────────────────────────────────────────────────────

    /** SEO / analytics crawlers that should be tracked (not silenced) */
    private const SEO_BOTS = [
        'googlebot'      => 'Googlebot',
        'bingbot'        => 'Bingbot',
        'slurp'          => 'Yahoo-Slurp',
        'duckduckbot'    => 'DuckDuckBot',
        'baiduspider'    => 'Baiduspider',
        'yandexbot'      => 'YandexBot',
        'applebot'       => 'Applebot',
        'ahrefsbot'      => 'Ahrefsbot',
        'semrushbot'     => 'SemrushBot',
        'mj12bot'        => 'MJ12bot',
        'dotbot'         => 'DotBot',
        'rogerbot'       => 'Rogerbot',
        'screaming frog' => 'ScreamingFrog',
        'seokicks'       => 'SEOKicks',
        'searchatlas'    => 'SearchAtlas',
        'petalbot'       => 'PetalBot',
    ];

    /** Generic / nuisance bots that can be silently ignored */
    private const GENERIC_BOTS = [
        'facebookexternalhit',
        'twitterbot',
        'linkedinbot',
        'embedly',
        'pinterest',
        'slackbot',
        'discordbot',
        'whatsapp',
        'tumblr',
        'bitlybot',
        'skypeuripreview',
        'flipboard',
        'outbrain',
        'quora link preview',
        'w3c_validator',
        'redditbot',
        'qwantify',
        'vkshare',
        'nuzzel',
        'bitrix',
        'ia_archiver',
        'wget',
        'libwww-perl',
        'python-requests',
        'python-urllib',
        'go-http-client',
        'curl/',
        'java/',
        'ruby',
        'php/',
    ];

    /** Request rate-limit cache key prefix */
    private const RATE_LIMIT_PREFIX = 'seopulse_404_rl_';

    /** Max log entries per IP per minute */
    private const RATE_LIMIT_MAX = 10;

    /** TTL for rate-limit transient (seconds) */
    private const RATE_LIMIT_TTL = 60;

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'seopulse_404_logs';

        // Register custom table on $wpdb so it is recognised as a safe identifier.
        $wpdb->seopulse_404_logs = $this->table;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Detects if the current request is a 404 and logs it.
     *
     * Hooked on `template_redirect` (priority 99) by Monitor404Module.
     */
    public function maybeLog(): void
    {
        if (!is_404()) {
            return;
        }

        $options = $this->getOptions();

        // Bail if tracking is globally disabled
        if (!($options['enable_tracking'] ?? true)) {
            return;
        }

        $url       = $this->getCurrentUrl();
        $userAgent = $this->getUserAgent();
        $botInfo   = $this->classifyBot($userAgent);

        // Should we log SEO bots?
        if ($botInfo['is_bot'] && !($options['track_bots'] ?? true)) {
            return;
        }

        // Ignore generic/nuisance bots always
        if ($botInfo['is_generic_bot']) {
            return;
        }

        // Static asset check – skip image/js/css 404s when configured
        if (($options['ignore_static'] ?? true) && $this->isStaticAsset($url)) {
            return;
        }

        // Per-IP rate limiting (applies to humans only)
        if (!$botInfo['is_bot'] && !$this->passRateLimit()) {
            return;
        }

        // Differentiate behavior by user login state
        $loggedIn = is_user_logged_in();
        if ($loggedIn && !($options['track_logged_in'] ?? true)) {
            return;
        }

        $referrer = $this->getReferrer();
        $ip       = $this->getIpByMode($options['ip_mode'] ?? 'hashed');

        $insertedId = $this->upsert($url, $referrer, $userAgent, $ip, $botInfo, $loggedIn);

        // Auto-suggest redirect for new entries when enabled
        if ($insertedId > 0 && !empty($options['auto_suggest_enabled'])) {
            $this->maybeAutoSuggest($insertedId, $url);
        }
    }

    /**
     * Detects the bot information from a User-Agent string (public for tests).
     *
     * @param string $userAgent
     * @return array{is_bot: bool, is_generic_bot: bool, bot_name: string|null}
     */
    public function classifyBot(string $userAgent): array
    {
        $ua = strtolower($userAgent);

        foreach (self::SEO_BOTS as $signature => $name) {
            if (str_contains($ua, $signature)) {
                return [
                    'is_bot'         => true,
                    'is_generic_bot' => false,
                    'bot_name'       => $name,
                ];
            }
        }

        foreach (self::GENERIC_BOTS as $signature) {
            if (str_contains($ua, $signature)) {
                return [
                    'is_bot'         => true,
                    'is_generic_bot' => true,
                    'bot_name'       => null,
                ];
            }
        }

        return [
            'is_bot'         => false,
            'is_generic_bot' => false,
            'bot_name'       => null,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Upserts a 404 log row (insert or increment).
     */
    private function upsert(
        string $url,
        string $referrer,
        string $userAgent,
        string $ip,
        array $botInfo,
        bool $loggedIn,
    ): int {
        global $wpdb;

        $now = current_time('mysql');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, hits FROM {$wpdb->seopulse_404_logs} WHERE url = %s AND status != 'ignored' LIMIT 1",
                $url,
            ),
        );

        if ($existing) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $this->table,
                [
                    'hits'           => (int) $existing->hits + 1,
                    'last_hit'       => $now,
                    'referrer'       => $referrer,
                    'user_agent'     => substr($userAgent, 0, 512),
                    'ip_hash'        => $ip,
                    'is_bot'         => (int) $botInfo['is_bot'],
                    'bot_name'       => $botInfo['bot_name'],
                    'user_logged_in' => (int) $loggedIn,
                ],
                ['id' => (int) $existing->id],
                ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d'],
                ['%d'],
            );

            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $this->table,
            [
                'url'            => $url,
                'referrer'       => $referrer,
                'user_agent'     => substr($userAgent, 0, 512),
                'ip_hash'        => $ip,
                'hits'           => 1,
                'first_hit'      => $now,
                'last_hit'       => $now,
                'status'         => 'active',
                'is_bot'         => (int) $botInfo['is_bot'],
                'bot_name'       => $botInfo['bot_name'],
                'user_logged_in' => (int) $loggedIn,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d'],
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Checks if the current IP is within the configured rate limit.
     */
    private function passRateLimit(): bool
    {
        $ip  = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $key = self::RATE_LIMIT_PREFIX . md5($ip);

        $count = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT_MAX) {
            return false;
        }

        set_transient($key, $count + 1, self::RATE_LIMIT_TTL);

        return true;
    }

    /**
     * Auto-suggests a redirect for a newly inserted 404 entry.
     */
    private function maybeAutoSuggest(int $id, string $url): void
    {
        try {
            $engine = new Monitor404SuggestionEngine();
            $best   = $engine->suggest($url);

            if ($best) {
                $repo = new Monitor404Repository();
                $repo->storeSuggestion($id, $best['url'], $best['score']);
            }
        } catch (\Throwable $e) {
            // Silently fail — never block 404 logging for suggestion errors
        }
    }

    /**
     * Returns the current request path (no query string).
     */
    private function getCurrentUrl(): string
    {
        $uri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
            : '/';

        return '/' . ltrim(strtok($uri, '?'), '/');
    }

    /**
     * Returns the sanitised User-Agent string.
     */
    private function getUserAgent(): string
    {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';
    }

    /**
     * Returns a clean referrer URL.
     */
    private function getReferrer(): string
    {
        $raw = wp_get_referer();

        return $raw ?: '';
    }

    /**
     * Returns the IP address in the configured privacy mode.
     *
     * @param string $mode 'disabled' | 'anonymised' | 'hashed' | 'full'
     */
    private function getIpByMode(string $mode): string
    {
        if ($mode === 'disabled') {
            return '';
        }

        $raw = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $raw = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
            $raw   = trim($parts[0]);
        } else {
            $raw = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        }

        if (empty($raw)) {
            return '';
        }

        switch ($mode) {
            case 'full':
                return $raw;

            case 'anonymised':
                // GDPR: zero the last octet (IPv4) or last 80 bits (IPv6)
                if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return preg_replace('/\.\d+$/', '.0', $raw) ?: $raw;
                }
                if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return preg_replace('/(:[0-9a-fA-F]{0,4}){5}$/', ':0:0:0:0:0', $raw) ?: $raw;
                }

                return $raw;

            case 'hashed':
            default:
                return hash('sha256', $raw . wp_salt('auth'));
        }
    }

    /**
     * Checks whether the URL looks like a static asset (image, JS, CSS, font).
     */
    private function isStaticAsset(string $url): bool
    {
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|svg|ico|css|js|woff2?|ttf|eot|map|pdf)(\?.*)?$/i', $url);
    }

    /**
     * Returns the module settings array.
     *
     * @return array<string, mixed>
     */
    private function getOptions(): array
    {
        $opts = get_option('seopulse_404_settings', []);

        return is_array($opts) ? $opts : [];
    }
}
