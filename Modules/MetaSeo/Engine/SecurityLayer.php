<?php

/**
 * Security layer — escaping and template validation.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

final class SecurityLayer
{
    /**
     * Sanitise resolved template output.
     *
     * @param string $value Raw resolved string.
     * @param string $outputContext One of: attr, html, url, json, raw.
     * @return string Escaped value.
     */
    public function sanitize(string $value, string $outputContext = 'attr'): string
    {
        // Strip any HTML tags that might have leaked through
        $value = wp_strip_all_tags($value);

        // Normalise whitespace (collapse multiple spaces, trim)
        $value = (string) preg_replace('/\s+/', ' ', trim($value));

        return match ($outputContext) {
            'attr' => function_exists('esc_attr') ? esc_attr($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            'html' => function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            'url'  => function_exists('esc_url') ? esc_url($value) : filter_var($value, FILTER_SANITIZE_URL),
            'json' => (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'raw'  => $value,
            default => function_exists('esc_attr') ? esc_attr($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
        };
    }

    /**
     * Validate a template string for safety.
     *
     * Returns false if the template contains potentially dangerous code.
     */
    public function validateTemplate(string $template): bool
    {
        // No PHP tags
        if (preg_match('/<\?php|<\?=/', $template)) {
            return false;
        }

        // No script tags
        if (preg_match('/<script/i', $template)) {
            return false;
        }

        // No event handlers
        if (preg_match('/\bon\w+\s*=/i', $template)) {
            return false;
        }

        return true;
    }
}
