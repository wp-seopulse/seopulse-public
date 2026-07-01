<?php

/**
 * Readability analysis module - Improved version
 *
 * Analyzes Flesch Reading Ease, sentence length, word complexity, paragraphs, transitions
 * Inspired by Yoast SEO, Rank Math and SEOPress
 *
 * @package SEOPulse\Modules\Content
 * @since 1.0.0
 * @version 1.1.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Content;

use SEOPulse\Core\Abstracts\Module;

/**
 * ReadabilityAnalyzer class
 */
class ReadabilityAnalyzer extends Module {

	/**
	 * Check visibility tiers for Phase 1 surface
	 *
	 * core      â€” always visible in the metabox
	 * secondary â€” visible in expanded "Detailed Checks" view
	 */
	public const CHECK_TIERS = array(
		'flesch_score'          => 'core',
		'sentence_length'       => 'core',
		'paragraph_length'      => 'core',

		'word_complexity'       => 'secondary',
		'transition_words'      => 'secondary',

		'passive_voice'         => 'secondary',
		'consecutive_sentences' => 'secondary',
	);

	/**
	 * Recommended configuration
	 *
	 * @var array
	 */
	private array $config = array(
		// Flesch Reading Ease
		'flesch_target_min'         => 60,     // Minimum recommended score
		'flesch_optimal_min'        => 70,    // Minimum optimal score

		// Sentences
		'max_sentence_length'       => 25,    // Max words per sentence
		'optimal_sentence_length'   => 20, // Optimal words per sentence
		'long_sentence_threshold'   => 25, // Long sentence threshold
		'long_sentence_max_percent' => 25, // Max % of long sentences

		// Complex words
		'complex_word_syllables'    => 3,  // 3+ syllables = complex
		'max_complex_percentage'    => 15, // Max % of complex words

		// Paragraphs
		'max_paragraph_length'      => 150,  // Max words per paragraph
		'optimal_paragraph_length'  => 100, // Optimal

		// Transitions
		'min_transition_percentage' => 20, // Min % of sentences with transitions
	);

	/**
	 * Transition words (French and English)
	 *
	 * @var array
	 */
	private array $transition_words = array(
		// French
		'donc',
		'ainsi',
		'alors',
		'ensuite',
		'enfin',
		'cependant',
		'nÃ©anmoins',
		'toutefois',
		'pourtant',
		'en effet',
		'par consÃ©quent',
		'autrement dit',
		'en outre',
		'de plus',
		'Ã©galement',
		'aussi',
		'd\'ailleurs',
		'par ailleurs',
		'premiÃ¨rement',
		'deuxiÃ¨mement',
		'finalement',
		'en conclusion',
		'bref',
		'en somme',
		'en rÃ©sumÃ©',
		'notamment',
		'par exemple',
		'c\'est-Ã -dire',

		// English
		'however',
		'therefore',
		'thus',
		'hence',
		'consequently',
		'nevertheless',
		'moreover',
		'furthermore',
		'additionally',
		'besides',
		'meanwhile',
		'first',
		'second',
		'third',
		'finally',
		'lastly',
		'in conclusion',
		'in summary',
		'for example',
		'for instance',
		'namely',
		'specifically',
		'indeed',
		'in fact',
		'actually',
		'certainly',
		'obviously',
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->name   = 'readability';
		$this->weight = 0.20; // 20% of total score

		/**
		 * Filters the ReadabilityAnalyzer configuration
		 *
		 * @since 1.0.0
		 * @param array $config Configuration
		 */
		$this->config = apply_filters( 'seopulse_readability_analyzer_config', $this->config );

		/**
		 * Filters the transition words
		 *
		 * @since 1.0.0
		 * @param array $words Transition words
		 */
		$this->transition_words = apply_filters( 'seopulse_transition_words', $this->transition_words );
	}

