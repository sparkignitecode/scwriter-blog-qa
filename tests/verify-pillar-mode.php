<?php

declare(strict_types=1);

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "Could not find wp-load.php\n" );
	exit( 1 );
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

if ( ! defined( 'BLOGQA_DISABLE_REMOTE_FETCHES' ) ) {
	define( 'BLOGQA_DISABLE_REMOTE_FETCHES', true );
}

require_once $wp_load;

if ( ! class_exists( \BlogQA\BlogQA_Checker::class ) ) {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
}

/**
 * Throw when an assertion fails.
 */
function blogqa_assert( bool $condition, string $message ) : void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Emit a progress note for the CLI harness.
 */
function blogqa_note( string $message ) : void {
	fwrite( STDERR, '[verify] ' . $message . PHP_EOL );
}

/**
 * Return the first result section with the requested label.
 *
 * @param array<int, array<string, mixed>> $results
 * @return array<string, mixed>|null
 */
function blogqa_find_section_by_label( array $results, string $label ) : ?array {
	foreach ( $results as $section ) {
		if ( $label === (string) ( $section['label'] ?? '' ) ) {
			return $section;
		}
	}

	return null;
}

/**
 * Return the first check with the requested ID from a section.
 *
 * @param array<string, mixed> $section
 * @return array<string, mixed>|null
 */
function blogqa_find_check( array $section, string $check_id ) : ?array {
	$checks = is_array( $section['checks'] ?? null ) ? $section['checks'] : array();

	foreach ( $checks as $check ) {
		if ( $check_id === (string) ( $check['id'] ?? '' ) ) {
			return is_array( $check ) ? $check : null;
		}
	}

	return null;
}

/**
 * Build a mock HTTP response for the WordPress HTTP API.
 *
 * @return array<string, mixed>
 */
function blogqa_http_response( string $body, int $status_code = 200 ) : array {
	return array(
		'headers' => array(),
		'body' => $body,
		'response' => array(
			'code' => $status_code,
			'message' => 200 === $status_code ? 'OK' : 'Error',
		),
		'cookies' => array(),
		'filename' => null,
	);
}

/**
 * Build plain text with a predictable word count.
 */
function blogqa_repeat_words( string $word, int $count ) : string {
	return trim( implode( ' ', array_fill( 0, $count, $word ) ) );
}

/**
 * Build pillar-mode HTML that satisfies the TOC and word-count bands.
 */
function blogqa_build_pillar_html( string $pp_lp_url ) : string {
	$intro = sprintf(
		'<p>%s</p>',
		blogqa_repeat_words( 'karate', 220 )
	);
	$toc = '<ul><li><a href="#what-is-karate">What Is Karate</a></li><li><a href="#training-benefits">Training Benefits</a></li></ul>';
	$section_one = sprintf(
		'<h2 id="what-is-karate">What Is Karate</h2><p>%s</p>',
		blogqa_repeat_words( 'basics', 1450 )
	);
	$section_two = sprintf(
		'<h2 id="training-benefits">Training Benefits</h2><p>%s</p>',
		blogqa_repeat_words( 'training', 1450 )
	);
	$link = sprintf( '<p><a href="%s">karate for kids</a></p>', esc_url( $pp_lp_url ) );
	$images = implode(
		'',
		array_map(
			static fn( int $index ) : string => sprintf(
				'<img src="https://cdn.example.com/image-%1$d.jpg" alt="karate image %1$d" />',
				$index
			),
			range( 1, 5 )
		)
	);

	return $intro . $toc . $section_one . $section_two . $link . $images;
}

/**
 * Test double for pillar internal linking without live HTTP requests.
 */
class BlogQATestPillarInternalLinking extends \BlogQA\Checks\PillarInternalLinking {

	/**
	 * @var array<string, array<string, mixed>>
	 */
	protected array $mock_payloads = array();

	/**
	 * Run the section against mocked links, classifications, and spark_seo payloads.
	 *
	 * @param array<string, mixed> $post_data
	 * @param array<int, array<string, string>> $links
	 * @param array<string, array<string, mixed>> $classifications
	 * @param array<string, array<string, mixed>> $payloads
	 * @return array<string, mixed>
	 */
	public function run_with_mocks( array $post_data, array $links, array $classifications, array $payloads ) : array {
		$this->mock_payloads = $payloads;

		return $this->build_section(
			'7',
			'Pillar Internal Linking',
			array(
				$this->check_pp_lp_link_presence( $links, $classifications ),
				$this->check_pp_lp_anchor_keywords( $links, $classifications ),
				$this->check_link_http_status( $classifications ),
				$this->check_linked_target_keyword_overlap( $links, $classifications, (string) ( $post_data['main_keyword'] ?? '' ) ),
				$this->check_near_me_plain_text( (string) ( $post_data['content'] ?? '' ) ),
				$this->check_near_me_anchor_targets( $links, $classifications ),
			)
		);
	}

