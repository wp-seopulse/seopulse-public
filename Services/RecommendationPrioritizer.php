<?php

/**
 * SEO recommendation prioritization service
 *
 * Sorts and organizes recommendations by impact and urgency.
 * Shared classification logic used by both editorial (metabox)
 * and dashboard (quick wins) surfaces.
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

/**
 * RecommendationPrioritizer class
 */
class RecommendationPrioritizer {

	/**
	 * Priority weights for sorting.
	 *
	 * @var array<string, int>
	 */
	private array $priority_weights = array(
		'critical' => 100,
		'high'     => 75,
		'medium'   => 50,
		'low'      => 25,
	);

	/**
	 * Category constants.
	 */
	public const CATEGORY_BLOCKER     = 'blocker';
	public const CATEGORY_QUICK_WIN   = 'quick_win';
	public const CATEGORY_IMPROVEMENT = 'improvement';

	/**
	 * Prioritizes a list of recommendations.
	 *
	 * Output keys:
	 *  - blockers:     critical items that must be fixed
	 *  - quick_wins:   high-ROI items easy/medium to implement
	 *  - others:       remaining improvements
	 *  - top:          blockers + quick_wins merged (backward compat)
	 *  - total:        total count across all groups
	 *
	 * @param array $recommendations Raw recommendation list from analysis modules
	 * @return array Sorted, enriched, and categorized recommendations
	 */
	public function prioritize( array $recommendations ): array {
		// Enrich each recommendation
		$enriched = array_map(
			function ( $rec ) {
				return $this->enrich_recommendation( $rec );
			},
			$recommendations
		);

		// Sort by priority then by estimated impact
		usort(
			$enriched,
			function ( $a, $b ) {
				$weight_a = $this->priority_weights[ $a['priority'] ] ?? 0;
				$weight_b = $this->priority_weights[ $b['priority'] ] ?? 0;

				if ( $weight_a === $weight_b ) {
					return ( $b['estimated_impact'] ?? 0 ) <=> ( $a['estimated_impact'] ?? 0 );
				}

				return $weight_b <=> $weight_a;
			}
		);

		// Split into categories
		$blockers   = array();
		$quick_wins = array();
		$others     = array();

		foreach ( $enriched as $rec ) {
			switch ( $rec['category'] ) {
				case self::CATEGORY_BLOCKER:
					$blockers[] = $rec;
					break;
				case self::CATEGORY_QUICK_WIN:
					$quick_wins[] = $rec;
					break;
				default:
					$others[] = $rec;
					break;
			}
		}

		// Limit quick wins to the 5 most impactful
		$quick_wins = array_slice( $quick_wins, 0, 5 );

		return array(
			'blockers'   => $blockers,
			'quick_wins' => $quick_wins,
			'others'     => $others,
			'top'        => array_merge( $blockers, $quick_wins ), // backward compat
			'total'      => count( $recommendations ),
		);
	}

	/**
	 * Enriches a recommendation with metadata.
	 *
	 * @param array $recommendation Raw recommendation from an analysis module
	 * @return array Enriched recommendation
	 */
	private function enrich_recommendation( array $recommendation ): array {
		$recommendation['id']               = $this->generate_recommendation_id( $recommendation );
		$recommendation['estimated_impact'] = $this->estimate_impact( $recommendation );
		$recommendation['icon']             = $this->get_icon_for_type( $recommendation['type'] ?? '' );
		$recommendation['difficulty']       = $this->estimate_difficulty( $recommendation );
		$recommendation['category']         = $this->categorize( $recommendation );
		$recommendation['pro_only']         = false;

		return $recommendation;
	}

