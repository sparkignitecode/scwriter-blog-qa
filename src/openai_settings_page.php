<?php

namespace BlogQA;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers and renders the OpenAI settings pages.
 */
class BlogQA_OpenAISettingsPage {

	public const MENU_SLUG = 'blogqa-openai-settings';

	protected const SAVE_ACTION = 'blogqa_save_openai_settings';
	protected const NOTICE_QUERY_ARG = 'blogqa_openai_status';

	protected BlogQA_OpenAISettings $settings;

	/**
	 * @param BlogQA_OpenAISettings|null $settings Settings resolver instance.
	 */
	public function __construct( ?BlogQA_OpenAISettings $settings = null ) {
		$this->settings = $settings ?? new BlogQA_OpenAISettings();
	}

	/**
	 * Register WordPress hooks for the settings UI.
	 */
	public function register_hooks() : void {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'network_admin_menu', array( $this, 'register_network_admin_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Register the single-site Blog QA menu and settings page.
	 */
	public function register_admin_menu() : void {
		if ( is_multisite() ) {
			return;
		}

		add_menu_page(
			__( 'Blog QA OpenAI Settings', 'sparkignite-blog-qa' ),
			__( 'Blog QA', 'sparkignite-blog-qa' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_site_page' ),
			'dashicons-pressthis'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'OpenAI Settings', 'sparkignite-blog-qa' ),
			__( 'OpenAI Settings', 'sparkignite-blog-qa' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_site_page' )
		);
	}

	/**
	 * Register the network-wide Blog QA settings page.
	 */
	public function register_network_admin_menu() : void {
		if ( ! is_multisite() ) {
			return;
		}

		add_menu_page(
			__( 'Blog QA Network OpenAI Settings', 'sparkignite-blog-qa' ),
			__( 'Blog QA', 'sparkignite-blog-qa' ),
			'manage_network_options',
			self::MENU_SLUG,
			array( $this, 'render_network_page' ),
			'dashicons-pressthis'
		);
	}

	/**
	 * Render the single-site settings page.
	 */
	public function render_site_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_page( false );
	}

	/**
	 * Render the network settings page.
	 */
	public function render_network_page() : void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$this->render_page( true );
	}

	/**
	 * Persist the OpenAI API key from the settings form.
	 */
	public function handle_save() : void {
		$scope = isset( $_POST['blogqa_scope'] ) ? sanitize_key( wp_unslash( $_POST['blogqa_scope'] ) ) : 'site';
		$is_network = 'network' === $scope;
		$required_capability = $is_network ? 'manage_network_options' : 'manage_options';

		if ( $is_network && ! is_multisite() ) {
			wp_die( esc_html__( 'Network settings are not available on this site.', 'sparkignite-blog-qa' ) );
		}

		if ( is_multisite() && ! $is_network ) {
			wp_die( esc_html__( 'OpenAI settings must be managed from network admin on multisite installs.', 'sparkignite-blog-qa' ) );
		}

		if ( ! current_user_can( $required_capability ) ) {
			wp_die( esc_html__( 'You do not have permission to update Blog QA settings.', 'sparkignite-blog-qa' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		$redirect_url = $this->get_page_url( $is_network );
		$submitted_api_key = isset( $_POST['blogqa_openai_api_key'] )
			? trim( (string) wp_unslash( $_POST['blogqa_openai_api_key'] ) )
			: '';

		if ( '' === $submitted_api_key ) {
			if ( ! $this->settings->has_stored_api_key() ) {
				$this->redirect_with_status( $redirect_url, 'missing_key' );
			}

			$this->redirect_with_status( $redirect_url, 'unchanged' );
		}

		$result = $this->settings->save_api_key( $submitted_api_key );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_status( $redirect_url, $result->get_error_code() );
		}

		$this->redirect_with_status( $redirect_url, 'updated' );
	}

	/**
	 * Render a settings form for the current admin context.
	 */
	protected function render_page( bool $is_network ) : void {
		$has_stored_key = $this->settings->has_stored_api_key();
		$has_valid_key = $this->settings->has_api_key();
		$page_title = $is_network
			? __( 'Blog QA Network Settings', 'sparkignite-blog-qa' )
			: __( 'Blog QA OpenAI Settings', 'sparkignite-blog-qa' );
		$description = $is_network
			? __( 'This encrypted OpenAI API key is stored once for the entire network and is used by Blog QA on every subsite.', 'sparkignite-blog-qa' )
			: __( 'This encrypted OpenAI API key is stored for this site and is used by Blog QA AI checks.', 'sparkignite-blog-qa' );
		$form_action = admin_url( 'admin-post.php' );
		$submit_label = $has_stored_key
			? __( 'Update OpenAI API Key', 'sparkignite-blog-qa' )
			: __( 'Save OpenAI API Key', 'sparkignite-blog-qa' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $page_title ); ?></h1>
			<p><?php echo esc_html( $description ); ?></p>
			<?php $this->render_status_notice(); ?>
			<?php if ( ! $this->settings->is_encryption_available() ) : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'This server does not currently support secure OpenAI key storage. Install OpenSSL with AES-256-GCM support or Sodium before saving a key.', 'sparkignite-blog-qa' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( $has_stored_key && ! $has_valid_key ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'A stored OpenAI API key could not be decrypted. This usually means WordPress salts changed or the saved payload is invalid. Save the key again to restore AI checks.', 'sparkignite-blog-qa' ); ?></p>
				</div>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Current status', 'sparkignite-blog-qa' ); ?></th>
						<td>
							<?php
							if ( $has_valid_key ) {
								esc_html_e( 'An encrypted OpenAI API key is stored.', 'sparkignite-blog-qa' );
							} elseif ( $has_stored_key ) {
								esc_html_e( 'A stored key exists but must be saved again before Blog QA can use it.', 'sparkignite-blog-qa' );
							} else {
								esc_html_e( 'No OpenAI API key has been saved yet.', 'sparkignite-blog-qa' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( $form_action ); ?>">
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<input type="hidden" name="blogqa_scope" value="<?php echo esc_attr( $is_network ? 'network' : 'site' ); ?>" />
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="blogqa-openai-api-key"><?php esc_html_e( 'OpenAI API key', 'sparkignite-blog-qa' ); ?></label>
							</th>
							<td>
								<input
									type="password"
									id="blogqa-openai-api-key"
									name="blogqa_openai_api_key"
									value=""
									class="regular-text"
									autocomplete="new-password"
									spellcheck="false"
								/>
								<p class="description"><?php esc_html_e( 'Leave this field blank to keep the existing key unchanged.', 'sparkignite-blog-qa' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( $submit_label ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a status notice after a save attempt.
	 */
	protected function render_status_notice() : void {
		$status = isset( $_GET[ self::NOTICE_QUERY_ARG ] )
			? sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY_ARG ] ) )
			: '';

		if ( '' === $status ) {
			return;
		}

		$notice_class = 'notice-success';
		$message = '';

		if ( 'updated' === $status ) {
			$message = __( 'The OpenAI API key was saved successfully.', 'sparkignite-blog-qa' );
		} elseif ( 'unchanged' === $status ) {
			$message = __( 'The OpenAI API key was left unchanged.', 'sparkignite-blog-qa' );
		} elseif ( 'missing_key' === $status ) {
			$notice_class = 'notice-error';
			$message = __( 'Enter an OpenAI API key before saving. Leave the field blank only when you want to keep an existing key unchanged.', 'sparkignite-blog-qa' );
		} elseif ( 'blogqa_openai_crypto_unavailable' === $status ) {
			$notice_class = 'notice-error';
			$message = __( 'The server could not store the OpenAI API key securely because supported cryptography is unavailable.', 'sparkignite-blog-qa' );
		} elseif ( 'blogqa_openai_random_bytes_failed' === $status ) {
			$notice_class = 'notice-error';
			$message = __( 'The server could not generate secure random data for OpenAI key storage. Try again or contact your host.', 'sparkignite-blog-qa' );
		} elseif ( 'blogqa_openai_encrypt_failed' === $status ) {
			$notice_class = 'notice-error';
			$message = __( 'The server could not encrypt the OpenAI API key. Check that OpenSSL or Sodium is configured correctly.', 'sparkignite-blog-qa' );
		} else {
			$notice_class = 'notice-error';
			$message = __( 'The OpenAI API key could not be saved. Try again or contact an administrator.', 'sparkignite-blog-qa' );
		}
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> inline">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Return the settings page URL for the requested scope.
	 */
	protected function get_page_url( bool $is_network ) : string {
		if ( $is_network ) {
			return network_admin_url( 'admin.php?page=' . self::MENU_SLUG );
		}

		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Redirect back to the settings page with a status flag.
	 */
	protected function redirect_with_status( string $redirect_url, string $status ) : void {
		wp_safe_redirect(
			add_query_arg(
				self::NOTICE_QUERY_ARG,
				$status,
				$redirect_url
			)
		);
		exit;
	}
}
