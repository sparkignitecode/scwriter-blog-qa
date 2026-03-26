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
		$pillar_post_id = $this->get_initial_pillar_post_id( $post->ID );
		$pillar_post_label = $this->get_pillar_post_label( $pillar_post_id );
		$results = get_post_meta( $post->ID, '_blog_qa_results', true );
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$last_run = (int) get_post_meta( $post->ID, '_blog_qa_last_run', true );
		$last_run_mode = $this->get_last_run_mode( $post->ID, $pillar_post_id, $results );
		$is_ai_key_configured = '' !== trim( ( new \BlogQA\Checks\AIStrategy() )->get_openai_api_key() );

		$this->localize_script( $post->ID, $location, $pillar_post_id, $pillar_post_label, $results, $last_run, $last_run_mode );

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

		if ( ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['blogqa_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['blogqa_meta_box_nonce'] ) ), 'blogqa_meta_box' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$pillar_post_id = isset( $_POST['blogqa_pillar_post_id'] )
			? absint( wp_unslash( $_POST['blogqa_pillar_post_id'] ) )
			: 0;
		$pillar_post_id = $this->resolve_effective_pillar_post_id( $post_id, $pillar_post_id );

		$this->update_optional_int_meta( $post_id, '_blog_qa_pillar_post_id', $pillar_post_id );
		delete_post_meta( $post_id, '_blog_qa_pillar_post_url' );
		delete_post_meta( $post_id, '_blog_qa_pb_secondary_keywords' );
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
	 * @param array<int, array<string, mixed>> $results
	 */
	protected function localize_script( int $post_id, string $location, int $pillar_post_id, string $pillar_post_label, array $results, int $last_run, string $last_run_mode ) : void {
		wp_localize_script(
			BLOGQA_PREFIX . '-qa',
			'scwriterBlogQaData',
			array(
				'restUrl' => rest_url( 'scwriter-blog-qa/v1/check/' . $post_id ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'postId' => $post_id,
				'location' => $location,
				'pillarPostId' => $pillar_post_id,
				'pillarPostLabel' => $pillar_post_label,
				'pillarSearchUrl' => rest_url( 'scwriter-blog-qa/v1/pillar-posts' ),
				'initialResults' => $results,
				'lastRun' => $last_run,
				'lastRunMode' => $last_run_mode,
				'strings' => array(
					'run' => __( 'Run QA', 'scwriter-blog-qa' ),
					'running' => __( 'Running QA...', 'scwriter-blog-qa' ),
					'runFirst' => __( 'Run QA to evaluate this post.', 'scwriter-blog-qa' ),
					'lastRunNever' => __( 'Last run: Not yet', 'scwriter-blog-qa' ),
					'scoreEmpty' => __( 'No results yet', 'scwriter-blog-qa' ),
					'errorPrefix' => __( 'Unable to run QA:', 'scwriter-blog-qa' ),
					'locationRequired' => __( 'Location is required to run QA.', 'scwriter-blog-qa' ),
					'pillarSearchPlaceholder' => __( 'Search pillar posts', 'scwriter-blog-qa' ),
					'pillarSearchEmpty' => __( 'No pillar posts found.', 'scwriter-blog-qa' ),
					'pillarSearchLoading' => __( 'Searching pillar posts...', 'scwriter-blog-qa' ),
					'pillarSearchError' => __( 'Could not load pillar posts.', 'scwriter-blog-qa' ),
					'currentModePillar' => __( 'Leave empty for pillar mode. Select a post for regular mode.', 'scwriter-blog-qa' ),
					'currentModeRegular' => __( 'Regular mode: comparing this post against the selected pillar post.', 'scwriter-blog-qa' ),
					'resultsModePillar' => __( 'Last results: Pillar mode', 'scwriter-blog-qa' ),
					'resultsModeRegular' => __( 'Last results: Regular mode', 'scwriter-blog-qa' ),
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
	 * Return the stored pillar post ID or a non-persisted legacy URL match for display.
	 */
	protected function get_initial_pillar_post_id( int $post_id ) : int {
		$pillar_post_id = (int) get_post_meta( $post_id, '_blog_qa_pillar_post_id', true );
		$context = new BlogQA_PillarPostContext();

		if ( $context->resolve_selected_post_id( $post_id, $pillar_post_id ) > 0 ) {
			return $pillar_post_id;
		}

		return $this->resolve_legacy_pillar_post_id( $post_id );
	}

	/**
	 * Resolve a label for the selected pillar post.
	 */
	protected function get_pillar_post_label( int $pillar_post_id ) : string {
		if ( $pillar_post_id <= 0 ) {
			return '';
		}

		$pillar_post = get_post( $pillar_post_id );

		if ( ! ( $pillar_post instanceof WP_Post ) ) {
			return '';
		}

		$title = trim( wp_strip_all_tags( get_the_title( $pillar_post ) ) );

		if ( '' === $title ) {
			$title = __( '(no title)', 'scwriter-blog-qa' );
		}

		return sprintf( '%1$s (#%2$d)', $title, $pillar_post_id );
	}

	/**
	 * Resolve a saved local URL to the new pillar post ID without writing on render.
	 */
	protected function resolve_legacy_pillar_post_id( int $post_id ) : int {
		$context = new BlogQA_PillarPostContext();
		$legacy_url = trim( (string) get_post_meta( $post_id, '_blog_qa_pillar_post_url', true ) );

		if ( '' === $legacy_url || ! $this->is_local_site_url( $legacy_url ) ) {
			return 0;
		}

		$pillar_post_id = url_to_postid( $legacy_url );

		if ( $pillar_post_id <= 0 ) {
			return 0;
		}

		return $context->resolve_selected_post_id( $post_id, $pillar_post_id );
	}

	/**
	 * Resolve the final pillar post ID before save.
	 */
	protected function resolve_effective_pillar_post_id( int $post_id, int $pillar_post_id ) : int {
		return ( new BlogQA_PillarPostContext() )->resolve_selected_post_id( $post_id, $pillar_post_id );
	}

	/**
	 * Return whether the provided URL belongs to this site.
	 */
	protected function is_local_site_url( string $url ) : bool {
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return is_string( $url_host ) && is_string( $site_host ) && strtolower( $url_host ) === strtolower( $site_host );
	}

	/**
	 * Update or delete an integer meta value depending on whether it is empty.
	 */
	protected function update_optional_int_meta( int $post_id, string $meta_key, int $value ) : void {
		if ( $value <= 0 ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, (string) $value );
	}

	/**
	 * Resolve the mode label to show for the stored result set.
	 *
	 * @param array<int, array<string, mixed>> $results
	 */
	protected function get_last_run_mode( int $post_id, int $pillar_post_id, array $results ) : string {
		$last_run_mode = trim( (string) get_post_meta( $post_id, '_blog_qa_mode', true ) );

		if ( in_array( $last_run_mode, array( 'pillar', 'regular' ), true ) ) {
			return $last_run_mode;
		}

		if ( empty( $results ) ) {
			return $pillar_post_id > 0 ? 'regular' : 'pillar';
		}

		return $pillar_post_id > 0 ? 'regular' : 'pillar';
	}
}
