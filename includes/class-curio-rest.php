<?php
/**
 * REST API routes.
 *
 * @package Curio
 */

namespace Curio;

use Curio\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Every HTTP entry point the plugin exposes.
 *
 * The public routes deliberately use `__return_true` as their permission
 * callback, and that is worth being explicit about rather than leaving a
 * reviewer to wonder. A chat widget for website visitors is a public feature in
 * the same way a comment form or a contact form is; there is no user to
 * authenticate. What stands in for authentication is a short-lived session
 * token issued on open, a per-visitor rate limit, a site-wide monthly ceiling
 * and a hard cap on question length — controls that work for anonymous callers,
 * which a capability check does not.
 *
 * Nonces are the wrong tool here for a second reason: full-page caching serves
 * the same HTML to thousands of visitors, so a nonce printed into that page is
 * stale for everyone but the first. Plugins that ignore this ship a chat widget
 * that silently 403s on every cached site.
 */
final class REST {

	public const NAMESPACE = 'curio/v1';

	private const SESSION_TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public static function register(): void {
		// --- Public ------------------------------------------------------.

		register_rest_route(
			self::NAMESPACE,
			'/session',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'open_session' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/message',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'message' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'message'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'token'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'history'  => array(
						'required' => false,
						'type'     => 'array',
					),
					'page_url' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		// --- Administrative ----------------------------------------------.

		$admin = array( __CLASS__, 'can_manage' );

		register_rest_route(
			self::NAMESPACE,
			'/knowledge',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_knowledge' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_knowledge' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_knowledge' ),
				'permission_callback' => $admin,
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'import_knowledge' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'export_knowledge' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/clear',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'clear_knowledge' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connection/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'test_connection' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connection/key',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_key' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_key' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/models',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'refresh_models' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/index/(?P<action>start|run|state)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'indexing' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/log/clear',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'clear_log' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/palette',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'palette' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * Capability gate for the administrative routes.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	// ---------------------------------------------------------------------
	// Public routes.
	// ---------------------------------------------------------------------

	/**
	 * Issue a short-lived session token.
	 *
	 * Called once when the visitor opens the chat, not on page load, so a page
	 * nobody chats on costs nothing. Issuing is itself throttled, because a
	 * token endpoint that hands out tokens without limit is just a slower way
	 * of having no limit at all.
	 *
	 * @return \WP_REST_Response
	 */
	public static function open_session(): \WP_REST_Response {
		if ( Rate_Limiter::visitor_throttled() ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'curio_throttled',
					'message' => __( 'Too many requests. Please wait a moment.', 'curio-ai-chat' ),
				),
				429
			);
		}

		$token = wp_generate_password( 32, false, false );
		set_transient( 'curio_session_' . $token, 1, self::SESSION_TTL );

		return new \WP_REST_Response(
			array(
				'token'   => $token,
				'expires' => self::SESSION_TTL,
			),
			200
		);
	}

