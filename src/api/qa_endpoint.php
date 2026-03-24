<?php

namespace BlogQA\API;

use BlogQA\BlogQA_Checker;
use BlogQA\BlogQA_PillarPostFetcher;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * REST endpoint for running post QA checks on demand.
 */
class BlogQA_QAEndpoint {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the QA run route.
	 */
	public function register_routes() : void {
		register_rest_route(
			'scwriter-blog-qa/v1',
			'/check/(?P<post_id>\d+)',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'can_run_checks' ),
			)
		);
	}

	/**
	 * Check if the current user can run QA.
	 */
	public function can_run_checks( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return true;
		}

		if ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		return new WP_Error(
			'blogqa_forbidden',
			__( 'You are not allowed to run SCwriter Blog QA.', 'scwriter-blog-qa' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Run QA for the requested post.
	 */
	public function handle_request( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'blogqa_post_not_found',
				__( 'Post not found.', 'scwriter-blog-qa' ),
				array( 'status' => 404 )
			);
		}

		$location = sanitize_text_field( trim( (string) $request->get_param( 'location' ) ) );
		$pillar_post_url = esc_url_raw( trim( (string) $request->get_param( 'pillar_post_url' ) ) );
		$pb_secondary_keywords = trim( sanitize_textarea_field( (string) $request->get_param( 'pb_secondary_keywords' ) ) );

		if ( '' === $location ) {
			return new WP_Error(
				'blogqa_location_required',
				__( 'Location is required to run QA.', 'scwriter-blog-qa' ),
				array( 'status' => 400 )
			);
		}

		update_post_meta( $post_id, '_blog_qa_location', $location );
		update_post_meta( $post_id, '_blog_qa_pillar_post_url', $pillar_post_url );
		update_post_meta( $post_id, '_blog_qa_pb_secondary_keywords', $pb_secondary_keywords );

		$pb_data = '' !== $pillar_post_url
			? BlogQA_PillarPostFetcher::fetch( $pillar_post_url )
			: null;

		$results = ( new BlogQA_Checker( $post_id ) )->run( $pb_data, $pb_secondary_keywords, $pillar_post_url );

		return new WP_REST_Response(
			array(
				'results' => $results,
				'last_run' => (int) get_post_meta( $post_id, '_blog_qa_last_run', true ),
			),
			200
		);
	}
}
