<?php

namespace BlogQA;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Fetches normalized SEO data from a remote pillar post.
 */
class BlogQA_PillarPostFetcher {

	/**
	 * Fetch pillar-post data from the spark_seo endpoint.
	 *
	 * @return array<string, string>|null
	 */
	public static function fetch( string $url ) : ?array {
		$request_url = self::build_request_url( $url );

		if ( '' === $request_url ) {
			return null;
		}

		try {
			$response = wp_remote_get(
				$request_url,
				array(
					'timeout' => 5,
				)
			);
		} catch ( \Throwable $exception ) {
			return null;
		}

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $payload ) ) {
			return null;
		}

		return array(
			'title' => self::normalize_value( $payload['title'] ?? '' ),
			'content' => self::normalize_value( $payload['content'] ?? '' ),
			'seo_title' => self::normalize_value( $payload['seo_title'] ?? '' ),
			'seo_description' => self::normalize_value( $payload['seo_description'] ?? '' ),
			'main_keyword' => self::normalize_value( $payload['main_keyword'] ?? '' ),
		);
	}

	/**
	 * Build the remote endpoint URL.
	 */
	protected static function build_request_url( string $url ) : string {
		$sanitized_url = esc_url_raw( trim( $url ) );

		if ( '' === $sanitized_url ) {
			return '';
		}

		return (string) add_query_arg( 'spark_seo', 'scwriter', $sanitized_url );
	}

	/**
	 * Normalize scalar response values to strings.
	 *
	 * @param mixed $value
	 */
	protected static function normalize_value( $value ) : string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
