<?php

namespace BlogQA\Checks;

use BlogQA\BlogQA_HtmlDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Programmatic pillar-mode image checks.
 */
class PillarImages extends Images {

	/**
	 * Run pillar-specific image checks.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<string, mixed>
	 */
	public function run( array $post_data ) : array {
		$featured_image_id = (int) ( $post_data['featured_image_id'] ?? 0 );
		$content_images = is_array( $post_data['content_images'] ?? null ) ? $post_data['content_images'] : array();
		$images = $this->build_image_collection( $post_data );
		$body_text = ( new BlogQA_HtmlDocument( (string) ( $post_data['content'] ?? '' ) ) )->get_body_text();

		$keywords = array();
		$main_keyword = (string) ( $post_data['main_keyword'] ?? '' );

		if ( '' !== $main_keyword ) {
			$keywords[] = $main_keyword;
		}

		$secondary_keywords = is_array( $post_data['secondary_keywords'] ?? null ) ? $post_data['secondary_keywords'] : array();

		foreach ( $secondary_keywords as $secondary_keyword ) {
			$keywords[] = (string) $secondary_keyword;
		}

		return $this->build_section(
			'4',
			'Pillar Images',
			array(
				$this->check_featured_image( $featured_image_id ),
				$this->check_image_count_band( str_word_count( $body_text ), $featured_image_id, $content_images ),
				$this->check_uniqueness( $images ),
				$this->check_alt_keywords( $images, $keywords ),
				$this->check_near_me_in_alt( $images ),
			)
		);
	}

	/**
	 * Check 4.1.
	 *
	 * @return array<string, string>
	 */
	protected function check_featured_image( int $featured_image_id ) : array {
		return $featured_image_id > 0
			? $this->build_check( '4.1', 'A featured image is present', 'pass' )
			: $this->build_check( '4.1', 'A featured image is present', 'fail', 'No featured image was found.' );
	}

	/**
	 * Check 4.2.
	 *
	 * @param array<int, array<string, int|string>> $content_images
	 * @return array<string, string>
	 */
	protected function check_image_count_band( int $word_count, int $featured_image_id, array $content_images ) : array {
		if ( $word_count < 2800 ) {
			return $this->build_check(
				'4.2',
				'In-article image count matches the pillar word-count band',
				'skipped',
				'Word count is outside the pillar image bands.'
			);
		}

		$effective_word_count = min( 5000, $word_count );
		$required_content_images = $this->get_required_content_image_count( $effective_word_count );
		$content_image_count = count( $content_images );

		if ( $featured_image_id > 0 && $content_image_count === $required_content_images ) {
			return $this->build_check( '4.2', 'In-article image count matches the pillar word-count band', 'pass' );
		}

		return $this->build_check(
			'4.2',
			'In-article image count matches the pillar word-count band',
			'fail',
			sprintf(
				'Word count %1$d uses the %2$d-word pillar band and requires %3$d in-article images plus a featured image. Found featured image: %4$s. In-article images found: %5$d.',
				$word_count,
				$effective_word_count,
				$required_content_images,
				$featured_image_id > 0 ? 'yes' : 'no',
				$content_image_count
			)
		);
	}

	/**
	 * Return the required content-image count for the current word-count band.
	 */
	protected function get_required_content_image_count( int $word_count ) : int {
		if ( $word_count <= 3599 ) {
			return 5;
		}

		if ( $word_count <= 4199 ) {
			return 6;
		}

		if ( $word_count <= 4799 ) {
			return 7;
		}

		return 8;
	}
}