	/**
	 * Return a mocked spark_seo payload for the requested target.
	 *
	 * @return array<string, mixed>
	 */
	protected function load_target_seo_payload( string $target_url ) : array {
		return $this->mock_payloads[ $target_url ] ?? array( 'error' => 'Missing mock spark_seo payload' );
	}
}

$admin_ids = get_users(
	array(
		'role' => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( empty( $admin_ids ) ) {
	fwrite( STDERR, "No administrator user found for verification.\n" );
	exit( 1 );
}

wp_set_current_user( (int) $admin_ids[0] );

if ( is_multisite() ) {
	delete_site_option( \BlogQA\BlogQA_OpenAISettings::OPTION_NAME );
} else {
	delete_option( \BlogQA\BlogQA_OpenAISettings::OPTION_NAME );
}

$created_post_ids = array();
$exit_code = 0;
$output_message = "Verification passed.\n";

try {
	blogqa_note( 'creating temporary posts' );
	$pillar_post_id = wp_insert_post(
		array(
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Verification Pillar',
			'post_content' => '<p>Reference pillar content.</p>',
		)
	);
	blogqa_assert( $pillar_post_id > 0, 'Could not create the reference pillar post.' );
	$created_post_ids[] = $pillar_post_id;
	update_post_meta( $pillar_post_id, 'primary_keyword', 'pillar keyword' );
	update_post_meta( $pillar_post_id, '_blog_qa_location', 'Austin' );

	$support_post_id = wp_insert_post(
		array(
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Verification Support Post',
			'post_content' => '<p>Support content with no outbound links.</p>',
		)
	);
	blogqa_assert( $support_post_id > 0, 'Could not create the support post.' );
	$created_post_ids[] = $support_post_id;
	update_post_meta( $support_post_id, 'primary_keyword', 'support keyword' );
	update_post_meta( $support_post_id, '_blog_qa_location', 'Austin' );

	$checker = new \BlogQA\BlogQA_Checker( $support_post_id );

	blogqa_note( 'running regular mode' );
	$regular_results = $checker->run( $pillar_post_id );
	blogqa_assert( 'regular' === (string) get_post_meta( $support_post_id, '_blog_qa_mode', true ), 'A valid selected pillar post should run regular mode.' );
	blogqa_assert( null !== blogqa_find_section_by_label( $regular_results, 'Pillar Post' ), 'Regular mode should include the Section 7 Pillar Post section.' );

	blogqa_note( 'running empty pillar mode' );
	$pillar_results = $checker->run( 0 );
	blogqa_assert( 'pillar' === (string) get_post_meta( $support_post_id, '_blog_qa_mode', true ), 'An empty pillar selection should run pillar mode.' );
	blogqa_assert( null === blogqa_find_section_by_label( $pillar_results, 'Pillar Post' ), 'Pillar mode should not reuse the regular Section 7 Pillar Post section.' );
	blogqa_assert( null !== blogqa_find_section_by_label( $pillar_results, 'Pillar Structure' ), 'Pillar mode should include the dedicated Pillar Structure section.' );

	blogqa_note( 'running self-selection fallback mode' );
	$self_results = $checker->run( $support_post_id );
	blogqa_assert( 'pillar' === (string) get_post_meta( $support_post_id, '_blog_qa_mode', true ), 'Selecting the current post as the pillar should fall back to pillar mode.' );
	blogqa_assert( null === blogqa_find_section_by_label( $self_results, 'Pillar Post' ), 'Self-selection should not produce the regular Pillar Post section.' );

	if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) && function_exists( 'wpseo_replace_vars' ) ) {
		blogqa_note( 'running yoast replacement assertions' );
		$yoast_post_id = wp_insert_post(
			array(
				'post_type' => 'post',
				'post_status' => 'draft',
				'post_title' => 'Yoast Variable Post',
				'post_content' => '<p>Yoast test content.</p>',
			)
		);
		blogqa_assert( $yoast_post_id > 0, 'Could not create the Yoast verification post.' );
		$created_post_ids[] = $yoast_post_id;

		$site_name = trim( sanitize_text_field( get_bloginfo( 'name' ) ) );

		update_post_meta( $yoast_post_id, '_yoast_wpseo_title', '%%title%% %%sitename%%' );
		update_post_meta( $yoast_post_id, '_yoast_wpseo_metadesc', 'About %%title%% on %%sitename%%.' );

		$yoast_post_data = new \BlogQA\BlogQA_PostData( $yoast_post_id );

		blogqa_assert(
			'Yoast Variable Post ' . $site_name === $yoast_post_data->get_meta_title(),
			'Yoast meta title templates should resolve to plain text.'
		);
		blogqa_assert(
			'About Yoast Variable Post on ' . $site_name . '.' === $yoast_post_data->get_meta_description(),
			'Yoast meta description templates should resolve to plain text.'
		);

		update_post_meta( $yoast_post_id, '_yoast_wpseo_title', 'Plain SEO Title' );
		update_post_meta( $yoast_post_id, '_yoast_wpseo_metadesc', 'Plain SEO description.' );

		blogqa_assert(
			'Plain SEO Title' === $yoast_post_data->get_meta_title(),
			'Plain Yoast meta titles should be returned unchanged.'
		);
		blogqa_assert(
			'Plain SEO description.' === $yoast_post_data->get_meta_description(),
			'Plain Yoast meta descriptions should be returned unchanged.'
		);

		update_post_meta( $yoast_post_id, '_yoast_wpseo_title', '' );
		update_post_meta( $yoast_post_id, '_yoast_wpseo_metadesc', '' );

		blogqa_assert(
			'' === $yoast_post_data->get_meta_title(),
			'Empty Yoast meta titles should stay empty.'
		);
		blogqa_assert(
			'' === $yoast_post_data->get_meta_description(),
			'Empty Yoast meta descriptions should stay empty.'
		);
	}

	$pillar_post_data = array(
		'title' => 'Karate Basics',
		'main_keyword' => 'karate basics',
		'secondary_keywords' => array( 'martial arts basics', 'beginner karate guide' ),
		'meta_title' => 'Karate Basics Guide',
		'meta_description' => 'Learn the basics of karate with clear explanations and beginner-friendly structure.',
		'location' => 'Austin',
		'content' => blogqa_build_pillar_html( 'https://example.com/karate-for-kids' ),
		'slug' => 'karate-basics',
		'featured_image_id' => 999,
		'featured_image_src' => 'https://cdn.example.com/featured.jpg',
		'featured_image_alt' => 'karate basics featured image',
		'content_images' => array(
			array( 'src' => 'https://cdn.example.com/image-1.jpg', 'alt' => 'karate basics image 1', 'attachment_id' => 0 ),
			array( 'src' => 'https://cdn.example.com/image-2.jpg', 'alt' => 'karate basics image 2', 'attachment_id' => 0 ),
			array( 'src' => 'https://cdn.example.com/image-3.jpg', 'alt' => 'karate basics image 3', 'attachment_id' => 0 ),
			array( 'src' => 'https://cdn.example.com/image-4.jpg', 'alt' => 'karate basics image 4', 'attachment_id' => 0 ),
			array( 'src' => 'https://cdn.example.com/image-5.jpg', 'alt' => 'karate basics image 5', 'attachment_id' => 0 ),
		),
	);

	blogqa_note( 'running pillar structure assertions' );
	$structure_section = ( new \BlogQA\Checks\PillarStructure() )->run( $pillar_post_data );
	blogqa_assert( 'pass' === ( blogqa_find_check( $structure_section, '2.1' )['status'] ?? '' ), 'Pillar structure should pass the 2800-5000 word-count check.' );
	blogqa_assert( 'pass' === ( blogqa_find_check( $structure_section, '2.3' )['status'] ?? '' ), 'Pillar structure should detect a TOC after the introduction.' );
	blogqa_assert( 'pass' === ( blogqa_find_check( $structure_section, '2.4' )['status'] ?? '' ), 'Pillar structure should verify TOC coverage across H2 headings.' );

	blogqa_note( 'running pillar image assertions' );
	$image_section = ( new \BlogQA\Checks\PillarImages() )->run( $pillar_post_data );
	blogqa_assert( 'pass' === ( blogqa_find_check( $image_section, '4.1' )['status'] ?? '' ), 'Pillar images should require a featured image.' );
	blogqa_assert( 'pass' === ( blogqa_find_check( $image_section, '4.2' )['status'] ?? '' ), 'Pillar images should enforce the correct in-article image band.' );

	blogqa_note( 'running keyword placement assertions' );
	$keyword_placement = new \BlogQA\Checks\KeywordPlacement();
	$keyword_pass_section = $keyword_placement->run(
		array(
			'title' => 'Karate Basics',
			'main_keyword' => 'karate basics',
			'secondary_keywords' => array( 'beginner karate guide', 'kids karate classes' ),
			'content' => '<h2>Beginner Karate Guide for Families</h2><h3>Choosing a Beginner Karate Guide Program</h3><p>Short paragraph.</p>',
			'meta_title' => '',
			'slug' => 'karate-basics',
		)
	);
	$keyword_pass_check = blogqa_find_check( $keyword_pass_section, '1.8' );
	blogqa_assert( 'pass' === ( $keyword_pass_check['status'] ?? '' ), 'Check 1.8 should pass when the same secondary keyword appears in at least two non-H1 headings.' );
	blogqa_assert( false !== strpos( (string) ( $keyword_pass_check['reason'] ?? '' ), 'beginner karate guide' ), 'Check 1.8 pass reason should name the matched secondary keyword.' );
	blogqa_assert( 2 === count( $keyword_pass_check['details'] ?? array() ), 'Check 1.8 pass details should list the matched headings.' );

	$keyword_single_match_section = $keyword_placement->run(
		array(
			'title' => 'Karate Basics',
			'main_keyword' => 'karate basics',
			'secondary_keywords' => array( 'beginner karate guide' ),
			'content' => '<h2>Beginner Karate Guide for Families</h2><h3>Choosing the Right Dojo</h3><p>Short paragraph.</p>',
			'meta_title' => '',
			'slug' => 'karate-basics',
		)
	);
	$keyword_single_match_check = blogqa_find_check( $keyword_single_match_section, '1.8' );
	blogqa_assert( 'fail' === ( $keyword_single_match_check['status'] ?? '' ), 'Check 1.8 should fail when the best keyword appears only once.' );
	blogqa_assert( false !== strpos( (string) ( $keyword_single_match_check['reason'] ?? '' ), 'beginner karate guide' ), 'Check 1.8 fail reason should name the best-matching secondary keyword.' );
	blogqa_assert( false !== strpos( (string) ( $keyword_single_match_check['details'][0] ?? '' ), 'Beginner Karate Guide for Families' ), 'Check 1.8 fail details should list the matched heading text.' );

	$keyword_split_match_section = $keyword_placement->run(
		array(
			'title' => 'Karate Basics',
			'main_keyword' => 'karate basics',
			'secondary_keywords' => array( 'beginner karate guide', 'kids karate classes' ),
			'content' => '<h2>Beginner Karate Guide for Families</h2><h3>Kids Karate Classes in Austin</h3><p>Short paragraph.</p>',
			'meta_title' => '',
			'slug' => 'karate-basics',
		)
	);
	$keyword_split_match_check = blogqa_find_check( $keyword_split_match_section, '1.8' );
	blogqa_assert( 'fail' === ( $keyword_split_match_check['status'] ?? '' ), 'Check 1.8 should fail when two different secondary keywords only appear once each.' );
	blogqa_assert( false !== strpos( (string) ( $keyword_split_match_check['reason'] ?? '' ), 'same secondary keyword must appear in 2 or more non-H1 headings' ), 'Check 1.8 fail reason should explain the repeated same-keyword requirement.' );

	blogqa_note( 'running paragraph detail assertions' );
	$content_quality_section = ( new \BlogQA\Checks\ContentQuality() )->run(
		array(
			'title' => 'Karate Basics',
			'content' => '<p>One. Two. Three. Four. Five.</p><p>Short. Two.</p><p>Alpha. Beta. Gamma. Delta. Epsilon. Zeta.</p>',
		)
	);
	$content_quality_check = blogqa_find_check( $content_quality_section, '2.4' );
	blogqa_assert( 'fail' === ( $content_quality_check['status'] ?? '' ), 'Regular-mode paragraph-length check should fail when paragraphs exceed four sentences.' );
	blogqa_assert( 2 === count( $content_quality_check['details'] ?? array() ), 'Regular-mode paragraph-length check should list every failing paragraph.' );
	blogqa_assert( false !== strpos( (string) ( $content_quality_check['details'][0] ?? '' ), 'Paragraph 1 (5 sentences): One. Two. Three. Four. Five.' ), 'Regular-mode paragraph details should include the paragraph number and sentence count.' );
	blogqa_assert( false !== strpos( (string) ( $content_quality_check['details'][1] ?? '' ), 'Paragraph 3 (6 sentences): Alpha. Beta. Gamma. Delta. Epsilon. Zeta.' ), 'Regular-mode paragraph details should preserve DOM paragraph order.' );

	$pillar_structure_details_section = ( new \BlogQA\Checks\PillarStructure() )->run(
		array(
			'title' => 'Karate Basics',
			'content' => '<h2 id="overview">Overview</h2><p>One. Two. Three. Four. Five.</p><p>Short. Two.</p>',
		)
	);
	$pillar_structure_check = blogqa_find_check( $pillar_structure_details_section, '2.7' );
	blogqa_assert( 'fail' === ( $pillar_structure_check['status'] ?? '' ), 'Pillar-mode paragraph-length check should fail when a paragraph exceeds four sentences.' );
	blogqa_assert( 1 === count( $pillar_structure_check['details'] ?? array() ), 'Pillar-mode paragraph-length check should list failing paragraphs.' );
	blogqa_assert( false !== strpos( (string) ( $pillar_structure_check['details'][0] ?? '' ), 'Paragraph 1 (5 sentences): One. Two. Three. Four. Five.' ), 'Pillar-mode paragraph details should include the paragraph number and excerpt.' );

	blogqa_note( 'running mocked internal-linking assertions' );
	$link_tester = new BlogQATestPillarInternalLinking();
	$pass_link_section = $link_tester->run_with_mocks(
		array(
			'main_keyword' => 'karate basics',
			'content' => '<p><a href="https://example.com/karate-for-kids">karate for kids</a></p>',
		),
		array(
			array(
				'href' => 'https://example.com/karate-for-kids',
				'text' => 'karate for kids',
			),
		),
		array(
			'https://example.com/karate-for-kids' => array(
				'is_pp_lp' => true,
				'inferred_keyword' => 'karate for kids',
				'status_code' => 200,
				'fetch_error' => '',
				'is_requestable' => true,
			),
		),
		array(
			'https://example.com/karate-for-kids' => array(
				'main_keyword' => 'karate for kids',
				'secondary_keywords' => array( 'kids martial arts', 'children karate' ),
			),
		)
	);
	blogqa_assert( 'pass' === ( blogqa_find_check( $pass_link_section, '7.4' )['status'] ?? '' ), 'Linked PP/LP keyword comparison should pass when target keywords differ from the pillar keyword.' );

	$fail_link_section = $link_tester->run_with_mocks(
		array(
			'main_keyword' => 'karate basics',
			'content' => '<p><a href="https://example.com/karate-basics">karate basics</a></p>',
		),
		array(
			array(
				'href' => 'https://example.com/karate-basics',
				'text' => 'karate basics',
			),
		),
		array(
			'https://example.com/karate-basics' => array(
				'is_pp_lp' => true,
				'inferred_keyword' => 'karate basics',
				'status_code' => 200,
				'fetch_error' => '',
				'is_requestable' => true,
			),
		),
		array(
			'https://example.com/karate-basics' => array(
				'main_keyword' => 'karate basics',
				'secondary_keywords' => array( 'beginner karate guide' ),
			),
		)
	);
	blogqa_assert( 'fail' === ( blogqa_find_check( $fail_link_section, '7.4' )['status'] ?? '' ), 'Linked PP/LP keyword comparison should fail when the target main keyword matches the pillar keyword.' );
	blogqa_note( 'all assertions completed' );

} catch ( Throwable $throwable ) {
	$output_message = $throwable->getMessage() . PHP_EOL;
	$exit_code = 1;
} finally {
	blogqa_note( 'cleaning up temporary posts' );

	if ( is_multisite() ) {
		delete_site_option( \BlogQA\BlogQA_OpenAISettings::OPTION_NAME );
	} else {
		delete_option( \BlogQA\BlogQA_OpenAISettings::OPTION_NAME );
	}

	foreach ( array_reverse( $created_post_ids ) as $post_id ) {
		if ( $post_id > 0 ) {
			wp_delete_post( $post_id, true );
		}
	}
}

if ( 0 === $exit_code ) {
	echo $output_message;
} else {
	fwrite( STDERR, $output_message );
}

exit( $exit_code );
