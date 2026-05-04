<?php

namespace BlogQA\Checks;

use BlogQA\BlogQA_OpenAISettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * AI-backed strategy and language checks.
 */
class AIStrategy extends BlogQA_CheckBase {

	protected const OPENAI_TIMEOUT = 60;

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
			'model' => BLOGQA_OPENAI_MODEL,
			'response_format' => array(
				'type' => 'json_object',
			),
			'messages' => array(
				array(
					'role' => 'system',
					'content' => 'You review SEO blog content and return only a JSON object. The object must contain title_not_commercial, keyword_is_informational, and no_grammar_errors. Each key must contain pass (boolean) and reason (string). Reasons must be concise. When pass is false, format reason as a short bullet-style list using separate lines that each start with "- ". Include at most 4 bullets. Each bullet must describe one concrete issue and, when possible, include a short quoted example from the content. When pass is true, use a single short sentence.',
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
					'timeout' => self::OPENAI_TIMEOUT,
					'headers' => array(
						'Authorization' => 'Bearer ' . $openai_api_key,
						'Content-Type' => 'application/json',
					),
					'body' => wp_json_encode( $request_body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $this->build_uniform_results( 'error', $this->get_wp_error_message( $response ) );
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
				$this->build_grammar_check( $payload['no_grammar_errors'], $post_data ),
			);
		} catch ( \Throwable $exception ) {
			$this->log_ai_request_exception( $exception );
			return $this->build_uniform_results( 'error', 'AI request failed. Check server logs and try again.' );
		}
	}

	/**
	 * Read the plugin OpenAI API key from the encrypted settings store.
	 */
	public function get_openai_api_key() : string {
		return ( new BlogQA_OpenAISettings() )->get_api_key();
	}

	/**
	 * Return the AI prompt for the current post.
	 *
	 * @param array<string, mixed> $post_data
	 */
	protected function build_prompt( array $post_data ) : string {
		$title = (string) ( $post_data['title'] ?? '' );
		$main_keyword = (string) ( $post_data['main_keyword'] ?? '' );
		$content = $this->get_plain_content( (string) ( $post_data['content'] ?? '' ) );

		return implode(
			"\n\n",
			array(
				'Check the following post and return only the required JSON object.',
				'The title should be judged for commercial wording like buy, hire, best, affordable, cheap, top, service, or company.',
				'The main keyword should be judged as informational or commercial intent.',
				'The full content text should be judged for spelling, grammar, punctuation, formatting artifacts, and obvious capitalization inconsistencies.',
				'Do not fail domain-specific acronyms, sport names, or accepted transliteration variants when the meaning is clear.',
				'Specifically treat BJJ, Brazilian jiu jitsu, Brazilian jiu-jitsu, jiu jitsu, and jiu-jitsu as acceptable variants rather than spelling or capitalization errors by themselves.',
				'For any failed check, return a short list of concrete issues using newline-separated bullets that begin with "- ".',
				'Each failed bullet should point to a real example from the content when possible, such as a quoted phrase, malformed heading, or punctuation issue.',
				'Title: ' . $title,
				'Main keyword: ' . $main_keyword,
				'Content: ' . $content,
			)
		);
	}

	/**
	 * Return full post content as plain text with HTML removed.
	 */
	protected function get_plain_content( string $content ) : string {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $content ) ) ?? '' );
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
	 * Build the grammar result, ignoring allowed martial-arts terminology variants.
	 *
	 * @param array<string, mixed> $grammar_payload
	 * @param array<string, mixed> $post_data
	 * @return array<string, string>
	 */
	protected function build_grammar_check( array $grammar_payload, array $post_data ) : array {
		$reason = $this->normalize_reason( (string) $grammar_payload['reason'] );
		$status = (bool) $grammar_payload['pass'] ? 'pass' : 'fail';
		$content = (string) ( $post_data['content'] ?? '' );

		if ( 'fail' === $status && $this->should_ignore_grammar_failure( $reason, $content ) ) {
			$status = 'pass';
			$reason = 'Accepted terminology variants such as BJJ and Brazilian jiu jitsu were ignored.';
		}

		return $this->build_check(
			'6.4',
			'Content has no spelling or grammar errors',
			$status,
			$reason
		);
	}

	/**
	 * Decide whether a grammar failure should be ignored as an allowed terminology variant.
	 */
	protected function should_ignore_grammar_failure( string $reason, string $content ) : bool {
		$normalized_reason = $this->normalize_for_search( $reason );
		$normalized_content = $this->normalize_for_search( $content );

		if ( '' === $normalized_reason || '' === $normalized_content ) {
			return false;
		}

		$allowed_terms = array(
			'bjj',
			'bj j',
			'brazilian jiu jitsu',
			'brazilian jiu-jitsu',
			'jiu jitsu',
			'jiu-jitsu',
		);

		$has_allowed_term = false;

		foreach ( $allowed_terms as $allowed_term ) {
			if ( false !== strpos( $normalized_content, $this->normalize_for_search( $allowed_term ) ) ) {
				$has_allowed_term = true;
				break;
			}
		}

		if ( ! $has_allowed_term ) {
			return false;
		}

		$capitalization_markers = array(
			'capitalization',
			'inconsistent capitalization',
			'capitalisation',
			'inconsistent capitalisation',
			'casing',
			'case consistency',
			'uppercase',
			'lowercase',
		);

		$has_capitalization_marker = false;

		foreach ( $capitalization_markers as $marker ) {
			if ( false !== strpos( $normalized_reason, $marker ) ) {
				$has_capitalization_marker = true;
				break;
			}
		}

		if ( ! $has_capitalization_marker ) {
			return false;
		}

		$hard_failure_markers = array(
			'spelling mistake',
			'spelling error',
			'misspelling',
			'grammar error',
			'punctuation',
			'fragment',
			'run-on',
			'typo',
		);

		foreach ( $hard_failure_markers as $marker ) {
			if ( false !== strpos( $normalized_reason, $marker ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build a readable HTTP error string from the API response.
	 *
	 * @param array<string, mixed>|\WP_HTTP_Requests_Response|mixed $response
	 */
	protected function get_http_error_message( $response, int $status_code ) : string {
		$default_message = sprintf( 'OpenAI API request failed with status %d', $status_code );

		if ( 401 === $status_code ) {
			return $default_message . '. Save a valid OpenAI API key in Blog QA settings.';
		}

		if ( 403 === $status_code ) {
			return $default_message . '. OpenAI denied access for the configured Blog QA key.';
		}

		if ( 429 === $status_code ) {
			return $default_message . '. OpenAI rate-limited the request. Try again shortly.';
		}

		if ( $status_code >= 500 ) {
			return $default_message . '. OpenAI returned a server error. Try again later.';
		}

		return $default_message;
	}

	/**
	 * Build a readable message from a WP_Error returned by the HTTP client.
	 */
	protected function get_wp_error_message( \WP_Error $error ) : string {
		$message = trim( $error->get_error_message() );

		if ( false !== stripos( $message, 'cURL error 28' ) || false !== stripos( $message, 'timed out' ) ) {
			return 'OpenAI API request timed out after 60 seconds. Try again. If it keeps happening, re-save the OpenAI key in settings and check server outbound HTTPS access.';
		}

		return '' !== $message ? $message : 'OpenAI API request failed.';
	}

	/**
	 * Log AI request exceptions without exposing them in the editor UI.
	 */
	protected function log_ai_request_exception( \Throwable $exception ) : void {
		error_log(
			sprintf(
				'[Blog QA] AI strategy request exception: %s: %s',
				$exception::class,
				$exception->getMessage()
			)
		);
	}
}
