<?php

/**
 * AES-256-CTR encryption for sensitive CORE data (OAuth tokens)
 *
 * Extends the shared algorithm from AbstractDataEncryption.
 * Key and salt are derived lazily from WordPress constants on each call,
 * keeping this class stateless.
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\AbstractDataEncryption;

class DataEncryption extends AbstractDataEncryption
{
    protected function getKey(): string
    {
        if (defined('SEOPULSE_ENCRYPTION_KEY')) {
            return constant('SEOPULSE_ENCRYPTION_KEY');
        }

        if (defined('LOGGED_IN_KEY')) {
            return constant('LOGGED_IN_KEY');
        }

        return wp_salt('logged_in');
    }

    protected function getSalt(): string
    {
        if (defined('SEOPULSE_ENCRYPTION_SALT')) {
            return constant('SEOPULSE_ENCRYPTION_SALT');
        }

        if (defined('LOGGED_IN_SALT')) {
            return constant('LOGGED_IN_SALT');
        }

        return wp_salt('nonce');
    }
}
