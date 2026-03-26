<?php

namespace BlogQA;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Builds the pillar-post context used by section 7 checks.
 */
class BlogQA_PillarPostContext {

	/**
	 * Build normalized pillar data for a QA run.
	 *
	 * @return array{
	 *     pb_data: array<string, mixed>|null,
	 *     pb_keywords: array<int, string>,
	 *     pillar_post_url: string,
	 *     skip_reason: string
	 * }
	 */
	public function build( int $post_id, int $pillar_post_id ) : array {
		$context = array(
			'pb_data' => null,
			'pb_keywords' => array(),
			'pillar_post_url' => '',
			'skip_reason' => '',
		);

		if ( $pillar_post_id <= 0 ) {
			$context['skip_reason'] = __( 'No Pillar Post selected', 'scwriter-blog-qa' );
			return $context;
		}

		if ( $pillar_post_id === $post_id ) {
			$context['skip_reason'] = __( 'Pillar Post cannot match the current post', 'scwriter-blog-qa' );
			return $context;
		}

		$pillar_post = get_post( $pillar_post_id );

		if ( ! $pillar_post ) {
			$context['skip_reason'] = __( 'Pillar Post could not be found', 'scwriter-blog-qa' );
			return $context;
		}

		if ( ! current_user_can( 'read_post', $pillar_post_id ) ) {
			$context['skip_reason'] = __( 'You are not allowed to read the selected Pillar Post', 'scwriter-blog-qa' );
			return $context;
		}

		$post_data = ( new BlogQA_PostData( $pillar_post_id ) )->get_data();
		$secondary_keywords = is_array( $post_data['secondary_keywords'] ?? null )
			? array_values(
				array_filter(
					array_map( 'strval', $post_data['secondary_keywords'] ),
					static fn( string $keyword ) : bool => '' !== trim( $keyword )
				)
			)
			: array();

		$context['pb_data'] = array(
			'post_id' => (int) ( $post_data['post_id'] ?? 0 ),
			'title' => (string) ( $post_data['title'] ?? '' ),
			'content' => (string) ( $post_data['content'] ?? '' ),
			'seo_title' => (string) ( $post_data['meta_title'] ?? '' ),
			'seo_description' => (string) ( $post_data['meta_description'] ?? '' ),
			'main_keyword' => (string) ( $post_data['main_keyword'] ?? '' ),
			'featured_image_id' => (int) ( $post_data['featured_image_id'] ?? 0 ),
			'featured_image_src' => (string) ( $post_data['featured_image_src'] ?? '' ),
			'content_images' => is_array( $post_data['content_images'] ?? null ) ? $post_data['content_images'] : array(),
		);
		$context['pb_keywords'] = $secondary_keywords;

		$permalink = get_permalink( $pillar_post_id );

		if ( is_string( $permalink ) ) {
			$context['pillar_post_url'] = $permalink;
		}

		return $context;
	}
}
