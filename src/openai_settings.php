<?php

namespace BlogQA;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Stores and resolves the encrypted OpenAI API key.
 */
class BlogQA_OpenAISettings {

	public const OPTION_NAME = 'blog_qa_openai_api_key';

	protected const PAYLOAD_VERSION = 1;

	/**
	 * @var array{stored_value:string,key_id:string,api_key:string}|null
	 */
	protected static ?array $api_key_cache = null;

	/**
	 * Return the decrypted OpenAI API key or an empty string when unavailable.
	 */
	public function get_api_key() : string {
		$stored_value = $this->get_stored_value();
		$key_id = $this->get_encryption_key_id();

		if ( '' === $stored_value ) {
			self::$api_key_cache = array(
				'stored_value' => '',
				'key_id' => $key_id,
				'api_key' => '',
			);

			return '';
		}

		if (
			is_array( self::$api_key_cache )
			&& $stored_value === self::$api_key_cache['stored_value']
			&& $key_id === self::$api_key_cache['key_id']
		) {
			return self::$api_key_cache['api_key'];
		}

		$payload = $this->parse_payload( $stored_value );

		if ( null === $payload ) {
			if ( $this->looks_like_encrypted_payload( $stored_value ) ) {
				$this->cache_api_key( $stored_value, $key_id, '' );
				return '';
			}

			$api_key = $this->maybe_upgrade_plaintext_value( $stored_value );
			$this->cache_api_key( $this->get_stored_value(), $this->get_encryption_key_id(), $api_key );

			return $api_key;
		}

		$api_key = $this->decrypt_payload( $payload );

		if ( null === $api_key ) {
			$this->cache_api_key( $stored_value, $key_id, '' );
			return '';
		}

		$api_key = trim( $api_key );
		$this->cache_api_key( $stored_value, $key_id, $api_key );

		return $api_key;
	}

	/**
	 * Return whether a usable API key is currently configured.
	 */
	public function has_api_key() : bool {
		return '' !== $this->get_api_key();
	}

	/**
	 * Return whether any stored key payload exists.
	 */
	public function has_stored_api_key() : bool {
		return '' !== $this->get_stored_value();
	}

	/**
	 * Return whether secure encryption is available on the current server.
	 */
	public function is_encryption_available() : bool {
		return $this->can_use_openssl_gcm() || $this->can_use_sodium_secretbox();
	}

	/**
	 * Return the OpenAI settings page URL for the current install type.
	 */
	public function get_settings_page_url() : string {
		if ( is_multisite() ) {
			return network_admin_url( 'admin.php?page=' . BlogQA_OpenAISettingsPage::MENU_SLUG );
		}

		return admin_url( 'admin.php?page=' . BlogQA_OpenAISettingsPage::MENU_SLUG );
	}

	/**
	 * Return copy for the editor warning when no OpenAI key is available.
	 */
	public function get_missing_key_notice() : string {
		if ( is_multisite() ) {
			return __( 'AI-backed checks will be skipped until a network administrator saves an OpenAI API key in Network Admin > Blog QA.', 'scwriter-blog-qa' );
		}

		return __( 'AI-backed checks will be skipped until an administrator saves an OpenAI API key in Blog QA > OpenAI Settings.', 'scwriter-blog-qa' );
	}

	/**
	 * Save a new OpenAI API key in encrypted form.
	 *
	 * @return true|WP_Error
	 */
	public function save_api_key( string $api_key ) {
		$api_key = trim( $api_key );

		if ( '' === $api_key ) {
			return true;
		}

		$encrypted_value = $this->encrypt_api_key( $api_key );

		if ( is_wp_error( $encrypted_value ) ) {
			return $encrypted_value;
		}

		$this->update_stored_value( $encrypted_value );
		$this->cache_api_key( $encrypted_value, $this->get_encryption_key_id(), $api_key );

		return true;
	}

	/**
	 * Clear the request-level API key cache.
	 */
	public function clear_api_key_cache() : void {
		self::$api_key_cache = null;
	}

