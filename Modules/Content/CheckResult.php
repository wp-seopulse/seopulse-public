<?php

/**
 * Value object representing the result of a single content check
 *
 * @package SEOPulse\Modules\Content
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CheckResult DTO
 *
 * Immutable value object returned by every ContentCheck.
 */
final class CheckResult {

	/**
	 * @param string              $name Check identifier
	 * @param string              $status 'success' | 'warning' | 'error'
	 * @param string              $message Human-readable check message
	 * @param int                 $penalty Score penalty (0 = pass)
	 * @param array<int, array>   $issues Issue entries for the issues list
	 * @param array<int, array>   $recommendations Recommendation entries
	 * @param array<string,mixed> $data Extra data to merge into the data bag
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $status,
		public readonly string $message,
		public readonly int $penalty = 0,
		public readonly array $issues = array(),
		public readonly array $recommendations = array(),
		public readonly array $data = array(),
	) {
	}

	/**
	 * Create a passing result
	 */
	public static function pass( string $name, string $message ): self {
		return new self( $name, 'success', $message );
	}

	/**
	 * Create a warning result
	 */
	public static function warning(
		string $name,
		string $message,
		int $penalty = 0,
		array $issues = array(),
		array $recommendations = array(),
		array $data = array(),
	): self {
		return new self( $name, 'warning', $message, $penalty, $issues, $recommendations, $data );
	}

	/**
	 * Create an error result
	 */
	public static function error(
		string $name,
		string $message,
		int $penalty = 0,
		array $issues = array(),
		array $recommendations = array(),
		array $data = array(),
	): self {
		return new self( $name, 'error', $message, $penalty, $issues, $recommendations, $data );
	}
}
