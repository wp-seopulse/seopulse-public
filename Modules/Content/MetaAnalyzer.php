<?php

/**
 * Meta tags analysis module (title, description) - Improved version
 *
 * Detailed analysis with precise pixel calculation, multi-plugin compatibility
 * Inspired by Yoast SEO, Rank Math and SEOPress
 *
 * @package SEOPulse\Modules\Content
 * @since 1.0.0
 * @version 1.1.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Content;

use SEOPulse\Core\Abstracts\Module;
use SEOPulse\Core\Traits\FocusKeywordTrait;

/**
 * MetaAnalyzer class
 */
class MetaAnalyzer extends Module {

	use FocusKeywordTrait;

	/**
	 * Recommended configuration (based on Google 2024)
	 *
	 * @var array
	 */
	private array $config = array(
		// Meta Title
		'title_min_length'     => 30,
		'title_max_length'     => 60,
		'title_optimal_min'    => 50,
		'title_optimal_max'    => 60,
		'title_max_pixels'     => 600,      // Max width in Google SERPs
		'title_optimal_pixels' => 580,   // Optimal width

		// Meta Description
		'desc_min_length'      => 120,
		'desc_max_length'      => 160,
		'desc_optimal_min'     => 140,
		'desc_optimal_max'     => 155,
		'desc_max_pixels'      => 920,        // Max width in SERPs
		'desc_optimal_pixels'  => 900,     // Optimal width

		// Open Graph
		'og_title_max'         => 95,
		'og_desc_max'          => 200,
	);

	/**
	 * Focus keyword
	 *
	 * @var string
	 */
	private string $focus_keyword = '';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->name   = 'meta';
		$this->weight = 0.25; // 25% of total score