	/**
	 * Return the stored option value as a string.
	 */
	protected function get_stored_value() : string {
		$value = is_multisite()
			? get_site_option( self::OPTION_NAME, '' )
			: get_option( self::OPTION_NAME, '' );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Persist the encrypted option value for the current install type.
	 */
	protected function update_stored_value( string $value ) : void {
		if ( is_multisite() ) {
			update_site_option( self::OPTION_NAME, $value );
			return;
		}

		update_option( self::OPTION_NAME, $value, false );
	}

	/**
	 * Encrypt an API key into a versioned payload string.
	 *
	 * @return string|WP_Error
	 */
	protected function encrypt_api_key( string $api_key ) {
		if ( $this->can_use_openssl_gcm() ) {
			return $this->encrypt_with_openssl( $api_key );
		}

		if ( $this->can_use_sodium_secretbox() ) {
			return $this->encrypt_with_sodium( $api_key );
		}

		return new WP_Error(
			'blogqa_openai_crypto_unavailable',
			__( 'This server cannot store the OpenAI API key securely because neither OpenSSL AES-256-GCM nor Sodium is available.', 'scwriter-blog-qa' )
		);
	}

	/**
	 * Encrypt using AES-256-GCM through OpenSSL.
	 *
	 * @return string|WP_Error
	 */
	protected function encrypt_with_openssl( string $api_key ) {
		try {
			$iv = random_bytes( 12 );
		} catch ( \Throwable $exception ) {
			return new WP_Error(
				'blogqa_openai_random_bytes_failed',
				__( 'The server could not generate secure random bytes for OpenAI key storage.', 'scwriter-blog-qa' )
			);
		}

		$tag = '';
		$ciphertext = openssl_encrypt(
			$api_key,
			'aes-256-gcm',
			$this->get_encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16
		);

		if ( ! is_string( $ciphertext ) || '' === $tag ) {
			return new WP_Error(
				'blogqa_openai_encrypt_failed',
				__( 'The server could not encrypt the OpenAI API key.', 'scwriter-blog-qa' )
			);
		}

		return (string) wp_json_encode(
			array(
				'v' => self::PAYLOAD_VERSION,
				'alg' => 'aes-256-gcm',
				'iv' => base64_encode( $iv ),
				'tag' => base64_encode( $tag ),
				'data' => base64_encode( $ciphertext ),
			)
		);
	}

	/**
	 * Encrypt using libsodium secretbox.
	 *
	 * @return string|WP_Error
	 */
	protected function encrypt_with_sodium( string $api_key ) {
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		} catch ( \Throwable $exception ) {
			return new WP_Error(
				'blogqa_openai_random_bytes_failed',
				__( 'The server could not generate secure random bytes for OpenAI key storage.', 'scwriter-blog-qa' )
			);
		}

		$ciphertext = sodium_crypto_secretbox( $api_key, $nonce, $this->get_encryption_key() );

		return (string) wp_json_encode(
			array(
				'v' => self::PAYLOAD_VERSION,
				'alg' => 'sodium-secretbox',
				'nonce' => base64_encode( $nonce ),
				'data' => base64_encode( $ciphertext ),
			)
		);
	}

	/**
	 * Parse a stored payload into a normalized array.
	 *
	 * @return array<string, string>|null
	 */
	protected function parse_payload( string $stored_value ) : ?array {
		$payload = json_decode( $stored_value, true );

		if ( ! is_array( $payload ) ) {
			return null;
		}

		$version = isset( $payload['v'] ) ? (int) $payload['v'] : 0;
		$algorithm = isset( $payload['alg'] ) && is_string( $payload['alg'] ) ? $payload['alg'] : '';
		$data = isset( $payload['data'] ) && is_string( $payload['data'] ) ? $payload['data'] : '';

		if ( self::PAYLOAD_VERSION !== $version || '' === $algorithm || '' === $data ) {
			return null;
		}

		if ( 'aes-256-gcm' === $algorithm ) {
			$iv = isset( $payload['iv'] ) && is_string( $payload['iv'] ) ? $payload['iv'] : '';
			$tag = isset( $payload['tag'] ) && is_string( $payload['tag'] ) ? $payload['tag'] : '';

			if ( '' === $iv || '' === $tag ) {
				return null;
			}

			return array(
				'alg' => $algorithm,
				'iv' => $iv,
				'tag' => $tag,
				'data' => $data,
			);
		}

		if ( 'sodium-secretbox' === $algorithm ) {
			$nonce = isset( $payload['nonce'] ) && is_string( $payload['nonce'] ) ? $payload['nonce'] : '';

			if ( '' === $nonce ) {
				return null;
			}

			return array(
				'alg' => $algorithm,
				'nonce' => $nonce,
				'data' => $data,
			);
		}

		return null;
	}

