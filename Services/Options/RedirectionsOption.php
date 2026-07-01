<?php

/**
 * Redirections options access service
 *
 * @package SEOPulse\Services\Options
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services\Options;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;

/**
 * RedirectionsOption class
 */
class RedirectionsOption
{
    /** @var string Service identifier for the Container */
    public const NAME_SERVICE = 'RedirectionsOption';

    /**
     * Retrieves all redirections
     *
     * @return array
     */
    public function getAll(): array
    {
        return (array) get_option(Options::REDIRECTIONS, []);
    }

    /**
     * Updates the redirections list
     *
     * @param array $redirects
     * @return bool
     */
    public function update(array $redirects): bool
    {
        return update_option(Options::REDIRECTIONS, $redirects);
    }

    /**
     * Retrieves 404 logs
     *
     * @return array
     */
    public function get404Logs(): array
    {
        return (array) get_option(Options::REDIRECTIONS_404, []);
    }

    /**
     * Updates 404 logs
     *
     * @param array $logs
     * @return bool
     */
    public function update404Logs(array $logs): bool
    {
        return update_option(Options::REDIRECTIONS_404, $logs);
    }

    /**
     * Number of recorded 404s
     *
     * @return int
     */
    public function get404Count(): int
    {
        return count($this->get404Logs());
    }
}
