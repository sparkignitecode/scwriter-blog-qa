<?php

namespace BlogQA\Checks;

use BlogQA\BlogQA_HtmlDocument;
use BlogQA\BlogQA_LinkClassifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cross-page pillar-post and PP/LP checks.
 */
class BlogQA_PillarPostChecks extends BlogQA_CheckBase {

	/**
	 * Run section 7 checks.
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
			'Pillar Post',
			array(
				$this->check_main_keyword_difference( $post_data, $pb_data, $pb_skip_reason ),
				$this->check_secondary_keyword_overlap( $post_data, $pb_data, $pb_keywords, $pb_skip_reason ),
				$this->check_intent_overlap( $post_data, $pb_data, $pb_skip_reason ),
				$this->check_image_overlap( $post_data, $pb_data, $pb_skip_reason ),
				$this->check_link_to_pillar_post( $links, $pillar_post_url, $pb_data, $pb_skip_reason ),
				$this->check_pillar_post_anchor_text( $links, $pb_data, $pb_keywords, $pillar_post_url, $pb_skip_reason ),
				$this->check_pp_lp_link_presence( $links, $classifications ),
				$this->check_pp_lp_anchor_keywords( $links, $classifications ),
				$this->check_link_http_status( $classifications ),
				$this->check_near_me_plain_text( (string) ( $post_data['content'] ?? '' ) ),
				$this->check_near_me_anchor_targets( $links, $classifications ),
			)
		);
	}

	/**
	 * Check 7.1a.
	 *
	 * @param array<string, mixed> $post_data
	 * @param array<string, mixed>|null $pb_data
	 * @return array<string, string>
	 */
	protected function check_main_keyword_difference( array $post_data, ?array $pb_data, string $pb_skip_reason ) : array {
		$skip_reason = $this->get_pb_dependency_skip_reason( $pb_data, $pb_skip_reason );

		if ( '' !== $skip_reason ) {
			return $this->build_check( '7.1a', 'SB main keyword differs from PB main keyword', 'skipped', $skip_reason );
		}

		$sb_main_keyword = (string) ( $post_data['main_keyword'] ?? '' );
		$pb_main_keyword = $this->get_string_value( $pb_data, 'main_keyword' );

		if ( '' === $sb_main_keyword || '' === $pb_main_keyword ) {
			return $this->build_check( '7.1a', 'SB main keyword differs from PB main keyword', 'skipped', 'Main keyword not available for comparison.' );
		}

		if ( $this->normalize_for_search( $sb_main_keyword ) === $this->normalize_for_search( $pb_main_keyword ) ) {
			return $this->build_check( '7.1a', 'SB main keyword differs from PB main keyword', 'fail', 'SB and PB share the same main keyword' );
		}

		return $this->build_check( '7.1a', 'SB main keyword differs from PB main keyword', 'pass' );
	}

	/**
	 * Check 7.1b.
	 *
	 * @param array<string, mixed> $post_data
	 * @param array<string, mixed>|null $pb_data
	 * @param array<int, string> $pb_keywords
	 * @return array<string, string>
	 */
	protected function check_secondary_keyword_overlap( array $post_data, ?array $pb_data, array $pb_keywords, string $pb_skip_reason ) : array {
		$skip_reason = $this->get_pb_dependency_skip_reason( $pb_data, $pb_skip_reason );

		if ( '' !== $skip_reason ) {
			return $this->build_check( '7.1b', 'SB main keyword is not used in PB secondary keywords', 'skipped', $skip_reason );
		}

		if ( empty( $pb_keywords ) ) {
			return $this->build_check( '7.1b', 'SB main keyword is not used in PB secondary keywords', 'skipped', 'No PB secondary keywords provided' );
		}

		$sb_main_keyword = (string) ( $post_data['main_keyword'] ?? '' );

		if ( '' === $sb_main_keyword ) {
			return $this->build_check( '7.1b', 'SB main keyword is not used in PB secondary keywords', 'skipped', 'SB main keyword not set.' );
		}

		foreach ( $pb_keywords as $pb_keyword ) {
			if ( $this->contains( $pb_keyword, $sb_main_keyword ) ) {
				return $this->build_check(
					'7.1b',
					'SB main keyword is not used in PB secondary keywords',
					'fail',
					sprintf( 'SB main keyword matched PB secondary keyword "%s".', $pb_keyword )
				);
			}
		}

		return $this->build_check( '7.1b', 'SB main keyword is not used in PB secondary keywords', 'pass' );
	}

