<?php

namespace BlogQA;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Builds the pillar-post context used by QA mode selection and section 7 checks.
 */
class BlogQA_PillarPostContext {

	/**
	 * Return the validated selected pillar post ID for regular mode or zero for pillar mode.
	 */
	public function resolve_selected_post_id( int $post_id, int $pillar_post_id ) : int {
		if ( $pillar_post_id <= 0 || $pillar_post_id === $post_id ) {
			return 0;
		}

		$pillar_post = get_post( $pillar_post_id );

		if ( ! $pillar_post ) {
			return 0;
		}

		if ( ! current_user_can( 'read_post', $pillar_post_id ) ) {
			return 0;
		}

		return $pillar_post_id;
	}

	/**
	 * Build normalized pillar data for a QA run.
	 *
	 * @return array{
	 *     mode: string,
	 *     selected_pillar_post_id: int,
	 *     pb_data: array<string, mixed>|null,
	 *     pb_keywords: array<int, string>,
	 *     pillar_post_url: string,
	 *     skip_reason: string
	 * }
	 */
	public function build( int $post_id, int $pillar_post_id ) : array {
		$context = array(
			'mode' => 'pillar',
			'selected_pillar_post_id' => 0,
			'pb_data' => null,
			'pb_keywords' => array(),
			'pillar_post_url' => '',
			'skip_reason' => '',
		);

		$resolved_pillar_post_id = $this->resolve_selected_post_id( $post_id, $pillar_post_id );

		if ( $resolved_pillar_post_id <= 0 ) {
			return $context;
		}

		$context['mode'] = 'regular';
		$context['selected_pillar_post_id'] = $resolved_pillar_post_id;

		$post_data = ( new BlogQA_PostData( $resolved_pillar_post_id ) )->get_data();
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

		$permalink = get_permalink( $resolved_pillar_post_id );

		if ( is_string( $permalink ) ) {
			$context['pillar_post_url'] = $permalink;
		}

		return $context;
	}
}
