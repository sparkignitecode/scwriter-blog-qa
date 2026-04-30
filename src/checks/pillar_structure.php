<?php

namespace BlogQA\Checks;

use BlogQA\BlogQA_HtmlDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Programmatic pillar-mode structure checks.
 */
class PillarStructure extends BlogQA_CheckBase {

	/**
	 * Run pillar-specific structure checks.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<string, mixed>
	 */
	public function run( array $post_data ) : array {
		$content = (string) ( $post_data['content'] ?? '' );
		$title = (string) ( $post_data['title'] ?? '' );
		$document = new BlogQA_HtmlDocument( $content );
		$body_text = $document->get_body_text();
		$paragraphs = $document->get_paragraph_texts();
		$xpath = $this->create_xpath( $content );
		$toc_data = $this->find_toc_data( $xpath );
		$h2_headings = $this->get_heading_data( $xpath, 2 );
		$h3_headings = $this->get_heading_data( $xpath, 3 );
		$h4_headings = $this->get_heading_data( $xpath, 4 );

		return $this->build_section(
			'2',
			'Pillar Structure',
			array(
				$this->check_word_count( $body_text ),
				$this->check_single_h1( $title, $document->get_h1_count() ),
				$this->check_toc_after_intro( $xpath, $toc_data ),
				$this->check_toc_covers_h2_headings( $toc_data, $h2_headings ),
				$this->check_h4_absent( $h4_headings ),
				$this->check_h3_usage( $xpath, $h2_headings, $h3_headings ),
				$this->check_paragraph_length( $paragraphs ),
			)
		);
	}

