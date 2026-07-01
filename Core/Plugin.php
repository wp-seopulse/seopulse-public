<?php

/**
 * Main plugin class for SEOPulse
 *
 * Manages initialization, module loading and general coordination
 *
 * @package SEOPulse\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core;

use SEOPulse\Core\Module\ModuleManager;
use SEOPulse\Services\CacheManager;

/**
 * Main class - Singleton Pattern
 */
final class Plugin {

	/**
	 * Unique instance
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Hook manager
	 *
	 * @var Loader
	 */
	private Loader $loader;

	/**
	 * Assets manager
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * Cache manager
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Loaded modules
	 *
	 * @var array<string, object>
	 */
	private array $modules = array();

	/**
	 * Initialization state
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Private constructor (Singleton)
	 */
	private function __construct() {
		$this->loader = new Loader();
		$this->assets = new Assets();
		$this->cache  = new CacheManager();
	}

	/**
	 * Returns the unique instance
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initializes the plugin
	 *
	 * With the Kernel, hooks are dispatched automatically.
	 * This method ensures backward compatibility and handles
	 * tasks that are not covered by interfaces.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		// Module registration in the internal map (backward compatibility)
		$this->register_modules_map();

		$this->initialized = true;

		/**
		 * Action triggered after complete initialization
		 *
		 * @since 1.0.0
		 */
		do_action( 'seopulse_initialized' );
	}

	/**
	 * Registers the internal module map (backward compatibility)
	 *
	 * Allows get_module() to work as before
	 * without recreating instances — reuses the Container.
	 *
	 * @return void
	 */
	private function register_modules_map(): void {
		$container = Kernel::getContainer();

		// Map short names to Container classes
		// with the associated Kernel module key for gating
		$moduleMap = array(
			'content_analyzer'     => array(
				'class'      => \SEOPulse\Modules\Content\ContentAnalyzer::class,
				'module_key' => 'content_analysis',
			),
			'meta_analyzer'        => array(
				'class'      => \SEOPulse\Modules\Content\MetaAnalyzer::class,
				'module_key' => 'content_analysis',
			),
			'readability_analyzer' => array(
				'class'      => \SEOPulse\Modules\Content\ReadabilityAnalyzer::class,
				'module_key' => 'content_analysis',
			),
			'meta_seo'             => array(
				'class'      => \SEOPulse\Modules\MetaSeo\MetaSeoModule::class,
				'module_key' => 'meta_seo',
			),
			'local_seo'            => array(
				'class'      => \SEOPulse\Modules\LocalSeo\LocalSeoModule::class,
				'module_key' => 'local_seo',
			),
			'redirections'         => array(
				'class'      => \SEOPulse\Modules\Redirections\RedirectionsModule::class,
				'module_key' => 'redirections',
			),
			'sitemap'              => array(
				'class'      => \SEOPulse\Modules\Sitemap\SitemapModule::class,
				'module_key' => 'sitemap',
			),
			'analytics'            => array(
				'class'      => \SEOPulse\Modules\Analytics\AnalyticsModule::class,
				'module_key' => 'analytics',
			),
		);

		foreach ( $moduleMap as $name => $config ) {
			// Do not load disabled modules
			if ( ! ModuleManager::instance()->isModuleEnabled( $config['module_key'] ) ) {
				continue;
			}

			$instance = $container->getAction( $config['class'] );
			if ( $instance !== null ) {
				$this->modules[ $name ] = $instance;
			}
		}

		/**
		 * Filter allowing to add custom modules
		 *
		 * @since 1.0.0
		 * @param array $modules List of loaded modules
		 */
		$this->modules = apply_filters( 'seopulse_loaded_modules', $this->modules );
	}

	/**
	 * Retrieves a specific module
	 *
	 * @param string $module_name Module name
	 * @return object|null
	 */
	public function get_module( string $module_name ): ?object {
		return $this->modules[ $module_name ] ?? null;
	}

	/**
	 * Retrieves the cache manager
	 *
	 * @return CacheManager
	 */
	public function cache(): CacheManager {
		return $this->cache;
	}

	/**
	 * Retrieves the loader
	 *
	 * @return Loader
	 */
	public function loader(): Loader {
		return $this->loader;
	}

	/**
	 * Prevents cloning
	 */
	private function __clone() {
	}

	/**
	 * Prevents unserialization
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
