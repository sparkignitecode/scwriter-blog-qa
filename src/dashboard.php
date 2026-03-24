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
		add_action( 'save_post', array( $this, 'save_meta_box_fields' ) );
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

		$location = $this->get_initial_location( $post->ID );
		$pillar_post_url = trim( (string) get_post_meta( $post->ID, '_blog_qa_pillar_post_url', true ) );
		$pb_secondary_keywords = trim( (string) get_post_meta( $post->ID, '_blog_qa_pb_secondary_keywords', true ) );
		$results = get_post_meta( $post->ID, '_blog_qa_results', true );
		$last_run = (int) get_post_meta( $post->ID, '_blog_qa_last_run', true );
		$is_ai_key_configured = '' !== trim( ( new \BlogQA\Checks\AIStrategy() )->get_openai_api_key() );

		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$this->localize_script( $post->ID, $location, $pillar_post_url, $pb_secondary_keywords, $results, $last_run );

		$formatted_last_run = $this->format_last_run( $last_run );
		$score_text = $this->format_score( $results );

		require BLOGQA_PLUGIN_DIR . 'views/meta_box.php';
	}

	/**
	 * Persist meta box fields on normal post saves.
	 */
	public function save_meta_box_fields( int $post_id ) : void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['blogqa_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['blogqa_meta_box_nonce'] ) ), 'blogqa_meta_box' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$pillar_post_url = isset( $_POST['blogqa_pillar_post_url'] )
			? esc_url_raw( trim( wp_unslash( $_POST['blogqa_pillar_post_url'] ) ) )
			: '';
		$pb_secondary_keywords = isset( $_POST['blogqa_pb_secondary_keywords'] )
			? trim( sanitize_textarea_field( wp_unslash( $_POST['blogqa_pb_secondary_keywords'] ) ) )
			: '';

		$this->update_optional_meta( $post_id, '_blog_qa_pillar_post_url', $pillar_post_url );
		$this->update_optional_meta( $post_id, '_blog_qa_pb_secondary_keywords', $pb_secondary_keywords );
	}

	/**
	 * Return whether the current user can edit the current post.
	 */
	protected function can_edit_post( WP_Post $post ) : bool {
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Return the initial location value for the meta box.
	 */
	protected function get_initial_location( int $post_id ) : string {
		$location = trim( (string) get_post_meta( $post_id, '_blog_qa_location', true ) );

		if ( '' !== $location ) {
			return $location;
		}

		$brand_name = trim( (string) get_post_meta( $post_id, 'brand_name', true ) );

		if ( '' !== $brand_name ) {
			return $brand_name;
		}

		// Backward compatibility for posts created before the meta key rename.
		$legacy_brand_meta_key = defined( 'SCWRITER_PREFIX' )
			? SCWRITER_PREFIX . '_brand_name'
			: 'scwriter__brand_name';

		return trim( (string) get_post_meta( $post_id, $legacy_brand_meta_key, true ) );
	}

	/**
	 * Pass initial state to the admin script.
	 *
	 * @param string $pillar_post_url
	 * @param string $pb_secondary_keywords
	 * @param array<int, array<string, mixed>> $results
	 */
	protected function localize_script( int $post_id, string $location, string $pillar_post_url, string $pb_secondary_keywords, array $results, int $last_run ) : void {
		wp_localize_script(
			BLOGQA_PREFIX . '-qa',
			'scwriterBlogQaData',
			array(
				'restUrl' => rest_url( 'scwriter-blog-qa/v1/check/' . $post_id ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'postId' => $post_id,
				'location' => $location,
				'pillarPostUrl' => $pillar_post_url,
				'pbSecondaryKeywords' => $pb_secondary_keywords,
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

	/**
	 * Update or delete a meta value depending on whether it is empty.
	 */
	protected function update_optional_meta( int $post_id, string $meta_key, string $value ) : void {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}
}
