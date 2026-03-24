<?php

namespace BlogQA\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Keyword cluster membership check.
 */
class KeywordCluster extends BlogQA_CheckBase {

	/**
	 * Run check 6.2.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<string, string>
	 */
	public function run( array $post_data ) : array {
		$main_keyword = (string) ( $post_data['main_keyword'] ?? '' );
		$secondary_keywords = is_array( $post_data['secondary_keywords'] ?? null ) ? $post_data['secondary_keywords'] : array();

		if ( '' === $main_keyword ) {
			return $this->build_check( '6.2', 'Main keyword appears in the secondary keywords', 'skipped', 'Main keyword not set.' );
		}

		if ( empty( $secondary_keywords ) ) {
			return $this->build_check( '6.2', 'Main keyword appears in the secondary keywords', 'skipped', 'Secondary keywords not set.' );
		}

		foreach ( $secondary_keywords as $secondary_keyword ) {
			if ( $this->contains( (string) $secondary_keyword, $main_keyword ) ) {
				return $this->build_check( '6.2', 'Main keyword appears in the secondary keywords', 'pass' );
			}
		}

		return $this->build_check( '6.2', 'Main keyword appears in the secondary keywords', 'fail', 'Main keyword not found in the secondary keywords list.' );
	}
}
