<?php
/**
 * SCwriter Blog QA meta box template.
 *
 * @var string $location
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
			<p><?php esc_html_e( 'AI strategy checks will be skipped until an OpenAI API key is added to scwriter-blog-qa/env.php.', 'scwriter-blog-qa' ); ?></p>
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
