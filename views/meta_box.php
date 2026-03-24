<?php
/**
 * SCwriter Blog QA meta box template.
 *
 * @var string $location
 * @var string $pillar_post_url
 * @var string $pb_secondary_keywords
 * @var string $formatted_last_run
 * @var string $score_text
 * @var array<int, array<string, mixed>> $results
 * @var bool $is_ai_key_configured
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<div id="blogqa-meta-box" class="blogqa-meta-box">
	<?php wp_nonce_field( 'blogqa_meta_box', 'blogqa_meta_box_nonce' ); ?>
	<div class="blogqa-toolbar">
		<div class="blogqa-field">
			<label class="blogqa-label" for="blogqa-location">
				<?php esc_html_e( 'Location', 'scwriter-blog-qa' ); ?>
				<span class="required">*</span>
			</label>
			<input
				type="text"
				id="blogqa-location"
				class="regular-text"
				value="<?php echo esc_attr( $location ); ?>"
				required
				aria-required="true"
				aria-describedby="blogqa-location-help"
				placeholder="<?php esc_attr_e( 'Enter a city or service area', 'scwriter-blog-qa' ); ?>"
			/>
			<p id="blogqa-location-help" class="description">
				<?php esc_html_e( 'Location must be set before running QA.', 'scwriter-blog-qa' ); ?>
			</p>
		</div>

		<div class="blogqa-field">
			<label class="blogqa-label" for="blogqa-pillar-post-url">
				<?php esc_html_e( 'Pillar Post URL', 'scwriter-blog-qa' ); ?>
			</label>
			<input
				type="url"
				id="blogqa-pillar-post-url"
				name="blogqa_pillar_post_url"
				class="regular-text"
				value="<?php echo esc_attr( $pillar_post_url ); ?>"
				placeholder="<?php esc_attr_e( 'https://example.com/pillar-post/', 'scwriter-blog-qa' ); ?>"
			/>
		</div>

		<div
			class="blogqa-field"
			id="blogqa-pb-secondary-keywords-field"
			style="<?php echo '' === $pillar_post_url ? 'display: none;' : ''; ?>"
		>
			<label class="blogqa-label" for="blogqa-pb-secondary-keywords">
				<?php esc_html_e( 'Pillar Post Secondary Keywords', 'scwriter-blog-qa' ); ?>
			</label>
			<textarea
				id="blogqa-pb-secondary-keywords"
				name="blogqa_pb_secondary_keywords"
				class="large-text"
				rows="4"
				placeholder="<?php esc_attr_e( 'keyword one, keyword two, keyword three', 'scwriter-blog-qa' ); ?>"
			><?php echo esc_textarea( $pb_secondary_keywords ); ?></textarea>
			<p class="description">
				<?php esc_html_e( 'Enter comma-separated keywords.', 'scwriter-blog-qa' ); ?>
			</p>
		</div>

		<div class="blogqa-actions">
			<button type="button" class="button button-primary" id="blogqa-run-button">
				<?php esc_html_e( 'Run QA', 'scwriter-blog-qa' ); ?>
			</button>
			<span id="blogqa-spinner" class="spinner blogqa-spinner" aria-hidden="true"></span>
		</div>
	</div>

	<div class="blogqa-summary">
		<div id="blogqa-score" class="blogqa-score" aria-live="polite">
			<?php echo esc_html( $score_text ); ?>
		</div>
		<div id="blogqa-last-run" class="blogqa-last-run" aria-live="polite">
			<?php echo esc_html( $formatted_last_run ); ?>
		</div>
	</div>

	<?php if ( ! $is_ai_key_configured ) : ?>
		<div class="notice notice-warning inline blogqa-warning">
			<p><?php esc_html_e( 'AI-backed checks will be skipped until an OpenAI API key is added to scwriter-blog-qa/env.php.', 'scwriter-blog-qa' ); ?></p>
		</div>
	<?php endif; ?>

	<div id="blogqa-error" class="notice notice-error inline blogqa-error" hidden></div>

	<div id="blogqa-results" class="blogqa-results" aria-live="polite">
		<p class="blogqa-placeholder">
			<?php
			echo esc_html(
				empty( $results )
					? __( 'Run QA to evaluate this post.', 'scwriter-blog-qa' )
					: __( 'Loading previous QA results...', 'scwriter-blog-qa' )
			);
			?>
		</p>
	</div>
</div>
