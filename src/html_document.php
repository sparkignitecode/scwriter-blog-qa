<?php

namespace BlogQA;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Small DOM wrapper for extracting reusable content fragments.
 */
class BlogQA_HtmlDocument {

	protected \DOMDocument $document;

	protected \DOMXPath $xpath;

	public function __construct( string $html ) {
		$this->document = new \DOMDocument( '1.0', 'UTF-8' );

		$previous_state = libxml_use_internal_errors( true );
		$this->document->loadHTML(
			'<?xml encoding="utf-8" ?><!DOCTYPE html><html><body>' . $html . '</body></html>'
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		$this->xpath = new \DOMXPath( $this->document );
	}

	/**
	 * Return the stripped body text.
	 */
	public function get_body_text() : string {
		$body_nodes = $this->xpath->query( '//body' );
		if ( ! $body_nodes || 0 === $body_nodes->length ) {
			return '';
		}

		$body = $body_nodes->item( 0 );

		return $body ? $this->normalize_text( $body->textContent ) : '';
	}

	/**
	 * Return all heading texts for the requested levels.
	 */
	public function get_heading_texts( int $min_level = 1, int $max_level = 6 ) : array {
		$selectors = array();

		for ( $level = max( 1, $min_level ); $level <= min( 6, $max_level ); $level++ ) {
			$selectors[] = '//h' . $level;
		}

		return $this->query_texts( implode( ' | ', $selectors ) );
	}

	/**
	 * Return the first paragraph text.
	 */
	public function get_first_paragraph_text() : string {
		$paragraphs = $this->query_texts( '//p[1]' );

		return $paragraphs[0] ?? '';
	}

	/**
	 * Return all paragraph texts.
	 */
	public function get_paragraph_texts() : array {
		return $this->query_texts( '//p' );
	}

	/**
	 * Return the number of H1 tags.
	 */
	public function get_h1_count() : int {
		return (int) $this->xpath->evaluate( 'count(//h1)' );
	}

	/**
	 * Return the number of ordered and unordered lists.
	 */
	public function get_list_count() : int {
		return (int) $this->xpath->evaluate( 'count(//ul | //ol)' );
	}

	/**
	 * Return normalized image attributes.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	public function get_images() : array {
		$nodes = $this->xpath->query( '//img' );

		if ( ! $nodes ) {
			return array();
		}

		$images = array();

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$images[] = array(
				'src' => trim( (string) $node->getAttribute( 'src' ) ),
				'alt' => $this->normalize_text( (string) $node->getAttribute( 'alt' ) ),
				'attachment_id' => $this->resolve_attachment_id( $node ),
			);
		}

		return $images;
	}

	/**
	 * Return normalized link attributes and anchor text.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_links() : array {
		$nodes = $this->xpath->query( '//a[@href]' );

		if ( ! $nodes ) {
			return array();
		}

		$links = array();

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$href = trim( (string) $node->getAttribute( 'href' ) );

			if ( '' === $href ) {
				continue;
			}

			$links[] = array(
				'href' => $href,
				'text' => $this->normalize_text( $node->textContent ?? '' ),
			);
		}

		return $links;
	}

	/**
	 * Query and normalize node text content.
	 *
	 * @return array<int, string>
	 */
	protected function query_texts( string $query ) : array {
		$nodes = $this->xpath->query( $query );

		if ( ! $nodes ) {
			return array();
		}

		$texts = array();

		foreach ( $nodes as $node ) {
			$text = $this->normalize_text( $node->textContent ?? '' );

			if ( '' === $text ) {
				continue;
			}

			$texts[] = $text;
		}

		return $texts;
	}

	/**
	 * Collapse repeated whitespace after stripping tags.
	 */
	protected function normalize_text( string $text ) : string {
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	}

	/**
	 * Resolve a local attachment ID for an image node when possible.
	 */
	protected function resolve_attachment_id( \DOMElement $node ) : int {
		$class_names = trim( (string) $node->getAttribute( 'class' ) );

		if ( '' !== $class_names && preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class_names, $matches ) ) {
			return (int) $matches[1];
		}

		$data_id = (int) $node->getAttribute( 'data-id' );

		if ( $data_id > 0 ) {
			return $data_id;
		}

		$src = trim( (string) $node->getAttribute( 'src' ) );

		if ( '' === $src || ! $this->is_local_media_url( $src ) ) {
			return 0;
		}

		return (int) attachment_url_to_postid( $src );
	}

	/**
	 * Return whether the provided image URL belongs to the current site.
	 */
	protected function is_local_media_url( string $url ) : bool {
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return is_string( $url_host ) && is_string( $site_host ) && strtolower( $url_host ) === strtolower( $site_host );
	}
}
