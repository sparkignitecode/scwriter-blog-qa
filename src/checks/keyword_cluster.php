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
		$keyword_cluster = (string) ( $post_data['keyword_cluster'] ?? '' );

		if ( '' === $main_keyword ) {
			return $this->build_check( '6.2', 'Main keyword appears in the keyword cluster', 'skipped', 'Main keyword not set.' );
		}

		if ( '' === $keyword_cluster ) {
			return $this->build_check( '6.2', 'Main keyword appears in the keyword cluster', 'skipped', 'Keyword cluster not set' );
		}

		return $this->contains( $keyword_cluster, $main_keyword )
			? $this->build_check( '6.2', 'Main keyword appears in the keyword cluster', 'pass' )
			: $this->build_check( '6.2', 'Main keyword appears in the keyword cluster', 'fail', 'Main keyword not found in the keyword cluster value.' );
	}
}
