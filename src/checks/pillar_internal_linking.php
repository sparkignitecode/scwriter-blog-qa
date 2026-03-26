<?php

namespace BlogQA\Checks;

use BlogQA\BlogQA_HtmlDocument;
use BlogQA\BlogQA_LinkClassifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Pillar-mode internal-linking and PP/LP comparison checks.
 */
class PillarInternalLinking extends BlogQA_PillarPostChecks {

	/**
	 * Run pillar-specific linking checks.
	 *
	 * @param array<string, mixed> $post_data
	 * @param array<string, mixed>|null $pb_data
	 * @param array<int, string> $pb_keywords
	 * @return array<string, mixed>
	 */
	public function run( array $post_data, ?array $pb_data = null, array $pb_keywords = array(), string $pillar_post_url = '', string $pb_skip_reason = '' ) : array {
		$document = new BlogQA_HtmlDocument( (string) ( $post_data['content'] ?? '' ) );
		$links = $document->get_links();
		$classifications = ( new BlogQA_LinkClassifier( $links ) )->classify();

		return $this->build_section(
			'7',
			'Pillar Internal Linking',
			array(
				$this->check_pp_lp_link_presence( $links, $classifications ),
				$this->check_pp_lp_anchor_keywords( $links, $classifications ),
				$this->check_link_http_status( $classifications ),
				$this->check_linked_target_keyword_overlap( $links, $classifications, (string) ( $post_data['main_keyword'] ?? '' ) ),
				$this->check_near_me_plain_text( (string) ( $post_data['content'] ?? '' ) ),
				$this->check_near_me_anchor_targets( $links, $classifications ),
			)
		);
	}

	/**
	 * Check 7.1.
	 *
	 * @param array<int, array<string, string>> $links
	 * @param array<string, array<string, bool|string>> $classifications
	 * @return array<string, string>
	 */
	protected function check_pp_lp_link_presence( array $links, array $classifications ) : array {
		if ( empty( $links ) ) {
			return $this->build_check( '7.1', 'The post contains at least one PP/LP link', 'fail', 'No links found in post content.' );
		}

		foreach ( $classifications as $classification ) {
			if ( ! empty( $classification['is_pp_lp'] ) ) {
				return $this->build_check( '7.1', 'The post contains at least one PP/LP link', 'pass' );
			}
		}

		return $this->build_check( '7.1', 'The post contains at least one PP/LP link', 'fail', 'No links to PP/LP pages found.' );
	}

	/**
	 * Check 7.4.
	 *
	 * @param array<int, array<string, string>> $links
	 * @param array<string, array<string, mixed>> $classifications
	 * @return array<string, string>
	 */
	protected function check_linked_target_keyword_overlap( array $links, array $classifications, string $pillar_main_keyword ) : array {
		$normalized_pillar_keyword = $this->normalize_for_search( $pillar_main_keyword );

		if ( '' === $normalized_pillar_keyword ) {
			return $this->build_check( '7.4', 'PP/LP target keywords differ from the pillar post main keyword', 'skipped', 'Main keyword not set for the current post.' );
		}

		$targets = $this->collect_pp_lp_targets( $links, $classifications );

		if ( empty( $targets ) ) {
			return $this->build_check( '7.4', 'PP/LP target keywords differ from the pillar post main keyword', 'skipped', 'No PP/LP links found.' );
		}

		$conflicts = array();
		$errors = array();
		$evaluated_targets = 0;

		foreach ( $targets as $target_url ) {
			$payload = $this->load_target_seo_payload( $target_url );

			if ( ! empty( $payload['error'] ) ) {
				$errors[] = sprintf( '%1$s (%2$s)', $target_url, $payload['error'] );
				continue;
			}

			$evaluated_targets++;
			$target_main_keyword = $this->normalize_for_search( (string) ( $payload['main_keyword'] ?? '' ) );

			if ( '' !== $target_main_keyword && $target_main_keyword === $normalized_pillar_keyword ) {
				$conflicts[] = sprintf( '%s (main keyword match)', $target_url );
			}

			$secondary_keywords = is_array( $payload['secondary_keywords'] ?? null ) ? $payload['secondary_keywords'] : array();

			foreach ( $secondary_keywords as $secondary_keyword ) {
				if ( $this->normalize_for_search( (string) $secondary_keyword ) === $normalized_pillar_keyword ) {
					$conflicts[] = sprintf( '%s (secondary keyword match)', $target_url );
					break;
				}
			}
		}

		if ( ! empty( $conflicts ) ) {
			return $this->build_check(
				'7.4',
				'PP/LP target keywords differ from the pillar post main keyword',
				'fail',
				sprintf( 'The pillar main keyword matched linked target keywords for: %s.', implode( '; ', array_unique( $conflicts ) ) )
			);
		}

		if ( ! empty( $errors ) ) {
			$status = $evaluated_targets > 0 ? 'error' : 'skipped';

			return $this->build_check(
				'7.4',
				'PP/LP target keywords differ from the pillar post main keyword',
				$status,
				sprintf( 'Could not load target SEO data for: %s.', implode( '; ', array_unique( $errors ) ) )
			);
		}

		return $this->build_check( '7.4', 'PP/LP target keywords differ from the pillar post main keyword', 'pass' );
	}

