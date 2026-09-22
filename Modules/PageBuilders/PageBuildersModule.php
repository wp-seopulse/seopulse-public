<?php

/**
 * Page Builders Integration module for SEOPulse
 *
 * Bridges the existing Meta SEO REST endpoints (already used by the
 * Gutenberg editor sidebar) into the native editing UI of supported
 * page builders (Elementor, Divi, Beaver Builder, Oxygen), so users
 * never have to leave their builder to manage a post's SEO.
 *
 * Each builder adapter self-gates on its own detection + sub-toggle,
 * so no builder-specific hook/asset is ever registered when that
 * builder is not installed (zero dead code executed).
 *
 * @package SEOPulse\Modules\PageBuilders
 * @since 1.7.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\PageBuilders;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Attributes\AsModule;
use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Contracts\ExecuteHooks;
use SEOPulse\Core\Contracts\ModuleInterface;
use SEOPulse\Modules\PageBuilders\Divi\DiviIntegration;
use SEOPulse\Modules\PageBuilders\Elementor\ElementorIntegration;
use SEOPulse\Modules\PageBuilders\Kubio\KubioIntegration;
use SEOPulse\Modules\PageBuilders\LiveComposer\LiveComposerIntegration;

/**
 * PageBuildersModule class
 */
#[AsModule(
    key: 'page_builders',
    label: 'Page Builders Integration',
    description: 'Edit all SEO directly inside Elementor, Live Composer, Kubio — without leaving the page builder.',
    icon: 'dashicons-layout',
    pro: false,
    requires: ['meta_seo'],
    namespace: 'SEOPulse\\Modules\\PageBuilders\\',
)]
class PageBuildersModule implements ExecuteHooks, ModuleInterface
{
    /**
     * {@inheritDoc}
     */
    public function getKey(): string
    {
        return 'page_builders';
    }

    /**
     * Registers WordPress hooks — admin-only, no frontend footprint.
     *
     * @return void
     */
    public function hooks(): void
    {
        (new ElementorIntegration())->hooks();
        //(new DiviIntegration())->hooks();
        (new LiveComposerIntegration())->hooks();
        (new KubioIntegration())->hooks();
    }

    /**
     * Reads the per-builder sub-toggle state.
     *
     * Separate from the module's own on/off state (Options::MODULES_ENABLED)
     * on purpose: ModuleManager's dependency graph is not designed for
     * per-card sub-toggles, so this is a lightweight parallel option instead.
     *
     * @param string $builder One of: elementor, divi, beaver_builder, oxygen
     * @return bool
     */
    public static function isBuilderEnabled(string $builder): bool
    {
        $settings = get_option(Options::PAGE_BUILDERS_SETTINGS, []);

        if (!is_array($settings) || !isset($settings[ $builder ])) {
            // Default: enabled as soon as the builder is detected.
            return true;
        }

        return (bool) $settings[ $builder ];
    }

    /**
     * {@inheritDoc}
     */
    public function onActivate(): void
    {
        // No initial setup needed — sub-toggles default to "enabled" on read.
    }

    /**
     * {@inheritDoc}
     */
    public function onDeactivate(): void
    {
        // No cleanup needed — builder adapters simply stop registering hooks.
    }
}
