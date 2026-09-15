<?php
/**
 * Anthropic (Claude) adapter.
 *
 * @package Curio
 */

namespace Curio\Providers;

use Curio\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Anthropic Messages API.
 */
final class Anthropic extends Provider_Base {

	private const ENDPOINT   = 'https://api.anthropic.com/v1/messages';
	private const MODELS_URL = 'https://api.anthropic.com/v1/models?limit=40';
	private const API_DATE   = '2023-06-01';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'anthropic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Anthropic (Claude)', 'curio-ai-chat' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_model(): string {
		return 'claude-haiku-4-5-20251001';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fallback_models(): array {
		return array(
			'claude-haiku-4-5-20251001' => __( 'Claude Haiku 4.5 — fastest and cheapest', 'curio-ai-chat' ),
			'claude-sonnet-5'           => __( 'Claude Sonnet 5 — balanced', 'curio-ai-chat' ),
			'claude-opus-5'             => __( 'Claude Opus 5 — most capable', 'curio-ai-chat' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function links(): array {
		return array(
			'console' => 'https://console.anthropic.com/settings/keys',
			'terms'   => 'https://www.anthropic.com/legal/commercial-terms',
			'privacy' => 'https://www.anthropic.com/legal/privacy',
			'pricing' => 'https://www.anthropic.com/pricing#api',
		);
	}

	/**
	 * Auth headers.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		return array(
			'x-api-key'         => $this->api_key(),
			'anthropic-version' => self::API_DATE,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch_models() {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'curio_no_key', __( 'Add an API key first.', 'curio-ai-chat' ) );
		}

		$result = $this->get( self::MODELS_URL, $this->headers() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 200 !== $result['code'] ) {
			return $this->explain( $result['code'], $result['body'], (string) ( $result['body']['error']['message'] ?? '' ) );
		}

		$models = array();
		foreach ( (array) ( $result['body']['data'] ?? array() ) as $entry ) {
			$id = (string) ( $entry['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$models[ $id ] = (string) ( $entry['display_name'] ?? $id );
		}

		if ( array() === $models ) {
			return new \WP_Error( 'curio_no_models', __( 'The provider returned no models for this key.', 'curio-ai-chat' ) );
		}

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

		$messages   = $this->trim_history( $history, Options::number( 'history_turns' ) );
		$messages[] = array(
			'role'    => 'user',
			'content' => $question,
		);

		$result = $this->post(
			self::ENDPOINT,
			array(
				'model'       => $this->model(),
				'max_tokens'  => Options::number( 'max_tokens' ),
				'temperature' => (float) Options::get( 'temperature', 0.2 ),
				'system'      => $system,
				'messages'    => $messages,
			),
			$this->headers()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 200 !== $result['code'] ) {
			return $this->explain( $result['code'], $result['body'], (string) ( $result['body']['error']['message'] ?? '' ) );
		}

		// The reply arrives as an array of content blocks. Concatenating every
		// text block rather than reading `content[0]` is what stops an empty
		// answer whenever the model emits anything before its prose.
		$text = '';
		foreach ( (array) ( $result['body']['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= (string) $block['text'];
			}
		}

		$text = trim( $text );
		if ( '' === $text ) {
			return new \WP_Error( 'curio_empty', __( 'The provider returned an empty reply.', 'curio-ai-chat' ) );
		}

		return array(
			'text'       => $text,
			'tokens_in'  => (int) ( $result['body']['usage']['input_tokens'] ?? 0 ),
			'tokens_out' => (int) ( $result['body']['usage']['output_tokens'] ?? 0 ),
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
			self::ENDPOINT,
			array(
				'model'      => $this->model(),
				'max_tokens' => 8,
				'messages'   => array( array( 'role' => 'user', 'content' => 'Reply with the single word OK.' ) ),
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
