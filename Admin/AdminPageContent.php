<?php

/**
 * Renders a disabled-state overlay on individual module settings pages.
 *
 * Usage in a settings page render method:
 *
 *     AdminPageContent::begin('meta_seo', __('Meta SEO', 'seopulse'));
 *     // … normal page content …
 *     AdminPageContent::end();
 *
 * For menus:
 *
 *     AdminPageContent::menuLabel('meta_seo', __('Meta SEO', 'seopulse'))
 *
 * @package SEOPulse\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Module\ModuleDefinition;
use SEOPulse\Core\Module\ModuleManager;

final class AdminPageContent
{
    /**
     * Returns a menu label with a visual "OFF" badge when the module is disabled.
     *
     * Use as the $menu_title parameter in add_submenu_page().
     */
    public static function menuLabel(string $moduleKey, string $label): string
    {
        if (ModuleManager::instance()->isModuleEnabled($moduleKey)) {
            return $label;
        }

        return $label . ' <span class="seopulse-menu-badge--off">'
            . esc_html__('OFF', 'seopulse') . '</span>';
    }

    /**
     * Opens the module page wrapper: starts content container with
     * a disabled-state overlay and notice when the module is off.
     *
     * When $pageTitle is provided, the admin header is rendered
     * **above** the content wrapper so it stays outside the
     * disabled-overlay scope.
     *
     * Must be paired with end().
     */
    public static function begin(string $moduleKey, string $pageTitle = ''): void
    {
        $manager    = ModuleManager::instance();
        $definition = $manager->getDefinition($moduleKey);

        // Admin Header above the content wrapper
        if ($pageTitle !== '') {
            self::renderAdminHeader($pageTitle);
        }

        if (!$definition) {
            echo '<div class="seopulse-module-page__content">';

            return;
        }

        $isEnabled = $manager->isModuleEnabled($moduleKey);

        echo '<div class="seopulse-module-page__content">';

        if (!$isEnabled) {
            self::renderDisabledNotice($definition);
        }
    }

    /**
     * Closes the module page wrapper.
     */
    public static function end(): void
    {
        echo '</div><!-- .seopulse-module-page__content -->';
    }

    /**
     * Renders the "module disabled" info notice.
     */
    private static function renderDisabledNotice(ModuleDefinition $definition): void
    {
        $nonce = wp_create_nonce('seopulse_toggle_module');
        ?>
<div class="seopulse-module-page__disabled-notice"
	data-module="<?php echo esc_attr($definition->key); ?>">
	<span class="dashicons dashicons-info-outline"></span>
	<p>
		<?php
                printf(
                    /* translators: %s: Module name wrapped in <strong> (e.g. "Meta SEO") */
                    esc_html__('The %s module is currently disabled.', 'seopulse'),
                    '<strong>' . esc_html($definition->label) . '</strong>',
                );
        ?>
	</p>
	<label class="seopulse-module-page__enable-toggle">
		<input type="checkbox" class="seopulse-module-page__enable-input"
			data-module="<?php echo esc_attr($definition->key); ?>"
			data-nonce="<?php echo esc_attr($nonce); ?>">
		<span class="seopulse-module-page__enable-toggle-slider"></span>
	</label>
</div>
<?php
    }
    /**
     * Renders the adminHeader.
     *
     * Used internally by begin() and can be called directly
     * on standalone pages (e.g. Tools, Logs) via renderAdminHeader().
     */
    public static function renderAdminHeader(string $pageTitle): void
    {
        $dashboard_url = admin_url('admin.php?page=seopulse');
        ?>
<div class="seopulse-admin-header-wrapper">
    <div id="seopulse-admin-header-root"
         data-page-title="<?php echo esc_attr($pageTitle); ?>"
         data-dashboard-url="<?php echo esc_attr($dashboard_url); ?>">
    </div>
</div>
<?php
    }

}
?>