	/**
	 * Assigns a display category based on priority and difficulty.
	 *
	 * - critical priority → blocker
	 * - high/medium priority + easy/medium difficulty → quick_win
	 * - everything else → improvement
	 *
	 * @param array $recommendation Enriched recommendation (must already have priority & difficulty)
	 * @return string One of the CATEGORY_* constants
	 */
	private function categorize( array $recommendation ): string {
		$priority   = $recommendation['priority'] ?? 'low';
		$difficulty = $recommendation['difficulty'] ?? 'hard';

		if ( $priority === 'critical' ) {
			return self::CATEGORY_BLOCKER;
		}

		if (
			in_array( $priority, array( 'high', 'medium' ), true )
			&& in_array( $difficulty, array( 'easy', 'medium' ), true )
		) {
			return self::CATEGORY_QUICK_WIN;
		}

		return self::CATEGORY_IMPROVEMENT;
	}

	/**
	 * Estimates the impact of a recommendation.
	 *
	 * @param array $recommendation Recommendation
	 * @return int Estimated impact (0-30 points)
	 */
	private function estimate_impact( array $recommendation ): int {
		$type     = $recommendation['type'] ?? '';
		$priority = $recommendation['priority'] ?? 'low';

		$base_impacts = array(
			'title_length'        => 15,
			'title_missing'       => 25,
			'meta_title'          => 20,
			'meta_description'    => 20,
			'content_length'      => 20,
			'heading_structure'   => 15,
			'readability'         => 10,
			'keyword_usage'       => 12,
			'sentence_length'     => 8,
			'word_complexity'     => 5,
			'paragraph_length'    => 5,
			// Image SEO
			'image_alt'           => 8,
			'featured_image'      => 10,
			'featured_image_size' => 4,
			'image_filenames'     => 3,
		);

		$base_impact = $base_impacts[ $type ] ?? 10;

		$priority_multipliers = array(
			'critical' => 1.5,
			'high'     => 1.2,
			'medium'   => 1.0,
			'low'      => 0.7,
		);

		$multiplier = $priority_multipliers[ $priority ] ?? 1.0;

		return min( 30, (int) round( $base_impact * $multiplier ) );
	}

	/**
	 * Generates a unique ID for a recommendation.
	 *
	 * @param array $recommendation Recommendation
	 * @return string ID
	 */
	private function generate_recommendation_id( array $recommendation ): string {
		$type     = $recommendation['type'] ?? 'unknown';
		$priority = $recommendation['priority'] ?? 'low';

		return 'rec_' . md5( $type . $priority . ( $recommendation['message'] ?? '' ) );
	}

	/**
	 * Returns an icon for a recommendation type.
	 *
	 * @param string $type Recommendation type
	 * @return string Icon name (Dashicons compatible)
	 */
	private function get_icon_for_type( string $type ): string {
		$icons = array(
			'title_length'        => 'edit',
			'title_missing'       => 'warning',
			'meta_title'          => 'tag',
			'meta_description'    => 'media-document',
			'content_length'      => 'media-text',
			'heading_structure'   => 'editor-justify',
			'readability'         => 'book',
			'keyword_usage'       => 'search',
			'no_h2_headings'      => 'editor-removeformatting',
			// Image SEO
			'image_alt'           => 'format-image',
			'featured_image'      => 'images-alt2',
			'featured_image_size' => 'image-crop',
			'image_filenames'     => 'admin-media',
		);

		return $icons[ $type ] ?? 'lightbulb';
	}

	/**
	 * Estimates the implementation difficulty.
	 *
	 * @param array $recommendation Recommendation
	 * @return string Difficulty (easy, medium, hard)
	 */
	private function estimate_difficulty( array $recommendation ): string {
		$type = $recommendation['type'] ?? '';

		$easy_types = array(
			'meta_title',
			'meta_description',
			'title_length',
			// Image SEO — adding alt text or a featured image is a quick, direct edit
			'image_alt',
			'featured_image',
			'image_filenames',
		);

		$medium_types = array(
			'heading_structure',
			'keyword_usage',
			'paragraph_length',
		);

		if ( in_array( $type, $easy_types, true ) ) {
			return 'easy';
		} elseif ( in_array( $type, $medium_types, true ) ) {
			return 'medium';
		} else {
			return 'hard';
		}
	}
}
