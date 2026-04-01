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
		if ( ! $this->is_remote_fetch_enabled() ) {
			return $this->build_classification( false, $this->infer_keyword_from_url( $href ), 0, 'Remote link fetching is disabled', true );
		}

		$request_url = $this->build_request_url( $href );

		if ( '' === $request_url ) {
			return $this->build_classification( false, '', 0, '', false );
		}

		$request_error = $this->validate_request_url( $request_url );

		if ( '' !== $request_error ) {
			return $this->build_classification( false, $this->infer_keyword_from_url( $href ), 0, $request_error, true );
		}

		$http_status_result = $this->fetch_http_status( $request_url, $href );

		if ( '' !== (string) ( $http_status_result['fetch_error'] ?? '' ) ) {
			return $http_status_result;
		}

		$spark_seo_classification = $this->classify_via_spark_seo( $request_url, $href );

		if ( null !== $spark_seo_classification ) {
			return $spark_seo_classification;
		}

		return $http_status_result;
	}

	/**
	 * Fetch the direct target URL and confirm it returns HTTP 200.
	 *
	 * @return array<string, mixed>
	 */
	protected function fetch_http_status( string $request_url, string $href ) : array {
		try {
			$response = wp_safe_remote_get(
				$request_url,
				array(
					'timeout' => 5,
					'redirection' => 5,
					'reject_unsafe_urls' => true,
					'limit_response_size' => 4096,
				)
			);
		} catch ( \Throwable $exception ) {
			return $this->build_classification( false, $this->infer_keyword_from_url( $href ), 0, $exception->getMessage(), true );
		}

		if ( is_wp_error( $response ) ) {
			return $this->build_classification( false, $this->infer_keyword_from_url( $href ), 0, $response->get_error_message(), true );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return $this->build_classification( false, $this->infer_keyword_from_url( $href ), $status_code, sprintf( 'HTTP %d', $status_code ), true );
		}

		return $this->build_classification( false, $this->infer_keyword_from_url( $href ), 200, '', true );
	}

	/**
	 * Classify a URL through the spark_seo JSON endpoint.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function classify_via_spark_seo( string $request_url, string $href ) : ?array {
		$spark_seo_url = add_query_arg( 'spark_seo', 'scwriter', $request_url );

		try {
			$response = wp_safe_remote_get(
				$spark_seo_url,
				array(
					'timeout' => 5,
					'redirection' => 0,
					'reject_unsafe_urls' => true,
					'limit_response_size' => 150000,
				)
			);
		} catch ( \Throwable $exception ) {
			return null;
		}

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			// The spark_seo endpoint is optional classification metadata and should
			// never make an otherwise healthy link look broken.
			return null;
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $payload ) || isset( $payload['error'] ) ) {
			return null;
		}

		return $this->build_classification(
			! empty( $payload['is_pp_lp'] ),
			$this->resolve_inferred_keyword( $href, $payload ),
			200,
			'',
			true
		);
	}

	/**
	 * Resolve the inferred keyword from spark_seo or fall back to the URL slug.
	 *
	 * @param array<string, mixed> $payload
	 */
	protected function resolve_inferred_keyword( string $href, array $payload ) : string {
		$main_keyword = isset( $payload['main_keyword'] ) && is_scalar( $payload['main_keyword'] )
			? trim( sanitize_text_field( (string) $payload['main_keyword'] ) )
			: '';

		if ( '' !== $main_keyword ) {
			return $main_keyword;
		}

		return $this->infer_keyword_from_url( $href );
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
	 * Return whether outbound link fetching is enabled.
	 */
	protected function is_remote_fetch_enabled() : bool {
		$is_enabled = ! defined( 'BLOGQA_DISABLE_REMOTE_FETCHES' ) || ! BLOGQA_DISABLE_REMOTE_FETCHES;

		/**
		 * Filter whether Blog QA may fetch remote link targets.
		 */
		return (bool) apply_filters( 'blogqa_enable_remote_fetches', $is_enabled );
	}

	/**
	 * Validate a request URL against SSRF protections.
	 */
	protected function validate_request_url( string $request_url ) : string {
		$parts = wp_parse_url( $request_url );

		if ( ! is_array( $parts ) ) {
			return 'Blocked unsafe URL';
		}

		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		if ( '' === $host || '' === $scheme ) {
			return 'Blocked unsafe URL';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return 'Blocked URL with embedded credentials';
		}

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return 'Blocked non-HTTP URL';
		}

		if ( 0 !== $port && ! in_array( $port, array( 80, 443 ), true ) ) {
			return sprintf( 'Blocked URL using disallowed port %d', $port );
		}

		if ( ! $this->is_allowed_host( $host ) ) {
			return sprintf( 'Blocked URL host %s by allowlist policy', $host );
		}

		$resolved_ips = $this->resolve_host_ips( $host );

		if ( empty( $resolved_ips ) ) {
			return sprintf( 'Blocked URL host %s because it could not be resolved safely', $host );
		}

		foreach ( $resolved_ips as $resolved_ip ) {
			if ( ! $this->is_public_ip_address( $resolved_ip ) ) {
				return sprintf( 'Blocked URL host %1$s because it resolves to private or reserved IP %2$s', $host, $resolved_ip );
			}
		}

		return '';
	}

	/**
	 * Return whether the URL host is permitted by the configured allowlist.
	 */
	protected function is_allowed_host( string $host ) : bool {
		$allowed_hosts = apply_filters( 'blogqa_link_classifier_allowed_hosts', array() );

		if ( ! is_array( $allowed_hosts ) || empty( $allowed_hosts ) ) {
			return true;
		}

		$normalized_host = strtolower( $host );

		foreach ( $allowed_hosts as $allowed_host ) {
			$allowed_host = strtolower( trim( (string) $allowed_host ) );

			if ( '' === $allowed_host ) {
				continue;
			}

			if ( $normalized_host === $allowed_host || str_ends_with( $normalized_host, '.' . $allowed_host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve all IPv4 and IPv6 addresses for the provided host.
	 *
	 * @return array<int, string>
	 */
	protected function resolve_host_ips( string $host ) : array {
		if ( '' === $host ) {
			return array();
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$resolved_ips = array();

		if ( function_exists( 'dns_get_record' ) ) {
			$records = dns_get_record( $host, DNS_A + DNS_AAAA );

			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					$record_ip = $record['ip'] ?? $record['ipv6'] ?? '';

					if ( is_string( $record_ip ) && '' !== $record_ip ) {
						$resolved_ips[] = $record_ip;
					}
				}
			}
		}

		if ( empty( $resolved_ips ) && function_exists( 'gethostbynamel' ) ) {
			$ipv4_addresses = gethostbynamel( $host );

			if ( is_array( $ipv4_addresses ) ) {
				$resolved_ips = array_merge( $resolved_ips, $ipv4_addresses );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $resolved_ips ) ) ) );
	}

	/**
	 * Return whether an IP address is public and routable.
	 */
	protected function is_public_ip_address( string $ip_address ) : bool {
		return false !== filter_var(
			$ip_address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
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
		$request_url = $this->build_request_url( $href );
		$path = (string) wp_parse_url( $request_url, PHP_URL_PATH );

		$segments = array_values(
			array_filter(
				array_map( 'trim', explode( '/', $path ) ),
				static fn( string $segment ) : bool => '' !== $segment
			)
		);

		$slug = empty( $segments ) ? '' : rawurldecode( (string) end( $segments ) );

		if ( '' !== $slug && ! $this->is_generic_slug_candidate( $slug ) ) {
			return $slug;
		}

		$query_slug = $this->extract_slug_from_query( $request_url );

		if ( '' !== $query_slug ) {
			return $query_slug;
		}

		return $slug;
	}

	/**
	 * Return whether the slug is too generic to use without checking query params.
	 */
	protected function is_generic_slug_candidate( string $slug ) : bool {
		$slug = strtolower( trim( rawurldecode( $slug ) ) );

		if ( '' === $slug ) {
			return true;
		}

		if ( in_array( $slug, $this->get_generic_slugs(), true ) ) {
			return true;
		}

		return (bool) preg_match( '/\.(php|asp|aspx|jsp|cgi)$/', $slug );
	}

	/**
	 * Extract a keyword-like slug from common query parameters.
	 */
	protected function extract_slug_from_query( string $request_url ) : string {
		$query = (string) wp_parse_url( $request_url, PHP_URL_QUERY );

		if ( '' === $query ) {
			return '';
		}

		parse_str( $query, $query_args );

		if ( ! is_array( $query_args ) || empty( $query_args ) ) {
			return '';
		}

		$candidate_keys = array( 'id', 'slug', 'page', 'pagename', 'name', 'title', 'doc', 'article' );

		foreach ( $candidate_keys as $candidate_key ) {
			if ( ! isset( $query_args[ $candidate_key ] ) || ! is_scalar( $query_args[ $candidate_key ] ) ) {
				continue;
			}

			$candidate_value = trim( sanitize_text_field( (string) $query_args[ $candidate_key ] ) );

			if ( '' === $candidate_value || is_numeric( $candidate_value ) ) {
				continue;
			}

			return rawurldecode( $candidate_value );
		}

		foreach ( $query_args as $candidate_value ) {
			if ( ! is_scalar( $candidate_value ) ) {
				continue;
			}

			$candidate_value = trim( sanitize_text_field( (string) $candidate_value ) );

			if ( '' === $candidate_value || is_numeric( $candidate_value ) || false === preg_match( '/[a-z]/i', $candidate_value ) ) {
				continue;
			}

			return rawurldecode( $candidate_value );
		}

		return '';
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
