<?php
/**
 * Privacy tooling.
 *
 * @package Curio
 */

namespace Curio;

use Curio\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into WordPress's own privacy tools.
 *
 * A plugin that sends what visitors type to a third party and can optionally
 * store it makes its site owner a data controller with obligations. Wiring into
 * the exporter, the eraser and the suggested privacy-policy text costs perhaps
 * a hundred lines and means the owner can answer a subject access request from
 * their own dashboard instead of from a database client.
 */
final class Privacy {

	/**
	 * Register with WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'suggest_policy_text' ) );
	}

	/**
	 * Add the exporter.
	 *
	 * @param array<string,mixed> $exporters Registered exporters.
	 * @return array<string,mixed>
	 */
	public static function register_exporter( $exporters ): array {
		$exporters['curio-ai-chat'] = array(
			'exporter_friendly_name' => __( 'Curio chat transcripts', 'curio-ai-chat' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return (array) $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param array<string,mixed> $erasers Registered erasers.
	 * @return array<string,mixed>
	 */
	public static function register_eraser( $erasers ): array {
		$erasers['curio-ai-chat'] = array(
			'eraser_friendly_name' => __( 'Curio chat transcripts', 'curio-ai-chat' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return (array) $erasers;
	}

	/**
	 * Export a user's stored exchanges.
	 *
	 * Only logged-in visitors can be matched, because that is the only case
	 * where anything identifying was recorded at all. No IP address is stored
	 * for anonymous visitors, by design.
	 *
	 * @param string $email Email address being exported.
	 * @param int    $page  Page number, 1 based.
	 * @return array<string,mixed>
	 */
	public static function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page = max( 1, (int) $page );
		$rows = Conversation_Log::for_user( (int) $user->ID, $page, 100 );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'curio-chat',
				'group_label' => __( 'Chat assistant transcripts', 'curio-ai-chat' ),
				'item_id'     => 'curio-message-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date', 'curio-ai-chat' ),
						'value' => (string) $row['created_at'],
					),
					array(
						'name'  => __( 'Question', 'curio-ai-chat' ),
						'value' => (string) $row['question'],
					),
					array(
						'name'  => __( 'Answer', 'curio-ai-chat' ),
						'value' => (string) $row['answer'],
					),
					array(
						'name'  => __( 'Page', 'curio-ai-chat' ),
						'value' => (string) $row['page_url'],
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < 100,
		);
	}

	/**
	 * Erase a user's stored exchanges.
	 *
	 * @param string $email Email address being erased.
	 * @param int    $page  Page number, 1 based.
	 * @return array<string,mixed>
	 */
	public static function erase( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed = Conversation_Log::erase_user( (int) $user->ID );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Offer WordPress a paragraph for the site's privacy policy.
	 *
	 * @return void
	 */
	public static function suggest_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$provider = Registry::current();
		$links    = $provider->links();

		$text = '<p>' . esc_html__(
			'This site runs a chat assistant. When you send it a message, the text of that message, along with excerpts of this site\'s own content selected as context, is sent to a third-party AI provider so that a reply can be generated. Do not enter personal or sensitive information into the chat.',
			'curio-ai-chat'
		) . '</p>';

		if ( ! empty( $links['privacy'] ) ) {
			$text .= '<p>' . sprintf(
				/* translators: 1: provider name, 2: URL of the provider's privacy policy. */
				esc_html__( 'The provider currently in use is %1$s. Their privacy policy is at %2$s.', 'curio-ai-chat' ),
				esc_html( $provider->label() ),
				'<a href="' . esc_url( $links['privacy'] ) . '">' . esc_html( $links['privacy'] ) . '</a>'
			) . '</p>';
		}

		if ( Options::flag( 'log_conversations' ) ) {
			$text .= '<p>' . sprintf(
				/* translators: %d: number of days transcripts are kept. */
				esc_html__( 'Chat messages and replies are stored on this site for %d days so that we can improve the answers, and are then deleted automatically. No IP addresses are recorded.', 'curio-ai-chat' ),
				(int) Options::number( 'log_retention_days' )
			) . '</p>';
		} else {
			$text .= '<p>' . esc_html__( 'Chat messages are not stored on this site.', 'curio-ai-chat' ) . '</p>';
		}

		// Worth a sentence even though it never leaves the visitor's machine.
		// "Where did my conversation come back from after I refreshed?" is a
		// reasonable question, and the honest answer costs nothing to give.
		$text .= '<p>' . esc_html__(
			'So that the conversation is not lost if you reload the page, it is kept in your own browser for the rest of the visit, using session storage rather than a cookie. It is never sent to this site and is discarded when you close the tab. You can clear it at any time from the button in the chat window.',
			'curio-ai-chat'
		) . '</p>';

		wp_add_privacy_policy_content( __( 'Curio — Grounded AI Chat', 'curio-ai-chat' ), $text );
	}
}
