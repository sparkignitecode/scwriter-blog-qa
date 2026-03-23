<?php

namespace BlogQA\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Programmatic metadata checks.
 */
class Metadata extends BlogQA_CheckBase {

	/**
	 * Run section 3 checks.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<string, mixed>
	 */
	public function run( array $post_data ) : array {
		$meta_title = (string) ( $post_data['meta_title'] ?? '' );
		$meta_description = (string) ( $post_data['meta_description'] ?? '' );
		$location = (string) ( $post_data['location'] ?? '' );

		$checks = array(
			$this->check_description_length( $meta_description ),
			$this->check_near_me( $meta_title, $meta_description ),
			$this->check_location( $meta_title, $meta_description, $location ),
		);

		return $this->build_section( '3', 'Metadata', $checks );
	}

	/**
	 * Check 3.1.
	 *
	 * @return array<string, string>
	 */
	protected function check_description_length( string $meta_description ) : array {
		if ( '' === $meta_description ) {
			return $this->build_check( '3.1', 'Meta description is 155 characters or fewer', 'skipped', 'Meta description is empty.' );
		}

		$length = $this->get_char_length( $meta_description );

		return $length <= 155
			? $this->build_check( '3.1', 'Meta description is 155 characters or fewer', 'pass' )
			: $this->build_check(
				'3.1',
				'Meta description is 155 characters or fewer',
				'fail',
				sprintf( 'Meta description is %d characters long.', $length )
			);
	}

	/**
	 * Check 3.2.
	 *
	 * @return array<string, string>
	 */
	protected function check_near_me( string $meta_title, string $meta_description ) : array {
		$combined_meta = trim( $meta_title . ' ' . $meta_description );

		return ! $this->contains( $combined_meta, 'near me' )
			? $this->build_check( '3.2', '"Near me" is not used in the title or meta description', 'pass' )
			: $this->build_check( '3.2', '"Near me" is not used in the title or meta description', 'fail', '"Near me" was found in the title or meta description.' );
	}

	/**
	 * Check 3.3.
	 *
	 * @return array<string, string>
	 */
	protected function check_location( string $meta_title, string $meta_description, string $location ) : array {
		if ( '' === $location ) {
			return $this->build_check( '3.3', 'Location is not used in the title or meta description', 'error', 'Location is required to run QA.' );
		}

		$combined_meta = trim( $meta_title . ' ' . $meta_description );

		return ! $this->contains( $combined_meta, $location )
			? $this->build_check( '3.3', 'Location is not used in the title or meta description', 'pass' )
			: $this->build_check( '3.3', 'Location is not used in the title or meta description', 'fail', 'Location was found in the title or meta description.' );
	}
}
