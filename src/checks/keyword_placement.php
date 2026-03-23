<?php

namespace BlogQA\Checks;

use BlogQA\BlogQA_HtmlDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Programmatic keyword placement checks.
 */
class KeywordPlacement extends BlogQA_CheckBase {

	/**
	 * Run section 1 checks.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<string, mixed>
	 */
	public function run( array $post_data ) : array {
		$main_keyword = (string) ( $post_data['main_keyword'] ?? '' );
		$meta_title = (string) ( $post_data['meta_title'] ?? '' );
		$title = (string) ( $post_data['title'] ?? '' );
		$slug = (string) ( $post_data['slug'] ?? '' );
		$secondary_keywords = is_array( $post_data['secondary_keywords'] ?? null ) ? $post_data['secondary_keywords'] : array();

		$document = new BlogQA_HtmlDocument( (string) ( $post_data['content'] ?? '' ) );
		$body_text = $document->get_body_text();
		$first_paragraph = $document->get_first_paragraph_text();
		$non_h1_headings = $document->get_heading_texts( 2, 6 );

		$checks = array(
			$this->check_meta_title( $main_keyword, $meta_title ),
			$this->check_slug( $main_keyword, $slug ),
			$this->check_h1( $main_keyword, $title ),
			$this->check_body( $main_keyword, $body_text ),
			$this->check_first_paragraph( $main_keyword, $first_paragraph ),
			$this->check_last_section( $main_keyword, $body_text ),
			$this->check_main_keyword_headings( $main_keyword, $non_h1_headings ),
			$this->check_secondary_keyword_headings( $secondary_keywords, $non_h1_headings ),
		);

		return $this->build_section( '1', 'Keyword Placement', $checks );
	}

	/**
	 * Check 1.1.
	 *
	 * @return array<string, string>
	 */
	protected function check_meta_title( string $main_keyword, string $meta_title ) : array {
		if ( '' === $main_keyword ) {
			return $this->build_check( '1.1', 'Main keyword is in the meta title', 'skipped', 'Main keyword not set.' );
		}

		return $this->contains( $meta_title, $main_keyword )
			? $this->build_check( '1.1', 'Main keyword is in the meta title', 'pass' )
			: $this->build_check( '1.1', 'Main keyword is in the meta title', 'fail', 'Main keyword not found in the meta title.' );
	}

	/**
	 * Check 1.2.
	 *
	 * @return array<string, string>
	 */
	protected function check_slug( string $main_keyword, string $slug ) : array {
		if ( '' === $main_keyword ) {
			return $this->build_check( '1.2', 'Main keyword is in the URL slug', 'skipped', 'Main keyword not set.' );
		}

		$normalized_keyword = sanitize_title( $main_keyword );
		$normalized_slug = sanitize_title( $slug );

		return ( '' !== $normalized_keyword && false !== strpos( $normalized_slug, $normalized_keyword ) )
			? $this->build_check( '1.2', 'Main keyword is in the URL slug', 'pass' )
			: $this->build_check( '1.2', 'Main keyword is in the URL slug', 'fail', 'Main keyword slug was not found in the post URL.' );
	}

	/**
	 * Check 1.3.
	 *
	 * @return array<string, string>
	 */
	protected function check_h1( string $main_keyword, string $title ) : array {
		if ( '' === $main_keyword ) {
			return $this->build_check( '1.3', 'Main keyword is in the H1', 'skipped', 'Main keyword not set.' );
		}

		if ( $this->contains( $title, $main_keyword ) ) {
			return $this->build_check( '1.3', 'Main keyword is in the H1', 'pass' );
		}

		return $this->build_check( '1.3', 'Main keyword is in the H1', 'fail', 'Main keyword not found in the post title.' );
	}

	/**
	 * Check 1.4.
	 *
	 * @return array<string, string>
	 */
	protected function check_body( string $main_keyword, string $body_text ) : array {
		if ( '' === $main_keyword ) {
			return $this->build_check( '1.4', 'Main keyword is in the body content', 'skipped', 'Main keyword not set.' );
		}

		return $this->contains( $body_text, $main_keyword )
			? $this->build_check( '1.4', 'Main keyword is in the body content', 'pass' )
			: $this->build_check( '1.4', 'Main keyword is in the body content', 'fail', 'Main keyword not found in the post body.' );
	}

