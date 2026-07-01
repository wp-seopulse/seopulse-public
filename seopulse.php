<?php

/**
 * Plugin Name: SEOPulse
 * Plugin URI: https://wordpress.org/plugins/seopulse/
 * Description: SEOPulse is a SEO Plugin to Boost Rankings & Traffic.
 * Version: 1.4.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Citrus Design Lab
 * Author URI: https://www.citrus-design.fr
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: seopulse
 * Domain Path: /languages
 *
 * @package SEOPulse
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse;

// Security: prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('SEOPULSE_VERSION', '1.4.0');
define('SEOPULSE_PLUGIN_FILE', __FILE__);
define('SEOPULSE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SEOPULSE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SEOPULSE_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('SEOPULSE_ASSETS_URL', plugin_dir_url(__FILE__) . 'assets/');

// PHP version check
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action(
        'admin_notices',
        function () {
            echo '<div class="error"><p>';
            echo esc_html__('SEOPulse requires PHP 8.1 or higher. Please upgrade PHP.', 'seopulse');
            echo '</p></div>';
        },
    );

    return;
}

// Composer autoload (if used) or custom autoload
if (file_exists(SEOPULSE_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once SEOPULSE_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    // Simple PSR-4 autoload
    spl_autoload_register(
        function ($class) {
            $prefix   = 'SEOPulse\\';
            $base_dir = SEOPULSE_PLUGIN_DIR;

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relative_class = substr($class, $len);
            $file           = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        },
    );
}

// Load main plugin
require_once SEOPULSE_PLUGIN_DIR . 'Core/Plugin.php';
require_once SEOPULSE_PLUGIN_DIR . 'Core/helpers.php';
require_once SEOPULSE_PLUGIN_DIR . 'Core/template-tags.php';

/**
 * Returns the singleton instance of the plugin
 *
 * @return Core\Plugin
 */
function seopulse(): Core\Plugin
{
    return Core\Plugin::instance();
}

// ── Bootstrap Kernel (auto‑discovery + dispatching) ────────────
Core\Kernel::execute(
    [
        'file'      => __FILE__,
        'slug'      => 'seopulse',
        'main_file' => SEOPULSE_PLUGIN_FILE,
        'root'      => SEOPULSE_PLUGIN_DIR,
    ],
);

// ── Load translations ──────────────────────────────────────────
add_action(
    'init',
    static function () {
        load_plugin_textdomain(
            'seopulse',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages',
        );
    },
);

// ── Action links on the plugins page ───────────────────────────
add_filter(
    'plugin_action_links_' . SEOPULSE_PLUGIN_BASENAME,
    function (array $links): array {
        $wizard_link = '<a href="' . esc_url(admin_url('admin.php?page=seopulse-setup-wizard')) . '">'
        . esc_html__('Setup Wizard', 'seopulse')
        . '</a>';

        // Insert the Setup Wizard link first
        array_unshift($links, $wizard_link);

        return $links;
    },
);

// ── Backward compatibility: legacy initialization ─────────────
add_action(
    'plugins_loaded',
    function () {
        seopulse()->init();
    },
    10,
);

// ── Upgrade routine on admin pages ────────────────────────────
add_action(
    'admin_init',
    function () {
        Core\Installer::maybe_upgrade();
    },
    5,
);