	/**
	 * Analyzes a post's readability
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

		$content      = wp_strip_all_tags( $post->post_content );
		$html_content = $post->post_content;

		// Check if the content is sufficient for analysis
		if ( str_word_count( $content ) < 100 ) {
			return array(
				'score'           => 100, // No penalty for short content
				'issues'          => array(),
				'recommendations' => array(),
				'data'            => array( 'insufficient_content' => true ),
				'checks'          => array(
					array(
						'name'    => 'content_length',
						'status'  => 'info',
						'message' => __( 'Content too short for readability analysis', 'seopulse' ),
					),
				),
			);
		}

		// 1. Flesch Reading Ease calculation
		$flesch_score    = $this->calculate_flesch_reading_ease( $content );
		$flesch_analysis = $this->analyze_flesch_score( $flesch_score );

		$score          -= $flesch_analysis['penalty'];
		$issues          = array_merge( $issues, $flesch_analysis['issues'] );
		$recommendations = array_merge( $recommendations, $flesch_analysis['recommendations'] );
		$data['flesch']  = $flesch_analysis['data'];
		$checks          = array_merge( $checks, $flesch_analysis['checks'] );

		// 2. Sentence length analysis
		$sentence_analysis = $this->analyze_sentence_length( $content );
		$score            -= $sentence_analysis['penalty'];
		$issues            = array_merge( $issues, $sentence_analysis['issues'] );
		$recommendations   = array_merge( $recommendations, $sentence_analysis['recommendations'] );
		$data['sentences'] = $sentence_analysis['data'];
		$checks            = array_merge( $checks, $sentence_analysis['checks'] );

		// 3. Word complexity analysis
		$word_analysis   = $this->analyze_word_complexity( $content );
		$score          -= $word_analysis['penalty'];
		$recommendations = array_merge( $recommendations, $word_analysis['recommendations'] );
		$data['words']   = $word_analysis['data'];
		$checks          = array_merge( $checks, $word_analysis['checks'] );

		// 4. Paragraph analysis
		$paragraph_analysis = $this->analyze_paragraphs( $html_content );
		$score             -= $paragraph_analysis['penalty'];
		$recommendations    = array_merge( $recommendations, $paragraph_analysis['recommendations'] );
		$data['paragraphs'] = $paragraph_analysis['data'];
		$checks             = array_merge( $checks, $paragraph_analysis['checks'] );

		// 5. **Transition words analysis (NEW)**
		$transition_analysis = $this->analyze_transition_words( $content );
		$score              -= $transition_analysis['penalty'];
		$recommendations     = array_merge( $recommendations, $transition_analysis['recommendations'] );
		$data['transitions'] = $transition_analysis['data'];
		$checks              = array_merge( $checks, $transition_analysis['checks'] );

		// 6. **Passive voice analysis (NEW)**
		$passive_analysis      = $this->analyze_passive_voice( $content );
		$score                -= $passive_analysis['penalty'];
		$recommendations       = array_merge( $recommendations, $passive_analysis['recommendations'] );
		$data['passive_voice'] = $passive_analysis['data'];
		$checks                = array_merge( $checks, $passive_analysis['checks'] );

		// 7. **Consecutive sentences analysis (NEW)**
		$consecutive_analysis          = $this->analyze_consecutive_sentences( $content );
		$score                        -= $consecutive_analysis['penalty'];
		$recommendations               = array_merge( $recommendations, $consecutive_analysis['recommendations'] );
		$data['consecutive_sentences'] = $consecutive_analysis['data'];
		$checks                        = array_merge( $checks, $consecutive_analysis['checks'] );

		return array(
			'score'           => max( 0, min( 100, $score ) ),
			'issues'          => $issues,
			'recommendations' => $recommendations,
			'data'            => $data,
			'checks'          => self::tag_checks( $checks ),
		);
	}

	/**
	 * Enriches each check with its visibility tier.
	 *
	 * @param array $checks Raw checks array
	 * @return array Checks with 'tier' key added
	 */
	private static function tag_checks( array $checks ): array {
		foreach ( $checks as &$check ) {
			$check['tier'] = self::CHECK_TIERS[ $check['name'] ?? '' ] ?? 'secondary';
		}
		unset( $check );

		return $checks;
	}

