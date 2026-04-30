<?php

namespace BlogQA\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shared helpers for structured QA checks.
 */
abstract class BlogQA_CheckBase {

	/**
	 * Create a normalized section response.
	 *
	 * @param array<int, array<string, mixed>> $checks
	 * @return array<string, mixed>
	 */
	protected function build_section( string $section, string $label, array $checks ) : array {
		return array(
			'section' => $section,
			'label' => $label,
			'checks' => $checks,
		);
	}

	/**
	 * Create a normalized check response.
	 *
	 * @param array<int, string> $details
	 * @return array<string, mixed>
	 */
	protected function build_check( string $id, string $label, string $status, string $reason = '', array $details = array() ) : array {
		$check = array(
			'id' => $id,
			'label' => $label,
			'status' => $status,
			'reason' => $reason,
		);

		$details = array_values(
			array_filter(
				array_map( 'strval', $details ),
				static fn( string $detail ) : bool => '' !== trim( $detail )
			)
		);

		if ( ! empty( $details ) ) {
			$check['details'] = $details;
		}

		return $check;
	}

	/**
	 * Case-insensitive substring search with accent normalization.
	 */
	protected function contains( string $haystack, string $needle ) : bool {
		$normalized_needle = $this->normalize_for_search( $needle );

		if ( '' === $normalized_needle ) {
			return false;
		}

		return false !== strpos( $this->normalize_for_search( $haystack ), $normalized_needle );
	}

	/**
	 * Return matched keywords found in the target text.
	 *
	 * @param array<int, string> $keywords
	 * @return array<int, string>
	 */
	protected function find_matching_keywords( string $haystack, array $keywords ) : array {
		$matches = array();

		foreach ( $keywords as $keyword ) {
			if ( $this->contains( $haystack, $keyword ) && ! in_array( $keyword, $matches, true ) ) {
				$matches[] = $keyword;
			}
		}

		return $matches;
	}

	/**
	 * Count case-insensitive occurrences of a string.
	 */
	protected function count_occurrences( string $haystack, string $needle ) : int {
		$normalized_needle = $this->normalize_for_search( $needle );

		if ( '' === $normalized_needle ) {
			return 0;
		}

		return substr_count( $this->normalize_for_search( $haystack ), $normalized_needle );
	}

	/**
	 * Count headings containing a keyword.
	 *
	 * @param array<int, string> $headings
	 */
	protected function count_heading_matches( array $headings, string $keyword ) : int {
		return count( $this->get_heading_matches( $headings, $keyword ) );
	}

	/**
	 * Return non-H1 headings that contain the requested keyword.
	 *
	 * @param array<int, string> $headings
	 * @return array<int, string>
	 */
	protected function get_heading_matches( array $headings, string $keyword ) : array {
		$matches = array();

		foreach ( $headings as $heading ) {
			if ( $this->contains( $heading, $keyword ) ) {
				$matches[] = (string) $heading;
			}
		}

		return $matches;
	}

	/**
	 * Count sentences in a paragraph using basic punctuation splitting.
	 */
	protected function get_sentence_count( string $paragraph ) : int {
		$sentences = preg_split( '/[.!?]+/u', $paragraph );

		if ( ! is_array( $sentences ) ) {
			return 0;
		}

		$sentences = array_filter(
			array_map( 'trim', $sentences ),
			static fn( string $sentence ) : bool => '' !== $sentence
		);

		return count( $sentences );
	}

	/**
	 * Multibyte-safe character length helper.
	 */
	protected function get_char_length( string $text ) : int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		return strlen( $text );
	}

	/**
	 * Normalize text for case-insensitive matching.
	 */
	protected function normalize_for_search( string $text ) : string {
		$text = remove_accents( wp_strip_all_tags( $text ) );
		$text = (string) preg_replace( '/[\-–—_]+/u', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}

		return strtolower( $text );
	}

	/**
	 * Build paragraph failure details in DOM order.
	 *
	 * @param array<int, string> $paragraphs
	 * @return array<int, string>
	 */
	protected function get_paragraph_failure_details( array $paragraphs ) : array {
		$details = array();

		foreach ( $paragraphs as $index => $paragraph ) {
			$sentence_count = $this->get_sentence_count( $paragraph );

			if ( $sentence_count <= 4 ) {
				continue;
			}

			$details[] = sprintf(
				'Paragraph %1$d (%2$d sentences): %3$s',
				$index + 1,
				$sentence_count,
				$this->get_excerpt( $paragraph, 140 )
			);
		}

		return $details;
	}

	/**
	 * Return a normalized excerpt with a fixed maximum length.
	 */
	protected function get_excerpt( string $text, int $max_length ) : string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );

		if ( '' === $text || $max_length <= 0 ) {
			return '';
		}

		if ( $this->get_char_length( $text ) <= $max_length ) {
			return $text;
		}

		$excerpt_length = max( 1, $max_length - 3 );

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, $excerpt_length, 'UTF-8' ) . '...';
		}

		return substr( $text, 0, $excerpt_length ) . '...';
	}
}
