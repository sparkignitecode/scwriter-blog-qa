<?php

namespace BlogQA;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers and renders the SCwriter Blog QA meta box.
 */
class BlogQA_Dashboard {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 10, 2 );
	}

	/**
	 * Register the SCwriter Blog QA meta box on posts.
	 */
	public function register_meta_box( string $post_type, WP_Post $post ) : void {
		if ( 'post' !== $post_type || ! $this->can_edit_post( $post ) ) {
			return;
		}

		add_meta_box(
			BLOGQA_PREFIX . '-meta-box',
			__( 'SCwriter Blog QA', 'scwriter-blog-qa' ),
			array( $this, 'render_meta_box' ),
			'post',
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box template and localize the initial state.
	 */
	public function render_meta_box( WP_Post $post ) : void {
		if ( ! $this->can_edit_post( $post ) ) {
			return;
		}

		$location = (string) get_post_meta( $post->ID, '_blog_qa_location', true );
		$results = get_post_meta( $post->ID, '_blog_qa_results', true );
		$last_run = (int) get_post_meta( $post->ID, '_blog_qa_last_run', true );
		$is_ai_key_configured = '' !== trim( ( new \BlogQA\Checks\AIStrategy() )->get_openai_api_key() );

		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$this->localize_script( $post->ID, $location, $results, $last_run );

		$formatted_last_run = $this->format_last_run( $last_run );
		$score_text = $this->format_score( $results );

		require BLOGQA_PLUGIN_DIR . 'views/meta_box.php';
	}

	/**
	 * Return whether the current user can edit the current post.
	 */
	protected function can_edit_post( WP_Post $post ) : bool {
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Pass initial state to the admin script.
	 *
	 * @param array<int, array<string, mixed>> $results
	 */
	protected function localize_script( int $post_id, string $location, array $results, int $last_run ) : void {
		wp_localize_script(
			BLOGQA_PREFIX . '-qa',
			'scwriterBlogQaData',
			array(
				'restUrl' => rest_url( 'scwriter-blog-qa/v1/check/' . $post_id ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'postId' => $post_id,
				'location' => $location,
				'initialResults' => $results,
				'lastRun' => $last_run,
				'strings' => array(
					'run' => __( 'Run QA', 'scwriter-blog-qa' ),
					'running' => __( 'Running QA...', 'scwriter-blog-qa' ),
					'runFirst' => __( 'Run QA to evaluate this post.', 'scwriter-blog-qa' ),
					'lastRunNever' => __( 'Last run: Not yet', 'scwriter-blog-qa' ),
					'scoreEmpty' => __( 'No results yet', 'scwriter-blog-qa' ),
					'errorPrefix' => __( 'Unable to run QA:', 'scwriter-blog-qa' ),
					'locationRequired' => __( 'Location is required to run QA.', 'scwriter-blog-qa' ),
				),
			)
		);
	}

	/**
	 * Format the last-run timestamp for the template.
	 */
	protected function format_last_run( int $last_run ) : string {
		if ( $last_run <= 0 ) {
			return __( 'Last run: Not yet', 'scwriter-blog-qa' );
		}

		if ( ( time() - $last_run ) < DAY_IN_SECONDS ) {
			return sprintf(
				/* translators: %s is a relative time string. */
				__( 'Last run: %s ago', 'scwriter-blog-qa' ),
				human_time_diff( $last_run, time() )
			);
		}

		return sprintf(
			/* translators: %s is a formatted date. */
			__( 'Last run: %s', 'scwriter-blog-qa' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run )
		);
	}

	/**
	 * Format the overall score shown above the results.
	 *
	 * @param array<int, array<string, mixed>> $results
	 */
	protected function format_score( array $results ) : string {
		if ( empty( $results ) ) {
			return __( 'No results yet', 'scwriter-blog-qa' );
		}

		$passed = 0;
		$total = 0;

		foreach ( $results as $section ) {
			$checks = is_array( $section['checks'] ?? null ) ? $section['checks'] : array();

			foreach ( $checks as $check ) {
				$status = (string) ( $check['status'] ?? '' );

				if ( 'skipped' === $status ) {
					continue;
				}

				$total++;

				if ( 'pass' === $status ) {
					$passed++;
				}
			}
		}

		return sprintf(
			/* translators: 1: passed checks, 2: total non-skipped checks. */
			__( '%1$d / %2$d passed', 'scwriter-blog-qa' ),
			$passed,
			$total
		);
	}
}
