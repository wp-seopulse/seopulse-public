<?php

/**
 * Abstract class for all analysis modules
 *
 * @package SEOPulse\Core\Abstracts
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Abstracts;

use SEOPulse\Core\Contracts\ExecuteHooks;

/**
 * Abstract Module class
 *
 * Concrete modules that register WordPress hooks
 * must implement ExecuteHooks (or ExecuteHooksAdmin/ExecuteHooksFrontend)
 * and define the hooks() method.
 */
abstract class Module implements ExecuteHooks {

	/**
	 * Module name
	 *
	 * @var string
	 */
	protected string $name;

	/**
	 * Module weight in the global score (0-1)
	 *
	 * @var float
	 */
	protected float $weight = 1.0;

	/**
	 * Registers the module's WordPress hooks
	 *
	 * @return void
	 */
	public function hooks(): void {
		// Can be overridden by child modules
	}

	/**
	 * Initializes the module (backward compatibility)
	 *
	 * @deprecated 2.0.0 Use hooks() instead
	 * @return void
	 */
	public function init(): void {
		$this->hooks();
	}

	/**
	 * Analyzes the content
	 *
	 * @param \WP_Post $post WordPress post
	 * @return array{score: int, issues: array, recommendations: array}
	 */
	abstract public function analyze( \WP_Post $post ): array;

	/**
	 * Returns the module name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Returns the module weight
	 *
	 * @return float
	 */
	public function get_weight(): float {
		return $this->weight;
	}

	/**
	 * Sets the module weight
	 *
	 * @param float $weight Weight (0-1)
	 * @return void
	 */
	public function set_weight( float $weight ): void {
		$this->weight = max( 0.0, min( 1.0, $weight ) );
	}
}
