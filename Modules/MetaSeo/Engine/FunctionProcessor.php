<?php

/**
 * FunctionProcessor
 *
 * Maintains a registry of named transformation functions that can
 * be applied to variable values inside templates.
 *
 * Built-in functions are registered in the constructor.
 * Third-party functions can be added via the
 * `seopulse/meta_engine/register_functions` action.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

final class FunctionProcessor
{
    /** @var array<string, callable(string, string[]): string> */
    private array $functions = [];

    /** @var bool */
    private bool $externalCollected = false;

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * Register a named function.
     *
     * @param string $name Lowercase function identifier.
     * @param callable $callback fn(string $value, string[] $args): string
     */
    public function register(string $name, callable $callback): void
    {
        $this->functions[ strtolower($name) ] = $callback;
    }

    /**
     * Check if a function name is registered (or will be after external collection).
     */
    public function isFunction(string $name): bool
    {
        $this->collectExternal();

        return isset($this->functions[ strtolower($name) ]);
    }

    /**
     * Apply a function to a value.
     *
     * @param string $name Function name.
     * @param string $value Input value.
     * @param string[] $args Additional arguments.
     * @return string Transformed value.
     */
    public function apply(string $name, string $value, array $args = []): string
    {
        $this->collectExternal();

        $fn = $this->functions[ strtolower($name) ] ?? null;

        if ($fn === null) {
            return $value; // Unknown function: pass through
        }

        return $fn($value, $args);
    }

    // ------------------------------------------------------------------
    // Built-in functions
    // ------------------------------------------------------------------

    private function registerDefaults(): void
    {
        $this->register('uppercase', static fn (string $v): string => mb_strtoupper($v));

        $this->register('lowercase', static fn (string $v): string => mb_strtolower($v));

        $this->register(
            'capitalize',
            static function (string $v): string {
                if ($v === '') {
                    return '';
                }

                return mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1);
            },
        );

        $this->register('titlecase', static fn (string $v): string => mb_convert_case($v, MB_CASE_TITLE));

        $this->register(
            'truncate',
            static function (string $v, array $args): string {
                $limit = (int) ($args[0] ?? 160);

                if (mb_strlen($v) <= $limit) {
                    return $v;
                }

                $truncated = mb_substr($v, 0, $limit);
                $lastSpace = mb_strrpos($truncated, ' ');

                if ($lastSpace !== false && $lastSpace > $limit * 0.8) {
                    $truncated = mb_substr($truncated, 0, $lastSpace);
                }

                return rtrim($truncated) . '…';
            },
        );

        $this->register(
            'words',
            static function (string $v, array $args): string {
                $limit = (int) ($args[0] ?? 10);
                $words = preg_split('/\s+/', $v, $limit + 1);

                if ($words === false || count($words) <= $limit) {
                    return $v;
                }

                return implode(' ', array_slice($words, 0, $limit)) . '…';
            },
        );

        $this->register(
            'strip_tags',
            static function (string $v): string {
                return wp_strip_all_tags($v);
            },
        );

        $this->register(
            'replace',
            static function (string $v, array $args): string {
                $search  = $args[0] ?? '';
                $replace = $args[1] ?? '';

                return str_replace($search, $replace, $v);
            },
        );

        $this->register(
            'default',
            static function (string $v, array $args): string {
                return $v !== '' ? $v : ($args[0] ?? '');
            },
        );

        $this->register(
            'count',
            static function (string $v): string {
                return (string) count(array_filter(explode(',', $v), static fn (string $s) => trim($s) !== ''));
            },
        );

        $this->register('first', static fn (string $v): string => trim(explode(',', $v)[0] ?? ''));

        $this->register(
            'last',
            static function (string $v): string {
                $parts = explode(',', $v);

                return trim(end($parts) ?: '');
            },
        );

        $this->register(
            'join',
            static function (string $v, array $args): string {
                $sep   = $args[0] ?? ', ';
                $parts = array_map('trim', explode(',', $v));

                return implode($sep, $parts);
            },
        );

        $this->register('urlencode', static fn (string $v): string => rawurlencode($v));

        $this->register(
            'number_format',
            static function (string $v): string {
                return is_numeric($v) ? number_format((float) $v, 2) : $v;
            },
        );

        $this->register('md5', static fn (string $v): string => md5($v));
    }

    // ------------------------------------------------------------------
    // External collection (once, lazily)
    // ------------------------------------------------------------------

    private function collectExternal(): void
    {
        if ($this->externalCollected) {
            return;
        }

        $this->externalCollected = true;

        /**
         * Allow third-party plugins to register custom template functions.
         *
         * @since 1.0.0
         *
         * @param FunctionProcessor $processor
         */
        do_action('seopulse/meta_engine/register_functions', $this);
    }
}
