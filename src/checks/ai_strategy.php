<?php

namespace BlogQA\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * AI-backed strategy and language checks.
 */
class AIStrategy extends BlogQA_CheckBase {

	/**
	 * Run AI checks 6.1, 6.3, and 6.4.
	 *
	 * @param array<string, mixed> $post_data
	 * @return array<int, array<string, string>>
	 */
	public function run( array $post_data ) : array {
		$openai_api_key = $this->get_openai_api_key();

		if ( '' === $openai_api_key ) {
			return $this->build_uniform_results( 'skipped', 'OpenAI API key not configured' );
		}

		$request_body = array(
			'model' => 'gpt-5-mini',
			'response_format' => array(
				'type' => 'json_object',
			),
			'messages' => array(
				array(
					'role' => 'system',
					'content' => 'You review SEO blog content and return only a JSON object. The object must contain title_not_commercial, keyword_is_informational, and no_grammar_errors. Each key must contain pass (boolean) and reason (string). Reasons must be concise. When pass is false, format reason as a short bullet-style list using separate lines that each start with "- ". Include at most 4 bullets. When pass is true, use a single short sentence.',
				),
				array(
					'role' => 'user',
					'content' => $this->build_prompt( $post_data ),
				),
			),
		);

		try {
			$response = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . $openai_api_key,
						'Content-Type' => 'application/json',
					),
					'body' => wp_json_encode( $request_body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $this->build_uniform_results( 'error', $response->get_error_message() );
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				return $this->build_uniform_results( 'error', $this->get_http_error_message( $response, $status_code ) );
			}

			$decoded_response = json_decode( wp_remote_retrieve_body( $response ), true );
			$content = $decoded_response['choices'][0]['message']['content'] ?? '';
			$payload = json_decode( is_string( $content ) ? $content : '', true );

			if ( ! $this->is_valid_payload( $payload ) ) {
				return $this->build_uniform_results( 'error', 'AI response could not be parsed' );
			}

			return array(
				$this->build_check(
					'6.1',
					'Title is non-commercial',
					(bool) $payload['title_not_commercial']['pass'] ? 'pass' : 'fail',
					$this->normalize_reason( (string) $payload['title_not_commercial']['reason'] )
				),
				$this->build_check(
					'6.3',
					'Main keyword is informational',
					(bool) $payload['keyword_is_informational']['pass'] ? 'pass' : 'fail',
					$this->normalize_reason( (string) $payload['keyword_is_informational']['reason'] )
				),
				$this->build_check(
					'6.4',
					'Content has no spelling or grammar errors',
					(bool) $payload['no_grammar_errors']['pass'] ? 'pass' : 'fail',
					$this->normalize_reason( (string) $payload['no_grammar_errors']['reason'] )
				),
			);
		} catch ( \Throwable $exception ) {
			return $this->build_uniform_results( 'error', $exception->getMessage() );
		}
	}

	/**
	 * Read the plugin OpenAI API key from env.php.
	 */
	public function get_openai_api_key() : string {
		$this->load_env_file();

		if ( ! defined( 'BLOGQA_OPENAI_API_KEY' ) || ! is_string( BLOGQA_OPENAI_API_KEY ) ) {
			return '';
		}

		return trim( BLOGQA_OPENAI_API_KEY );
	}

	/**
	 * Load plugin-local environment config from env.php.
	 */
	protected function load_env_file() : void {
		$config_path = BLOGQA_PLUGIN_DIR . 'env.php';

		if ( ! file_exists( $config_path ) ) {
			return;
		}

		require_once $config_path;
	}

	/**
	 * Return the AI prompt for the current post.
	 *
	 * @param array<string, mixed> $post_data
	 */
	protected function build_prompt( array $post_data ) : string {
		$title = (string) ( $post_data['title'] ?? '' );
		$main_keyword = (string) ( $post_data['main_keyword'] ?? '' );
		$content = (string) ( $post_data['content'] ?? '' );
		$excerpt = $this->get_excerpt( $content, 500 );

		return implode(
			"\n\n",
			array(
				'Check the following post and return only the required JSON object.',
				'The title should be judged for commercial wording like buy, hire, best, affordable, cheap, top, service, or company.',
				'The main keyword should be judged as informational or commercial intent.',
				'The excerpt should be judged for spelling, grammar, punctuation, formatting artifacts, and obvious capitalization inconsistencies.',
				'For any failed check, return a short list of concrete issues using newline-separated bullets that begin with "- ".',
				'Title: ' . $title,
				'Main keyword: ' . $main_keyword,
				'Excerpt: ' . $excerpt,
			)
		);
	}

	/**
	 * Return the first N words from the content body.
	 */
	protected function get_excerpt( string $content, int $word_limit ) : string {
		$words = preg_split( '/\s+/u', trim( wp_strip_all_tags( $content ) ) );

		if ( ! is_array( $words ) ) {
			return '';
		}

		$words = array_values(
			array_filter(
				array_map( 'trim', $words ),
				static fn( string $word ) : bool => '' !== $word
			)
		);

		return implode( ' ', array_slice( $words, 0, $word_limit ) );
	}

	/**
	 * Validate the AI payload shape.
	 *
	 * @param mixed $payload
	 */
	protected function is_valid_payload( $payload ) : bool {
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$required_keys = array(
			'title_not_commercial',
			'keyword_is_informational',
			'no_grammar_errors',
		);

		foreach ( $required_keys as $required_key ) {
			if ( ! isset( $payload[ $required_key ] ) || ! is_array( $payload[ $required_key ] ) ) {
				return false;
			}

			if ( ! array_key_exists( 'pass', $payload[ $required_key ] ) || ! array_key_exists( 'reason', $payload[ $required_key ] ) ) {
				return false;
			}

			if ( ! is_bool( $payload[ $required_key ]['pass'] ) || ! is_string( $payload[ $required_key ]['reason'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return three results with the same status and reason.
	 *
	 * @return array<int, array<string, string>>
	 */
	protected function build_uniform_results( string $status, string $reason ) : array {
		return array(
			$this->build_check( '6.1', 'Title is non-commercial', $status, $reason ),
			$this->build_check( '6.3', 'Main keyword is informational', $status, $reason ),
			$this->build_check( '6.4', 'Content has no spelling or grammar errors', $status, $reason ),
		);
	}

	/**
	 * Normalize AI reasons so list-style output renders consistently.
	 */
	protected function normalize_reason( string $reason ) : string {
		$reason = trim( preg_replace( "/\r\n?/", "\n", $reason ) ?? $reason );

		if ( '' === $reason ) {
			return '';
		}

		if ( false !== strpos( $reason, "\n" ) ) {
			return $reason;
		}

		$parts = preg_split( '/;\s+/', $reason );

		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return $reason;
		}

		$parts = array_values(
			array_filter(
				array_map( 'trim', $parts ),
				static fn( string $part ) : bool => '' !== $part
			)
		);

		if ( count( $parts ) < 2 ) {
			return $reason;
		}

		return implode(
			"\n",
			array_map(
				static fn( string $part ) : string => '- ' . ltrim( $part, "- \t" ),
				array_slice( $parts, 0, 4 )
			)
		);
	}

	/**
	 * Build a readable HTTP error string from the API response.
	 *
	 * @param array<string, mixed>|\WP_HTTP_Requests_Response|mixed $response
	 */
	protected function get_http_error_message( $response, int $status_code ) : string {
		$default_message = sprintf( 'OpenAI API request failed with status %d', $status_code );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
			return sprintf( '%s: %s', $default_message, $body['error']['message'] );
		}

		return $default_message;
	}
}