	/**
	 * Check 7.1c.
	 *
	 * @param array<string, mixed> $post_data
	 * @param array<string, mixed>|null $pb_data
	 * @return array<string, string>
	 */
	protected function check_intent_overlap( array $post_data, ?array $pb_data, string $pb_skip_reason ) : array {
		$skip_reason = $this->get_pb_dependency_skip_reason( $pb_data, $pb_skip_reason );

		if ( '' !== $skip_reason ) {
			return $this->build_check( '7.1c', 'Search intent overlap is acceptable', 'skipped', $skip_reason );
		}

		$openai_api_key = ( new AIStrategy() )->get_openai_api_key();

		if ( '' === $openai_api_key ) {
			return $this->build_check( '7.1c', 'Search intent overlap is acceptable', 'skipped', 'OpenAI API key not configured' );
		}

		$sb_content = $this->get_plain_content( (string) ( $post_data['content'] ?? '' ) );
		$pb_content = $this->get_plain_content( $this->get_string_value( $pb_data, 'content' ) );

		if ( '' === $sb_content || '' === $pb_content ) {
			return $this->build_check( '7.1c', 'Search intent overlap is acceptable', 'skipped', 'Article content not available for intent comparison.' );
		}

		$request_body = array(
			'model' => 'gpt-5-mini',
			'messages' => array(
				array(
					'role' => 'system',
					'content' => 'You compare article intent overlap. Always begin the reply with either "pass" or "fail", followed by one concise sentence reason.',
				),
				array(
					'role' => 'user',
					'content' => implode(
						"\n\n",
						array(
							'Estimate the search intent duplication of these two articles. Reply with: pass (acceptable), fail (too similar), and one sentence reason.',
							'Support Blog content: ' . $sb_content,
							'Pillar Blog content: ' . $pb_content,
						)
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
			return $this->build_check( '7.1c', 'Search intent overlap is acceptable', 'skipped', 'Intent overlap could not be evaluated.' );
		}

		if ( is_wp_error( $response ) ) {
			return $this->build_check( '7.1c', 'Search intent overlap is acceptable', 'skipped', 'Intent overlap could not be evaluated.' );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->build_check( '7.1c', 'Search intent overlap is acceptable', 'skipped', 'Intent overlap could not be evaluated.' );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$content = $payload['choices'][0]['message']['content'] ?? '';

		if ( ! is_string( $content ) ) {
			return $this->build_check( '7.1c', 'Search intent overlap is acceptable', 'skipped', 'Intent overlap response could not be parsed.' );
		}

		$normalized_content = strtolower( trim( $content ) );

		if ( str_starts_with( $normalized_content, 'pass' ) ) {
			return $this->build_check( '7.1c', 'Search intent overlap is acceptable', 'pass', $this->extract_ai_reason( $content, 'Search intent appears distinct enough.' ) );
		}

		if ( str_starts_with( $normalized_content, 'fail' ) ) {
			return $this->build_check( '7.1c', 'Search intent overlap is acceptable', 'fail', $this->extract_ai_reason( $content, 'Search intent appears too similar.' ) );
		}

		return $this->build_check( '7.1c', 'Search intent overlap is acceptable', 'skipped', 'Intent overlap response could not be parsed.' );
	}

	/**
	 * Check 7.2.
	 *
	 * @param array<string, mixed> $post_data
	 * @param array<string, mixed>|null $pb_data
	 * @return array<string, string>
	 */
	protected function check_image_overlap( array $post_data, ?array $pb_data, string $pb_skip_reason ) : array {
		$skip_reason = $this->get_pb_dependency_skip_reason( $pb_data, $pb_skip_reason );

		if ( '' !== $skip_reason ) {
			return $this->build_check( '7.2', 'SB and PB use different images', 'skipped', $skip_reason );
		}

		$sb_images = $this->collect_image_signatures(
			(int) ( $post_data['featured_image_id'] ?? 0 ),
			(string) ( $post_data['featured_image_src'] ?? '' ),
			is_array( $post_data['content_images'] ?? null ) ? $post_data['content_images'] : array()
		);
		$pb_images = $this->collect_image_signatures(
			(int) ( $pb_data['featured_image_id'] ?? 0 ),
			(string) ( $pb_data['featured_image_src'] ?? '' ),
			is_array( $pb_data['content_images'] ?? null ) ? $pb_data['content_images'] : array()
		);

		if ( empty( $pb_images['attachment_ids'] ) && empty( $pb_images['urls'] ) ) {
			return $this->build_check( '7.2', 'SB and PB use different images', 'skipped', 'PB content contains no images' );
		}

		$overlapping_attachment_ids = array_values(
			array_unique(
				array_intersect( $sb_images['attachment_ids'], $pb_images['attachment_ids'] )
			)
		);
		$overlapping_file_hashes = array_values(
			array_unique(
				array_intersect( $sb_images['file_hashes'], $pb_images['file_hashes'] )
			)
		);
		$overlapping_image_urls = array_values(
			array_unique(
				array_intersect( $sb_images['urls'], $pb_images['urls'] )
			)
		);

		if ( empty( $overlapping_attachment_ids ) && empty( $overlapping_file_hashes ) && empty( $overlapping_image_urls ) ) {
			return $this->build_check( '7.2', 'SB and PB use different images', 'pass' );
		}

		$details = array();

		if ( ! empty( $overlapping_attachment_ids ) ) {
			$details[] = sprintf(
				'attachment IDs %s',
				implode( ', ', $overlapping_attachment_ids )
			);
		}

		if ( ! empty( $overlapping_file_hashes ) ) {
			$details[] = sprintf(
				'local file hashes %s',
				implode( ', ', $overlapping_file_hashes )
			);
		}

		if ( ! empty( $overlapping_image_urls ) ) {
			$details[] = sprintf(
				'image URLs %s',
				implode( ', ', $overlapping_image_urls )
			);
		}

		return $this->build_check(
			'7.2',
			'SB and PB use different images',
			'fail',
			sprintf( 'Shared image sources found via %s.', implode( '; ', $details ) )
		);
	}

	/**
	 * Check 7.3a.
	 *
	 * @param array<int, array<string, string>> $links
	 * @param array<string, mixed>|null $pb_data
	 * @return array<string, string>
	 */
	protected function check_link_to_pillar_post( array $links, string $pillar_post_url, ?array $pb_data, string $pb_skip_reason ) : array {
		$skip_reason = $this->get_pb_link_skip_reason( $pb_data, $pb_skip_reason, $pillar_post_url );

		if ( '' !== $skip_reason ) {
			return $this->build_check( '7.3a', 'SB links to the Pillar Post', 'skipped', $skip_reason );
		}

		if ( ! empty( $this->find_links_matching_url( $links, $pillar_post_url ) ) ) {
			return $this->build_check( '7.3a', 'SB links to the Pillar Post', 'pass' );
		}

		return $this->build_check( '7.3a', 'SB links to the Pillar Post', 'fail', 'No link to Pillar Post found in content' );
	}

	/**
	 * Check 7.3b.
	 *
	 * @param array<int, array<string, string>> $links
	 * @param array<string, mixed>|null $pb_data
	 * @param array<int, string> $pb_keywords
	 * @return array<string, string>
	 */
	protected function check_pillar_post_anchor_text( array $links, ?array $pb_data, array $pb_keywords, string $pillar_post_url, string $pb_skip_reason ) : array {
		$skip_reason = $this->get_pb_link_skip_reason( $pb_data, $pb_skip_reason, $pillar_post_url );

		if ( '' !== $skip_reason ) {
			return $this->build_check( '7.3b', 'SB to PB anchor text contains a PB keyword', 'skipped', $skip_reason );
		}

		$matching_links = $this->find_links_matching_url( $links, $pillar_post_url );

		if ( empty( $matching_links ) ) {
			return $this->build_check( '7.3b', 'SB to PB anchor text contains a PB keyword', 'skipped', 'No PB link found' );
		}

		$expected_keywords = array_values(
			array_filter(
				array_merge(
					array( $this->get_string_value( $pb_data, 'main_keyword' ) ),
					$pb_keywords
				),
				static fn( string $keyword ) : bool => '' !== $keyword
			)
		);

		if ( empty( $expected_keywords ) ) {
			return $this->build_check( '7.3b', 'SB to PB anchor text contains a PB keyword', 'skipped', 'No Pillar Post keywords available for anchor comparison.' );
		}

		$anchor_texts = array();

		foreach ( $matching_links as $link ) {
			$anchor_text = (string) ( $link['text'] ?? '' );
			$anchor_texts[] = '' !== $anchor_text ? $anchor_text : '[empty]';

			if ( ! empty( $this->find_matching_keywords( $anchor_text, $expected_keywords ) ) ) {
				return $this->build_check( '7.3b', 'SB to PB anchor text contains a PB keyword', 'pass' );
			}
		}

		return $this->build_check(
			'7.3b',
			'SB to PB anchor text contains a PB keyword',
			'fail',
			sprintf(
				'PB link anchor text did not include expected keywords. Anchor text: %1$s. Expected keywords: %2$s.',
				implode( ' | ', $anchor_texts ),
				implode( ', ', $expected_keywords )
			)
		);
	}

	/**
	 * Check 7.4a.
	 *
	 * @param array<int, array<string, string>> $links
	 * @param array<string, array<string, bool|string>> $classifications
	 * @return array<string, string>
	 */
	protected function check_pp_lp_link_presence( array $links, array $classifications ) : array {
		if ( empty( $links ) ) {
			return $this->build_check( '7.4a', 'SB contains at least one PP/LP link', 'fail', 'No links found in post content' );
		}

		foreach ( $classifications as $classification ) {
			if ( ! empty( $classification['is_pp_lp'] ) ) {
				return $this->build_check( '7.4a', 'SB contains at least one PP/LP link', 'pass' );
			}
		}

		return $this->build_check( '7.4a', 'SB contains at least one PP/LP link', 'fail', 'No links to PP/LP pages found' );
	}

	/**
	 * Check 7.4b.
	 *
	 * @param array<int, array<string, string>> $links
	 * @param array<string, array<string, bool|string>> $classifications
	 * @return array<string, string>
	 */
	protected function check_pp_lp_anchor_keywords( array $links, array $classifications ) : array {
		$pp_lp_links = array();

		foreach ( $links as $link ) {
			$href = trim( (string) ( $link['href'] ?? '' ) );

			if ( '' === $href || empty( $classifications[ $href ]['is_pp_lp'] ) ) {
				continue;
			}

			$pp_lp_links[] = $link;
		}

		if ( empty( $pp_lp_links ) ) {
			return $this->build_check( '7.4b', 'PP/LP link anchor text contains the inferred keyword', 'skipped', 'No PP/LP links found.' );
		}

		$failed_links = array();
		$skipped_links = array();
		$evaluated_links = 0;

		foreach ( $pp_lp_links as $link ) {
			$href = trim( (string) ( $link['href'] ?? '' ) );
			$anchor_text = (string) ( $link['text'] ?? '' );
			$inferred_keyword = trim( (string) ( $classifications[ $href ]['inferred_keyword'] ?? '' ) );

			if ( '' === $inferred_keyword ) {
				$skipped_links[] = $href;
				continue;
			}

			$evaluated_links++;

			if ( ! $this->contains( $anchor_text, $inferred_keyword ) ) {
				$failed_links[] = sprintf(
					'%1$s (anchor: "%2$s"; expected keyword: "%3$s")',
					$href,
					'' !== $anchor_text ? $anchor_text : '[empty]',
					$inferred_keyword
				);
			}
		}

		if ( ! empty( $failed_links ) ) {
			return $this->build_check(
				'7.4b',
				'PP/LP link anchor text contains the inferred keyword',
				'fail',
				sprintf( 'PP/LP anchors missing inferred keywords: %s.', implode( '; ', $failed_links ) )
			);
		}

		if ( 0 === $evaluated_links ) {
			return $this->build_check(
				'7.4b',
				'PP/LP link anchor text contains the inferred keyword',
				'skipped',
				sprintf( 'Could not infer keywords for PP/LP links: %s.', implode( ', ', array_unique( $skipped_links ) ) )
			);
		}

		return $this->build_check( '7.4b', 'PP/LP link anchor text contains the inferred keyword', 'pass' );
	}

	/**
	 * Check 7.4c.
	 *
	 * @param array<string, array<string, mixed>> $classifications
	 * @return array<string, string>
	 */
	protected function check_link_http_status( array $classifications ) : array {
		$fetch_errors = $this->get_link_fetch_errors( $classifications );
		$requestable_links = $this->get_requestable_link_count( $classifications );

		if ( 0 === $requestable_links ) {
			return $this->build_check( '7.4c', 'Fetched links returned HTTP 200', 'skipped', 'No fetchable links found.' );
		}

		if ( ! empty( $fetch_errors ) ) {
			return $this->build_check(
				'7.4c',
				'Fetched links returned HTTP 200',
				'error',
				sprintf( 'Some fetched links did not return HTTP 200: %s.', implode( '; ', $fetch_errors ) )
			);
		}

		return $this->build_check( '7.4c', 'Fetched links returned HTTP 200', 'pass' );
	}

	/**
	 * Check 7.5a.
	 *
	 * @return array<string, string>
	 */
	protected function check_near_me_plain_text( string $content ) : array {
		if ( empty( $this->find_plain_near_me_text_nodes( $content ) ) ) {
			return $this->build_check( '7.5a', '"Near me" does not appear in plain body text', 'pass' );
		}

		return $this->build_check( '7.5a', '"Near me" does not appear in plain body text', 'fail', 'near me found in plain body copy' );
	}

	/**
	 * Check 7.5b.
	 *
	 * @param array<int, array<string, string>> $links
	 * @param array<string, array<string, bool|string>> $classifications
	 * @return array<string, string>
	 */
	protected function check_near_me_anchor_targets( array $links, array $classifications ) : array {
		$offending_urls = array();
		$fetch_errors = array();

		foreach ( $links as $link ) {
			$anchor_text = (string) ( $link['text'] ?? '' );
			$href = trim( (string) ( $link['href'] ?? '' ) );

			if ( '' === $href || ! $this->contains( $anchor_text, 'near me' ) ) {
				continue;
			}

			$fetch_error = trim( (string) ( $classifications[ $href ]['fetch_error'] ?? '' ) );

			if ( '' !== $fetch_error ) {
				$fetch_errors[] = sprintf( '%1$s (%2$s)', $href, $fetch_error );
				continue;
			}

			if ( empty( $classifications[ $href ]['is_pp_lp'] ) ) {
				$offending_urls[] = $href;
			}
		}

		if ( ! empty( $fetch_errors ) ) {
			return $this->build_check(
				'7.5b',
				'"Near me" anchor text points only to PP/LP pages',
				'error',
				sprintf( 'Could not validate some "near me" anchors because the targets did not return HTTP 200: %s.', implode( ', ', array_unique( $fetch_errors ) ) )
			);
		}

		if ( empty( $offending_urls ) ) {
			return $this->build_check( '7.5b', '"Near me" anchor text points only to PP/LP pages', 'pass' );
		}

		return $this->build_check(
			'7.5b',
			'"Near me" anchor text points only to PP/LP pages',
			'fail',
			sprintf( '"Near me" anchor links must point to PP/LP pages. Offending URLs: %s.', implode( ', ', array_unique( $offending_urls ) ) )
		);
	}

	/**
	 * Return a skip reason for PB-dependent checks.
	 *
	 * @param array<string, mixed>|null $pb_data
	 */
	protected function get_pb_dependency_skip_reason( ?array $pb_data, string $pb_skip_reason ) : string {
		if ( '' !== trim( $pb_skip_reason ) ) {
			return $pb_skip_reason;
		}

		if ( null === $pb_data ) {
			return 'Could not load Pillar Post data';
		}

		return '';
	}

	/**
	 * Return a skip reason for PB-dependent link checks.
	 */
	protected function get_pb_link_skip_reason( ?array $pb_data, string $pb_skip_reason, string $pillar_post_url ) : string {
		$skip_reason = $this->get_pb_dependency_skip_reason( $pb_data, $pb_skip_reason );

		if ( '' !== $skip_reason ) {
			return $skip_reason;
		}

		if ( '' === trim( $pillar_post_url ) ) {
			return 'Pillar Post permalink is unavailable';
		}

		return '';
	}

	/**
	 * Return matching content links for the target URL.
	 *
	 * @param array<int, array<string, string>> $links
	 * @return array<int, array<string, string>>
	 */
	protected function find_links_matching_url( array $links, string $target_url ) : array {
		$normalized_target_url = $this->normalize_url_for_comparison( $target_url );

		if ( '' === $normalized_target_url ) {
			return array();
		}

		$matching_links = array();

		foreach ( $links as $link ) {
			$href = trim( (string) ( $link['href'] ?? '' ) );

			if ( '' === $href || $normalized_target_url !== $this->normalize_url_for_comparison( $href ) ) {
				continue;
			}

			$matching_links[] = $link;
		}

		return $matching_links;
	}

	/**
	 * Normalize URLs for case-insensitive comparisons.
	 */
	protected function normalize_url_for_comparison( string $url ) : string {
		$resolved_url = $this->resolve_url( $url );

		if ( '' === $resolved_url ) {
			return '';
		}

		$resolved_url = preg_replace( '/#.*/', '', $resolved_url ) ?? $resolved_url;
		$parts = wp_parse_url( $resolved_url );

		if ( ! is_array( $parts ) ) {
			return strtolower( untrailingslashit( $resolved_url ) );
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) . '://' : '';
		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$port = isset( $parts['port'] ) ? ':' . (string) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';
		$query = isset( $parts['query'] ) && '' !== (string) $parts['query']
			? '?' . strtolower( (string) $parts['query'] )
			: '';

		return $scheme . $host . $port . $path . $query;
	}

	/**
	 * Resolve a link into a comparable URL.
	 */
	protected function resolve_url( string $url ) : string {
		$url = trim( $url );

		if ( '' === $url || '#' === substr( $url, 0, 1 ) ) {
			return '';
		}

		$sanitized_url = esc_url_raw( $url );

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

		return $sanitized_url;
	}

	/**
	 * Return comparable image signatures.
	 *
	 * @param array<int, array<string, int|string>> $images
	 * @return array{attachment_ids: array<int, int>, file_hashes: array<int, string>, urls: array<int, string>}
	 */
	protected function collect_image_signatures( int $featured_image_id, string $featured_image_src, array $images ) : array {
		$attachment_ids = array();
		$file_hashes = array();
		$urls = array();

		if ( $featured_image_id > 0 ) {
			$attachment_ids[] = $featured_image_id;
		} elseif ( '' !== trim( $featured_image_src ) ) {
			$this->add_url_image_signature( $featured_image_src, $attachment_ids, $file_hashes, $urls );
		}

		foreach ( $images as $image ) {
			$attachment_id = (int) ( $image['attachment_id'] ?? 0 );

			if ( $attachment_id > 0 ) {
				$attachment_ids[] = $attachment_id;
				continue;
			}

			$this->add_url_image_signature( (string) ( $image['src'] ?? '' ), $attachment_ids, $file_hashes, $urls );
		}

		return array(
			'attachment_ids' => array_values( array_unique( array_map( 'intval', $attachment_ids ) ) ),
			'file_hashes' => array_values( array_unique( $file_hashes ) ),
			'urls' => array_values( array_unique( $urls ) ),
		);
	}

	/**
	 * Add the strongest available signature for an image URL.
	 *
	 * @param array<int, int> $attachment_ids
	 * @param array<int, string> $file_hashes
	 * @param array<int, string> $urls
	 */
	protected function add_url_image_signature( string $src, array &$attachment_ids, array &$file_hashes, array &$urls ) : void {
		$normalized_src = $this->normalize_url_for_comparison( $src );

		if ( '' === $normalized_src ) {
			return;
		}

		$attachment_id = (int) attachment_url_to_postid( $normalized_src );

		if ( $attachment_id > 0 ) {
			$attachment_ids[] = $attachment_id;
			return;
		}

		$file_hash = $this->get_local_file_hash_for_url( $normalized_src );

		if ( '' !== $file_hash ) {
			$file_hashes[] = $file_hash;
			return;
		}

		$urls[] = $normalized_src;
	}

	/**
	 * Return a checksum for a local uploads image URL when it maps to a readable file.
	 */
	protected function get_local_file_hash_for_url( string $url ) : string {
		$file_path = $this->resolve_local_upload_path_from_url( $url );

		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return '';
		}

		$hash = md5_file( $file_path );

		return is_string( $hash ) ? $hash : '';
	}

	/**
	 * Resolve a local uploads URL to an absolute file path.
	 */
	protected function resolve_local_upload_path_from_url( string $url ) : string {
		$uploads = wp_get_upload_dir();
		$base_url = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$base_dir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		if ( '' === $base_url || '' === $base_dir ) {
			return '';
		}

		$normalized_base_url = $this->normalize_url_for_comparison( $base_url );
		$normalized_url = $this->normalize_url_for_comparison( $url );

		if ( '' === $normalized_base_url || '' === $normalized_url ) {
			return '';
		}

		if ( ! str_starts_with( $normalized_url, $normalized_base_url . '/' ) && $normalized_url !== $normalized_base_url ) {
			return '';
		}

		$relative_path = ltrim( substr( $normalized_url, strlen( $normalized_base_url ) ), '/' );

		if ( '' === $relative_path ) {
			return '';
		}

		$file_path = wp_normalize_path( trailingslashit( $base_dir ) . $relative_path );
		$base_dir_path = trailingslashit( wp_normalize_path( $base_dir ) );

		if ( ! str_starts_with( $file_path, $base_dir_path ) ) {
			return '';
		}

		return $file_path;
	}

	/**
	 * Return human-readable fetch errors for requestable links.
	 *
	 * @param array<string, array<string, mixed>> $classifications
	 * @return array<int, string>
	 */
	protected function get_link_fetch_errors( array $classifications ) : array {
		$errors = array();

		foreach ( $classifications as $href => $classification ) {
			if ( empty( $classification['is_requestable'] ) ) {
				continue;
			}

			$fetch_error = trim( (string) ( $classification['fetch_error'] ?? '' ) );

			if ( '' === $fetch_error ) {
				continue;
			}

			$errors[] = sprintf( '%1$s (%2$s)', $href, $fetch_error );
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Count links that were eligible for HTTP fetching.
	 *
	 * @param array<string, array<string, mixed>> $classifications
	 */
	protected function get_requestable_link_count( array $classifications ) : int {
		$count = 0;

		foreach ( $classifications as $classification ) {
			if ( ! empty( $classification['is_requestable'] ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Return all non-anchor text nodes that contain "near me".
	 *
	 * @return array<int, string>
	 */
	protected function find_plain_near_me_text_nodes( string $content ) : array {
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$previous_state = libxml_use_internal_errors( true );

		$document->loadHTML(
			'<?xml encoding="utf-8" ?><!DOCTYPE html><html><body>' . $content . '</body></html>'
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		$xpath = new \DOMXPath( $document );
		$nodes = $xpath->query( '//text()[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "near me") and not(ancestor::a)]' );

		if ( ! $nodes ) {
			return array();
		}

		$matches = array();

		foreach ( $nodes as $node ) {
			$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $node->textContent ?? '' ) ) );

			if ( '' !== $text ) {
				$matches[] = $text;
			}
		}

		return $matches;
	}

	/**
	 * Return a scalar value from PB data.
	 *
	 * @param array<string, mixed>|null $pb_data
	 */
	protected function get_string_value( ?array $pb_data, string $key ) : string {
		if ( ! is_array( $pb_data ) ) {
			return '';
		}

		return isset( $pb_data[ $key ] ) && is_scalar( $pb_data[ $key ] )
			? trim( (string) $pb_data[ $key ] )
			: '';
	}

	/**
	 * Return HTML-stripped content for AI prompts.
	 */
	protected function get_plain_content( string $content ) : string {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $content ) ) ?? '' );
	}

	/**
	 * Extract a readable reason from an AI response.
	 */
	protected function extract_ai_reason( string $content, string $fallback_reason ) : string {
		$reason = trim( (string) preg_replace( '/^(pass|fail)\s*(\([^)]*\))?\s*[:\-]?\s*/i', '', trim( $content ) ) );

		return '' !== $reason ? $reason : $fallback_reason;
	}
}
