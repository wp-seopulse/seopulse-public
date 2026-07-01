<?php

/**
 * Complete SEO analyzer for the MetaSEO module
 *
 * @package SEOPulse\Modules\MetaSeo
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo;

use WP_Post;

/**
 * MetaSeoAnalyzer class
 */
class MetaSeoAnalyzer {

	/**
	 * Complete analysis of a post
	 *
	 * @param WP_Post $post WordPress post
	 * @return array Analysis result
	 */
	public function analyze( WP_Post $post ): array {
		$meta = get_post_meta( $post->ID, '_seopulse_meta_seo', true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$content = $post->post_content;

		// Global score calculation
		$score_data = $this->calculate_score( $post, $meta, $content );

		// Detailed checks list
		$checks = $this->get_checks( $post, $meta, $content );

		// Improvement suggestions
		$suggestions = $this->generate_suggestions( $post, $meta, $content, $score_data );

		// Save history
		$this->save_score_history( $post->ID, $score_data );

		// Prepare recommendations for SEOPulse
		$recommendations = $this->convert_suggestions_to_recommendations( $suggestions, $checks );

		return array(
			'score'           => $score_data['value'],
			'issues'          => $this->extract_issues( $checks ),
			'recommendations' => $recommendations,
			'data'            => array(
				'score_details' => $score_data,
				'checks'        => $checks,
				'suggestions'   => $suggestions,
			),
		);
	}

	/**
	 * Calculates the global SEO score (0-100)
	 *
	 * @param WP_Post $post Post
	 * @param array   $meta Meta data
	 * @param string  $content Content
	 * @return array Detailed score with classification
	 */
	private function calculate_score( WP_Post $post, array $meta, string $content ): array {
		$score = 0;

		// SEO title (20 points)
		$score += $this->score_title( $meta ) * 20;

		// SEO description (20 points)
		$score += $this->score_description( $meta ) * 20;

		// Keywords (10 points)
		$score += ( ! empty( $meta['keywords'] ) ? 10 : 0 );

		// Indexing (10 points)
		$robots = $meta['robots'] ?? 'index,follow';
		$score += ( $robots === 'index,follow' ? 10 : 0 );

		// Featured image (10 points)
		$score += ( has_post_thumbnail( $post->ID ) ? 10 : 0 );

		// Content length (10 points)
		$score += ( strlen( wp_strip_all_tags( $content ) ) > 300 ? 10 : 0 );

		// H1-H3 tags (10 points)
		$score += ( preg_match( '/<h[1-3][^>]*>.*?<\/h[1-3]>/i', $content ) ? 10 : 0 );

		// Internal links (5 points)
		$score += ( preg_match_all( '/<a[^>]+href=["\'][^"\']*["\'][^>]*>/i', $content ) > 0 ? 5 : 0 );

		// Readability (5 points)
		$readability = $this->calculate_readability( $content );
		$score      += ( $readability >= 60 ? 5 : 0 );

		return $this->classify_score( (int) $score );
	}

	/**
	 * Scores the title (0-1)
	 */
	private function score_title( array $meta ): float {
		if ( empty( $meta['title'] ) ) {
			return 0;
		}

		$length = mb_strlen( $meta['title'] );

		if ( $length >= 30 && $length <= 60 ) {
			return 1.0;
		}

		if ( $length >= 20 && $length <= 70 ) {
			return 0.75;
		}

		if ( $length > 0 ) {
			return 0.5;
		}

		return 0;
	}

	/**
	 * Scores the description (0-1)
	 */
	private function score_description( array $meta ): float {
		if ( empty( $meta['description'] ) ) {
			return 0;
		}

		$length = mb_strlen( $meta['description'] );

		if ( $length >= 120 && $length <= 160 ) {
			return 1.0;
		}

		if ( $length >= 100 && $length <= 170 ) {
			return 0.75;
		}

		if ( $length > 0 ) {
			return 0.5;
		}

		return 0;
	}

	/**
	 * Classifies the score into categories
	 */
	private function classify_score( int $score ): array {
		if ( $score >= 80 ) {
			return array(
				'value'       => $score,
				'class'       => 'excellent',
				'label'       => __( 'Excellent', 'seopulse' ),
				'description' => __( 'Your content is perfectly optimized for SEO!', 'seopulse' ),
			);
		}

		if ( $score >= 60 ) {
			return array(
				'value'       => $score,
				'class'       => 'good',
				'label'       => __( 'Good', 'seopulse' ),
				'description' => __( 'Your content is well optimized, some improvements possible.', 'seopulse' ),
			);
		}

		if ( $score >= 40 ) {
			return array(
				'value'       => $score,
				'class'       => 'average',
				'label'       => __( 'Average', 'seopulse' ),
				'description' => __( 'Your content needs SEO improvements.', 'seopulse' ),
			);
		}

		return array(
			'value'       => $score,
			'class'       => 'poor',
			'label'       => __( 'Needs Improvement', 'seopulse' ),
			'description' => __( 'Your content requires significant SEO optimization.', 'seopulse' ),
		);
	}

	/**
	 * Gets the list of SEO checks
	 */
	private function get_checks( WP_Post $post, array $meta, string $content ): array {
		$checks = array();

		// 1. Title tag
		$title        = $meta['title'] ?? get_the_title( $post->ID );
		$title_length = mb_strlen( $title );
		$title_status = ( $title_length >= 30 && $title_length <= 60 ) ? 'good' : 'warning';

		$checks[] = array(
			'title'       => __( 'Title Tag', 'seopulse' ),
			'description' => sprintf(
				/* translators: %d: title character count */
				__( 'Title length: %d characters (recommended: 30-60).', 'seopulse' ),
				$title_length,
			),
			'status'      => $title_status,
			'progress'    => min( ( $title_length / 60 ) * 100, 100 ),
			'advice'      => $this->get_advice( 'title', $title_status ),
		);

		// 2. Meta description
		$desc_length = ! empty( $meta['description'] ) ? mb_strlen( $meta['description'] ) : 0;
		$desc_status = ( $desc_length >= 120 && $desc_length <= 160 ) ? 'good' :
						( $desc_length > 0 ? 'warning' : 'bad' );

		$checks[] = array(
			'title'       => __( 'Meta Description', 'seopulse' ),
			'description' => sprintf(
				/* translators: %d: description character count */
				__( 'Description length: %d characters (recommended: 120-160).', 'seopulse' ),
				$desc_length,
			),
			'status'      => $desc_status,
			'progress'    => min( ( $desc_length / 160 ) * 100, 100 ),
			'advice'      => $this->get_advice( 'description', $desc_status ),
		);

		// 3. Keywords in permalink
		$permalink    = get_permalink( $post->ID );
		$has_keywords = ! empty( $meta['keywords'] ) &&
						strpos( $permalink, sanitize_title( $meta['keywords'] ) ) !== false;

		$checks[] = array(
			'title'       => __( 'Keywords in URL', 'seopulse' ),
			'description' => __( 'URL contains relevant keywords.', 'seopulse' ),
			'status'      => $has_keywords ? 'good' : 'warning',
			'advice'      => $has_keywords ?
				__( 'URL optimized.', 'seopulse' ) :
				__( 'Add keywords to the URL.', 'seopulse' ),
		);

		// 4. H1-H3 headings
		preg_match_all( '/<h1[^>]*>.*?<\/h1>/i', $content, $h1_matches );
		preg_match_all( '/<h2[^>]*>.*?<\/h2>/i', $content, $h2_matches );
		preg_match_all( '/<h3[^>]*>.*?<\/h3>/i', $content, $h3_matches );

		$h1_count = count( $h1_matches[0] );
		$h2_count = count( $h2_matches[0] );
		$h3_count = count( $h3_matches[0] );

		$heading_status = ( $h1_count === 1 && $h2_count > 0 ) ? 'good' :
						( $h1_count > 0 ? 'warning' : 'bad' );

		$checks[] = array(
			'title'       => __( 'Heading Tags', 'seopulse' ),
			'description' => sprintf(
				/* translators: 1: H1 count, 2: H2 count, 3: H3 count */
				__( 'H1: %1$d, H2: %2$d, H3: %3$d', 'seopulse' ),
				$h1_count,
				$h2_count,
				$h3_count,
			),
			'status'      => $heading_status,
			'advice'      => $this->get_advice( 'headings', $heading_status ),
		);

		// 5. Featured image
		$has_thumbnail = has_post_thumbnail( $post->ID );

		$checks[] = array(
			'title'       => __( 'Featured Image', 'seopulse' ),
			'description' => $has_thumbnail ?
				__( 'Featured image is set.', 'seopulse' ) :
				__( 'No featured image.', 'seopulse' ),
			'status'      => $has_thumbnail ? 'good' : 'warning',
			'advice'      => $has_thumbnail ?
				__( 'Image optimized.', 'seopulse' ) :
				__( 'Add a featured image.', 'seopulse' ),
		);

		// 6. Content length
		$content_text = wp_strip_all_tags( $content );
		$word_count   = str_word_count( $content_text );

		$length_status = ( $word_count >= 300 ) ? 'good' :
						( $word_count >= 150 ? 'warning' : 'bad' );

		$checks[] = array(
			'title'       => __( 'Content Length', 'seopulse' ),
			'description' => sprintf(
				/* translators: %d: word count */
				__( 'Word count: %d (recommended: 300+).', 'seopulse' ),
				$word_count,
			),
			'status'      => $length_status,
			'advice'      => $this->get_advice( 'content_length', $length_status ),
		);

		// 7. Readability
		$readability        = $this->calculate_readability( $content );
		$readability_status = ( $readability >= 60 ) ? 'good' :
							( $readability >= 40 ? 'warning' : 'bad' );

		$checks[] = array(
			'title'       => __( 'Readability', 'seopulse' ),
			'description' => sprintf(
				/* translators: %d: Flesch readability score */
				__( 'Flesch score: %d/100', 'seopulse' ),
				$readability,
			),
			'status'      => $readability_status,
			'advice'      => $this->get_advice( 'readability', $readability_status ),
		);

		// 8. Keyword density
		if ( ! empty( $meta['keywords'] ) ) {
			$density        = $this->calculate_keyword_density( $content, $meta['keywords'] );
			$density_status = ( $density >= 0.5 && $density <= 2.5 ) ? 'good' :
							( $density > 0 ? 'warning' : 'bad' );

			$checks[] = array(
				'title'       => __( 'Keyword Density', 'seopulse' ),
				'description' => sprintf(
					/* translators: %f: keyword density percentage */
					__( 'Density: %.2f%% (recommended: 0.5-2.5%%).', 'seopulse' ),
					$density,
				),
				'status'      => $density_status,
				'advice'      => $this->get_advice( 'keyword_density', $density_status ),
			);
		}

		// 9. Internal links
		preg_match_all( '/<a[^>]+href=["\']([^"\']*)["\'][^>]*>/i', $content, $link_matches );
		$internal_links = 0;

		foreach ( $link_matches[1] as $url ) {
			if ( strpos( $url, home_url() ) !== false || strpos( $url, '/' ) === 0 ) {
				++$internal_links;
			}
		}

		$links_status = ( $internal_links > 0 ) ? 'good' : 'warning';

		$checks[] = array(
			'title'       => __( 'Internal Links', 'seopulse' ),
			'description' => sprintf(
				/* translators: %d: number of internal links */
				__( 'Internal links: %d', 'seopulse' ),
				$internal_links,
			),
			'status'      => $links_status,
			'advice'      => $links_status === 'good' ?
				__( 'Good internal linking.', 'seopulse' ) :
				__( 'Add internal links.', 'seopulse' ),
		);

		// 10. Canonical tags
		$has_canonical = ! empty( $meta['canonical'] );

		$checks[] = array(
			'title'       => __( 'Canonical Tag', 'seopulse' ),
			'description' => __( 'Single canonical tag recommended.', 'seopulse' ),
			'status'      => $has_canonical ? 'good' : 'neutral',
			'advice'      => $this->get_advice( 'canonical', $has_canonical ? 'good' : 'neutral' ),
		);

		// 11. Social meta tags
		$has_og        = ! empty( $meta['og_title'] ) || ! empty( $meta['og_description'] ) || has_post_thumbnail( $post->ID );
		$has_twitter   = ! empty( $meta['twitter_title'] ) || ! empty( $meta['twitter_description'] );
		$social_status = ( $has_og && $has_twitter ) ? 'good' : 'warning';

		$checks[] = array(
			'title'       => __( 'Social Meta Tags', 'seopulse' ),
			'description' => __( 'Open Graph and Twitter Cards configured.', 'seopulse' ),
			'status'      => $social_status,
			'advice'      => $social_status === 'good' ?
				__( 'Social tags optimized.', 'seopulse' ) :
				__( 'Configure social tags.', 'seopulse' ),
		);

		return $checks;
	}

	/**
	 * Generates improvement suggestions
	 */
	private function generate_suggestions( WP_Post $post, array $meta, string $content, array $score_data ): array {
		$suggestions = array();

		if ( $score_data['value'] < 80 ) {
			if ( empty( $meta['title'] ) || mb_strlen( $meta['title'] ) < 30 ) {
				$suggestions[] = __( 'Add an SEO title of 30-60 characters', 'seopulse' );
			}

			if ( empty( $meta['description'] ) || mb_strlen( $meta['description'] ) < 120 ) {
				$suggestions[] = __( 'Write a meta description of 120-160 characters', 'seopulse' );
			}

			if ( empty( $meta['keywords'] ) ) {
				$suggestions[] = __( 'Define main keywords', 'seopulse' );
			}
		}

		$content_word_count = str_word_count( wp_strip_all_tags( $content ) );
		if ( $content_word_count < 300 ) {
			$suggestions[] = __( 'Increase content length (minimum 300 words)', 'seopulse' );
		}

		preg_match_all( '/<h1[^>]*>.*?<\/h1>/i', $content, $h1_matches );
		if ( count( $h1_matches[0] ) === 0 ) {
			$suggestions[] = __( 'Add an H1 tag to the content', 'seopulse' );
		}

		if ( ! has_post_thumbnail( $post->ID ) ) {
			$suggestions[] = __( 'Add a featured image', 'seopulse' );
		}

		return array_slice( $suggestions, 0, 5 );
	}

	/**
	 * Calculates keyword density
	 */
	private function calculate_keyword_density( string $content, string $keywords ): float {
		if ( empty( $keywords ) || empty( $content ) ) {
			return 0;
		}

		$content_text = wp_strip_all_tags( $content );
		$total_words  = str_word_count( $content_text );

		if ( $total_words === 0 ) {
			return 0;
		}

		$keyword_count = 0;
		foreach ( explode( ',', $keywords ) as $keyword ) {
			$keyword = trim( $keyword );
			if ( $keyword ) {
				$keyword_count += substr_count( strtolower( $content_text ), strtolower( $keyword ) );
			}
		}

		return ( $keyword_count / $total_words ) * 100;
	}

	/**
	 * Calculates the Flesch readability score (0-100)
	 */
	private function calculate_readability( string $content ): int {
		$content_text = wp_strip_all_tags( $content );

		if ( empty( $content_text ) ) {
			return 0;
		}

		$sentences      = preg_split( '/[.!?]+/', $content_text, -1, PREG_SPLIT_NO_EMPTY );
		$sentence_count = count( $sentences );

		$word_count = str_word_count( $content_text );

		if ( $sentence_count === 0 || $word_count === 0 ) {
			return 0;
		}

		$syllable_count = 0;
		foreach ( str_word_count( $content_text, 1 ) as $word ) {
			$syllable_count += $this->count_syllables( $word );
		}

		if ( $syllable_count > 0 ) {
			$score = 206.835 - ( 1.015 * ( $word_count / $sentence_count ) ) - ( 84.6 * ( $syllable_count / $word_count ) );

			return max( 0, min( 100, (int) $score ) );
		}

		return 50;
	}

	/**
	 * Counts the syllables of a word
	 */
	private function count_syllables( string $word ): int {
		$word               = strtolower( $word );
		$syllables          = 0;
		$vowels             = 'aeiouy';
		$previous_was_vowel = false;

		for ( $i = 0; $i < strlen( $word ); $i++ ) {
			$is_vowel = strpos( $vowels, $word[ $i ] ) !== false;
			if ( $is_vowel && ! $previous_was_vowel ) {
				++$syllables;
			}
			$previous_was_vowel = $is_vowel;
		}

		if ( substr( $word, -1 ) === 'e' ) {
			--$syllables;
		}

		return $syllables === 0 ? 1 : $syllables;
	}

	/**
	 * Gets advice based on type and status
	 */
	private function get_advice( string $type, string $status ): string {
		$advice_map = array(
			'title'           => array(
				'good'    => __( 'Title optimized for SEO.', 'seopulse' ),
				'warning' => __( 'Title should be between 30 and 60 characters.', 'seopulse' ),
			),
			'description'     => array(
				'good'    => __( 'Perfect description for search results.', 'seopulse' ),
				'warning' => __( 'Description should be between 120 and 160 characters.', 'seopulse' ),
				'bad'     => __( 'Add a meta description.', 'seopulse' ),
			),
			'readability'     => array(
				'good'    => __( 'Content is easy to read.', 'seopulse' ),
				'warning' => __( 'Use shorter sentences.', 'seopulse' ),
				'bad'     => __( 'Simplify vocabulary.', 'seopulse' ),
			),
			'content_length'  => array(
				'good'    => __( 'Optimal content length.', 'seopulse' ),
				'warning' => __( 'Add more content (minimum 300 words).', 'seopulse' ),
				'bad'     => __( 'Content is too short.', 'seopulse' ),
			),
			'headings'        => array(
				'good'    => __( 'Proper heading structure.', 'seopulse' ),
				'warning' => __( 'Use one H1 and multiple H2 tags.', 'seopulse' ),
				'bad'     => __( 'Add heading tags to structure content.', 'seopulse' ),
			),
			'keyword_density' => array(
				'good'    => __( 'Optimal keyword density.', 'seopulse' ),
				'warning' => __( 'Adjust keyword usage (0.5-2.5%).', 'seopulse' ),
				'bad'     => __( 'Add keywords to content.', 'seopulse' ),
			),
			'canonical'       => array(
				'good'    => __( 'Canonical tag is set.', 'seopulse' ),
				'neutral' => __( 'Consider setting a canonical URL.', 'seopulse' ),
			),
		);

		return $advice_map[ $type ][ $status ] ?? '';
	}

	/**
	 * Converts suggestions to SEOPulse recommendations
	 */
	private function convert_suggestions_to_recommendations( array $suggestions, array $checks ): array {
		$recommendations = array();

		foreach ( $suggestions as $suggestion ) {
			$recommendations[] = array(
				'type'     => 'meta_seo_suggestion',
				'priority' => 'medium',
				'message'  => $suggestion,
				'action'   => $suggestion,
			);
		}

		return $recommendations;
	}

	/**
	 * Extracts issues from checks
	 */
	private function extract_issues( array $checks ): array {
		$issues = array();

		foreach ( $checks as $check ) {
			if ( in_array( $check['status'], array( 'bad', 'warning' ), true ) ) {
				$severity = $check['status'] === 'bad' ? 'high' : 'medium';

				$issues[] = array(
					'type'     => 'meta_seo_check',
					'severity' => $severity,
					'message'  => $check['title'] . ': ' . $check['description'],
				);
			}
		}

		return $issues;
	}

	/**
	 * Saves the score history
	 */
	private function save_score_history( int $post_id, array $score_data ): void {
		$history = get_post_meta( $post_id, '_seopulse_meta_seo_score_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'score' => $score_data['value'],
			'date'  => current_time( 'mysql' ),
			'class' => $score_data['class'],
		);

		// Keep only the last 10 entries
		if ( count( $history ) > 10 ) {
			$history = array_slice( $history, -10 );
		}

		update_post_meta( $post_id, '_seopulse_meta_seo_score_history', $history );
	}
}