		/**
		 * Filters the MetaAnalyzer configuration
		 *
		 * @since 1.0.0
		 * @param array $config Configuration
		 */
		$this->config = apply_filters( 'seopulse_meta_analyzer_config', $this->config );
	}

	/**
	 * Analyzes a post's meta tags
	 *
	 * @param \WP_Post $post WordPress post
	 * @return array{score: int, issues: array, recommendations: array, data: array, checks: array}
	 */
	public function analyze( \WP_Post $post ): array {
		$score           = 100;
		$issues          = array();
		$recommendations = array();
		$data            = array();
		$checks          = array();

		// Retrieve the focus keyword
		$this->focus_keyword = $this->get_focus_keyword( $post );

		// Retrieve meta (compatibility with Yoast, RankMath, etc.)
		$meta_title       = $this->get_meta_title( $post );
		$meta_description = $this->get_meta_description( $post );

		// 1. Meta title analysis
		$title_analysis     = $this->analyze_meta_title( $meta_title, $post->post_title );
		$score             -= $title_analysis['penalty'];
		$issues             = array_merge( $issues, $title_analysis['issues'] );
		$recommendations    = array_merge( $recommendations, $title_analysis['recommendations'] );
		$data['meta_title'] = $title_analysis['data'];
		$checks             = array_merge( $checks, $title_analysis['checks'] );

		// 2. Meta description analysis
		$desc_analysis            = $this->analyze_meta_description( $meta_description );
		$score                   -= $desc_analysis['penalty'];
		$issues                   = array_merge( $issues, $desc_analysis['issues'] );
		$recommendations          = array_merge( $recommendations, $desc_analysis['recommendations'] );
		$data['meta_description'] = $desc_analysis['data'];
		$checks                   = array_merge( $checks, $desc_analysis['checks'] );

		// 3. Open Graph analysis
		$og_analysis        = $this->analyze_open_graph( $post );
		$score             -= $og_analysis['penalty'];
		$recommendations    = array_merge( $recommendations, $og_analysis['recommendations'] );
		$data['open_graph'] = $og_analysis['data'];
		$checks             = array_merge( $checks, $og_analysis['checks'] );

		// 4. Twitter Cards analysis
		$twitter_analysis = $this->analyze_twitter_cards( $post );
		$score           -= $twitter_analysis['penalty'];
		$recommendations  = array_merge( $recommendations, $twitter_analysis['recommendations'] );
		$data['twitter']  = $twitter_analysis['data'];
		$checks           = array_merge( $checks, $twitter_analysis['checks'] );

		return array(
			'score'           => max( 0, min( 100, $score ) ),
			'issues'          => $issues,
			'recommendations' => $recommendations,
			'data'            => $data,
			'checks'          => $checks,
		);
	}

	/**
	 * Retrieves the meta title
	 *
	 * @param \WP_Post $post WordPress post
	 * @return string
	 */
	private function get_meta_title( \WP_Post $post ): string {
		// Priority order: custom meta > Yoast > RankMath > SEOPress > post title

		// SEOPulse custom meta
		$custom_meta = get_post_meta( $post->ID, '_seopulse_meta_title', true );
		if ( ! empty( $custom_meta ) ) {
			return $custom_meta;
		}

		// Module MetaSeo
		$meta_seo = get_post_meta( $post->ID, '_seopulse_meta_seo', true );
		if ( is_array( $meta_seo ) && ! empty( $meta_seo['title'] ) ) {
			return $meta_seo['title'];
		}

		// Yoast SEO
		$yoast_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
		if ( ! empty( $yoast_title ) ) {
			return $yoast_title;
		}

		// Rank Math
		$rankmath_title = get_post_meta( $post->ID, 'rank_math_title', true );
		if ( ! empty( $rankmath_title ) ) {
			return $rankmath_title;
		}

		// SEOPress
		$seopress_title = get_post_meta( $post->ID, '_seopress_titles_title', true );
		if ( ! empty( $seopress_title ) ) {
			return $seopress_title;
		}

		// Default: post title
		return $post->post_title;
	}

	/**
	 * Retrieves the meta description
	 *
	 * @param \WP_Post $post WordPress post
	 * @return string
	 */
	private function get_meta_description( \WP_Post $post ): string {
		// SEOPulse custom meta
		$custom_meta = get_post_meta( $post->ID, '_seopulse_meta_description', true );
		if ( ! empty( $custom_meta ) ) {
			return $custom_meta;
		}

		// Module MetaSeo
		$meta_seo = get_post_meta( $post->ID, '_seopulse_meta_seo', true );
		if ( is_array( $meta_seo ) && ! empty( $meta_seo['description'] ) ) {
			return $meta_seo['description'];
		}

		// Yoast SEO
		$yoast_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( ! empty( $yoast_desc ) ) {
			return $yoast_desc;
		}

		// Rank Math
		$rankmath_desc = get_post_meta( $post->ID, 'rank_math_description', true );
		if ( ! empty( $rankmath_desc ) ) {
			return $rankmath_desc;
		}

		// SEOPress
		$seopress_desc = get_post_meta( $post->ID, '_seopress_titles_desc', true );
		if ( ! empty( $seopress_desc ) ) {
			return $seopress_desc;
		}

		// Default: excerpt or beginning of content
		if ( ! empty( $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}

		return wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
	}

	/**
	 * Analyzes the meta title - Improved version
	 *
	 * @param string $meta_title Meta title
	 * @param string $post_title Post title (fallback)
	 * @return array{penalty: int, issues: array, recommendations: array, data: array, checks: array}
	 */
	private function analyze_meta_title( string $meta_title, string $post_title ): array {
		$penalty         = 0;
		$issues          = array();
		$recommendations = array();
		$checks          = array();

		$length      = mb_strlen( $meta_title );
		$pixel_width = $this->estimate_pixel_width( $meta_title );
		$word_count  = str_word_count( $meta_title );

		$data = array(
			'value'            => $meta_title,
			'length'           => $length,
			'pixel_width'      => $pixel_width,
			'word_count'       => $word_count,
			'is_custom'        => $meta_title !== $post_title,
			'has_keyword'      => false,
			'keyword_position' => -1,
		);

		// Check for keyword presence
		if ( ! empty( $this->focus_keyword ) ) {
			if ( self::hasKeywordIn( $meta_title, $this->focus_keyword ) ) {
				$data['has_keyword']      = true;
				$normTitle                = self::normalizeForMatch( $meta_title );
				$normKw                   = self::normalizeForMatch( trim( explode( ',', $this->focus_keyword )[0] ) );
				$pos                      = $normKw !== '' ? mb_strpos( $normTitle, $normKw ) : false;
				$data['keyword_position'] = $pos !== false ? $pos : 0;
			}
		}

		// Check: Empty meta title
		if ( empty( $meta_title ) ) {
			$penalty          += 20;
			$issues[]          = array(
				'type'     => 'meta_title_missing',
				'severity' => 'high',
				'message'  => __( 'Meta title is missing', 'seopulse' ),
			);
			$recommendations[] = array(
				'type'             => 'meta_title',
				'priority'         => 'high',
				'message'          => __( 'No custom meta title is set. Search engines will use your post title instead.', 'seopulse' ),
				'action'           => __( 'Create a compelling meta title optimized for search results (50-60 characters)', 'seopulse' ),
				'estimated_impact' => 20,
			);
			$checks[]          = array(
				'name'    => 'meta_title_present',
				'status'  => 'error',
				'message' => __( 'Meta title is missing', 'seopulse' ),
			);
		} else {
			$checks[] = array(
				'name'    => 'meta_title_present',
				'status'  => 'success',
				'message' => __( 'Meta title is set', 'seopulse' ),
			);
		}

		// Check: Character length
		if ( $length > 0 ) {
			if ( $length < $this->config['title_min_length'] ) {
				$penalty          += 10;
				$recommendations[] = array(
					'type'             => 'meta_title',
					'priority'         => 'medium',
					'message'          => sprintf(
						/* translators: %d: current length */
						__( 'Your meta title is only %d characters. It should be longer to use available space in search results.', 'seopulse' ),
						$length,
					),
					'action'           => __( 'Expand your meta title to 50-60 characters', 'seopulse' ),
					'estimated_impact' => 10,
				);
				$checks[]          = array(
					'name'    => 'meta_title_length',
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: %d: character count */
						__( 'Meta title too short: %d chars', 'seopulse' ),
						$length,
					),
				);
			} elseif ( $length >= $this->config['title_optimal_min'] && $length <= $this->config['title_optimal_max'] ) {
				$checks[] = array(
					'name'    => 'meta_title_length',
					'status'  => 'success',
					'message' => sprintf(
						/* translators: %d: character count */
						__( 'Meta title length optimal: %d chars', 'seopulse' ),
						$length,
					),
				);
			} elseif ( $length > $this->config['title_max_length'] ) {
				$checks[] = array(
					'name'    => 'meta_title_length',
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: %d: character count */
						__( 'Meta title too long: %d chars', 'seopulse' ),
						$length,
					),
				);
			} else {
				$checks[] = array(
					'name'    => 'meta_title_length',
					'status'  => 'success',
					'message' => sprintf(
						/* translators: %d: character count */
						__( 'Meta title length good: %d chars', 'seopulse' ),
						$length,
					),
				);
			}
		}

		// Check: Pixel width (more precise than length)
		if ( $pixel_width > $this->config['title_max_pixels'] ) {
			$penalty          += 15;
			$issues[]          = array(
				'type'     => 'meta_title_too_long',
				'severity' => 'medium',
				'message'  => __( 'Meta title is too long', 'seopulse' ),
			);
			$recommendations[] = array(
				'type'             => 'meta_title',
				'priority'         => 'high',
				'message'          => sprintf(
					/* translators: %d: estimated pixel width */
					__( 'Your meta title is approximately %d pixels wide. Google typically displays up to 600 pixels.', 'seopulse' ),
					$pixel_width,
				),
				'action'           => __( 'Shorten your meta title to prevent truncation in search results', 'seopulse' ),
				'estimated_impact' => 15,
			);
			$checks[]          = array(
				'name'    => 'meta_title_pixel_width',
				'status'  => 'error',
				'message' => sprintf(
					/* translators: %d: estimated pixel width */
					__( 'Meta title too wide: ~%d pixels', 'seopulse' ),
					$pixel_width,
				),
			);
		} elseif ( $pixel_width > $this->config['title_optimal_pixels'] ) {
			$checks[] = array(
				'name'    => 'meta_title_pixel_width',
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %d: estimated pixel width */
					__( 'Meta title width: ~%d pixels (acceptable)', 'seopulse' ),
					$pixel_width,
				),
			);
		} else {
			$checks[] = array(
				'name'    => 'meta_title_pixel_width',
				'status'  => 'success',
				'message' => sprintf(
					/* translators: %d: estimated pixel width */
					__( 'Meta title width optimal: ~%d pixels', 'seopulse' ),
					$pixel_width,
				),
			);
		}

		// Check: Keyword in meta title
		if ( ! empty( $this->focus_keyword ) ) {
			if ( $data['has_keyword'] ) {
				$checks[] = array(
					'name'    => 'keyword_in_meta_title',
					'status'  => 'success',
					'message' => __( 'Focus keyword in meta title', 'seopulse' ),
				);

				// Bonus if at the beginning
				if ( $data['keyword_position'] < 10 ) {
					$checks[] = array(
						'name'    => 'keyword_meta_title_position',
						'status'  => 'success',
						'message' => __( 'Focus keyword at the beginning (optimal)', 'seopulse' ),
					);
				}
			} else {
				$penalty          += 12;
				$recommendations[] = array(
					'type'             => 'keyword_meta_title',
					'priority'         => 'high',
					'message'          => sprintf(
						/* translators: %s: focus keyword */
						__( 'Your focus keyword "%s" is not in the meta title.', 'seopulse' ),
						$this->focus_keyword,
					),
					'action'           => __( 'Include your focus keyword in the meta title, preferably at the beginning', 'seopulse' ),
					'estimated_impact' => 12,
				);
				$checks[]          = array(
					'name'    => 'keyword_in_meta_title',
					'status'  => 'error',
					'message' => __( 'Focus keyword not in meta title', 'seopulse' ),
				);
			}
		}

		return array(
			'penalty'         => $penalty,
			'issues'          => $issues,
			'recommendations' => $recommendations,
			'data'            => $data,
			'checks'          => $checks,
		);
	}

	/**
	 * Analyzes the meta description - Improved version
	 *
	 * @param string $meta_description Meta description
	 * @return array{penalty: int, issues: array, recommendations: array, data: array, checks: array}
	 */
	private function analyze_meta_description( string $meta_description ): array {
		$penalty         = 0;
		$issues          = array();
		$recommendations = array();
		$checks          = array();

		$length     = mb_strlen( $meta_description );
		$word_count = str_word_count( $meta_description );
		$has_cta    = $this->has_call_to_action( $meta_description );

		$data = array(
			'value'       => $meta_description,
			'length'      => $length,
			'word_count'  => $word_count,
			'has_cta'     => $has_cta,
			'has_keyword' => false,
		);

		// Check for keyword presence
		if ( ! empty( $this->focus_keyword ) ) {
			if ( self::hasKeywordIn( $meta_description, $this->focus_keyword ) ) {
				$data['has_keyword'] = true;
			}
		}

		// Check: Empty meta description
		if ( empty( $meta_description ) ) {
			$penalty          += 25;
			$issues[]          = array(
				'type'     => 'meta_description_missing',
				'severity' => 'high',
				'message'  => __( 'Meta description is missing', 'seopulse' ),
			);
			$recommendations[] = array(
				'type'             => 'meta_description',
				'priority'         => 'high',
				'message'          => __( 'No meta description is set. Search engines will generate one automatically, which may not be optimal.', 'seopulse' ),
				'action'           => __( 'Write a compelling meta description (140-155 characters) that summarizes your content and includes a call-to-action', 'seopulse' ),
				'estimated_impact' => 25,
			);
			$checks[]          = array(
				'name'    => 'meta_description_present',
				'status'  => 'error',
				'message' => __( 'Meta description is missing', 'seopulse' ),
			);
		} else {
			$checks[] = array(
				'name'    => 'meta_description_present',
				'status'  => 'success',
				'message' => __( 'Meta description is set', 'seopulse' ),
			);
		}

		// Check: Length
		if ( $length > 0 ) {
			if ( $length < $this->config['desc_min_length'] ) {
				$penalty          += 10;
				$recommendations[] = array(
					'type'             => 'meta_description',
					'priority'         => 'medium',
					'message'          => sprintf(
						/* translators: %d: current length */
						__( 'Your meta description is %d characters. It should be longer to maximize visibility.', 'seopulse' ),
						$length,
					),
					'action'           => __( 'Expand your meta description to 140-155 characters', 'seopulse' ),
					'estimated_impact' => 10,
				);
				$checks[]          = array(
					'name'    => 'meta_description_length',
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: %d: character count */
						__( 'Meta description too short: %d chars', 'seopulse' ),
						$length,
					),
				);
			} elseif ( $length > $this->config['desc_max_length'] ) {
				$penalty          += 15;
				$issues[]          = array(
					'type'     => 'meta_description_too_long',
					'severity' => 'medium',
					'message'  => __( 'Meta description is too long', 'seopulse' ),
				);
				$recommendations[] = array(
					'type'             => 'meta_description',
					'priority'         => 'high',
					'message'          => sprintf(
						/* translators: %d: current length */
						__( 'Your meta description is %d characters. Google typically shows up to 155-160 characters.', 'seopulse' ),
						$length,
					),
					'action'           => __( 'Shorten your meta description while keeping the key message and call-to-action', 'seopulse' ),
					'estimated_impact' => 15,
				);
				$checks[]          = array(
					'name'    => 'meta_description_length',
					'status'  => 'error',
					'message' => sprintf(
						/* translators: %d: character count */
						__( 'Meta description too long: %d chars', 'seopulse' ),
						$length,
					),
				);
			} elseif ( $length >= $this->config['desc_optimal_min'] && $length <= $this->config['desc_optimal_max'] ) {
				$checks[] = array(
					'name'    => 'meta_description_length',
					'status'  => 'success',
					'message' => sprintf(
						/* translators: %d: character count */
						__( 'Meta description length optimal: %d chars', 'seopulse' ),
						$length,
					),
				);
			} else {
				$checks[] = array(
					'name'    => 'meta_description_length',
					'status'  => 'success',
					'message' => sprintf(
						/* translators: %d: character count */
						__( 'Meta description length good: %d chars', 'seopulse' ),
						$length,
					),
				);
			}
		}

		// Check: Keyword in description
		if ( ! empty( $this->focus_keyword ) ) {
			if ( $data['has_keyword'] ) {
				$checks[] = array(
					'name'    => 'keyword_in_meta_description',
					'status'  => 'success',
					'message' => __( 'Focus keyword in meta description', 'seopulse' ),
				);
			} else {
				$penalty          += 8;
				$recommendations[] = array(
					'type'             => 'keyword_meta_description',
					'priority'         => 'medium',
					'message'          => __( 'Your focus keyword is not in the meta description.', 'seopulse' ),
					'action'           => __( 'Include your focus keyword naturally in the meta description', 'seopulse' ),
					'estimated_impact' => 8,
				);
				$checks[]          = array(
					'name'    => 'keyword_in_meta_description',
					'status'  => 'warning',
					'message' => __( 'Focus keyword not in meta description', 'seopulse' ),
				);
			}
		}

		// Check : Call-to-action
		if ( $length > 0 && ! $has_cta ) {
			$penalty          += 3;
			$recommendations[] = array(
				'type'             => 'meta_description_cta',
				'priority'         => 'low',
				'message'          => __( 'Your meta description doesn\'t appear to have a call-to-action.', 'seopulse' ),
				'action'           => __( 'Add an action phrase like "Learn more", "Read now", "Discover how" to encourage clicks', 'seopulse' ),
				'estimated_impact' => 3,
			);
			$checks[]          = array(
				'name'    => 'meta_description_cta',
				'status'  => 'warning',
				'message' => __( 'No call-to-action detected', 'seopulse' ),
			);
		} elseif ( $has_cta ) {
			$checks[] = array(
				'name'    => 'meta_description_cta',
				'status'  => 'success',
				'message' => __( 'Call-to-action present', 'seopulse' ),
			);
		}

		return array(
			'penalty'         => $penalty,
			'issues'          => $issues,
			'recommendations' => $recommendations,
			'data'            => $data,
			'checks'          => $checks,
		);
	}

	/**
	 * Open Graph analysis - New feature
	 *
	 * @param \WP_Post $post Post
	 * @return array{penalty: int, recommendations: array, data: array, checks: array}
	 */
	private function analyze_open_graph( \WP_Post $post ): array {
		$penalty         = 0;
		$recommendations = array();
		$checks          = array();

		// Retrieve OG data
		$og_title       = get_post_meta( $post->ID, '_seopulse_og_title', true );
		$og_description = get_post_meta( $post->ID, '_seopulse_og_description', true );
		$og_image       = get_post_meta( $post->ID, '_seopulse_og_image', true );

		// Also check other plugins
		if ( empty( $og_title ) ) {
			$og_title = get_post_meta( $post->ID, '_yoast_wpseo_opengraph-title', true );
		}
		if ( empty( $og_description ) ) {
			$og_description = get_post_meta( $post->ID, '_yoast_wpseo_opengraph-description', true );
		}

		$has_featured_image = has_post_thumbnail( $post->ID );

		$data = array(
			'og_title'           => $og_title,
			'og_description'     => $og_description,
			'og_image'           => $og_image,
			'has_featured_image' => $has_featured_image,
		);

		// Check: OG image or featured image
		if ( empty( $og_image ) && ! $has_featured_image ) {
			$penalty          += 8;
			$recommendations[] = array(
				'type'             => 'og_image',
				'priority'         => 'medium',
				'message'          => __( 'No Open Graph image set and no featured image.', 'seopulse' ),
				'action'           => __( 'Add a featured image or set a custom Open Graph image for better social sharing', 'seopulse' ),
				'estimated_impact' => 8,
			);
			$checks[]          = array(
				'name'    => 'og_image',
				'status'  => 'warning',
				'message' => __( 'No OG image or featured image', 'seopulse' ),
			);
		} else {
			$checks[] = array(
				'name'    => 'og_image',
				'status'  => 'success',
				'message' => __( 'Social sharing image available', 'seopulse' ),
			);
		}

		// Basic check for OG meta tag presence
		$has_og_tags = ! empty( $og_title ) || ! empty( $og_description ) || ! empty( $og_image ) || $has_featured_image;
		if ( $has_og_tags ) {
			$checks[] = array(
				'name'    => 'og_tags_present',
				'status'  => 'success',
				'message' => __( 'Open Graph tags configured', 'seopulse' ),
			);
		} else {
			$penalty          += 5;
			$recommendations[] = array(
				'type'             => 'og_tags',
				'priority'         => 'low',
				'message'          => __( 'Open Graph tags are not configured.', 'seopulse' ),
				'action'           => __( 'Configure Open Graph tags for better control over social media sharing', 'seopulse' ),
				'estimated_impact' => 5,
			);
			$checks[]          = array(
				'name'    => 'og_tags_present',
				'status'  => 'warning',
				'message' => __( 'Open Graph tags not configured', 'seopulse' ),
			);
		}

		return array(
			'penalty'         => $penalty,
			'recommendations' => $recommendations,
			'data'            => $data,
			'checks'          => $checks,
		);
	}

	/**
	 * Twitter Cards analysis - New feature
	 *
	 * @param \WP_Post $post Post
	 * @return array{penalty: int, recommendations: array, data: array, checks: array}
	 */
	private function analyze_twitter_cards( \WP_Post $post ): array {
		$penalty         = 0;
		$recommendations = array();
		$checks          = array();

		// Retrieve Twitter data
		$twitter_title       = get_post_meta( $post->ID, '_seopulse_twitter_title', true );
		$twitter_description = get_post_meta( $post->ID, '_seopulse_twitter_description', true );
		$twitter_image       = get_post_meta( $post->ID, '_seopulse_twitter_image', true );

		$data = array(
			'twitter_title'       => $twitter_title,
			'twitter_description' => $twitter_description,
			'twitter_image'       => $twitter_image,
		);

		// Basic check
		$has_twitter_tags = ! empty( $twitter_title ) || ! empty( $twitter_description ) || ! empty( $twitter_image );

		if ( $has_twitter_tags ) {
			$checks[] = array(
				'name'    => 'twitter_cards_present',
				'status'  => 'success',
				'message' => __( 'Twitter Cards configured', 'seopulse' ),
			);
		} else {
			// Light penalty since OG tags can serve as fallback
			$penalty          += 3;
			$recommendations[] = array(
				'type'             => 'twitter_cards',
				'priority'         => 'low',
				'message'          => __( 'Twitter Cards are not configured.', 'seopulse' ),
				'action'           => __( 'Configure Twitter Cards for optimized sharing on Twitter/X', 'seopulse' ),
				'estimated_impact' => 3,
			);
			$checks[]          = array(
				'name'    => 'twitter_cards_present',
				'status'  => 'info',
				'message' => __( 'Twitter Cards not configured (OG fallback may apply)', 'seopulse' ),
			);
		}

		return array(
			'penalty'         => $penalty,
			'recommendations' => $recommendations,
			'data'            => $data,
			'checks'          => $checks,
		);
	}

	/**
	 * Estimates the pixel width of a text (approximation for Google)
	 *
	 * Based on the font used by Google (Arial/system)
	 * Improved calculation with more precise character widths
	 *
	 * @param string $text Text to measure
	 * @return int Estimated width in pixels
	 */
	private function estimate_pixel_width( string $text ): int {
		$width  = 0;
		$length = mb_strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = mb_substr( $text, $i, 1 );

			// Very wide characters (wide uppercase + W, M)
			if ( in_array( $char, array( 'W', 'M' ), true ) ) {
				$width += 14;
			}
			// Medium uppercase
			elseif ( preg_match( '/[A-HJ-VX-Z]/', $char ) ) {
				$width += 11;
			}
			// Wide lowercase
			elseif ( in_array( $char, array( 'w', 'm' ), true ) ) {
				$width += 10;
			}
			// Standard lowercase
			elseif ( preg_match( '/[a-z]/', $char ) ) {
				$width += 7;
			}
			// Narrow characters (i, l, t, j, etc.)
			elseif ( in_array( $char, array( 'i', 'l', 't', 'j', 'I', '!', '|' ), true ) ) {
				$width += 4;
			}
			// Digits
			elseif ( preg_match( '/[0-9]/', $char ) ) {
				$width += 8;
			}
			// Space
			elseif ( $char === ' ' ) {
				$width += 4;
			}
			// Punctuation
			elseif ( preg_match( '/[.,;:!?]/', $char ) ) {
				$width += 4;
			}
			// Other characters (default)
			else {
				$width += 8;
			}
		}

		return $width;
	}

	/**
	 * Detects the presence of a call-to-action
	 *
	 * @param string $text Text to analyze
	 * @return bool
	 */
	private function has_call_to_action( string $text ): bool {
		$cta_phrases = array(
			// English
			'learn more',
			'read more',
			'discover',
			'find out',
			'click here',
			'get started',
			'sign up',
			'try now',
			'download',
			'buy now',
			'shop now',
			'contact us',
			'subscribe',
			'join us',
			'register',

			// French
			'en savoir plus',
			'découvrez',
			'cliquez ici',
			'lire la suite',
			'commencer',
			'inscrivez-vous',
			'essayez',
			'téléchargez',
			'achetez',
			'contactez-nous',
			'abonnez-vous',
			'rejoignez',
		);

		$text_lower = mb_strtolower( $text );

		foreach ( $cta_phrases as $phrase ) {
			if ( strpos( $text_lower, $phrase ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
