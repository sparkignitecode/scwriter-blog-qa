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

		if ( ! is_singular( 'post' ) ) {
			wp_send_json( array( 'error' => 'Post not found' ), 404 );
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			wp_send_json( array( 'error' => 'Post not found' ), 404 );
		}

		$post_data = ( new BlogQA_PostData( (int) $post->ID ) )->get_data();

		nocache_headers();

		wp_send_json(
			array(
				'title' => (string) ( $post_data['title'] ?? '' ),
				'content' => (string) ( $post_data['content'] ?? '' ),
				'seo_title' => (string) ( $post_data['meta_title'] ?? '' ),
				'seo_description' => (string) ( $post_data['meta_description'] ?? '' ),
				'main_keyword' => (string) ( $post_data['main_keyword'] ?? '' ),
			),
			200
		);
	}
}
