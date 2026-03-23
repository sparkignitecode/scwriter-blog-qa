<?php

namespace BlogQA\Checks;

use BlogQA\BlogQA_HtmlDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Programmatic location usage checks.
 */
class Location extends BlogQA_CheckBase {

	/**
	 * Run section 5 checks.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<string, mixed>
	 */
	public function run( array $post_data ) : array {
		$location = (string) ( $post_data['location'] ?? '' );
		$document = new BlogQA_HtmlDocument( (string) ( $post_data['content'] ?? '' ) );

		$checks = array(
			$this->check_headings( $location, $document->get_heading_texts( 1, 6 ) ),
			$this->check_content_count( $location, $document->get_body_text() ),
		);

		return $this->build_section( '5', 'Location Usage', $checks );
	}

	/**
	 * Check 5.1.
	 *
	 * @param array<int, string> $headings
	 * @return array<string, string>
	 */
	protected function check_headings( string $location, array $headings ) : array {
		if ( '' === $location ) {
			return $this->build_check( '5.1', 'Location is not used in headings', 'error', 'Location is required to run QA.' );
		}

		$matching_headings = 0;

		foreach ( $headings as $heading ) {
			if ( $this->contains( $heading, $location ) ) {
				$matching_headings++;
			}
		}

		return 0 === $matching_headings
			? $this->build_check( '5.1', 'Location is not used in headings', 'pass' )
			: $this->build_check(
				'5.1',
				'Location is not used in headings',
				'fail',
				sprintf( 'Location appeared in %d heading(s).', $matching_headings )
			);
	}

	/**
	 * Check 5.2.
	 *
	 * @return array<string, string>
	 */
	protected function check_content_count( string $location, string $body_text ) : array {
		if ( '' === $location ) {
			return $this->build_check( '5.2', 'Location appears no more than 4 times in the body', 'error', 'Location is required to run QA.' );
		}

		$occurrences = $this->count_occurrences( $body_text, $location );

		return $occurrences <= 4
			? $this->build_check( '5.2', 'Location appears no more than 4 times in the body', 'pass' )
			: $this->build_check(
				'5.2',
				'Location appears no more than 4 times in the body',
				'fail',
				sprintf( 'Location appeared %d times in the body content.', $occurrences )
			);
	}
}
