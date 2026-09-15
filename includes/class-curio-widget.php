<?php
/**
 * Front-end widget.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the widget appears, and puts it on the page when it should.
 *
 * Assets are enqueued only on pages the widget will actually render on. A chat
 * plugin that loads its stylesheet and script on every request — including
 * feeds, sitemaps and the checkout — is a performance complaint waiting to
 * happen, and the previous build did exactly that, plus a render-blocking
 * Tailwind CDN script on top.
 */
final class Widget {

	/**
	 * Should the widget render on this request?
	 *
	 * @return bool
	 */
	public static function should_render(): bool {
		if ( ! Options::flag( 'enabled' ) ) {
			return false;
		}
		if ( is_admin() || is_feed() || is_embed() || is_robots() || is_preview() ) {
			return false;
		}
		if ( wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( Options::flag( 'hide_for_logged_in' ) && is_user_logged_in() ) {
			return false;
		}

		$show = true;
		$mode = Options::text( 'display_mode' );

		if ( 'all' !== $mode ) {
			$ids     = array_map( 'absint', (array) Options::get( 'display_ids', array() ) );
			$current = (int) get_queried_object_id();
			$listed  = $current > 0 && in_array( $current, $ids, true );
			$show    = 'include' === $mode ? $listed : ! $listed;
		}

		/**
		 * Filter whether the chat widget renders on this request.
		 *
		 * The escape hatch for rules the settings screen does not model —
		 * hiding it on the checkout, showing it only to a logged-in role,
		 * restricting it to one language.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $show Whether to render.
		 */
		return (bool) apply_filters( 'curio_should_display', $show );
	}

	/**
	 * Register and enqueue front-end assets.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! self::should_render() ) {
			return;
		}

		wp_enqueue_style(
			'curio-widget',
			CURIO_URL . 'assets/css/curio-widget.css',
			array(),
			CURIO_VERSION
		);

		wp_add_inline_style( 'curio-widget', Appearance::inline_css() );

		wp_enqueue_script(
			'curio-widget',
			CURIO_URL . 'assets/js/curio-widget.js',
			array(),
			CURIO_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_localize_script( 'curio-widget', 'curioConfig', self::config() );
	}

	/**
	 * The data handed to the browser.
	 *
	 * Nothing secret is in here, and that is checked rather than assumed: the
	 * API key never leaves the server, and the only endpoint the script knows
	 * about is this site's own REST route.
	 *
	 * Public because it is the whole contract between the two halves of this
	 * plugin, and the browser-side test harness builds its page from this
	 * rather than from a second copy that can drift out of step with it.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		return array(
			'root'         => esc_url_raw( rest_url( REST::NAMESPACE ) ),
			'nonce'        => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'maxLength'    => Options::number( 'max_question_length' ),
			'historyTurns' => Options::number( 'history_turns' ),
			'strings'      => array(
				'welcome'       => Prompt::welcome(),
				'placeholder'   => self::placeholder(),
				'send'          => __( 'Send', 'curio-ai-chat' ),
				'open'          => self::launcher_label(),
				'close'         => __( 'Close chat', 'curio-ai-chat' ),
				'expand'        => __( 'Expand chat', 'curio-ai-chat' ),
				'collapse'      => __( 'Collapse chat', 'curio-ai-chat' ),
				'thinking'      => __( 'Assistant is typing', 'curio-ai-chat' ),
				'sources'       => __( 'Source', 'curio-ai-chat' ),
				'error'         => Prompt::unavailable(),
				'offline'       => __( 'You appear to be offline. Check your connection and try again.', 'curio-ai-chat' ),
				'youSaid'       => __( 'You said', 'curio-ai-chat' ),
				'assistantSaid' => __( 'Assistant said', 'curio-ai-chat' ),
				'confirmReset'  => __( 'Clear this conversation? It cannot be brought back.', 'curio-ai-chat' ),
				'cleared'       => __( 'Conversation cleared.', 'curio-ai-chat' ),
			),
		);
	}

	/**
	 * Input placeholder.
	 *
	 * @return string
	 */
	private static function placeholder(): string {
		$custom = Options::text( 'input_placeholder' );
		return '' !== $custom ? $custom : __( 'Ask a question…', 'curio-ai-chat' );
	}

	/**
	 * Accessible label for the launcher button.
	 *
	 * @return string
	 */
	public static function launcher_label(): string {
		$custom = Options::text( 'launcher_label' );
		return '' !== $custom ? $custom : __( 'Open chat', 'curio-ai-chat' );
	}

	/**
	 * The name shown in the widget header.
	 *
	 * Reads the setting the owner filled in. In the plugin this replaces it was
	 * one particular client's business name, compiled in, so every site that
	 * installed it put that company's name in its own chat header.
	 *
	 * @return string
	 */
	public static function header_title(): string {
		$business = Options::text( 'business_name' );
		return '' !== $business ? $business : __( 'Chat', 'curio-ai-chat' );
	}

	/**
	 * The line under the header title.
	 *
	 * @return string
	 */
	public static function header_subtitle(): string {
		$custom = Options::text( 'header_subtitle' );
		return '' !== $custom ? $custom : __( 'Ask us anything', 'curio-ai-chat' );
	}

	/**
	 * Print the widget markup in the footer.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! self::should_render() ) {
			return;
		}
		if ( ! wp_script_is( 'curio-widget', 'enqueued' ) ) {
			return;
		}
		require CURIO_DIR . 'templates/widget.php';
	}

	/**
	 * The avatar image, or an empty string.
	 *
	 * @return string
	 */
	public static function avatar_html(): string {
		$id = Options::number( 'avatar_id' );
		if ( $id < 1 ) {
			return '';
		}
		return (string) wp_get_attachment_image(
			$id,
			array( 64, 64 ),
			false,
			array(
				'class' => 'curio-avatar-image',
				'alt'   => '',
				'aria-hidden' => 'true',
				'loading' => 'lazy',
			)
		);
	}
}
