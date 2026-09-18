<?php
/**
 * OpenAI adapter.
 *
 * @package Curio
 */

namespace Curio\Providers;

use Curio\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the OpenAI Chat Completions API.
 *
 * The interesting part is `drop_and_retry()`. OpenAI has quietly changed which
 * parameters a model accepts more than once — `max_tokens` became
 * `max_completion_tokens`, some reasoning models reject any `temperature` other
 * than the default — and each change breaks every plugin that hard-codes the
 * old body. Rather than pin a guess to a model list that will be wrong within
 * months, this reads the parameter name out of OpenAI's own 400 response,
 * removes it and tries once more. It fixes the class of bug instead of an
 * instance of it.
 */
final class OpenAI extends Provider_Base {

	private const ENDPOINT   = 'https://api.openai.com/v1/chat/completions';
	private const MODELS_URL = 'https://api.openai.com/v1/models';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'OpenAI (ChatGPT)', 'curio-ai-chat' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_model(): string {
		return 'gpt-5.6-luna';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fallback_models(): array {
		return array(
			'gpt-5.6-luna'  => __( 'GPT-5.6 Luna (fastest and cheapest)', 'curio-ai-chat' ),
			'gpt-5.6-terra' => __( 'GPT-5.6 Terra (balanced)', 'curio-ai-chat' ),
			'gpt-5.6-sol'   => __( 'GPT-5.6 Sol (most capable)', 'curio-ai-chat' ),
			'gpt-4o-mini'   => __( 'GPT-4o mini (older, widely available)', 'curio-ai-chat' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function links(): array {
		return array(
			'console' => 'https://platform.openai.com/api-keys',
			'terms'   => 'https://openai.com/policies/business-terms/',
			'privacy' => 'https://openai.com/policies/privacy-policy/',
			'pricing' => 'https://openai.com/api/pricing/',
		);
	}

	/**
	 * Auth headers.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		return array( 'Authorization' => 'Bearer ' . $this->api_key() );
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
			// The list includes embeddings, audio, image and moderation models
			// that cannot answer a chat message. Offering them in the dropdown
			// only invites a support ticket.
			if ( preg_match( '/(embedding|whisper|tts|audio|image|dall|moderation|realtime|transcribe|search|rerank|codex)/i', $id ) ) {
				continue;
			}
			$models[ $id ] = $id;
		}

		if ( array() === $models ) {
			return new \WP_Error( 'curio_no_models', __( 'The provider returned no chat models for this key.', 'curio-ai-chat' ) );
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

		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( $this->trim_history( $history, Options::number( 'history_turns' ) ) as $turn ) {
			$messages[] = $turn;
		}
		$messages[] = array( 'role' => 'user', 'content' => $question );

		$body = array(
			'model'                 => $this->model(),
			'messages'              => $messages,
			'max_completion_tokens' => Options::number( 'max_tokens' ),
			'temperature'           => (float) Options::get( 'temperature', 0.2 ),
		);

		$result = $this->send( $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = trim( (string) ( $result['body']['choices'][0]['message']['content'] ?? '' ) );
		if ( '' === $text ) {
			return new \WP_Error( 'curio_empty', __( 'The provider returned an empty reply.', 'curio-ai-chat' ) );
		}

		return array(
			'text'       => $text,
			'tokens_in'  => (int) ( $result['body']['usage']['prompt_tokens'] ?? 0 ),
			'tokens_out' => (int) ( $result['body']['usage']['completion_tokens'] ?? 0 ),
		);
	}

	/**
	 * Send a request, retrying once without whichever parameter was refused.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function send( array $body ) {
		$attempts = 0;

		while ( $attempts < 3 ) {
			++$attempts;

			$result = $this->post( self::ENDPOINT, $body, $this->headers() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( 200 === $result['code'] ) {
				return $result;
			}

			$error   = (array) ( $result['body']['error'] ?? array() );
			$message = (string) ( $error['message'] ?? '' );

			if ( 400 === $result['code'] ) {
				$dropped = $this->drop_unsupported( $body, $error, $message );
				if ( null !== $dropped ) {
					$body = $dropped;
					continue;
				}
			}

			return $this->explain( $result['code'], $result['body'], $message );
		}

		return new \WP_Error( 'curio_api', __( 'The provider rejected the request repeatedly.', 'curio-ai-chat' ) );
	}

	/**
	 * Work out which parameter a 400 was complaining about and remove it.
	 *
	 * @param array<string,mixed> $body    Current request body.
	 * @param array<string,mixed> $error   Decoded error object.
	 * @param string              $message Error message.
	 * @return array<string,mixed>|null Modified body, or null when nothing can be dropped.
	 */
	private function drop_unsupported( array $body, array $error, string $message ): ?array {
		$named = (string) ( $error['param'] ?? '' );

		if ( '' !== $named && array_key_exists( $named, $body ) && 'model' !== $named && 'messages' !== $named ) {
			unset( $body[ $named ] );
			return $body;
		}

		// Some responses name the parameter only in prose.
		if ( isset( $body['max_completion_tokens'] ) && false !== stripos( $message, 'max_tokens' ) ) {
			$body['max_tokens'] = $body['max_completion_tokens'];
			unset( $body['max_completion_tokens'] );
			return $body;
		}
		if ( isset( $body['temperature'] ) && false !== stripos( $message, 'temperature' ) ) {
			unset( $body['temperature'] );
			return $body;
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test() {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'curio_no_key', __( 'Enter an API key first.', 'curio-ai-chat' ) );
		}

		$result = $this->send(
			array(
				'model'                 => $this->model(),
				'messages'              => array( array( 'role' => 'user', 'content' => 'Reply with the single word OK.' ) ),
				'max_completion_tokens' => 8,
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}
}