	/**
	 * Answer a visitor's message.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function message( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Options::flag( 'enabled' ) ) {
			return self::error( 'curio_disabled', __( 'The chat assistant is switched off.', 'curio-ai-chat' ), 403 );
		}

		$token = (string) $request->get_param( 'token' );
		if ( '' === $token || false === get_transient( 'curio_session_' . $token ) ) {
			return self::error(
				'curio_session',
				__( 'Your chat session expired. Please reload the page and try again.', 'curio-ai-chat' ),
				403
			);
		}

		$history = self::clean_history( $request->get_param( 'history' ) );
		$result  = Chat::answer(
			(string) $request->get_param( 'message' ),
			$history,
			(string) $request->get_param( 'page_url' )
		);

		if ( is_wp_error( $result ) ) {
			$data   = (array) $result->get_error_data();
			$status = isset( $data['status'] ) ? (int) $data['status'] : 500;
			return self::error( (string) $result->get_error_code(), $result->get_error_message(), $status );
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Reduce whatever the client sent into a safe history array.
	 *
	 * The client is not trusted with this. History is echoed straight into a
	 * paid API call, so an unbounded array from a public endpoint is somebody
	 * else's bill.
	 *
	 * @param mixed $raw Raw history.
	 * @return array<int,array<string,string>>
	 */
	private static function clean_history( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$turns = max( 0, Options::number( 'history_turns' ) );
		$clean = array();

		foreach ( array_slice( $raw, -( $turns * 2 ) ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$role = ( isset( $entry['role'] ) && 'assistant' === $entry['role'] ) ? 'assistant' : 'user';
			$text = sanitize_textarea_field( (string) ( $entry['content'] ?? '' ) );
			if ( '' === trim( $text ) ) {
				continue;
			}
			$clean[] = array(
				'role'    => $role,
				'content' => Text::truncate( $text, 2000 ),
			);
		}

		return $clean;
	}

	// ---------------------------------------------------------------------
	// Administrative routes.
	// ---------------------------------------------------------------------

	/**
	 * List knowledge entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_knowledge( \WP_REST_Request $request ): \WP_REST_Response {
		$rows = Knowledge_Store::paged(
			array(
				'search'      => (string) $request->get_param( 'search' ),
				'source_type' => (string) $request->get_param( 'source_type' ),
				'per_page'    => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
				'page'        => (int) ( $request->get_param( 'page' ) ?: 1 ),
			)
		);

		return new \WP_REST_Response(
			array(
				'items'  => $rows,
				'counts' => Knowledge_Store::counts_by_type(),
				'total'  => Knowledge_Store::count(),
			),
			200
		);
	}

	/**
	 * Create or update one hand-written entry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function save_knowledge( \WP_REST_Request $request ): \WP_REST_Response {
		$content = trim( sanitize_textarea_field( (string) $request->get_param( 'content' ) ) );
		$title   = trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) );

		if ( '' === $content ) {
			return self::error( 'curio_missing', __( 'An answer is required.', 'curio-ai-chat' ), 400 );
		}
		if ( '' === $title ) {
			return self::error( 'curio_missing', __( 'A question or title is required.', 'curio-ai-chat' ), 400 );
		}

		$id  = absint( $request->get_param( 'id' ) );
		$ref = '';
		if ( $id > 0 ) {
			$existing = Knowledge_Store::find( $id );
			if ( ! $existing ) {
				return self::error( 'curio_not_found', __( 'That entry no longer exists.', 'curio-ai-chat' ), 404 );
			}
			if ( Knowledge_Store::SOURCE_MANUAL !== $existing['source_type'] ) {
				return self::error(
					'curio_readonly',
					__( 'That entry comes from indexed site content. Edit the page it came from instead.', 'curio-ai-chat' ),
					400
				);
			}
			$ref = (string) $existing['ref'];
		}

		$saved = Knowledge_Store::upsert(
			array(
				'ref'         => '' !== $ref ? $ref : Knowledge_Store::SOURCE_MANUAL . ':' . md5( $title . '|' . microtime( true ) . '|' . wp_rand() ),
				'source_type' => Knowledge_Store::SOURCE_MANUAL,
				'title'       => $title,
				'content'     => $content,
				'keywords'    => (string) $request->get_param( 'keywords' ),
				'url'         => (string) $request->get_param( 'url' ),
			)
		);

		if ( $saved < 1 ) {
			return self::error( 'curio_save_failed', __( 'That entry could not be saved.', 'curio-ai-chat' ), 500 );
		}

		return new \WP_REST_Response(
			array(
				'id'      => $saved,
				'item'    => Knowledge_Store::find( $saved ),
				'message' => __( 'Saved.', 'curio-ai-chat' ),
			),
			200
		);
	}

	/**
	 * Delete one entry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function delete_knowledge( \WP_REST_Request $request ): \WP_REST_Response {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! Knowledge_Store::delete( $id ) ) {
			return self::error( 'curio_not_found', __( 'That entry no longer exists.', 'curio-ai-chat' ), 404 );
		}
		return new \WP_REST_Response(
			array(
				'deleted' => $id,
				'total'   => Knowledge_Store::count(),
			),
			200
		);
	}

	/**
	 * Bulk import from a JSON array.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function import_knowledge( \WP_REST_Request $request ): \WP_REST_Response {
		$raw = (string) $request->get_param( 'json' );
		if ( '' === trim( $raw ) ) {
			return self::error( 'curio_missing', __( 'Paste some JSON first.', 'curio-ai-chat' ), 400 );
		}

		$data = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::error(
				'curio_bad_json',
				sprintf(
					/* translators: %s: the JSON parser's own error message. */
					__( 'That is not valid JSON: %s', 'curio-ai-chat' ),
					json_last_error_msg()
				),
				400
			);
		}
		if ( ! is_array( $data ) ) {
			return self::error( 'curio_bad_json', __( 'The JSON must be an array of entries.', 'curio-ai-chat' ), 400 );
		}

		$imported = 0;
		$skipped  = 0;
		foreach ( array_slice( $data, 0, 2000 ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				++$skipped;
				continue;
			}

			// `question`/`answer` as well as `title`/`content`, because those
			// are the words a person exporting an FAQ from anywhere else will
			// have used, and rejecting their file over vocabulary is a bad
			// first five minutes.
			$title   = trim( sanitize_text_field( (string) ( $entry['title'] ?? $entry['question'] ?? '' ) ) );
			$content = trim( sanitize_textarea_field( (string) ( $entry['content'] ?? $entry['answer'] ?? '' ) ) );

			if ( '' === $content ) {
				++$skipped;
				continue;
			}
			if ( '' === $title ) {
				$title = Text::truncate( $content, 60 );
			}

			$id = Knowledge_Store::upsert(
				array(
					'ref'         => Knowledge_Store::SOURCE_MANUAL . ':' . md5( $title . '|' . $content ),
					'source_type' => Knowledge_Store::SOURCE_MANUAL,
					'title'       => $title,
					'content'     => $content,
					'keywords'    => $entry['keywords'] ?? array(),
					'url'         => (string) ( $entry['url'] ?? '' ),
				)
			);

			if ( $id > 0 ) {
				++$imported;
			} else {
				++$skipped;
			}
		}

		return new \WP_REST_Response(
			array(
				'imported' => $imported,
				'skipped'  => $skipped,
				'total'    => Knowledge_Store::count(),
				'message'  => sprintf(
					/* translators: 1: number of entries imported, 2: number skipped. */
					_n( 'Imported %1$d entry. %2$d skipped.', 'Imported %1$d entries. %2$d skipped.', $imported, 'curio-ai-chat' ),
					$imported,
					$skipped
				),
			),
			200
		);
	}

	/**
	 * Export hand-written entries as JSON.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export_knowledge( \WP_REST_Request $request ): \WP_REST_Response {
		$all = 'all' === (string) $request->get_param( 'scope' );
		return new \WP_REST_Response(
			array( 'items' => Knowledge_Store::export( ! $all ) ),
			200
		);
	}

	/**
	 * Empty part or all of the knowledge base.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function clear_knowledge( \WP_REST_Request $request ): \WP_REST_Response {
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );

		if ( in_array( $scope, array( Knowledge_Store::SOURCE_MANUAL, Knowledge_Store::SOURCE_POST, Knowledge_Store::SOURCE_PRODUCT ), true ) ) {
			$removed = Knowledge_Store::clear_type( $scope );
		} else {
			Knowledge_Store::clear_all();
			$removed = -1;
		}

		return new \WP_REST_Response(
			array(
				'removed' => $removed,
				'total'   => Knowledge_Store::count(),
			),
			200
		);
	}

	/**
	 * Test the saved credential.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function test_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$provider = Registry::get( sanitize_key( (string) $request->get_param( 'provider' ) ) );
		if ( ! $provider ) {
			return self::error( 'curio_unknown_provider', __( 'Unknown provider.', 'curio-ai-chat' ), 400 );
		}

		$result = $provider->test();
		if ( is_wp_error( $result ) ) {
			return self::error( (string) $result->get_error_code(), $result->get_error_message(), 200 );
		}

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %s: provider name. */
					__( 'Connected. %s answered normally.', 'curio-ai-chat' ),
					$provider->label()
				),
			),
			200
		);
	}

	/**
	 * Save an API key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function save_key( \WP_REST_Request $request ): \WP_REST_Response {
		$slug = sanitize_key( (string) $request->get_param( 'provider' ) );
		if ( ! Registry::get( $slug ) || 'demo' === $slug ) {
			return self::error( 'curio_unknown_provider', __( 'Unknown provider.', 'curio-ai-chat' ), 400 );
		}

		// Not `sanitize_text_field`: it collapses whitespace and strips
		// characters that are legal inside a credential. A key is an opaque
		// token and mangling it silently produces "invalid API key" from the
		// provider and an afternoon lost to a bug that was ours.
		$key = trim( (string) $request->get_param( 'key' ) );
		$key = preg_replace( '/[^\x21-\x7E]/', '', $key );

		if ( '' === $key ) {
			return self::error( 'curio_missing', __( 'Paste a key first.', 'curio-ai-chat' ), 400 );
		}

		Secret::put( $slug, (string) $key );

		return new \WP_REST_Response(
			array(
				'saved'     => true,
				'masked'    => Secret::mask( $slug ),
				'encrypted' => Secret::is_encrypted(),
				'message'   => __( 'Key saved.', 'curio-ai-chat' ),
			),
			200
		);
	}

	/**
	 * Remove an API key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function delete_key( \WP_REST_Request $request ): \WP_REST_Response {
		$slug = sanitize_key( (string) $request->get_param( 'provider' ) );
		Secret::forget( $slug );
		return new \WP_REST_Response(
			array(
				'deleted' => true,
				'message' => __( 'Key removed.', 'curio-ai-chat' ),
			),
			200
		);
	}

	/**
	 * Ask a provider for its current model list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function refresh_models( \WP_REST_Request $request ): \WP_REST_Response {
		$provider = Registry::get( sanitize_key( (string) $request->get_param( 'provider' ) ) );
		if ( ! $provider ) {
			return self::error( 'curio_unknown_provider', __( 'Unknown provider.', 'curio-ai-chat' ), 400 );
		}

		$models = $provider->fetch_models();
		if ( is_wp_error( $models ) ) {
			return self::error( (string) $models->get_error_code(), $models->get_error_message(), 200 );
		}

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'models'  => $models,
				'message' => sprintf(
					/* translators: %d: number of models found. */
					_n( 'Found %d model.', 'Found %d models.', count( $models ), 'curio-ai-chat' ),
					count( $models )
				),
			),
			200
		);
	}

	/**
	 * Drive the batched re-index.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function indexing( \WP_REST_Request $request ): \WP_REST_Response {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );

		switch ( $action ) {
			case 'start':
				$state = Indexer::start();
				break;
			case 'run':
				$state = Indexer::run_batch( (int) ( $request->get_param( 'size' ) ?: 10 ) );
				break;
			default:
				$state = Indexer::state();
		}

		// The queue itself can be thousands of ids; the browser only needs the
		// progress numbers.
		unset( $state['queue'] );
		$state['knowledge_total'] = Knowledge_Store::count();
		$state['counts']          = Knowledge_Store::counts_by_type();

		return new \WP_REST_Response( $state, 200 );
	}

	/**
	 * Empty the conversation log.
	 *
	 * @return \WP_REST_Response
	 */
	public static function clear_log(): \WP_REST_Response {
		Conversation_Log::clear();
		return new \WP_REST_Response( array( 'cleared' => true ), 200 );
	}

	/**
	 * Colours from the active theme, for the appearance tab.
	 *
	 * @return \WP_REST_Response
	 */
	public static function palette(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'colors' => Appearance::theme_palette() ), 200 );
	}

	/**
	 * A consistently shaped error response.
	 *
	 * Returned as a 200-with-body for admin actions the UI wants to display
	 * inline, and with a real status for anything a client should treat as a
	 * failure.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @param int    $status  HTTP status.
	 * @return \WP_REST_Response
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
