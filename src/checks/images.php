<?php

namespace BlogQA\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Programmatic image checks.
 */
class Images extends BlogQA_CheckBase {

	/**
	 * Run section 4 checks.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<string, mixed>
	 */
	public function run( array $post_data ) : array {
		$featured_image_id = (int) ( $post_data['featured_image_id'] ?? 0 );
		$content_images = is_array( $post_data['content_images'] ?? null ) ? $post_data['content_images'] : array();
		$images = $this->build_image_collection( $post_data );

		$keywords = array();
		$main_keyword = (string) ( $post_data['main_keyword'] ?? '' );
		if ( '' !== $main_keyword ) {
			$keywords[] = $main_keyword;
		}

		$secondary_keywords = is_array( $post_data['secondary_keywords'] ?? null ) ? $post_data['secondary_keywords'] : array();
		foreach ( $secondary_keywords as $secondary_keyword ) {
			$keywords[] = (string) $secondary_keyword;
		}

		$checks = array(
			$this->check_image_count( $featured_image_id, $content_images ),
			$this->check_uniqueness( $images ),
			$this->check_alt_keywords( $images, $keywords ),
			$this->check_near_me_in_alt( $images ),
		);

		return $this->build_section( '4', 'Images', $checks );
	}

	/**
	 * Check 4.1.
	 *
	 * @param array<int, array<string, string>> $content_images
	 * @return array<string, string>
	 */
	protected function check_image_count( int $featured_image_id, array $content_images ) : array {
		$content_image_count = count( $content_images );

		if ( $featured_image_id > 0 && $content_image_count >= 2 ) {
			return $this->build_check( '4.1', 'The post has a featured image and at least 2 content images', 'pass' );
		}

		return $this->build_check(
			'4.1',
			'The post has a featured image and at least 2 content images',
			'fail',
			sprintf(
				'Featured image present: %1$s. Content images found: %2$d.',
				$featured_image_id > 0 ? 'yes' : 'no',
				$content_image_count
			)
		);
	}

	/**
	 * Check 4.2.
	 *
	 * @param array<int, array<string, string>> $images
	 * @return array<string, string>
	 */
	protected function check_uniqueness( array $images ) : array {
		if ( empty( $images ) ) {
			return $this->build_check( '4.2', 'All images are unique', 'fail', 'No images were found.' );
		}

		$sources = array_map(
			static fn( array $image ) : string => (string) ( $image['src'] ?? '' ),
			$images
		);

		if ( in_array( '', $sources, true ) ) {
			return $this->build_check( '4.2', 'All images are unique', 'fail', 'One or more images are missing a source URL.' );
		}

		return count( array_unique( $sources ) ) === count( $sources )
			? $this->build_check( '4.2', 'All images are unique', 'pass' )
			: $this->build_check( '4.2', 'All images are unique', 'fail', 'Duplicate image sources were found.' );
	}

	/**
	 * Check 4.3.
	 *
	 * @param array<int, array<string, string>> $images
	 * @param array<int, string> $keywords
	 * @return array<string, string>
	 */
	protected function check_alt_keywords( array $images, array $keywords ) : array {
		if ( empty( $images ) ) {
			return $this->build_check( '4.3', 'All images have alt text that uses a keyword', 'fail', 'No images were found.' );
		}

		if ( empty( array_filter( $keywords ) ) ) {
			return $this->build_check( '4.3', 'All images have alt text that uses a keyword', 'skipped', 'No keywords available for image alt text checks.' );
		}

		$invalid_images = array();

		foreach ( $images as $index => $image ) {
			$alt_text = (string) ( $image['alt'] ?? '' );

			if ( '' === $alt_text || empty( $this->find_matching_keywords( $alt_text, $keywords ) ) ) {
				$invalid_images[] = $this->get_image_label( $image, $index );
			}
		}

		return empty( $invalid_images )
			? $this->build_check( '4.3', 'All images have alt text that uses a keyword', 'pass' )
			: $this->build_check(
				'4.3',
				'All images have alt text that uses a keyword',
				'fail',
				sprintf( 'Missing descriptive keyword-based alt text for: %s.', implode( ', ', $invalid_images ) )
			);
	}

	/**
	 * Check 4.4.
	 *
	 * @param array<int, array<string, string>> $images
	 * @return array<string, string>
	 */
	protected function check_near_me_in_alt( array $images ) : array {
		if ( empty( $images ) ) {
			return $this->build_check( '4.4', '"Near me" is not used in image alt text', 'fail', 'No images were found.' );
		}

		$invalid_images = array();

		foreach ( $images as $index => $image ) {
			if ( $this->contains( (string) ( $image['alt'] ?? '' ), 'near me' ) ) {
				$invalid_images[] = $this->get_image_label( $image, $index );
			}
		}

		return empty( $invalid_images )
			? $this->build_check( '4.4', '"Near me" is not used in image alt text', 'pass' )
			: $this->build_check(
				'4.4',
				'"Near me" is not used in image alt text',
				'fail',
				sprintf( '"Near me" was found in the alt text for: %s.', implode( ', ', $invalid_images ) )
			);
	}

	/**
	 * Build a unified featured-plus-content image collection.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<int, array<string, string>>
	 */
	protected function build_image_collection( array $post_data ) : array {
		$images = array();

		if ( (int) ( $post_data['featured_image_id'] ?? 0 ) > 0 ) {
			$images[] = array(
				'type' => 'featured',
				'src' => (string) ( $post_data['featured_image_src'] ?? '' ),
				'alt' => (string) ( $post_data['featured_image_alt'] ?? '' ),
			);
		}

		$content_images = is_array( $post_data['content_images'] ?? null ) ? $post_data['content_images'] : array();

		foreach ( $content_images as $content_image ) {
			$images[] = array(
				'type' => 'content',
				'src' => (string) ( $content_image['src'] ?? '' ),
				'alt' => (string) ( $content_image['alt'] ?? '' ),
			);
		}

		return $images;
	}

	/**
	 * Return a display label for image error messages.
	 *
	 * @param array<string, string> $image
	 */
	protected function get_image_label( array $image, int $index ) : string {
		return 'featured' === ( $image['type'] ?? '' )
			? 'featured image'
			: sprintf( 'content image %d', $index );
	}
}
