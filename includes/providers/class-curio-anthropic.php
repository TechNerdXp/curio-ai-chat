<?php
/**
 * Anthropic (Claude) adapter.
 *
 * @package Curio
 */

namespace Curio\Providers;

use Curio\Cache;
use Curio\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Anthropic Messages API.
 *
 * Every request carries the owner's `temperature`, and the newer Claude models
 * (the Sonnet 5 line, Opus 4.7 onwards) refuse it with a 400: they take an
 * `effort` instead. 1.0.x sent it regardless, so choosing a current model from
 * the refreshed list made every reply fail. `send()` reads the refusal, swaps
 * the temperature for a low effort, which is the level a short grounded answer
 * needs, and remembers the model so the next message does not ask twice. Read
 * from the API's own answer rather than from a list of model names, for the
 * reason the OpenAI adapter gives: a list is wrong within months.
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
			'claude-haiku-4-5-20251001' => __( 'Claude Haiku 4.5 (fastest and cheapest)', 'curio-ai-chat' ),
			'claude-sonnet-5-5'         => __( 'Claude Sonnet 5.5 (balanced)', 'curio-ai-chat' ),
			'claude-opus-5-5'           => __( 'Claude Opus 5.5 (most capable)', 'curio-ai-chat' ),
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

		$result = $this->send(
			array(
				'model'      => $this->model(),
				'max_tokens' => Options::number( 'max_tokens' ),
				'system'     => $system,
				'messages'   => $messages,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
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

		// Through send(), with the same parameters a reply carries. A test that
		// sent less than a reply would pass for a model every reply fails on.
		$result = $this->send(
			array(
				'model'      => $this->model(),
				'max_tokens' => 8,
				'messages'   => array( array( 'role' => 'user', 'content' => 'Reply with the single word OK.' ) ),
			),
			20
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Send a request, trading the temperature for an effort if the model wants one.
	 *
	 * @param array<string,mixed> $body    Request body without sampling or effort.
	 * @param int                 $timeout Seconds to wait.
	 * @return array<string,mixed>|\WP_Error The decoded 200 response, or the explained failure.
	 */
	private function send( array $body, int $timeout = self::TIMEOUT ) {
		$model = (string) $body['model'];
		if ( Cache::get( 'effort_only', $model ) ) {
			$body['output_config'] = array( 'effort' => 'low' );
		} else {
			$body['temperature'] = (float) Options::get( 'temperature', 0.2 );
		}

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$result = $this->post( self::ENDPOINT, $body, $this->headers(), $timeout );
			if ( is_wp_error( $result ) || 200 === $result['code'] ) {
				return $result;
			}

			$message = (string) ( $result['body']['error']['message'] ?? '' );
			if ( 400 === $result['code'] && isset( $body['temperature'] ) && false !== stripos( $message, 'temperature' ) ) {
				unset( $body['temperature'] );
				$body['output_config'] = array( 'effort' => 'low' );
				Cache::set( 'effort_only', $model, true, WEEK_IN_SECONDS );
				continue;
			}
			if ( 400 === $result['code'] && isset( $body['output_config'] ) && preg_match( '/effort|output_config/i', $message ) ) {
				unset( $body['output_config'] );
				continue;
			}

			return $this->explain( $result['code'], $result['body'], $message );
		}

		return new \WP_Error( 'curio_api', __( 'The provider rejected the request repeatedly.', 'curio-ai-chat' ) );
	}
}
