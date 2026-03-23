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
	 * @param array<int, array<string, string>> $checks
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
	 * @return array<string, string>
	 */
	protected function build_check( string $id, string $label, string $status, string $reason = '' ) : array {
		return array(
			'id' => $id,
			'label' => $label,
			'status' => $status,
			'reason' => $reason,
		);
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
		$matches = 0;

		foreach ( $headings as $heading ) {
			if ( $this->contains( $heading, $keyword ) ) {
				$matches++;
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
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}

		return strtolower( $text );
	}
}
