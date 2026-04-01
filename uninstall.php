<?php
/**
 * Uninstall Spark Ignite Blog QA.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'blog_qa_openai_api_key' );
delete_site_option( 'blog_qa_openai_api_key' );