	/**
	 * Check 2.1.
	 *
	 * @return array<string, string>
	 */
	protected function check_word_count( string $body_text ) : array {
		$word_count = str_word_count( $body_text );

		if ( $word_count >= 2800 && $word_count <= 5000 ) {
			return $this->build_check( '2.1', 'Word count is between 2800 and 5000', 'pass' );
		}

		return $this->build_check(
			'2.1',
			'Word count is between 2800 and 5000',
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
	 * @param array{node: \DOMElement, links: array<int, array<string, string>>}|null $toc_data
	 * @return array<string, string>
	 */
	protected function check_toc_after_intro( \DOMXPath $xpath, ?array $toc_data ) : array {
		if ( null === $toc_data ) {
			return $this->build_check( '2.3', 'A valid TOC appears after the introduction', 'fail', 'No linked TOC list was found.' );
		}

		$first_paragraph = $xpath->query( '//p[normalize-space()]' );

		if ( ! $first_paragraph || 0 === $first_paragraph->length || ! ( $first_paragraph->item( 0 ) instanceof \DOMElement ) ) {
			return $this->build_check( '2.3', 'A valid TOC appears after the introduction', 'fail', 'No introduction paragraph was found.' );
		}

		$toc_order = $this->get_node_order( $xpath, $toc_data['node'] );
		$intro_order = $this->get_node_order( $xpath, $first_paragraph->item( 0 ) );

		if ( $toc_order <= $intro_order ) {
			return $this->build_check( '2.3', 'A valid TOC appears after the introduction', 'fail', 'The TOC must appear after the introduction paragraph.' );
		}

		$first_h2 = $xpath->query( '//h2[normalize-space()]' );

		if ( $first_h2 && $first_h2->length > 0 && $first_h2->item( 0 ) instanceof \DOMElement ) {
			$first_h2_order = $this->get_node_order( $xpath, $first_h2->item( 0 ) );

			if ( $toc_order >= $first_h2_order ) {
				return $this->build_check( '2.3', 'A valid TOC appears after the introduction', 'fail', 'The TOC must appear before the first H2 section.' );
			}
		}

		return $this->build_check( '2.3', 'A valid TOC appears after the introduction', 'pass' );
	}

	/**
	 * Check 2.4.
	 *
	 * @param array{node: \DOMElement, links: array<int, array<string, string>>}|null $toc_data
	 * @param array<int, array{node: \DOMElement, id: string, text: string}> $h2_headings
	 * @return array<string, string>
	 */
	protected function check_toc_covers_h2_headings( ?array $toc_data, array $h2_headings ) : array {
		if ( null === $toc_data ) {
			return $this->build_check( '2.4', 'TOC links cover every H2 heading', 'fail', 'No linked TOC list was found.' );
		}

		if ( empty( $h2_headings ) ) {
			return $this->build_check( '2.4', 'TOC links cover every H2 heading', 'fail', 'No H2 headings were found.' );
		}

		$toc_fragments = array();
		$toc_texts = array();

		foreach ( $toc_data['links'] as $link ) {
			$fragment = $this->extract_fragment( (string) ( $link['href'] ?? '' ) );
			$link_text = $this->normalize_for_search( (string) ( $link['text'] ?? '' ) );

			if ( '' !== $fragment ) {
				$toc_fragments[] = $fragment;
			}

			if ( '' !== $link_text ) {
				$toc_texts[] = $link_text;
			}
		}

		$missing_headings = array();

		foreach ( $h2_headings as $heading ) {
			$heading_text = (string) ( $heading['text'] ?? '' );
			$expected_tokens = array_values(
				array_unique(
					array_filter(
						array(
							$this->normalize_fragment( (string) ( $heading['id'] ?? '' ) ),
							sanitize_title( $heading_text ),
						),
						static fn( string $token ) : bool => '' !== $token
					)
				)
			);

			$matched_fragment = ! empty( array_intersect( $expected_tokens, $toc_fragments ) );
			$matched_text = in_array( $this->normalize_for_search( $heading_text ), $toc_texts, true );

			if ( ! $matched_fragment && ! $matched_text ) {
				$missing_headings[] = $heading_text;
			}
		}

		if ( empty( $missing_headings ) ) {
			return $this->build_check( '2.4', 'TOC links cover every H2 heading', 'pass' );
		}

		return $this->build_check(
			'2.4',
			'TOC links cover every H2 heading',
			'fail',
			sprintf( 'TOC links did not cover these H2 headings: %s.', implode( ', ', array_slice( $missing_headings, 0, 5 ) ) )
		);
	}

	/**
	 * Check 2.5.
	 *
	 * @param array<int, array{node: \DOMElement, id: string, text: string}> $h4_headings
	 * @return array<string, string>
	 */
	protected function check_h4_absent( array $h4_headings ) : array {
		return empty( $h4_headings )
			? $this->build_check( '2.5', 'H4 headings are not present', 'pass' )
			: $this->build_check( '2.5', 'H4 headings are not present', 'fail', sprintf( 'Found %d H4 heading(s).', count( $h4_headings ) ) );
	}

	/**
	 * Check 2.6.
	 *
	 * @param array<int, array{node: \DOMElement, id: string, text: string}> $h2_headings
	 * @param array<int, array{node: \DOMElement, id: string, text: string}> $h3_headings
	 * @return array<string, string>
	 */
	protected function check_h3_usage( \DOMXPath $xpath, array $h2_headings, array $h3_headings ) : array {
		if ( empty( $h3_headings ) ) {
			return $this->build_check( '2.6', 'H3 headings are used only as subsections beneath H2 headings', 'pass' );
		}

		if ( empty( $h2_headings ) ) {
			return $this->build_check( '2.6', 'H3 headings are used only as subsections beneath H2 headings', 'fail', 'H3 headings were found without any H2 headings.' );
		}

		$first_h2_order = $this->get_node_order( $xpath, $h2_headings[0]['node'] );

		foreach ( $h3_headings as $heading ) {
			if ( $this->get_node_order( $xpath, $heading['node'] ) < $first_h2_order ) {
				return $this->build_check( '2.6', 'H3 headings are used only as subsections beneath H2 headings', 'fail', 'An H3 heading appeared before the first H2 heading.' );
			}
		}

		return $this->build_check( '2.6', 'H3 headings are used only as subsections beneath H2 headings', 'pass' );
	}

	/**
	 * Check 2.7.
	 *
	 * @param array<int, string> $paragraphs
	 * @return array<string, mixed>
	 */
	protected function check_paragraph_length( array $paragraphs ) : array {
		if ( empty( $paragraphs ) ) {
			return $this->build_check( '2.7', 'Paragraphs contain no more than 4 sentences', 'fail', 'No paragraphs were found in the post content.' );
		}

		$details = $this->get_paragraph_failure_details( $paragraphs );
		$too_long = count( $details );

		return 0 === $too_long
			? $this->build_check( '2.7', 'Paragraphs contain no more than 4 sentences', 'pass' )
			: $this->build_check(
				'2.7',
				'Paragraphs contain no more than 4 sentences',
				'fail',
				sprintf( '%d paragraph(s) exceeded 4 sentences.', $too_long ),
				$details
			);
	}

	/**
	 * Build an XPath helper for the provided HTML fragment.
	 */
	protected function create_xpath( string $html ) : \DOMXPath {
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$previous_state = libxml_use_internal_errors( true );
		$document->loadHTML(
			'<?xml encoding="utf-8" ?><!DOCTYPE html><html><body>' . $html . '</body></html>'
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		return new \DOMXPath( $document );
	}

	/**
	 * Return the first TOC-like linked unordered list.
	 *
	 * @return array{node: \DOMElement, links: array<int, array<string, string>>}|null
	 */
	protected function find_toc_data( \DOMXPath $xpath ) : ?array {
		$nodes = $xpath->query( '//ul' );

		if ( ! $nodes ) {
			return null;
		}

		foreach ( $nodes as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}

			$list_items = $xpath->query( './li', $node );

			if ( ! $list_items ) {
				continue;
			}

			$links = array();

			foreach ( $list_items as $list_item ) {
				if ( ! ( $list_item instanceof \DOMElement ) ) {
					continue;
				}

				$link_nodes = $xpath->query( './/a[@href]', $list_item );

				if ( ! $link_nodes || 0 === $link_nodes->length || ! ( $link_nodes->item( 0 ) instanceof \DOMElement ) ) {
					continue;
				}

				$link = $link_nodes->item( 0 );
				$href = trim( (string) $link->getAttribute( 'href' ) );

				if ( '' === $href ) {
					continue;
				}

				$links[] = array(
					'href' => $href,
					'text' => $this->normalize_text( $link->textContent ?? '' ),
				);
			}

			if ( count( $links ) >= 2 ) {
				return array(
					'node' => $node,
					'links' => $links,
				);
			}
		}

		return null;
	}

	/**
	 * Return heading node data for a single heading level.
	 *
	 * @return array<int, array{node: \DOMElement, id: string, text: string}>
	 */
	protected function get_heading_data( \DOMXPath $xpath, int $level ) : array {
		$nodes = $xpath->query( sprintf( '//h%d[normalize-space()]', max( 1, min( 6, $level ) ) ) );

		if ( ! $nodes ) {
			return array();
		}

		$headings = array();

		foreach ( $nodes as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}

			$headings[] = array(
				'node' => $node,
				'id' => trim( (string) $node->getAttribute( 'id' ) ),
				'text' => $this->normalize_text( $node->textContent ?? '' ),
			);
		}

		return $headings;
	}

	/**
	 * Return a stable DOM order for comparisons.
	 */
	protected function get_node_order( \DOMXPath $xpath, \DOMNode $node ) : int {
		return (int) $xpath->evaluate( 'count(preceding::*)', $node );
	}

	/**
	 * Extract the normalized anchor fragment from a TOC link.
	 */
	protected function extract_fragment( string $href ) : string {
		$fragment = (string) wp_parse_url( $href, PHP_URL_FRAGMENT );

		if ( '' === $fragment && str_starts_with( $href, '#' ) ) {
			$fragment = substr( $href, 1 );
		}

		return $this->normalize_fragment( $fragment );
	}

	/**
	 * Normalize a fragment or heading token for comparison.
	 */
	protected function normalize_fragment( string $fragment ) : string {
		return sanitize_title( rawurldecode( trim( $fragment ) ) );
	}

	/**
	 * Normalize DOM text content.
	 */
	protected function normalize_text( string $text ) : string {
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	}
}
