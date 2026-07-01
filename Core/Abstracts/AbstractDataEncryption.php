<?php

/**
 * Abstract base class for AES-256-CTR data encryption
 *
 * Provides the shared encrypt/decrypt algorithm used by both the CORE and the PRO
 * plugins. Concrete subclasses only need to implement key and salt resolution.
 *
 * Why two separate subclasses instead of a single shared class?
 * - CORE (`SEOPulse\Services\DataEncryption`) derives its key/salt lazily on each
 *   call from WordPress constants, keeping the class stateless.
 * - PRO (`SEOPulsePro\Core\DataEncryption`) resolves key/salt once in the constructor
 *   and stores them for repeated use.
 * Both strategies are intentional; this abstract class captures only the algorithm
 * they share so that any future improvements (e.g. cipher upgrade) are applied once.
 *
 * @package SEOPulse\Core\Abstracts
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Abstracts;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractDataEncryption
{
    /**
     * OpenSSL cipher method used for all encryption/decryption operations.
     */
    protected const METHOD = 'aes-256-ctr';

    /**
     * Returns the encryption key.
     *
     * Implementations should derive this from WordPress constants
     * (e.g. `LOGGED_IN_KEY`, `SEOPULSE_ENCRYPTION_KEY`) or site-specific secrets.
     *
     * @return string
     */
    abstract protected function getKey(): string;

    /**
     * Returns the encryption salt.
     *
     * Implementations should derive this from WordPress constants
     * (e.g. `LOGGED_IN_SALT`, `SEOPULSE_ENCRYPTION_SALT`) or site-specific secrets.
     *
     * @return string
     */
    abstract protected function getSalt(): string;

    /**
     * Encrypts a plaintext string using AES-256-CTR.
     *
     * A random IV is prepended to the ciphertext before base64-encoding so that
     * identical inputs produce different outputs on every call.
     * Falls back to base64-encoding alone when the OpenSSL extension is unavailable.
     *
     * @param string $value Plaintext value to encrypt.
     * @return string Base64-encoded IV + ciphertext, or base64-encoded plaintext on failure.
     */
    public function encrypt(string $value): string
    {
        if (!extension_loaded('openssl')) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            return base64_encode($value);
        }

        $key    = $this->getKey();
        $salt   = $this->getSalt();
        $iv_len = openssl_cipher_iv_length(self::METHOD);
        $iv     = openssl_random_pseudo_bytes($iv_len);

        $encrypted = openssl_encrypt($value . $salt, self::METHOD, $key, 0, $iv);

        if ($encrypted === false) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            return base64_encode($value);
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypts a value previously encrypted with encrypt().
     *
     * Returns an empty string when decryption fails rather than the raw
     * ciphertext, to avoid silently leaking garbled data.
     *
     * @param string $raw Base64-encoded IV + ciphertext produced by encrypt().
     * @return string Decrypted plaintext, or an empty string on failure.
     */
    public function decrypt(string $raw): string
    {
        if (empty($raw)) {
            return '';
        }

        if (!extension_loaded('openssl')) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            return base64_decode($raw) ?: '';
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $decoded = base64_decode($raw, true);
        if ($decoded === false) {
            return '';
        }

        $key        = $this->getKey();
        $salt       = $this->getSalt();
        $iv_len     = openssl_cipher_iv_length(self::METHOD);
        $iv         = substr($decoded, 0, $iv_len);
        $ciphertext = substr($decoded, $iv_len);

        $decrypted = openssl_decrypt($ciphertext, self::METHOD, $key, 0, $iv);
        if ($decrypted === false) {
            return '';
        }

        // Strip the salt that was appended before encryption
        $salt_len = strlen($salt);
        if ($salt_len > 0 && substr($decrypted, -$salt_len) === $salt) {
            return substr($decrypted, 0, -$salt_len);
        }

        return $decrypted;
    }
}
