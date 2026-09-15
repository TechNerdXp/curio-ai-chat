<?php
/**
 * Shared provider plumbing.
 *
 * @package Curio
 */

namespace Curio\Providers;

use Curio\Cache;
use Curio\Options;
use Curio\Secret;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP, error translation and model-list caching, once instead of three times.
 *
 * The error mapping here is the part worth reading. A site owner whose chat
 * widget has gone quiet gets told which of the four things it actually is —
 * wrong key, no credit, rate limited, model retired — because "API request
 * failed" costs them an afternoon and a support ticket.
 */
abstract class Provider_Base implements Provider_Interface {

	/**
	 * How long a fetched model list stays fresh.
	 */
	protected const MODEL_TTL = DAY_IN_SECONDS;

	/**
	 * Seconds to wait on a completion request.
	 */
	protected const TIMEOUT = 30;

	/**
	 * The stored API key for this provider.
	 *
	 * @return string
	 */
	public function api_key(): string {
		return Secret::get( $this->slug() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key();
	}

	/**
	 * The model this provider should use right now.
	 *
	 * @return string
	 */
	public function model(): string {
		$chosen = Options::text( 'model' );
		if ( '' !== $chosen && Options::text( 'provider' ) === $this->slug() ) {
			return $chosen;
		}
		return $this->default_model();
	}

	/**
	 * {@inheritDoc}
	 */
	public function models(): array {
		$cached = Cache::get( 'models', $this->slug() );
		if ( is_array( $cached ) && array() !== $cached ) {
			return $cached;
		}
		return $this->fallback_models();
	}

	/**
	 * Store a freshly fetched model list.
	 *
	 * @param array<string,string> $models Model map.
	 * @return void
	 */
	protected function cache_models( array $models ): void {
		if ( array() !== $models ) {
			Cache::set( 'models', $this->slug(), $models, self::MODEL_TTL );
		}
	}

	/**
	 * The model list to show when the API has not been asked yet.
	 *
	 * Every provider on the market renames and retires models faster than a
	 * WordPress plugin gets updated — the code this replaces shipped a
	 * hard-coded list that had been dead for over a year, so the dropdown
	 * offered three models that no longer existed and the plugin simply
	 * returned an error. That is why `fetch_models()` exists and why the
	 * settings screen has a button to call it. This list is only the starting
	 * point before anyone presses it.
	 *
	 * @return array<string,string>
	 */
	abstract protected function fallback_models(): array;

	/**
	 * POST JSON to the provider and decode the reply.
	 *
	 * @param string               $url     Endpoint.
	 * @param array<string,mixed>  $body    Request body.
	 * @param array<string,string> $headers Request headers.
	 * @param int                  $timeout Seconds.
	 * @return array<string,mixed>|\WP_Error Keys: code, body.
	 */
	protected function post( string $url, array $body, array $headers, int $timeout = self::TIMEOUT ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'        => wp_json_encode( $body ),
				'timeout'     => $timeout,
				'redirection' => 0,
				'user-agent'  => 'Curio/' . CURIO_VERSION . '; ' . home_url( '/' ),
			)
		);

		return $this->unpack( $response );
	}

	/**
	 * GET JSON from the provider.
	 *
	 * @param string               $url     Endpoint.
	 * @param array<string,string> $headers Request headers.
	 * @param int                  $timeout Seconds.
	 * @return array<string,mixed>|\WP_Error Keys: code, body.
	 */
	protected function get( string $url, array $headers, int $timeout = 15 ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers'     => $headers,
				'timeout'     => $timeout,
				'redirection' => 0,
				'user-agent'  => 'Curio/' . CURIO_VERSION . '; ' . home_url( '/' ),
			)
		);

		return $this->unpack( $response );
	}

	/**
	 * Normalise a WordPress HTTP response into code plus decoded body.
	 *
	 * @param array<string,mixed>|\WP_Error $response Raw response.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function unpack( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'curio_http',
				sprintf(
					/* translators: %s: the underlying network error reported by WordPress. */
					__( 'Could not reach the AI provider: %s', 'curio-ai-chat' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( ! is_array( $body ) ) {
			$body = array();
		}

		return array(
			'code' => $code,
			'body' => $body,
			'raw'  => $raw,
		);
	}

	/**
	 * Turn an HTTP status and error body into something a human can act on.
	 *
	 * @param int                 $code    HTTP status.
	 * @param array<string,mixed> $body    Decoded body.
	 * @param string              $message Provider's own message, if any.
	 * @return \WP_Error
	 */
	protected function explain( int $code, array $body, string $message = '' ): \WP_Error {
		$message = trim( $message );

		switch ( true ) {
			case 401 === $code || 403 === $code:
				return new \WP_Error(
					'curio_auth',
					__( 'The API key was rejected. Check that you pasted the whole key, that it belongs to this provider, and that it has not been revoked.', 'curio-ai-chat' )
				);

			case 402 === $code || ( 400 === $code && false !== stripos( $message, 'credit' ) ):
				return new \WP_Error(
					'curio_billing',
					__( 'The provider refused the request for billing reasons. The account most likely has no credit or has hit its spending limit.', 'curio-ai-chat' )
				);

			case 404 === $code:
				return new \WP_Error(
					'curio_model',
					__( 'That model does not exist on this account. Use "Refresh model list" to pull the models your key can actually reach.', 'curio-ai-chat' )
				);

			case 429 === $code:
				return new \WP_Error(
					'curio_rate',
					__( 'The provider is rate limiting this key. Wait a minute and try again, or lower the traffic reaching the widget.', 'curio-ai-chat' )
				);

			case $code >= 500:
				return new \WP_Error(
					'curio_upstream',
					__( 'The provider returned a server error. This is at their end; try again shortly.', 'curio-ai-chat' )
				);
		}

		if ( '' !== $message ) {
			return new \WP_Error(
				'curio_api',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message returned by the provider. */
					__( 'The provider returned an error (%1$d): %2$s', 'curio-ai-chat' ),
					$code,
					$message
				)
			);
		}

		return new \WP_Error(
			'curio_api',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The provider returned an unexpected response (HTTP %d).', 'curio-ai-chat' ),
				$code
			)
		);
	}

	/**
	 * Trim conversation history to whole, alternating turns.
	 *
	 * Every provider rejects a `messages` array that does not alternate, and a
	 * client that lost a response mid-flight can easily send one that does not.
	 *
	 * @param array<int,array<string,string>> $history Raw history.
	 * @param int                             $turns   How many exchanges to keep.
	 * @return array<int,array<string,string>>
	 */
	protected function trim_history( array $history, int $turns ): array {
		if ( $turns < 1 ) {
			return array();
		}

		$clean = array();
		foreach ( $history as $entry ) {
			$role = ( isset( $entry['role'] ) && 'assistant' === $entry['role'] ) ? 'assistant' : 'user';
			$text = trim( (string) ( $entry['content'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			// Drop a repeat of the same role rather than sending an invalid
			// sequence the provider will reject outright.
			if ( array() !== $clean && $clean[ count( $clean ) - 1 ]['role'] === $role ) {
				$clean[ count( $clean ) - 1 ]['content'] = \Curio\Text::truncate( $text, 2000 );
				continue;
			}
			$clean[] = array(
				'role'    => $role,
				'content' => \Curio\Text::truncate( $text, 2000 ),
			);
		}

		$clean = array_slice( $clean, -( $turns * 2 ) );

		// A history that opens with the assistant confuses every API; the
		// welcome message is the usual culprit.
		while ( array() !== $clean && 'assistant' === $clean[0]['role'] ) {
			array_shift( $clean );
		}

		return array_values( $clean );
	}
}
