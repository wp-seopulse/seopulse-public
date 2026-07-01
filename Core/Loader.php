<?php

/**
 * Centralized WordPress hook manager
 *
 * Allows registering and executing all hooks (actions/filters) in an organized manner
 *
 * @package SEOPulse\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loader class
 */
class Loader {

	/**
	 * Registered actions
	 *
	 * @var array<array{hook: string, component: object, callback: string, priority: int, accepted_args: int}>
	 */
	private array $actions = array();

	/**
	 * Registered filters
	 *
	 * @var array<array{hook: string, component: object, callback: string, priority: int, accepted_args: int}>
	 */
	private array $filters = array();

	/**
	 * Adds a WordPress action
	 *
	 * @param string $hook Hook name
	 * @param object $component Object containing the method
	 * @param string $callback Method name
	 * @param int    $priority Priority (default: 10)
	 * @param int    $accepted_args Number of arguments (default: 1)
	 * @return void
	 */
	public function add_action(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1,
	): void {
		$this->actions[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Adds a WordPress filter
	 *
	 * @param string $hook Hook name
	 * @param object $component Object containing the method
	 * @param string $callback Method name
	 * @param int    $priority Priority (default: 10)
	 * @param int    $accepted_args Number of arguments (default: 1)
	 * @return void
	 */
	public function add_filter(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1,
	): void {
		$this->filters[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Executes all registered hooks
	 *
	 * @return void
	 */
	public function run(): void {
		// Register filters
		foreach ( $this->filters as $filter ) {
			add_filter(
				$filter['hook'],
				array( $filter['component'], $filter['callback'] ),
				$filter['priority'],
				$filter['accepted_args'],
			);
		}

		// Register actions
		foreach ( $this->actions as $action ) {
			add_action(
				$action['hook'],
				array( $action['component'], $action['callback'] ),
				$action['priority'],
				$action['accepted_args'],
			);
		}
	}

	/**
	 * Returns all registered actions
	 *
	 * @return array
	 */
	public function get_actions(): array {
		return $this->actions;
	}

	/**
	 * Returns all registered filters
	 *
	 * @return array
	 */
	public function get_filters(): array {
		return $this->filters;
	}
}