	/**
	 * Calculates the Flesch Reading Ease score
	 *
	 * Formula: 206.835 - 1.015 Ã— (total words / total sentences) - 84.6 Ã— (total syllables / total words)
	 * Score: 0-100 (higher = easier to read)
	 *
	 * @param string $text Text to analyze
	 * @return float Flesch score (0-100)
	 */
	private function calculate_flesch_reading_ease( string $text ): float {
		if ( empty( trim( $text ) ) ) {
			return 0.0;
		}

		$total_words = str_word_count( $text );
		if ( $total_words === 0 ) {
			return 0.0;
		}

		$total_sentences = $this->count_sentences( $text );
		if ( $total_sentences === 0 ) {
			$total_sentences = 1;
		}

		$total_syllables = $this->count_syllables( $text );

		$asl = $total_words / $total_sentences; // Average Sentence Length
		$asw = $total_syllables / $total_words; // Average Syllables per Word

		$score = 206.835 - ( 1.015 * $asl ) - ( 84.6 * $asw );

		return max( 0.0, min( 100.0, $score ) );
	}

	/**
	 * Counts the number of sentences
	 *
	 * @param string $text Text
	 * @return int Number of sentences
	 */
	private function count_sentences( string $text ): int {
		// Split based on . ! ?
		$sentences = preg_split( '/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		return count( $sentences );
	}

	/**
	 * Counts the number of syllables (approximation for English and French)
	 *
	 * @param string $text Text
	 * @return int Number of syllables
	 */
	private function count_syllables( string $text ): int {
		$words           = str_word_count( strtolower( $text ), 1 );
		$total_syllables = 0;

		foreach ( $words as $word ) {
			$syllables        = $this->count_word_syllables( $word );
			$total_syllables += $syllables;
		}

		return $total_syllables;
	}

	/**
	 * Counts the syllables of a word
	 *
	 * @param string $word Word
	 * @return int Number of syllables
	 */
	private function count_word_syllables( string $word ): int {
		$word = strtolower( trim( $word ) );

		if ( strlen( $word ) <= 3 ) {
			return 1;
		}

		// Remove silent final 'e' (English)
		$word = preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
		$word = preg_replace( '/^y/', '', $word );

		// Count vowel groups
		preg_match_all( '/[aeiouy]{1,2}/', $word, $matches );
		$syllables = count( $matches[0] );

		return max( 1, $syllables );
	}

	/**
	 * Analyzes the Flesch score - Improved version
	 *
	 * @param float $score Flesch score
	 * @return array{penalty: int, issues: array, recommendations: array, data: array, checks: array}
	 */
	private function analyze_flesch_score( float $score ): array {
		$penalty         = 0;
		$issues          = array();
		$recommendations = array();
		$checks          = array();

		$data = array(
			'score'         => round( $score, 1 ),
			'level'         => $this->get_flesch_level( $score ),
			'reading_grade' => $this->get_reading_grade( $score ),
		);

		// Check: Flesch score
		if ( $score < 30 ) {
			$penalty          += 20;
			$issues[]          = array(
				'type'     => 'readability_very_difficult',
				'severity' => 'high',
				'message'  => __( 'Content is very difficult to read', 'seopulse' ),
			);
			$recommendations[] = array(
				'type'             => 'readability',
				'priority'         => 'high',
				'message'          => sprintf(
					/* translators: 1: score, 2: level */
					__( 'Your content has a Flesch Reading Ease score of %1$.1f (%2$s). This is very difficult for most readers.', 'seopulse' ),
					$score,
					$data['level'],
				),
				'action'           => __( 'Simplify your sentences, use shorter words, and break down complex ideas', 'seopulse' ),
				'estimated_impact' => 20,
			);
			$checks[]          = array(
				'name'    => 'flesch_score',
				'status'  => 'error',
				'message' => sprintf(
					/* translators: %f: Flesch readability score */
					__( 'Flesch score: %.1f (very difficult)', 'seopulse' ),
					$score,
				),
			);
		} elseif ( $score < 50 ) {
			$penalty          += 10;
			$recommendations[] = array(
				'type'             => 'readability',
				'priority'         => 'medium',
				'message'          => sprintf(
					/* translators: 1: score, 2: level */
					__( 'Your content has a Flesch Reading Ease score of %1$.1f (%2$s). Consider simplifying for better engagement.', 'seopulse' ),
					$score,
					$data['level'],
				),
				'action'           => __( 'Use shorter sentences and simpler vocabulary where possible', 'seopulse' ),
				'estimated_impact' => 10,
			);
			$checks[]          = array(
				'name'    => 'flesch_score',
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %f: Flesch readability score */
					__( 'Flesch score: %.1f (difficult)', 'seopulse' ),
					$score,
				),
			);
		} elseif ( $score < $this->config['flesch_target_min'] ) {
			$penalty          += 5;
			$recommendations[] = array(
				'type'             => 'readability',
				'priority'         => 'low',
				'message'          => sprintf(
					/* translators: %f: score */
					__( 'Your content has a Flesch Reading Ease score of %.1f. Try to make it slightly easier to read.', 'seopulse' ),
					$score,
				),
				'action'           => __( 'Shorten some sentences and use simpler words where appropriate', 'seopulse' ),
				'estimated_impact' => 5,
			);
			$checks[]          = array(
				'name'    => 'flesch_score',
				'status'  => 'warning',
				/* translators: %.1f: Flesch readability score */
				'message' => sprintf( __( 'Flesch score: %.1f (acceptable)', 'seopulse' ), $score ),
			);
		} elseif ( $score >= $this->config['flesch_optimal_min'] ) {
			$checks[] = array(
				'name'    => 'flesch_score',
				'status'  => 'success',
				/* translators: %.1f: Flesch readability score */
				'message' => sprintf( __( 'Flesch score: %.1f (optimal)', 'seopulse' ), $score ),
			);
		} else {
			$checks[] = array(
				'name'    => 'flesch_score',
				'status'  => 'success',
				/* translators: %.1f: Flesch readability score */
				'message' => sprintf( __( 'Flesch score: %.1f (good)', 'seopulse' ), $score ),
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
	 * Returns the reading level based on the Flesch score
	 *
	 * @param float $score Flesch score
	 * @return string Reading level
	 */
	private function get_flesch_level( float $score ): string {
		if ( $score >= 90 ) {
			return __( 'Very Easy', 'seopulse' );
		} elseif ( $score >= 80 ) {
			return __( 'Easy', 'seopulse' );
		} elseif ( $score >= 70 ) {
			return __( 'Fairly Easy', 'seopulse' );
		} elseif ( $score >= 60 ) {
			return __( 'Standard', 'seopulse' );
		} elseif ( $score >= 50 ) {
			return __( 'Fairly Difficult', 'seopulse' );
		} elseif ( $score >= 30 ) {
			return __( 'Difficult', 'seopulse' );
		} else {
			return __( 'Very Difficult', 'seopulse' );
		}
	}

	/**
	 * Returns the corresponding school grade level
	 *
	 * @param float $score Flesch score
	 * @return string School grade level
	 */
	private function get_reading_grade( float $score ): string {
		if ( $score >= 90 ) {
			return __( '5th grade', 'seopulse' );
		} elseif ( $score >= 80 ) {
			return __( '6th grade', 'seopulse' );
		} elseif ( $score >= 70 ) {
			return __( '7th grade', 'seopulse' );
		} elseif ( $score >= 60 ) {
			return __( '8th-9th grade', 'seopulse' );
		} elseif ( $score >= 50 ) {
			return __( '10th-12th grade', 'seopulse' );
		} elseif ( $score >= 30 ) {
			return __( 'College', 'seopulse' );
		} else {
			return __( 'College graduate', 'seopulse' );
		}
	}

	/**
	 * Analyzes sentence length - Improved version
	 *
	 * @param string $text Text
	 * @return array{penalty: int, issues: array, recommendations: array, data: array, checks: array}
	 */
	private function analyze_sentence_length( string $text ): array {
		$penalty         = 0;
		$issues          = array();
		$recommendations = array();
		$checks          = array();

		$sentences           = preg_split( '/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$sentence_lengths    = array();
		$long_sentences      = 0;
		$very_long_sentences = 0;
		$short_sentences     = 0;

		foreach ( $sentences as $sentence ) {
			$word_count         = str_word_count( trim( $sentence ) );
			$sentence_lengths[] = $word_count;

			if ( $word_count > $this->config['long_sentence_threshold'] ) {
				++$long_sentences;

				if ( $word_count > 35 ) {
					++$very_long_sentences;
				}
			}

			if ( $word_count < 5 ) {
				++$short_sentences;
			}
		}

		$total_sentences = count( $sentence_lengths );
		$avg_length      = $total_sentences > 0
			? array_sum( $sentence_lengths ) / $total_sentences
			: 0;

		$long_sentence_percentage = $total_sentences > 0
			? ( $long_sentences / $total_sentences ) * 100
			: 0;

		$data = array(
			'average_length'            => round( $avg_length, 1 ),
			'long_sentences_count'      => $long_sentences,
			'very_long_sentences_count' => $very_long_sentences,
			'short_sentences_count'     => $short_sentences,
			'total_sentences'           => $total_sentences,
			'long_sentence_percentage'  => round( $long_sentence_percentage, 1 ),
		);

		// Check: Sentences too long
		if ( $very_long_sentences > 0 ) {
			$penalty          += 8;
			$issues[]          = array(
				'type'     => 'very_long_sentences',
				'severity' => 'medium',
				'message'  => __( 'Some sentences are very long', 'seopulse' ),
			);
			$recommendations[] = array(
				'type'             => 'sentence_length',
				'priority'         => 'medium',
				'message'          => sprintf(
					/* translators: %d: number of very long sentences */
					_n(
						'%d sentence is longer than 35 words. This can be very hard to read.',
						'%d sentences are longer than 35 words. These can be very hard to read.',
						$very_long_sentences,
						'seopulse',
					),
					$very_long_sentences,
				),
				'action'           => __( 'Split very long sentences into multiple shorter ones', 'seopulse' ),
				'estimated_impact' => 8,
			);
			$checks[]          = array(
				'name'    => 'sentence_length',
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %d: number of very long sentences */
					__( '%d very long sentence(s) (>35 words)', 'seopulse' ),
					$very_long_sentences,
				),
			);
		} elseif ( $long_sentence_percentage > $this->config['long_sentence_max_percent'] ) {
			$penalty          += 10;
			$recommendations[] = array(
				'type'             => 'sentence_length',
				'priority'         => 'medium',
				'message'          => sprintf(
					/* translators: 1: percentage of long sentences, 2: word threshold */
					__( '%1$d%% of your sentences are longer than %2$d words. Long sentences are harder to read.', 'seopulse' ),
					round( $long_sentence_percentage ),
					$this->config['long_sentence_threshold'],
				),
				'action'           => __( 'Break long sentences into shorter ones for better readability', 'seopulse' ),
				'estimated_impact' => 10,
			);
			$checks[]          = array(
				'name'    => 'sentence_length',
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %f: percentage of long sentences */
					__( '%.1f%% long sentences', 'seopulse' ),
					$long_sentence_percentage,
				),
			);
		} elseif ( $avg_length <= $this->config['optimal_sentence_length'] ) {
			$checks[] = array(
				'name'    => 'sentence_length',
				'status'  => 'success',
				/* translators: %.1f: average number of words per sentence */
				'message' => sprintf( __( 'Avg sentence length: %.1f words (optimal)', 'seopulse' ), $avg_length ),
			);
		} else {
			$checks[] = array(
				'name'    => 'sentence_length',
				'status'  => 'success',
				/* translators: %.1f: average number of words per sentence */
				'message' => sprintf( __( 'Avg sentence length: %.1f words (good)', 'seopulse' ), $avg_length ),
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
	 * Analyzes word complexity - Improved version
	 *
	 * @param string $text Text
	 * @return array{penalty: int, recommendations: array, data: array, checks: array}
	 */
	private function analyze_word_complexity( string $text ): array {
		$penalty         = 0;
		$recommendations = array();
		$checks          = array();

		$words         = str_word_count( strtolower( $text ), 1 );
		$complex_words = 0;

		foreach ( $words as $word ) {
			if ( $this->count_word_syllables( $word ) > $this->config['complex_word_syllables'] ) {
				++$complex_words;
			}
		}

		$total_words           = count( $words );
		$complexity_percentage = $total_words > 0
			? ( $complex_words / $total_words ) * 100
			: 0;

		$data = array(
			'complex_words_count'   => $complex_words,
			'total_words'           => $total_words,
			'complexity_percentage' => round( $complexity_percentage, 1 ),
		);

		// Check: Complex words
		if ( $complexity_percentage > $this->config['max_complex_percentage'] ) {
			$penalty          += 5;
			$recommendations[] = array(
				'type'             => 'word_complexity',
				'priority'         => 'low',
				'message'          => sprintf(
					/* translators: %d: percentage of complex words */
					__( '%.1f%% of your words have 4+ syllables. Consider using simpler alternatives where appropriate.', 'seopulse' ),
					$complexity_percentage,
				),
				'action'           => __( 'Replace complex words with simpler synonyms when possible', 'seopulse' ),
				'estimated_impact' => 5,
			);
			$checks[]          = array(
				'name'    => 'word_complexity',
				'status'  => 'warning',
				/* translators: %.1f: percentage of complex words */
				'message' => sprintf( __( '%.1f%% complex words', 'seopulse' ), $complexity_percentage ),
			);
		} else {
			$checks[] = array(
				'name'    => 'word_complexity',
				'status'  => 'success',
				/* translators: %.1f: percentage of complex words */
				'message' => sprintf( __( 'Word complexity: %.1f%% (good)', 'seopulse' ), $complexity_percentage ),
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
	 * Analyzes paragraphs - Improved version
	 *
	 * @param string $html_content HTML content
	 * @return array{penalty: int, recommendations: array, data: array, checks: array}
	 */
	private function analyze_paragraphs( string $html_content ): array {
		$penalty         = 0;
		$recommendations = array();
		$checks          = array();

		// Extract paragraphs
		preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $html_content, $matches );
		$paragraphs = $matches[1];

		$long_paragraphs      = 0;
		$very_long_paragraphs = 0;
		$paragraph_lengths    = array();

		foreach ( $paragraphs as $paragraph ) {
			$text       = wp_strip_all_tags( $paragraph );
			$word_count = str_word_count( $text );

			// Ignore empty or very short paragraphs
			if ( $word_count < 5 ) {
				continue;
			}

			$paragraph_lengths[] = $word_count;

			if ( $word_count > $this->config['max_paragraph_length'] ) {
				++$long_paragraphs;

				if ( $word_count > 200 ) {
					++$very_long_paragraphs;
				}
			}
		}

		$total_paragraphs = count( $paragraph_lengths );
		$avg_length       = $total_paragraphs > 0
			? array_sum( $paragraph_lengths ) / $total_paragraphs
			: 0;

		$data = array(
			'total_paragraphs'           => $total_paragraphs,
			'average_length'             => round( $avg_length, 1 ),
			'long_paragraphs_count'      => $long_paragraphs,
			'very_long_paragraphs_count' => $very_long_paragraphs,
		);

		// Check: Paragraphs too long
		if ( $very_long_paragraphs > 0 ) {
			$penalty          += 8;
			$recommendations[] = array(
				'type'             => 'paragraph_length',
				'priority'         => 'medium',
				'message'          => sprintf(
					/* translators: %d: number of very long paragraphs */
					_n(
						'You have %d paragraph with more than 200 words. This can be overwhelming for readers.',
						'You have %d paragraphs with more than 200 words. These can be overwhelming for readers.',
						$very_long_paragraphs,
						'seopulse',
					),
					$very_long_paragraphs,
				),
				'action'           => __( 'Break very long paragraphs into smaller, more manageable chunks', 'seopulse' ),
				'estimated_impact' => 8,
			);
			$checks[]          = array(
				'name'    => 'paragraph_length',
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %d: number of very long paragraphs */
					__( '%d very long paragraph(s)', 'seopulse' ),
					$very_long_paragraphs,
				),
			);
		} elseif ( $long_paragraphs > 0 ) {
			$penalty          += 5;
			$recommendations[] = array(
				'type'             => 'paragraph_length',
				'priority'         => 'low',
				'message'          => sprintf(
					/* translators: %d: number of long paragraphs */
					_n(
						'You have %d paragraph with more than 150 words.',
						'You have %d paragraphs with more than 150 words.',
						$long_paragraphs,
						'seopulse',
					),
					$long_paragraphs,
				),
				'action'           => __( 'Break long paragraphs into smaller chunks for better scannability', 'seopulse' ),
				'estimated_impact' => 5,
			);
			$checks[]          = array(
				'name'    => 'paragraph_length',
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %d: number of long paragraphs */
					__( '%d long paragraph(s) (>150 words)', 'seopulse' ),
					$long_paragraphs,
				),
			);
		} elseif ( $avg_length <= $this->config['optimal_paragraph_length'] ) {
			$checks[] = array(
				'name'    => 'paragraph_length',
				'status'  => 'success',
				/* translators: %.1f: average number of words per paragraph */
				'message' => sprintf( __( 'Avg paragraph length: %.1f words (optimal)', 'seopulse' ), $avg_length ),
			);
		} else {
			$checks[] = array(
				'name'    => 'paragraph_length',
				'status'  => 'success',
				/* translators: %.1f: average number of words per paragraph */
				'message' => sprintf( __( 'Avg paragraph length: %.1f words (good)', 'seopulse' ), $avg_length ),
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
	 * Transition words analysis - NEW FEATURE
	 *
	 * @param string $text Text
	 * @return array{penalty: int, recommendations: array, data: array, checks: array}
	 */
	private function analyze_transition_words( string $text ): array {
		$penalty         = 0;
		$recommendations = array();
		$checks          = array();

		$sentences                  = preg_split( '/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$total_sentences            = count( $sentences );
		$sentences_with_transitions = 0;

		$text_lower = mb_strtolower( $text );

		foreach ( $sentences as $sentence ) {
			$sentence_lower = mb_strtolower( $sentence );

			foreach ( $this->transition_words as $transition ) {
				if ( strpos( $sentence_lower, $transition ) !== false ) {
					++$sentences_with_transitions;
					break; // One transition per sentence is enough
				}
			}
		}

		$transition_percentage = $total_sentences > 0
			? ( $sentences_with_transitions / $total_sentences ) * 100
			: 0;

		$data = array(
			'sentences_with_transitions' => $sentences_with_transitions,
			'total_sentences'            => $total_sentences,
			'transition_percentage'      => round( $transition_percentage, 1 ),
		);

		// Check: Transition words
		if ( $transition_percentage < $this->config['min_transition_percentage'] ) {
			$penalty          += 5;
			$recommendations[] = array(
				'type'             => 'transition_words',
				'priority'         => 'low',
				'message'          => sprintf(
					/* translators: %d: percentage */
					__( 'Only %.1f%% of your sentences contain transition words. Aim for at least 20%%.', 'seopulse' ),
					$transition_percentage,
				),
				'action'           => __( 'Use transition words (however, therefore, furthermore, etc.) to improve text flow', 'seopulse' ),
				'estimated_impact' => 5,
			);
			$checks[]          = array(
				'name'    => 'transition_words',
				'status'  => 'warning',
				/* translators: %.1f: percentage of sentences with transition words */
				'message' => sprintf( __( '%.1f%% sentences with transitions (low)', 'seopulse' ), $transition_percentage ),
			);
		} else {
			$checks[] = array(
				'name'    => 'transition_words',
				'status'  => 'success',
				/* translators: %.1f: percentage of sentences with transition words */
				'message' => sprintf( __( '%.1f%% sentences with transitions (good)', 'seopulse' ), $transition_percentage ),
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
	 * Passive voice analysis - NEW FEATURE
	 *
	 * Basic detection (approximation)
	 *
	 * @param string $text Text
	 * @return array{penalty: int, recommendations: array, data: array, checks: array}
	 */
	private function analyze_passive_voice( string $text ): array {
		$penalty         = 0;
		$recommendations = array();
		$checks          = array();

		$sentences         = preg_split( '/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$total_sentences   = count( $sentences );
		$passive_sentences = 0;

		// Patterns to detect passive voice (approximation)
		// English: is/was/being/been + past participle
		// French: Ãªtre + past participle
		$passive_patterns = array(
			'/\b(is|are|was|were|be|being|been)\s+\w+(ed|en)\b/i', // English
			'/\b(est|sont|Ã©tait|Ã©taient|Ãªtre|Ã©tÃ©)\s+\w+(Ã©|Ã©e|Ã©s|Ã©es|i|ie|is|ies|u|ue|us|ues)\b/i', // French
		);

		foreach ( $sentences as $sentence ) {
			foreach ( $passive_patterns as $pattern ) {
				if ( preg_match( $pattern, $sentence ) ) {
					++$passive_sentences;
					break;
				}
			}
		}

		$passive_percentage = $total_sentences > 0
			? ( $passive_sentences / $total_sentences ) * 100
			: 0;

		$data = array(
			'passive_sentences'  => $passive_sentences,
			'total_sentences'    => $total_sentences,
			'passive_percentage' => round( $passive_percentage, 1 ),
		);

		// Check: Passive voice (recommendation if > 10%)
		if ( $passive_percentage > 10 ) {
			$penalty          += 3;
			$recommendations[] = array(
				'type'             => 'passive_voice',
				'priority'         => 'low',
				'message'          => sprintf(
					/* translators: %d: percentage */
					__( '%.1f%% of your sentences appear to use passive voice. Active voice is generally more engaging.', 'seopulse' ),
					$passive_percentage,
				),
				'action'           => __( 'Convert some passive sentences to active voice for more direct communication', 'seopulse' ),
				'estimated_impact' => 3,
			);
			$checks[]          = array(
				'name'    => 'passive_voice',
				'status'  => 'warning',
				/* translators: %.1f: percentage of passive voice sentences */
				'message' => sprintf( __( '%.1f%% passive voice (high)', 'seopulse' ), $passive_percentage ),
			);
		} else {
			$checks[] = array(
				'name'    => 'passive_voice',
				'status'  => 'success',
				/* translators: %.1f: percentage of passive voice sentences */
				'message' => sprintf( __( '%.1f%% passive voice (good)', 'seopulse' ), $passive_percentage ),
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
	 * Consecutive sentences starting with the same word analysis - NEW FEATURE
	 *
	 * @param string $text Text
	 * @return array{penalty: int, recommendations: array, data: array, checks: array}
	 */
	private function analyze_consecutive_sentences( string $text ): array {
		$penalty         = 0;
		$recommendations = array();
		$checks          = array();

		$sentences           = preg_split( '/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$consecutive_issues  = 0;
		$previous_first_word = '';

		foreach ( $sentences as $sentence ) {
			$sentence_trimmed = trim( $sentence );
			$words            = str_word_count( $sentence_trimmed, 1 );

			if ( empty( $words ) ) {
				continue;
			}

			$first_word = mb_strtolower( $words[0] );

			// Ignore articles and common words
			$ignore_words = array( 'the', 'a', 'an', 'le', 'la', 'les', 'un', 'une', 'des' );

			if ( in_array( $first_word, $ignore_words, true ) ) {
				continue;
			}

			if ( $first_word === $previous_first_word && ! empty( $previous_first_word ) ) {
				++$consecutive_issues;
			}

			$previous_first_word = $first_word;
		}

		$data = array(
			'consecutive_issues' => $consecutive_issues,
		);

		// Check: Consecutive sentences
		if ( $consecutive_issues > 3 ) {
			$penalty          += 3;
			$recommendations[] = array(
				'type'             => 'consecutive_sentences',
				'priority'         => 'low',
				'message'          => sprintf(
					/* translators: %d: number of issues */
					__( 'You have %d instances of consecutive sentences starting with the same word.', 'seopulse' ),
					$consecutive_issues,
				),
				'action'           => __( 'Vary your sentence beginnings for better flow and engagement', 'seopulse' ),
				'estimated_impact' => 3,
			);
			$checks[]          = array(
				'name'    => 'consecutive_sentences',
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %d: number of consecutive sentence issues */
					__( '%d consecutive sentences issue(s)', 'seopulse' ),
					$consecutive_issues,
				),
			);
		} else {
			$checks[] = array(
				'name'    => 'consecutive_sentences',
				'status'  => 'success',
				'message' => __( 'Good sentence variation', 'seopulse' ),
			);
		}

		return array(
			'penalty'         => $penalty,
			'recommendations' => $recommendations,
			'data'            => $data,
			'checks'          => $checks,
		);
	}
}


