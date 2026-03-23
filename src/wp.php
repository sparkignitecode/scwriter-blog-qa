<?php

namespace BlogQA;

use BlogQA\API\BlogQA_QAEndpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Plugin bootstrap for hooks and admin assets.
 */
class BlogQA_WP {

	protected BlogQA_Dashboard $dashboard;

	protected BlogQA_QAEndpoint $qa_endpoint;

	public function __construct() {
		$this->dashboard = new BlogQA_Dashboard();
		$this->qa_endpoint = new BlogQA_QAEndpoint();
		$this->register_hook_callbacks();
	}

	/**
	 * Register WordPress hooks used by the plugin.
	 */
	public function register_hook_callbacks() : void {
		add_action( 'admin_enqueue_scripts', array( $this, 'load_resources' ) );
	}

	/**
	 * Enqueue SCwriter Blog QA assets on post editing screens.
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

		return $screen && 'post' === $screen->post_type;
	}
}
