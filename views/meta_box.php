<?php
/**
 * Spark Ignite Blog QA meta box template.
 *
 * @var string $location
 * @var int $pillar_post_id
 * @var string $pillar_post_label
 * @var string $formatted_last_run
 * @var string $score_text
 * @var array<int, array<string, mixed>> $results
 * @var bool $is_ai_key_configured
 * @var string $ai_key_notice
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
			<label class="blogqa-label" for="blogqa-pillar-post-label">
				<?php esc_html_e( 'Pillar Post', 'scwriter-blog-qa' ); ?>
			</label>
			<input
				type="hidden"
				id="blogqa-pillar-post-id"
				name="blogqa_pillar_post_id"
				value="<?php echo esc_attr( (string) $pillar_post_id ); ?>"
			/>
			<div class="blogqa-pillars">
				<input
					type="search"
					id="blogqa-pillar-post-label"
					class="regular-text"
					value="<?php echo esc_attr( $pillar_post_label ); ?>"
					autocomplete="off"
					placeholder="<?php esc_attr_e( 'Search for a post title', 'scwriter-blog-qa' ); ?>"
				/>
			</div>
			<div id="blogqa-pillar-post-results" class="blogqa-autocomplete" hidden></div>
			<p id="blogqa-pillar-mode" class="description" aria-live="polite"></p>
		</div>

		<div class="blogqa-actions">
			<span class="blogqa-label blogqa-label-placeholder" aria-hidden="true">&nbsp;</span>
			<div class="blogqa-action-row">
				<button type="button" class="button button-primary" id="blogqa-run-button">
					<?php esc_html_e( 'Run QA', 'scwriter-blog-qa' ); ?>
				</button>
				<span id="blogqa-spinner" class="spinner blogqa-spinner" aria-hidden="true"></span>
			</div>
		</div>
	</div>

	<div class="blogqa-summary">
		<div id="blogqa-score" class="blogqa-score" aria-live="polite">
			<?php echo esc_html( $score_text ); ?>
		</div>
		<div id="blogqa-last-run" class="blogqa-last-run" aria-live="polite">
			<?php echo esc_html( $formatted_last_run ); ?>
		</div>
		<?php if ( ! empty( $results ) ) : ?>
			<div id="blogqa-results-mode" class="blogqa-last-run" aria-live="polite"></div>
		<?php endif; ?>
	</div>

	<?php if ( ! $is_ai_key_configured ) : ?>
		<div class="notice notice-warning inline blogqa-warning">
			<p><?php echo esc_html( $ai_key_notice ); ?></p>
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