	/**
	 * Return normalized unique PP/LP targets.
	 *
	 * @param array<int, array<string, string>> $links
	 * @param array<string, array<string, mixed>> $classifications
	 * @return array<int, string>
	 */
	protected function collect_pp_lp_targets( array $links, array $classifications ) : array {
		$targets = array();

		foreach ( $links as $link ) {
			$href = trim( (string) ( $link['href'] ?? '' ) );

			if ( '' === $href || empty( $classifications[ $href ]['is_pp_lp'] ) ) {
				continue;
			}

			$targets[] = $href;
		}

		return array_values( array_unique( $targets ) );
	}

	/**
	 * Load a target SEO payload through the spark_seo endpoint.
	 *
	 * @return array<string, mixed>
	 */
	protected function load_target_seo_payload( string $target_url ) : array {
		$request_url = $this->resolve_url( $target_url );

		if ( '' === $request_url ) {
			return array( 'error' => 'Target URL could not be resolved' );
		}

		$spark_seo_url = add_query_arg( 'spark_seo', 'scwriter', $request_url );

		try {
			$response = wp_safe_remote_get(
				$spark_seo_url,
				array(
					'timeout' => 5,
					'redirection' => 0,
					'reject_unsafe_urls' => true,
					'limit_response_size' => 50000,
				)
			);
		} catch ( \Throwable $exception ) {
			return array( 'error' => $exception->getMessage() );
		}

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return array( 'error' => sprintf( 'HTTP %d', $status_code ) );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $payload ) || isset( $payload['error'] ) ) {
			return array( 'error' => 'spark_seo payload could not be parsed' );
		}

		return array(
			'main_keyword' => isset( $payload['main_keyword'] ) && is_scalar( $payload['main_keyword'] ) ? (string) $payload['main_keyword'] : '',
			'secondary_keywords' => $this->normalize_secondary_keywords( $payload['secondary_keywords'] ?? array() ),
		);
	}

	/**
	 * Normalize secondary keywords from the spark_seo response.
	 *
	 * @param mixed $secondary_keywords
	 * @return array<int, string>
	 */
	protected function normalize_secondary_keywords( $secondary_keywords ) : array {
		if ( is_array( $secondary_keywords ) ) {
			$items = $secondary_keywords;
		} elseif ( is_scalar( $secondary_keywords ) ) {
			$items = preg_split( '/[\r\n,]+/', (string) $secondary_keywords );
		} else {
			return array();
		}

		if ( ! is_array( $items ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static fn( $keyword ) : string => trim( sanitize_text_field( (string) $keyword ) ),
					$items
				),
				static fn( string $keyword ) : bool => '' !== $keyword
			)
		);
	}
}
