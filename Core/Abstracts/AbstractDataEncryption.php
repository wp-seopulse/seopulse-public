<?php

/**
 * Abstract base class for authenticated data encryption
 *
 * Seals values with libsodium secretbox (or AES-256-GCM as fallback) under a versioned
 * prefix; values written by the former AES-256-CTR scheme stay readable.
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

    /** Prefix of values sealed with libsodium secretbox (XSalsa20-Poly1305). */
    private const PREFIX_SODIUM = 'v2s:';

    /** Prefix of values sealed with AES-256-GCM (used when libsodium is missing). */
    private const PREFIX_GCM = 'v2g:';

    private const GCM_METHOD  = 'aes-256-gcm';
    private const GCM_TAG_LEN = 16;

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
     * Encrypts a value with an authenticated cipher.
     *
     * Uses libsodium secretbox when available, otherwise AES-256-GCM, otherwise the
     * legacy path. The output carries a version prefix so decrypt() can pick the scheme.
     *
     * @param string $value Plaintext value to encrypt.
     * @return string Versioned, base64-encoded ciphertext.
     */
    public function encrypt(string $value): string
    {
        $key = $this->deriveKey();

        try {
            if (function_exists('sodium_crypto_secretbox')) {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $box   = sodium_crypto_secretbox($value, $nonce, $key);
                sodium_memzero($key);

                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
                return self::PREFIX_SODIUM . base64_encode($nonce . $box);
            }

            if (extension_loaded('openssl') && in_array(self::GCM_METHOD, openssl_get_cipher_methods(), true)) {
                $iv     = random_bytes((int) openssl_cipher_iv_length(self::GCM_METHOD));
                $tag    = '';
                $cipher = openssl_encrypt($value, self::GCM_METHOD, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG_LEN);

                if ($cipher !== false && strlen($tag) === self::GCM_TAG_LEN) {
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
                    return self::PREFIX_GCM . base64_encode($iv . $tag . $cipher);
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the legacy path.
        }

        return $this->legacyEncrypt($value);
    }

    /**
     * Decrypts a value produced by encrypt(), including legacy AES-256-CTR values.
     *
     * Returns an empty string when decryption or authentication fails.
     *
     * @param string $raw Value produced by encrypt().
     * @return string Decrypted plaintext, or an empty string on failure.
     */
    public function decrypt(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, self::PREFIX_SODIUM)) {
            return $this->openSodium(substr($raw, strlen(self::PREFIX_SODIUM)));
        }

        if (str_starts_with($raw, self::PREFIX_GCM)) {
            return $this->openGcm(substr($raw, strlen(self::PREFIX_GCM)));
        }

        return $this->legacyDecrypt($raw);
    }

    /**
     * Whether a stored value uses the legacy unauthenticated scheme and should be re-encrypted.
     *
     * @param string $raw Stored value.
     * @return bool
     */
    public function isLegacy(string $raw): bool
    {
        return $raw !== ''
            && !str_starts_with($raw, self::PREFIX_SODIUM)
            && !str_starts_with($raw, self::PREFIX_GCM);
    }

    /**
     * Derives a 32-byte subkey bound to the scheme from the key and salt.
     *
     * @return string Raw 32-byte key.
     */
    private function deriveKey(): string
    {
        return hash_hkdf('sha256', $this->getKey(), 32, 'seopulse-data-encryption-v2', $this->getSalt());
    }

    private function openSodium(string $encoded): string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            return '';
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key   = $this->deriveKey();
        $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
        sodium_memzero($key);

        return $plain === false ? '' : $plain;
    }

    private function openGcm(string $encoded): string
    {
        if (!extension_loaded('openssl')) {
            return '';
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $decoded = base64_decode($encoded, true);
        $iv_len  = (int) openssl_cipher_iv_length(self::GCM_METHOD);

        if ($decoded === false || strlen($decoded) <= $iv_len + self::GCM_TAG_LEN) {
            return '';
        }

        $iv     = substr($decoded, 0, $iv_len);
        $tag    = substr($decoded, $iv_len, self::GCM_TAG_LEN);
        $cipher = substr($decoded, $iv_len + self::GCM_TAG_LEN);
        $plain  = openssl_decrypt($cipher, self::GCM_METHOD, $this->deriveKey(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? '' : $plain;
    }

    /**
     * Legacy AES-256-CTR encryption (unauthenticated); only reached without sodium or GCM support.
     *
     * @param string $value Plaintext value to encrypt.
     * @return string Base64-encoded IV + ciphertext, or base64-encoded plaintext on failure.
     */
    private function legacyEncrypt(string $value): string
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
     * Decrypts a legacy AES-256-CTR value.
     *
     * Returns an empty string when decryption fails rather than the raw
     * ciphertext, to avoid silently leaking garbled data.
     *
     * @param string $raw Base64-encoded IV + ciphertext produced by the legacy encrypt().
     * @return string Decrypted plaintext, or an empty string on failure.
     */
    private function legacyDecrypt(string $raw): string
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
