<?php
/**
 * The route to a person.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Where a visitor goes when the assistant cannot help.
 *
 * A grounded assistant declines more often than a guessing one — that is the
 * trade the whole plugin is built around — which makes the dead end after a
 * decline the plugin's single biggest leak. The hand-off line already tells a
 * visitor what to do; this gives them something to press while they are still
 * reading it, because a sentence containing an email address asks somebody to
 * copy it out, open their mail client and start again, and most people simply
 * close the tab instead.
 *
 * Nothing here is filled in by default. A button that goes nowhere is worse
 * than no button, so the widget shows it only once a destination exists.
 */
final class Handoff {

	/**
	 * The destination, or an empty string when none is configured.
	 *
	 * @return string
	 */
	public static function url(): string {
		switch ( Options::text( 'handoff_type' ) ) {
			case 'page':
				$id   = Options::number( 'handoff_page' );
				$page = $id > 0 ? get_post( $id ) : null;

				// A page that has since been unpublished or binned is not a
				// destination. Linking one sends a customer who has already
				// been told "I cannot help" to a 404, which is worse than the
				// decline on its own.
				if ( $page instanceof \WP_Post && 'publish' === $page->post_status ) {
					return (string) get_permalink( $page );
				}
				return '';

			case 'email':
				$email = Options::text( 'handoff_email' );
				return is_email( $email ) ? 'mailto:' . $email : '';

			case 'url':
				return Options::text( 'handoff_url' );
		}

		return '';
	}

	/**
	 * Is a usable destination configured?
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::url();
	}

	/**
	 * The text on the button.
	 *
	 * @return string
	 */
	public static function label(): string {
		$custom = Options::text( 'handoff_label' );
		return '' !== $custom ? $custom : __( 'Talk to a person', 'curio-ai-chat' );
	}

	/**
	 * Show it from the first message rather than waiting for a decline.
	 *
	 * @return bool
	 */
	public static function always(): bool {
		return Options::flag( 'handoff_always' );
	}

	/**
	 * Should the destination open in a new tab?
	 *
	 * A `mailto:` must not: the browser hands it to a mail client and the blank
	 * tab it was given is left behind, empty, on top of the conversation.
	 *
	 * @return bool
	 */
	public static function opens_new_tab(): bool {
		return 'email' !== Options::text( 'handoff_type' );
	}
}
