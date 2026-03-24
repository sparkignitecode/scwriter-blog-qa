<?php

namespace BlogQA;

use BlogQA\Checks\AIStrategy;
use BlogQA\Checks\ContentQuality;
use BlogQA\Checks\Images;
use BlogQA\Checks\KeywordCluster;
use BlogQA\Checks\KeywordPlacement;
use BlogQA\Checks\Location;
use BlogQA\Checks\Metadata;
use BlogQA\Checks\BlogQA_PillarPostChecks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Orchestrates the full post QA run.
 */
class BlogQA_Checker {

	protected int $post_id;

	public function __construct( int $post_id ) {
		$this->post_id = $post_id;
	}

	/**
	 * Run all checks and persist the latest result set.
	 *
	 * @param array<string, string>|null $pb_data
	 * @return array<int, array<string, mixed>>
	 */
	public function run( ?array $pb_data = null, string $pb_secondary_keywords = '', string $pillar_post_url = '' ) : array {
		$post_data = ( new BlogQA_PostData( $this->post_id ) )->get_data();

		$results = array(
			( new KeywordPlacement() )->run( $post_data ),
			( new ContentQuality() )->run( $post_data ),
			( new Metadata() )->run( $post_data ),
			( new Images() )->run( $post_data ),
			( new Location() )->run( $post_data ),
			$this->build_strategy_section( $post_data ),
			( new BlogQA_PillarPostChecks() )->run( $post_data, $pb_data, $pb_secondary_keywords, $pillar_post_url ),
		);

		$this->persist_results( $results );

		return $results;
	}

	/**
	 * Build the final strategy section.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<string, mixed>
	 */
	protected function build_strategy_section( array $post_data ) : array {
		$checks = array_merge(
			array(
				( new KeywordCluster() )->run( $post_data ),
			),
			( new AIStrategy() )->run( $post_data )
		);

		return array(
			'section' => '6',
			'label' => 'Strategy and Intent',
			'checks' => $checks,
		);
	}

	/**
	 * Persist the latest result payload and timestamp.
	 *
	 * @param array<int, array<string, mixed>> $results
	 */
	protected function persist_results( array $results ) : void {
		update_post_meta( $this->post_id, '_blog_qa_results', $results );
		update_post_meta( $this->post_id, '_blog_qa_last_run', time() );
	}
}
