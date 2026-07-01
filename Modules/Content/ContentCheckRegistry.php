<?php

/**
 * Registry for all content analysis checks
 *
 * Auto-discovers and manages individual check classes.
 *
 * @package SEOPulse\Modules\Content
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPulse\Modules\Content\Checks\ContentCheck;

/**
 * ContentCheckRegistry class
 */
class ContentCheckRegistry {

	/**
	 * Registered checks
	 *
	 * @var ContentCheck[]
	 */
	private array $checks = array();

	/**
	 * Register a check instance
	 */
	public function register( ContentCheck $check ): void {
		$this->checks[ $check->getName() ] = $check;
	}

	/**
	 * Get all registered checks
	 *
	 * @return ContentCheck[]
	 */
	public function all(): array {
		return $this->checks;
	}

	/**
	 * Get a check by name
	 */
	public function get( string $name ): ?ContentCheck {
		return $this->checks[ $name ] ?? null;
	}

	/**
	 * Build a registry with all default checks
	 */
	public static function withDefaults(): self {
		$registry = new self();

		$checkClasses = array(
			// Title
			Checks\TitlePresenceCheck::class,
			Checks\TitleLengthCheck::class,
			Checks\TitleKeywordCheck::class,
			Checks\TitleKeywordPositionCheck::class,
			// Headings
			Checks\HeadingStructureCheck::class,
			Checks\HeadingKeywordCheck::class,
			// Content length
			Checks\ContentLengthCheck::class,
			// Keywords
			Checks\KeywordInContentCheck::class,
			Checks\KeywordDensityCheck::class,
			Checks\KeywordDensitySpamCheck::class,
			Checks\KeywordSimilarityCheck::class,
			Checks\IntroKeywordCheck::class,
			Checks\SlugKeywordCheck::class,
			// Images
			Checks\FeaturedImageCheck::class,
			Checks\ImageAltCheck::class,
			// Links
			Checks\InternalLinksCheck::class,
			Checks\ExternalLinksCheck::class,
			// Structure & readability
			Checks\ParagraphLengthCheck::class,
			Checks\SentenceLengthCheck::class,
			Checks\ListUsageCheck::class,
			Checks\ReadabilityCheck::class,
		);

		foreach ( $checkClasses as $class ) {
			$registry->register( new $class() );
		}

		/**
		 * Allows add-ons to register additional content checks.
		 *
		 * @since 1.0.0
		 * @param ContentCheckRegistry $registry
		 */
		do_action( 'seopulse_register_content_checks', $registry );

		return $registry;
	}
}
