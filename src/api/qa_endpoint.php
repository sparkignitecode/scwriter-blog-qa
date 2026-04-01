<?php

namespace BlogQA\API;

use BlogQA\BlogQA_Checker;
use BlogQA\BlogQA_PillarPostContext;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function BlogQA\blogqa_user_can_run_qa_for_post;
use function BlogQA\blogqa_user_can_search_pillar_posts;
use function BlogQA\blogqa_user_can_use_plugin;

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
				'args' => array(
					'post_id' => array(
						'type' => 'integer',
						'required' => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_post_id' ),
					),
					'location' => array(
						'type' => 'string',
						'required' => true,
						'sanitize_callback' => array( $this, 'sanitize_location' ),
						'validate_callback' => array( $this, 'validate_location' ),
					),
					'pillar_post_id' => array(
						'type' => 'integer',
						'required' => false,
						'default' => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_pillar_post_id' ),
					),
				),
			)
		);

		register_rest_route(
			'scwriter-blog-qa/v1',
			'/pillar-posts',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'search_pillar_posts' ),
				'permission_callback' => array( $this, 'can_search_pillar_posts' ),
				'args' => array(
					'search' => array(
						'type' => 'string',
						'required' => false,
						'default' => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'exclude_post_id' => array(
						'type' => 'integer',
						'required' => false,
						'default' => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Check if the current user can run QA.
	 */
	public function can_run_checks( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( $post_id <= 0 ) {
			return true;
		}

		if ( ! get_post( $post_id ) ) {
			if ( blogqa_user_can_use_plugin() ) {
				return true;
			}

			return new WP_Error(
				'blogqa_forbidden',
				__( 'You are not allowed to run SCwriter Blog QA.', 'scwriter-blog-qa' ),
				array( 'status' => 403 )
			);
		}

		if ( blogqa_user_can_run_qa_for_post( $post_id ) ) {
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

		$location = $this->sanitize_location( $request->get_param( 'location' ), $request, 'location' );
		$pillar_post_id = absint( $request->get_param( 'pillar_post_id' ) );
		$pillar_post_id = $this->resolve_effective_pillar_post_id( $post_id, $pillar_post_id );

		update_post_meta( $post_id, '_blog_qa_location', $location );
		if ( $pillar_post_id > 0 ) {
			update_post_meta( $post_id, '_blog_qa_pillar_post_id', (string) $pillar_post_id );
		} else {
			delete_post_meta( $post_id, '_blog_qa_pillar_post_id' );
		}

		delete_post_meta( $post_id, '_blog_qa_pillar_post_url' );
		delete_post_meta( $post_id, '_blog_qa_pb_secondary_keywords' );

		$results = ( new BlogQA_Checker( $post_id ) )->run( $pillar_post_id );

		return new WP_REST_Response(
			array(
				'results' => $results,
				'last_run' => (int) get_post_meta( $post_id, '_blog_qa_last_run', true ),
				'mode' => (string) get_post_meta( $post_id, '_blog_qa_mode', true ),
			),
			200
		);
	}

	/**
	 * Sanitize the location request field.
	 *
	 * @param mixed $value
	 */
	public function sanitize_location( $value, ?WP_REST_Request $request = null, string $param = '' ) : string {
		return sanitize_text_field( trim( (string) $value ) );
	}

	/**
	 * Validate the target post ID from the route.
	 *
	 * @param mixed $value
	 */
	public function validate_post_id( $value, WP_REST_Request $request, string $param ) : bool|WP_Error {
		$post_id = absint( $value );

		if ( $post_id > 0 ) {
			return true;
		}

		return new WP_Error(
			'blogqa_invalid_post_id',
			__( 'A valid post ID is required.', 'scwriter-blog-qa' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Validate the required location field.
	 *
	 * @param mixed $value
	 */
	public function validate_location( $value, WP_REST_Request $request, string $param ) : bool|WP_Error {
		if ( '' !== $this->sanitize_location( $value, $request, $param ) ) {
			return true;
		}

		return new WP_Error(
			'blogqa_location_required',
			__( 'Location is required to run QA.', 'scwriter-blog-qa' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Validate the optional pillar post ID field.
	 *
	 * @param mixed $value
	 */
	public function validate_pillar_post_id( $value, WP_REST_Request $request, string $param ) : bool|WP_Error {
		$pillar_post_id = absint( $value );

		if ( $pillar_post_id >= 0 ) {
			return true;
		}

		return new WP_Error(
			'blogqa_invalid_pillar_post_id',
			__( 'Pillar Post ID must be zero or a positive integer.', 'scwriter-blog-qa' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Check whether the current user can search local pillar posts.
	 */
	public function can_search_pillar_posts() {
		if ( blogqa_user_can_search_pillar_posts() ) {
			return true;
		}

		return new WP_Error(
			'blogqa_forbidden',
			__( 'You are not allowed to search Pillar Posts.', 'scwriter-blog-qa' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Return search results for the pillar selector.
	 */
	public function search_pillar_posts( WP_REST_Request $request ) : WP_REST_Response {
		$search = sanitize_text_field( trim( (string) $request->get_param( 'search' ) ) );
		$exclude_post_id = absint( $request->get_param( 'exclude_post_id' ) );
		$query_args = array(
			'post_type' => 'post',
			'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => 20,
			'post__not_in' => $exclude_post_id > 0 ? array( $exclude_post_id ) : array(),
			'orderby' => 'date',
			'order' => 'DESC',
			'ignore_sticky_posts' => true,
			'fields' => 'ids',
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$post_ids = get_posts( $query_args );

		$results = array();

		foreach ( $post_ids as $pillar_post_id ) {
			if ( ! current_user_can( 'read_post', $pillar_post_id ) ) {
				continue;
			}

			$results[] = array(
				'id' => $pillar_post_id,
				'label' => $this->get_pillar_post_label( $pillar_post_id ),
			);
		}

		return new WP_REST_Response( $results, 200 );
	}

	/**
	 * Build a concise label for the pillar selector.
	 */
	protected function get_pillar_post_label( int $post_id ) : string {
		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return '';
		}

		$title = trim( wp_strip_all_tags( get_the_title( $post ) ) );

		if ( '' === $title ) {
			$title = __( '(no title)', 'scwriter-blog-qa' );
		}

		return sprintf( '%1$s (#%2$d)', $title, $post_id );
	}

	/**
	 * Resolve the final pillar post ID before persistence.
	 */
	protected function resolve_effective_pillar_post_id( int $post_id, int $pillar_post_id ) : int {
		return ( new BlogQA_PillarPostContext() )->resolve_selected_post_id( $post_id, $pillar_post_id );
	}
}
