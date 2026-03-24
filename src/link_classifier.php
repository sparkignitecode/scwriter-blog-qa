<?php

namespace BlogQA;

use BlogQA\Checks\AIStrategy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Fetches linked pages and classifies them for PP/LP checks.
 */
class BlogQA_LinkClassifier {

	/**
	 * @var array<int, array<string, string>>
	 */
	protected array $links;

	/**
	 * @param array<int, array<string, string>> $links
	 */
	public function __construct( array $links ) {
		$this->links = $links;
	}

	/**
	 * Classify every unique href in the provided link collection.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function classify() : array {
		$classifications = array();
		$seen_hrefs = array();

		foreach ( $this->links as $link ) {
			$href = trim( (string) ( $link['href'] ?? '' ) );

			if ( '' === $href || isset( $seen_hrefs[ $href ] ) ) {
				continue;
			}

			$seen_hrefs[ $href ] = true;
			$classifications[ $href ] = $this->classify_href( $href );
		}

		return $classifications;
	}

	/**
	 * Fetch and classify a single link target.
	 *
	 * @return array<string, mixed>
	 */
	protected function classify_href( string $href ) : array {
		$request_url = $this->build_request_url( $href );

		if ( '' === $request_url ) {
			return $this->build_classification( false, '', 0, '', false );
		}

		try {
			$response = wp_remote_get(
				$request_url,
				array(
					'timeout' => 5,
				)
			);
		} catch ( \Throwable $exception ) {
			return $this->build_classification( false, '', 0, $exception->getMessage(), true );
		}

		if ( is_wp_error( $response ) ) {
			return $this->build_classification( false, '', 0, $response->get_error_message(), true );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return $this->build_classification( false, '', $status_code, sprintf( 'HTTP %d', $status_code ), true );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return $this->build_classification( false, '', 200, 'Empty response body', true );
		}

		return $this->build_classification(
			false !== strpos( $body, 'IgniteForm' ),
			$this->infer_keyword_from_url( $href ),
			200,
			'',
			true
		);
	}

	/**
	 * Normalize a fetched classification result.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_classification( bool $is_pp_lp, string $inferred_keyword, int $status_code, string $fetch_error, bool $is_requestable ) : array {
		return array(
			'is_pp_lp' => $is_pp_lp,
			'inferred_keyword' => $inferred_keyword,
			'status_code' => $status_code,
			'fetch_error' => $fetch_error,
			'is_requestable' => $is_requestable,
		);
	}

	/**
	 * Resolve a link href into a requestable URL.
	 */
	protected function build_request_url( string $href ) : string {
		$href = trim( $href );

		if ( '' === $href || '#' === substr( $href, 0, 1 ) ) {
			return '';
		}

		$lower_href = strtolower( $href );

		if ( str_starts_with( $lower_href, 'mailto:' ) || str_starts_with( $lower_href, 'tel:' ) || str_starts_with( $lower_href, 'javascript:' ) ) {
			return '';
		}

		$sanitized_url = esc_url_raw( $href );

		if ( '' === $sanitized_url ) {
			return '';
		}

		if ( str_starts_with( $sanitized_url, '//' ) ) {
			$sanitized_url = set_url_scheme( $sanitized_url, 'https' );
		}

		$scheme = wp_parse_url( $sanitized_url, PHP_URL_SCHEME );

		if ( ! is_string( $scheme ) || '' === $scheme ) {
			return esc_url_raw( home_url( '/' . ltrim( $sanitized_url, '/' ) ) );
		}

		if ( ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $sanitized_url;
	}

	/**
	 * Infer a keyword phrase from a URL slug or AI fallback.
	 */
	protected function infer_keyword_from_url( string $href ) : string {
		$slug = $this->extract_slug( $href );

		if ( '' === $slug ) {
			return '';
		}

		$keyword = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( '-', ' ', rawurldecode( $slug ) ) ) );
		$word_count = count(
			array_filter(
				preg_split( '/\s+/u', $keyword ) ?: array(),
				static fn( string $part ) : bool => '' !== trim( $part )
			)
		);

		if ( $word_count >= 2 && ! in_array( strtolower( $slug ), $this->get_generic_slugs(), true ) ) {
			return $keyword;
		}

		return $this->infer_keyword_with_ai( $slug );
	}

	/**
	 * Return the final path segment from a URL.
	 */
	protected function extract_slug( string $href ) : string {
		$path = (string) wp_parse_url( $this->build_request_url( $href ), PHP_URL_PATH );

		if ( '' === $path ) {
			return '';
		}

		$segments = array_values(
			array_filter(
				array_map( 'trim', explode( '/', $path ) ),
				static fn( string $segment ) : bool => '' !== $segment
			)
		);

		if ( empty( $segments ) ) {
			return '';
		}

		return rawurldecode( (string) end( $segments ) );
	}

	/**
	 * Infer a keyword phrase through OpenAI for ambiguous slugs.
	 */
	protected function infer_keyword_with_ai( string $slug ) : string {
		$openai_api_key = ( new AIStrategy() )->get_openai_api_key();

		if ( '' === $openai_api_key ) {
			return '';
		}

		$request_body = array(
			'model' => 'gpt-5-mini',
			'messages' => array(
				array(
					'role' => 'system',
					'content' => 'Reply with only the keyword phrase. Do not include explanations or punctuation beyond the phrase itself.',
				),
				array(
					'role' => 'user',
					'content' => sprintf(
						'What is the main SEO keyword phrase for a page with this URL slug: %s? Reply with only the keyword phrase.',
						$slug
					),
				),
			),
		);

		try {
			$response = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . $openai_api_key,
						'Content-Type' => 'application/json',
					),
					'body' => wp_json_encode( $request_body ),
				)
			);
		} catch ( \Throwable $exception ) {
			return '';
		}

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return '';
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$content = $payload['choices'][0]['message']['content'] ?? '';

		if ( ! is_string( $content ) ) {
			return '';
		}

		return trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $content ) ) );
	}

	/**
	 * Return slugs that are too generic for direct inference.
	 *
	 * @return array<int, string>
	 */
	protected function get_generic_slugs() : array {
		return array(
			'page',
			'services',
			'contact',
			'about',
			'home',
		);
	}
}
