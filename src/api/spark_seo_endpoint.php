<?php

namespace BlogQA\API;

use BlogQA\BlogQA_PostData;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Exposes SEO data for published posts through a query-parameter endpoint.
 */
class BlogQA_SparkSeoEndpoint {

	public function __construct() {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_json' ) );
	}

	/**
	 * Register the spark_seo query variable.
	 *
	 * @param array<int, string> $query_vars
	 * @return array<int, string>
	 */
	public function register_query_var( array $query_vars ) : array {
		if ( ! in_array( 'spark_seo', $query_vars, true ) ) {
			$query_vars[] = 'spark_seo';
		}

		return $query_vars;
	}

	/**
	 * Output JSON when the spark_seo query parameter is present.
	 */
	public function maybe_render_json() : void {
		if ( 'scwriter' !== (string) get_query_var( 'spark_seo' ) ) {
			return;
		}

		if ( ! is_singular() ) {
			wp_send_json( array( 'error' => 'Post not found' ), 404 );
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof WP_Post ) ) {
			wp_send_json( array( 'error' => 'Post not found' ), 404 );
		}

		$post_data = ( new BlogQA_PostData( (int) $post->ID ) )->get_data();

		nocache_headers();

		wp_send_json(
			array(
				'post_id' => (int) $post->ID,
				'post_type' => (string) $post->post_type,
				'title' => (string) ( $post_data['title'] ?? '' ),
				'content' => (string) ( $post_data['content'] ?? '' ),
				'seo_title' => (string) ( $post_data['meta_title'] ?? '' ),
				'seo_description' => (string) ( $post_data['meta_description'] ?? '' ),
				'main_keyword' => (string) ( $post_data['main_keyword'] ?? '' ),
				'secondary_keywords' => is_array( $post_data['secondary_keywords'] ?? null ) ? array_values( $post_data['secondary_keywords'] ) : array(),
				'is_pp_lp' => $this->resolve_is_pp_lp( $post, $post_data ),
			),
			200
		);
	}

	/**
	 * Resolve whether the current singular object should be treated as PP/LP.
	 *
	 * @param array<string, mixed> $post_data
	 */
	protected function resolve_is_pp_lp( WP_Post $post, array $post_data ) : bool {
		$meta_value = get_post_meta( $post->ID, 'is_pp_lp', true );

		if ( '' !== (string) $meta_value ) {
			return wp_validate_boolean( $meta_value );
		}

		$legacy_meta_value = get_post_meta( $post->ID, '_is_pp_lp', true );

		if ( '' !== (string) $legacy_meta_value ) {
			return wp_validate_boolean( $legacy_meta_value );
		}

		/**
		 * Filter the spark_seo PP/LP flag for the current singular object.
		 */
		return (bool) apply_filters( 'blogqa_spark_seo_is_pp_lp', false, $post, $post_data );
	}
}
