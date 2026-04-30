<?php

namespace BlogQA\Checks;

use BlogQA\BlogQA_HtmlDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Programmatic content quality checks.
 */
class ContentQuality extends BlogQA_CheckBase {

	/**
	 * Run section 2 checks.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<string, mixed>
	 */
	public function run( array $post_data ) : array {
		$title = (string) ( $post_data['title'] ?? '' );
		$document = new BlogQA_HtmlDocument( (string) ( $post_data['content'] ?? '' ) );
		$body_text = $document->get_body_text();
		$paragraphs = $document->get_paragraph_texts();

		$checks = array(
			$this->check_word_count( $body_text ),
			$this->check_single_h1( $title, $document->get_h1_count() ),
			$this->check_lists( $document->get_list_count() ),
			$this->check_paragraph_length( $paragraphs ),
		);

		return $this->build_section( '2', 'Content Quality', $checks );
	}

	/**
	 * Check 2.1.
	 *
	 * @return array<string, string>
	 */
	protected function check_word_count( string $body_text ) : array {
		$word_count = str_word_count( $body_text );

		if ( $word_count >= 800 && $word_count <= 1200 ) {
			return $this->build_check( '2.1', 'Word count is between 800 and 1200', 'pass' );
		}

		return $this->build_check(
			'2.1',
			'Word count is between 800 and 1200',
			'fail',
			sprintf( 'Post contains %d words.', $word_count )
		);
	}

	/**
	 * Check 2.2.
	 *
	 * @return array<string, string>
	 */
	protected function check_single_h1( string $title, int $content_h1_count ) : array {
		$effective_h1_count = '' !== trim( $title ) ? 1 + $content_h1_count : $content_h1_count;

		return 1 === $effective_h1_count
			? $this->build_check( '2.2', 'The post has exactly one H1', 'pass' )
			: $this->build_check( '2.2', 'The post has exactly one H1', 'fail', sprintf( 'Found %d effective H1 heading(s).', $effective_h1_count ) );
	}

	/**
	 * Check 2.3.
	 *
	 * @return array<string, string>
	 */
	protected function check_lists( int $list_count ) : array {
		return $list_count > 0
			? $this->build_check( '2.3', 'The post includes at least one list', 'pass' )
			: $this->build_check( '2.3', 'The post includes at least one list', 'fail', 'No ordered or unordered list was found.' );
	}

	/**
	 * Check 2.4.
	 *
	 * @param array<int, string> $paragraphs
	 * @return array<string, mixed>
	 */
	protected function check_paragraph_length( array $paragraphs ) : array {
		if ( empty( $paragraphs ) ) {
			return $this->build_check( '2.4', 'Paragraphs contain no more than 4 sentences', 'fail', 'No paragraphs were found in the post content.' );
		}

		$details = $this->get_paragraph_failure_details( $paragraphs );
		$too_long = count( $details );

		return 0 === $too_long
			? $this->build_check( '2.4', 'Paragraphs contain no more than 4 sentences', 'pass' )
			: $this->build_check(
				'2.4',
				'Paragraphs contain no more than 4 sentences',
				'fail',
				sprintf( '%d paragraph(s) exceeded 4 sentences.', $too_long ),
				$details
			);
	}
}