	/**
	 * Check 1.5.
	 *
	 * @return array<string, string>
	 */
	protected function check_first_paragraph( string $main_keyword, string $first_paragraph ) : array {
		if ( '' === $main_keyword ) {
			return $this->build_check( '1.5', 'Main keyword is in the first paragraph', 'skipped', 'Main keyword not set.' );
		}

		if ( '' === $first_paragraph ) {
			return $this->build_check( '1.5', 'Main keyword is in the first paragraph', 'fail', 'No opening paragraph was found in the post content.' );
		}

		return $this->contains( $first_paragraph, $main_keyword )
			? $this->build_check( '1.5', 'Main keyword is in the first paragraph', 'pass' )
			: $this->build_check( '1.5', 'Main keyword is in the first paragraph', 'fail', 'Main keyword not found in the first paragraph.' );
	}

	/**
	 * Check 1.6.
	 *
	 * @return array<string, string>
	 */
	protected function check_last_section( string $main_keyword, string $body_text ) : array {
		if ( '' === $main_keyword ) {
			return $this->build_check( '1.6', 'Main keyword is in the final section', 'skipped', 'Main keyword not set.' );
		}

		if ( '' === $body_text ) {
			return $this->build_check( '1.6', 'Main keyword is in the final section', 'fail', 'No body text was available for the final section check.' );
		}

		$tail = $this->get_text_tail( $body_text, 0.2 );

		return $this->contains( $tail, $main_keyword )
			? $this->build_check( '1.6', 'Main keyword is in the final section', 'pass' )
			: $this->build_check( '1.6', 'Main keyword is in the final section', 'fail', 'Main keyword not found in the last 20% of the body content.' );
	}

	/**
	 * Check 1.7.
	 *
	 * @param array<int, string> $headings
	 * @return array<string, string>
	 */
	protected function check_main_keyword_headings( string $main_keyword, array $headings ) : array {
		if ( '' === $main_keyword ) {
			return $this->build_check( '1.7', 'Main keyword appears in 2 or more non-H1 headings', 'skipped', 'Main keyword not set.' );
		}

		$matches = $this->count_heading_matches( $headings, $main_keyword );

		return $matches >= 2
			? $this->build_check( '1.7', 'Main keyword appears in 2 or more non-H1 headings', 'pass' )
			: $this->build_check(
				'1.7',
				'Main keyword appears in 2 or more non-H1 headings',
				'fail',
				sprintf( 'Found the main keyword in %d non-H1 heading(s).', $matches )
			);
	}

	/**
	 * Check 1.8.
	 *
	 * @param array<int, string> $secondary_keywords
	 * @param array<int, string> $headings
	 * @return array<string, string>
	 */
	protected function check_secondary_keyword_headings( array $secondary_keywords, array $headings ) : array {
		if ( empty( $secondary_keywords ) ) {
			return $this->build_check( '1.8', 'A secondary keyword appears in 2 or more non-H1 headings', 'skipped', 'No secondary keywords set.' );
		}

		$best_keyword = '';
		$best_count = 0;

		foreach ( $secondary_keywords as $keyword ) {
			$matches = $this->count_heading_matches( $headings, (string) $keyword );

			if ( $matches > $best_count ) {
				$best_count = $matches;
				$best_keyword = (string) $keyword;
			}
		}

		return $best_count >= 2
			? $this->build_check( '1.8', 'A secondary keyword appears in 2 or more non-H1 headings', 'pass' )
			: $this->build_check(
				'1.8',
				'A secondary keyword appears in 2 or more non-H1 headings',
				'fail',
				sprintf( 'Best match was "%1$s" in %2$d non-H1 heading(s).', $best_keyword ?: 'none', $best_count )
			);
	}

	/**
	 * Return the trailing portion of a text block.
	 */
	protected function get_text_tail( string $text, float $portion ) : string {
		$length = $this->get_char_length( $text );

		if ( 0 === $length ) {
			return '';
		}

		$portion = min( 1, max( 0, $portion ) );
		$offset = (int) floor( $length * ( 1 - $portion ) );

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, $offset, null, 'UTF-8' );
		}

		return substr( $text, $offset );
	}
}