	/**
	 * Decrypt a stored payload.
	 */
	protected function decrypt_payload( array $payload ) : ?string {
		if ( 'aes-256-gcm' === $payload['alg'] ) {
			return $this->decrypt_openssl_payload( $payload );
		}

		if ( 'sodium-secretbox' === $payload['alg'] ) {
			return $this->decrypt_sodium_payload( $payload );
		}

		return null;
	}

	/**
	 * Decrypt an OpenSSL AES-256-GCM payload.
	 */
	protected function decrypt_openssl_payload( array $payload ) : ?string {
		if ( ! $this->can_use_openssl_gcm() ) {
			return null;
		}

		$iv = base64_decode( $payload['iv'], true );
		$tag = base64_decode( $payload['tag'], true );
		$data = base64_decode( $payload['data'], true );

		if ( ! is_string( $iv ) || ! is_string( $tag ) || ! is_string( $data ) ) {
			return null;
		}

		$plaintext = openssl_decrypt(
			$data,
			'aes-256-gcm',
			$this->get_encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( ! is_string( $plaintext ) ) {
			return null;
		}

		return $plaintext;
	}

	/**
	 * Decrypt a sodium secretbox payload.
	 */
	protected function decrypt_sodium_payload( array $payload ) : ?string {
		if ( ! $this->can_use_sodium_secretbox() ) {
			return null;
		}

		$nonce = base64_decode( $payload['nonce'], true );
		$data = base64_decode( $payload['data'], true );

		if ( ! is_string( $nonce ) || ! is_string( $data ) ) {
			return null;
		}

		$plaintext = sodium_crypto_secretbox_open( $data, $nonce, $this->get_encryption_key() );

		if ( ! is_string( $plaintext ) ) {
			return null;
		}

		return $plaintext;
	}

	/**
	 * Upgrade a legacy plaintext value when encountered.
	 */
	protected function maybe_upgrade_plaintext_value( string $stored_value ) : string {
		$api_key = trim( $stored_value );

		if ( '' === $api_key ) {
			return '';
		}

		$encrypted_value = $this->encrypt_api_key( $api_key );

		if ( ! is_wp_error( $encrypted_value ) ) {
			$this->update_stored_value( $encrypted_value );
		}

		return $api_key;
	}

	/**
	 * Cache the resolved API key for the current request.
	 */
	protected function cache_api_key( string $stored_value, string $key_id, string $api_key ) : void {
		self::$api_key_cache = array(
			'stored_value' => $stored_value,
			'key_id' => $key_id,
			'api_key' => $api_key,
		);
	}

	/**
	 * Return whether the stored value looks like a serialized payload.
	 */
	protected function looks_like_encrypted_payload( string $stored_value ) : bool {
		$first_character = substr( ltrim( $stored_value ), 0, 1 );

		return '{' === $first_character || '[' === $first_character;
	}

	/**
	 * Return a 32-byte key derived from WordPress salts and keys.
	 */
	protected function get_encryption_key() : string {
		$key_material = implode(
			'|',
			array(
				wp_salt( 'auth' ),
				wp_salt( 'logged_in' ),
				defined( 'AUTH_KEY' ) && is_string( AUTH_KEY ) ? AUTH_KEY : '',
				defined( 'LOGGED_IN_SALT' ) && is_string( LOGGED_IN_SALT ) ? LOGGED_IN_SALT : '',
			)
		);

		/**
		 * Filter the key-derivation material used for the encrypted OpenAI option.
		 *
		 * This is primarily useful for automated verification of salt rotation behavior.
		 *
		 * @param string $key_material Key-derivation material before hashing.
		 */
		$key_material = (string) apply_filters( 'blogqa_openai_key_material', $key_material );

		return hash_hmac( 'sha256', 'blogqa-openai-api-key-v1', $key_material, true );
	}

	/**
	 * Return a cache key for the current derived encryption key.
	 */
	protected function get_encryption_key_id() : string {
		return bin2hex( $this->get_encryption_key() );
	}

	/**
	 * Return whether OpenSSL AES-256-GCM is available.
	 */
	protected function can_use_openssl_gcm() : bool {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'openssl_get_cipher_methods' ) ) {
			return false;
		}

		$methods = openssl_get_cipher_methods();

		if ( ! is_array( $methods ) ) {
			return false;
		}

		$methods = array_map( 'strtolower', $methods );

		return in_array( 'aes-256-gcm', $methods, true );
	}

	/**
	 * Return whether libsodium secretbox is available.
	 */
	protected function can_use_sodium_secretbox() : bool {
		return defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' )
			&& function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' );
	}
}
