<?php

namespace BlogQA;

use BlogQA\API\BlogQA_QAEndpoint;
use BlogQA\API\BlogQA_SparkSeoEndpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Plugin bootstrap for hooks and admin assets.
 */
class BlogQA_WP {

	protected BlogQA_Dashboard $dashboard;

	protected BlogQA_QAEndpoint $qa_endpoint;

	protected BlogQA_SparkSeoEndpoint $spark_seo_endpoint;

	protected BlogQA_OpenAISettingsPage $openai_settings_page;

	public function __construct() {
		$this->dashboard = new BlogQA_Dashboard();
		$this->qa_endpoint = new BlogQA_QAEndpoint();
		$this->spark_seo_endpoint = new BlogQA_SparkSeoEndpoint();
		$this->openai_settings_page = new BlogQA_OpenAISettingsPage();
		$this->register_hook_callbacks();
	}

	/**
	 * Register WordPress hooks used by the plugin.
	 */
	public function register_hook_callbacks() : void {
		add_action( 'admin_enqueue_scripts', array( $this, 'load_resources' ) );
		$this->openai_settings_page->register_hooks();
	}

	/**
	 * Enqueue Spark Ignite Blog QA assets on post editing screens.
	 */
	public function load_resources() : void {
		if ( ! $this->should_load_admin_assets() ) {
			return;
		}

		wp_enqueue_script(
			BLOGQA_PREFIX . '-qa',
			BLOGQA_PLUGIN_URL . 'javascript/qa.js',
			array(),
			filemtime( BLOGQA_PLUGIN_DIR . 'javascript/qa.js' ),
			true
		);

		wp_enqueue_style(
			BLOGQA_PREFIX . '-qa',
			BLOGQA_PLUGIN_URL . 'css/qa.css',
			array(),
			filemtime( BLOGQA_PLUGIN_DIR . 'css/qa.css' )
		);
	}

	/**
	 * Limit assets to post edit and create screens.
	 */
	protected function should_load_admin_assets() : bool {
		global $pagenow;

		if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->post_type ) {
			return false;
		}

		$post_id = $this->get_current_post_id();

		if ( $post_id > 0 ) {
			return blogqa_user_can_run_qa_for_post( $post_id );
		}

		return blogqa_user_can_search_pillar_posts();
	}

	/**
	 * Return the current post ID from the post editor request when available.
	 */
	protected function get_current_post_id() : int {
		if ( isset( $_GET['post'] ) ) {
			return absint( wp_unslash( $_GET['post'] ) );
		}

		if ( isset( $_POST['post_ID'] ) ) {
			return absint( wp_unslash( $_POST['post_ID'] ) );
		}

		return 0;
	}
}
