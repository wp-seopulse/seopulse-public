<?php
/**
 * Redirect lookup for headless payloads.
 *
 * @package SEOPulse\Modules\MetaSeo\Headless
 * @since   1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Headless;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Module\ModuleManager;
use SEOPulse\Modules\Redirections\RedirectionsManager;

/**
 * Asks the Redirections module whether a URL that has no public content is redirected.
 */
class RedirectLookup
{
    /**
     * Finds the redirect of a normalized URL.
     *
     * @param string $normalized Value returned by UrlResolver::normalize().
     * @return array{to: string|null, status: int}|null "to" is null for 410/451 rules; null when none applies.
     */
    public function find(string $normalized): ?array
    {
        if (!ModuleManager::instance()->isModuleEnabled('redirections')) {
            return null;
        }

        $path      = (string) strtok($normalized, '?');
        $home_path = untrailingslashit((string) wp_parse_url(home_url(), PHP_URL_PATH));
        $manager   = new RedirectionsManager();

        // Rules keep the source exactly as typed, with or without trailing slash.
        $candidates = '/' === $path ? ['/'] : [$path, $path . '/'];

        foreach ($candidates as $candidate) {
            $redirect = $manager->lookup($home_path . $candidate);

            if (null !== $redirect) {
                return $redirect;
            }
        }

        return null;
    }
}
