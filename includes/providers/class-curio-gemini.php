<?php
/**
 * Google Gemini adapter.
 *
 * @package Curio
 */

namespace Curio\Providers;

use Curio\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Gemini API on generativelanguage.googleapis.com.
 *
 * The key travels in the `x-goog-api-key` header rather than the `?key=` query
 * parameter Google's quick-start examples use. Same result, but a URL ends up
 * in access logs, proxy logs, error reports and `Referer` headers, and an API
 * key in a query string is a credential leak waiting for somebody to paste a
 * stack trace into a support forum.
 */
final class Gemini extends Provider_Base {

	private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Google Gemini', 'curio-ai-chat' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_model(): string {
		return 'gemini-3.5-flash-lite';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fallback_models(): array {
		return array(
			'gemini-3.5-flash-lite' => __( 'Gemini 3.5 Flash-Lite (fastest and cheapest)', 'curio-ai-chat' ),
			'gemini-3.7-flash'      => __( 'Gemini 3.7 Flash (balanced)', 'curio-ai-chat' ),
			'gemini-3.1-pro'        => __( 'Gemini 3.1 Pro (most capable)', 'curio-ai-chat' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function links(): array {
		return array(
			'console' => 'https://aistudio.google.com/app/apikey',
			'terms'   => 'https://ai.google.dev/gemini-api/terms',
			'privacy' => 'https://policies.google.com/privacy',
			'pricing' => 'https://ai.google.dev/gemini-api/docs/pricing',
		);
	}

	/**
	 * Auth headers.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		return array( 'x-goog-api-key' => $this->api_key() );
	}

	/**
	 * Strip the `models/` prefix Google's list endpoint returns.
	 *
	 * @param string $id Raw model id.
	 * @return string
	 */
	private function bare_model( string $id ): string {
		return preg_replace( '#^models/#', '', $id ) ?? $id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch_models() {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'curio_no_key', __( 'Add an API key first.', 'curio-ai-chat' ) );
		}

		$result = $this->get( self::BASE . '/models?pageSize=200', $this->headers() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 200 !== $result['code'] ) {
			return $this->explain( $result['code'], $result['body'], (string) ( $result['body']['error']['message'] ?? '' ) );
		}

		$models = array();
		foreach ( (array) ( $result['body']['models'] ?? array() ) as $entry ) {
			$id = $this->bare_model( (string) ( $entry['name'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			// Only models that can actually answer a chat message.
			$methods = (array) ( $entry['supportedGenerationMethods'] ?? array() );
			if ( array() !== $methods && ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}
			if ( preg_match( '/(embedding|aqa|imagen|veo|tts|vision-latest)/i', $id ) ) {
				continue;
			}
			$models[ $id ] = (string) ( $entry['displayName'] ?? $id );
		}

		if ( array() === $models ) {
			return new \WP_Error( 'curio_no_models', __( 'The provider returned no usable models for this key.', 'curio-ai-chat' ) );
		}

		ksort( $models );
		$this->cache_models( $models );
		return $models;
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( string $question, array $history, string $system ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'curio_no_key', __( 'No API key is saved for this provider.', 'curio-ai-chat' ) );
		}

		$contents = array();
		foreach ( $this->trim_history( $history, Options::number( 'history_turns' ) ) as $turn ) {
			$contents[] = array(
				// Gemini calls the assistant "model"; everything else calls it
				// "assistant". Translating here keeps the rest of the plugin
				// speaking one vocabulary.
				'role'  => 'assistant' === $turn['role'] ? 'model' : 'user',
				'parts' => array( array( 'text' => $turn['content'] ) ),
			);
		}
		$contents[] = array(
			'role'  => 'user',
			'parts' => array( array( 'text' => $question ) ),
		);

		$url = self::BASE . '/models/' . rawurlencode( $this->model() ) . ':generateContent';

		$result = $this->post(
			$url,
			array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
				'contents'          => $contents,
				'generationConfig'  => array(
					'maxOutputTokens' => Options::number( 'max_tokens' ),
					'temperature'     => (float) Options::get( 'temperature', 0.2 ),
				),
			),
			$this->headers()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 200 !== $result['code'] ) {
			return $this->explain( $result['code'], $result['body'], (string) ( $result['body']['error']['message'] ?? '' ) );
		}

		$candidate = (array) ( $result['body']['candidates'][0] ?? array() );

		// A safety block returns 200 with no parts at all, which is not a
		// network failure and should not be reported as one.
		$reason = (string) ( $candidate['finishReason'] ?? '' );
		if ( 'SAFETY' === $reason || 'PROHIBITED_CONTENT' === $reason || 'BLOCKLIST' === $reason ) {
			return new \WP_Error( 'curio_blocked', __( 'The provider declined to answer that message under its own content policy.', 'curio-ai-chat' ) );
		}

		$text = '';
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			if ( isset( $part['text'] ) ) {
				$text .= (string) $part['text'];
			}
		}

		$text = trim( $text );
		if ( '' === $text ) {
			if ( 'MAX_TOKENS' === $reason ) {
				return new \WP_Error( 'curio_empty', __( 'The reply hit the maximum length before any text was produced. Raise "Maximum reply length" on the Connection tab.', 'curio-ai-chat' ) );
			}
			return new \WP_Error( 'curio_empty', __( 'The provider returned an empty reply.', 'curio-ai-chat' ) );
		}

		return array(
			'text'       => $text,
			'tokens_in'  => (int) ( $result['body']['usageMetadata']['promptTokenCount'] ?? 0 ),
			'tokens_out' => (int) ( $result['body']['usageMetadata']['candidatesTokenCount'] ?? 0 ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function test() {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'curio_no_key', __( 'Enter an API key first.', 'curio-ai-chat' ) );
		}

		$result = $this->post(
			self::BASE . '/models/' . rawurlencode( $this->model() ) . ':generateContent',
			array(
				'contents'         => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => 'Reply with the single word OK.' ) ),
					),
				),
				'generationConfig' => array( 'maxOutputTokens' => 8 ),
			),
			$this->headers(),
			20
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 200 !== $result['code'] ) {
			return $this->explain( $result['code'], $result['body'], (string) ( $result['body']['error']['message'] ?? '' ) );
		}
		return true;
	}
}
