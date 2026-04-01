<?php

namespace BlogQA;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

include_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * Return whether Spark Ignite is active for the current site.
 */
function blogqa_is_spark_ignite_active() : bool {
	return function_exists( 'is_plugin_active' ) && \is_plugin_active( 'spark-ignite/spark_plugin.php' );
}

/**
 * Return whether the current user is allowed to use Blog QA.
 */
function blogqa_user_can_use_plugin() : bool {
	// Ignite uses this non-standard capability check in multiple admin gates.
	if ( is_super_admin() || current_user_can( 'administrator' ) || current_user_can( 'spark_seo_manager' ) ) {
		return true;
	}

	if ( ! blogqa_is_spark_ignite_active() || ! class_exists( '\Spark\WP' ) || ! is_callable( array( '\Spark\WP', 'is_spark_admins_email' ) ) ) {
		return false;
	}

	return (bool) \Spark\WP::is_spark_admins_email();
}

/**
 * Return whether the current user can run QA for a specific post.
 */
function blogqa_user_can_run_qa_for_post( int $post_id ) : bool {
	return $post_id > 0 && blogqa_user_can_use_plugin() && current_user_can( 'edit_post', $post_id );
}

/**
 * Return whether the current user can search pillar posts.
 */
function blogqa_user_can_search_pillar_posts() : bool {
	return blogqa_user_can_use_plugin() && current_user_can( 'edit_posts' );
}

/**
 * Resolve the effective Blog QA location value for a post.
 */
function blogqa_resolve_location_default( int $post_id ) : string {
	$location = blogqa_normalize_location_value( get_post_meta( $post_id, '_blog_qa_location', true ) );

	if ( '' !== $location ) {
		return $location;
	}

	$brand_name = blogqa_normalize_location_value( get_post_meta( $post_id, 'brand_name', true ) );

	if ( '' !== $brand_name ) {
		return $brand_name;
	}

	$legacy_brand_name = blogqa_normalize_location_value( get_post_meta( $post_id, blogqa_get_legacy_brand_meta_key(), true ) );

	if ( '' !== $legacy_brand_name ) {
		return $legacy_brand_name;
	}

	if ( ! blogqa_is_spark_ignite_active() || ! class_exists( '\Spark\Site_API' ) || ! is_callable( array( '\Spark\Site_API', 'get_site_info' ) ) ) {
		return '';
	}

	$site_info = \Spark\Site_API::get_site_info();

	if ( is_wp_error( $site_info ) || ! is_array( $site_info ) || ! empty( $site_info['error'] ) ) {
		return '';
	}

	return blogqa_normalize_location_value( $site_info['data']['location_name'] ?? '' );
}

/**
 * Return the legacy SCwriter brand meta key used before the rename.
 */
function blogqa_get_legacy_brand_meta_key() : string {
	return defined( 'SCWRITER_PREFIX' )
		? SCWRITER_PREFIX . '_brand_name'
		: 'scwriter__brand_name';
}

/**
 * Normalize a location-like value into a sanitized string.
 *
 * @param mixed $value
 */
function blogqa_normalize_location_value( $value ) : string {
	if ( is_array( $value ) ) {
		$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
	}

	if ( ! is_scalar( $value ) ) {
		return '';
	}

	return trim( sanitize_text_field( (string) $value ) );
}
