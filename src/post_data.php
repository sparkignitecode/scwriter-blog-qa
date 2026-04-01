<?php

namespace BlogQA;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

include_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * Loads all post data needed for the QA pass.
 */
class BlogQA_PostData {

	protected int $post_id;

	protected string $seo_plugin = 'meta';

	public function __construct( int $post_id ) {
		$this->post_id = $post_id;
		$this->detect_seo_plugin();
	}

	/**
	 * Return a normalized data payload for all QA checks.
	 *
	 * @return array<string, mixed>
	 */
	public function get_data() : array {
		return array(
			'post_id' => $this->post_id,
			'title' => $this->get_title(),
			'main_keyword' => $this->get_main_keyword(),
			'meta_title' => $this->get_meta_title(),
			'meta_description' => $this->get_meta_description(),
			'secondary_keywords' => $this->get_secondary_keywords(),
			'location' => $this->get_location(),
			'content' => $this->get_content(),
			'slug' => $this->get_slug(),
			'featured_image_id' => $this->get_featured_image_id(),
			'featured_image_src' => $this->get_featured_image_src(),
			'featured_image_alt' => $this->get_featured_image_alt(),
			'content_images' => $this->get_content_images(),
			'seo_plugin' => $this->seo_plugin,
		);
	}

	/**
	 * Detect the SEO plugin that owns primary metadata.
	 */
	public function detect_seo_plugin() : void {
		if ( \is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
			$this->seo_plugin = 'yoast';
			return;
		}

		if ( \is_plugin_active( 'smartcrawl-seo/wpmu-dev-seo.php' ) || \is_plugin_active( 'wpmu-dev-seo/wpmu-dev-seo.php' ) ) {
			$this->seo_plugin = 'smartcrawl';
			return;
		}

		$this->seo_plugin = 'meta';
	}

	/**
	 * Return the post title.
	 */
	public function get_title() : string {
		$post = get_post( $this->post_id );

		return $post ? $this->normalize_string( $post->post_title ?? '' ) : '';
	}

	/**
	 * Return the main keyword from the active SEO source.
	 */
	public function get_main_keyword() : string {
		return $this->read_seo_value(
			'_yoast_wpseo_focuskw',
			'_wds_focus-keywords',
			'primary_keyword'
		);
	}

	/**
	 * Return the SEO meta title from the active SEO source.
	 */
	public function get_meta_title() : string {
		return $this->read_seo_value(
			'_yoast_wpseo_title',
			'_wds_title',
			'seo_meta_title'
		);
	}

	/**
	 * Return the SEO meta description from the active SEO source.
	 */
	public function get_meta_description() : string {
		return $this->read_seo_value(
			'_yoast_wpseo_metadesc',
			'_wds_metadesc',
			'seo_meta_description'
		);
	}

	/**
	 * Return secondary keywords as a trimmed array.
	 *
	 * The `keywords` meta is assumed to store the primary keyword first followed by
	 * secondary keywords. We only drop the first item when it matches the normalized
	 * main keyword; otherwise the full normalized list is preserved.
	 *
	 * @return array<int, string>
	 */
	public function get_secondary_keywords() : array {
		$keywords = $this->normalize_keyword_list( get_post_meta( $this->post_id, 'keywords', true ) );

		if ( empty( $keywords ) ) {
			return array();
		}

		$main_keyword = $this->normalize_keyword_for_comparison( $this->get_main_keyword() );
		$first_keyword = $this->normalize_keyword_for_comparison( $keywords[0] ?? '' );

		if ( '' !== $main_keyword && '' !== $first_keyword && $main_keyword === $first_keyword ) {
			array_shift( $keywords );
		}

		return array_values( $keywords );
	}

	/**
	 * Return the per-post location value.
	 */
	public function get_location() : string {
		return blogqa_resolve_location_default( $this->post_id );
	}

	/**
	 * Return the raw post content.
	 */
	public function get_content() : string {
		$post = get_post( $this->post_id );

		return $post ? (string) ( $post->post_content ?? '' ) : '';
	}

	/**
	 * Return the post slug.
	 */
	public function get_slug() : string {
		$post = get_post( $this->post_id );

		return $post ? $this->normalize_string( $post->post_name ?? '' ) : '';
	}

	/**
	 * Return the featured image ID or zero when absent.
	 */
	public function get_featured_image_id() : int {
		return (int) get_post_thumbnail_id( $this->post_id );
	}

	/**
	 * Return the featured image URL.
	 */
	public function get_featured_image_src() : string {
		$featured_image_id = $this->get_featured_image_id();

		if ( $featured_image_id <= 0 ) {
			return '';
		}

		$image_url = wp_get_attachment_image_url( $featured_image_id, 'full' );

		return $image_url ? (string) $image_url : '';
	}

	/**
	 * Return the featured image alt text.
	 */
	public function get_featured_image_alt() : string {
		$featured_image_id = $this->get_featured_image_id();

		if ( $featured_image_id <= 0 ) {
			return '';
		}

		return $this->normalize_string( get_post_meta( $featured_image_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Return content images found in the post HTML.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	public function get_content_images() : array {
		$document = new BlogQA_HtmlDocument( $this->get_content() );

		return $document->get_images();
	}

	/**
	 * Read a value from the active SEO store.
	 */
	protected function read_seo_value( string $yoast_key, string $smartcrawl_key, string $meta_key ) : string {
		if ( 'yoast' === $this->seo_plugin ) {
			return $this->normalize_string( get_post_meta( $this->post_id, $yoast_key, true ) );
		}

		if ( 'smartcrawl' === $this->seo_plugin ) {
			return $this->normalize_string( get_post_meta( $this->post_id, $smartcrawl_key, true ) );
		}

		return $this->normalize_string( get_post_meta( $this->post_id, $meta_key, true ) );
	}

	/**
	 * Normalize scalar or array input into a single string.
	 *
	 * @param mixed $value
	 */
	protected function normalize_string( $value ) : string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( sanitize_text_field( (string) $value ) );
	}

	/**
	 * Normalize keyword lists from comma-separated strings or arrays.
	 *
	 * @param mixed $value
	 * @return array<int, string>
	 */
	protected function normalize_keyword_list( $value ) : array {
		if ( is_array( $value ) ) {
			$items = $value;
		} else {
			$string_value = $this->normalize_string( $value );

			if ( '' === $string_value ) {
				return array();
			}

			$items = preg_split( '/[\r\n,]+/', $string_value );
		}

		if ( ! is_array( $items ) ) {
			return array();
		}

		$keywords = array();
		$seen_keywords = array();

		foreach ( $items as $item ) {
			$keyword = trim( sanitize_text_field( (string) $item ) );
			$normalized_keyword = $this->normalize_keyword_for_comparison( $keyword );

			if ( '' === $keyword || '' === $normalized_keyword || in_array( $normalized_keyword, $seen_keywords, true ) ) {
				continue;
			}

			$keywords[] = $keyword;
			$seen_keywords[] = $normalized_keyword;
		}

		return $keywords;
	}

	/**
	 * Normalize a keyword for cross-format comparison.
	 */
	protected function normalize_keyword_for_comparison( string $keyword ) : string {
		$keyword = $this->normalize_string( $keyword );
		$keyword = (string) preg_replace( '/[\-–—_]+/u', ' ', $keyword );
		$keyword = trim( (string) preg_replace( '/\s+/u', ' ', $keyword ) );

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $keyword, 'UTF-8' );
		}

		return strtolower( $keyword );
	}
}
