<?php
/**
 * Plugin bootstrap.
 *
 * @package Curio
 */

namespace Curio;

use Curio\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every hook, and does nothing else.
 *
 * Kept as a map rather than a place where logic accumulates: if you want to
 * know what this plugin touches in WordPress, this file is the whole answer.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get, or create, the instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		// A plugin updated by dropping files over the old ones never fires its
		// activation hook, so the schema has to be able to catch up on its own.
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );

		add_action( 'rest_api_init', array( REST::class, 'register' ) );

		add_action( 'wp_enqueue_scripts', array( Widget::class, 'enqueue' ) );
		add_action( 'wp_footer', array( Widget::class, 'render' ), 100 );

		// Indexing follows content. `save_post` covers the editor, the REST
		// API and WP-CLI in one hook; the status transition catches scheduled
		// posts going live, which `save_post` alone misses.
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'trashed_post', array( $this, 'on_delete_post' ) );

		add_action( 'curio_daily_maintenance', array( $this, 'maintenance' ) );

		add_filter( 'plugin_action_links_' . CURIO_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );

		Privacy::register();
		CLI::register();

		if ( is_admin() ) {
			Admin::register();
		}
	}

	/**
	 * Re-index a post after it is saved.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this was an update.
	 * @return void
	 */
	public function on_save_post( $post_id, $post = null, $update = false ): void {
		if ( ! Options::flag( 'auto_index' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		Indexer::index_post( (int) $post_id );
	}

	/**
	 * Re-index when a post changes status.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function on_status_change( $new_status, $old_status, $post ): void {
		if ( ! Options::flag( 'auto_index' ) || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}
		// `index_post()` removes anything that no longer qualifies, so both
		// directions — published and unpublished — are handled by one call.
		Indexer::index_post( (int) $post->ID );
	}

	/**
	 * Drop a deleted post's knowledge rows.
	 *
	 * Runs whether or not auto-indexing is on. Deleting a page must always take
	 * it out of the assistant's mouth; leaving stale rows behind because a
	 * setting was switched off would have the bot quoting a page that no longer
	 * exists.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function on_delete_post( $post_id ): void {
		Indexer::remove_post( (int) $post_id );
	}

	/**
	 * Daily housekeeping.
	 *
	 * @return void
	 */
	public function maintenance(): void {
		Conversation_Log::prune();
	}

	/**
	 * Links under the plugin name on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . CURIO_SLUG ) ),
			esc_html__( 'Settings', 'curio-ai-chat' )
		);
		// The cast has to happen first and be kept. array_unshift() takes its
		// first argument by reference, and `(array) $links` is a temporary — PHP
		// 8 raises a fatal Error rather than unshifting it, which takes out the
		// whole Plugins screen. Even without the fatal, the link would have been
		// pushed onto a copy that is thrown away on the next line.
		$links = (array) $links;
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Extra links in the plugin's row.
	 *
	 * @param string[] $meta Existing meta links.
	 * @param string   $file Plugin file being filtered.
	 * @return string[]
	 */
	public function row_meta( $meta, $file ): array {
		if ( CURIO_BASENAME !== $file ) {
			return (array) $meta;
		}

		$meta = (array) $meta;

		$meta[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				add_query_arg(
					array(
						'page' => CURIO_SLUG,
						'tab'  => 'help',
					),
					admin_url( 'options-general.php' )
				)
			),
			esc_html__( 'How it works', 'curio-ai-chat' )
		);

		/*
		 * Support, not a solicitation. The author is already linked from this
		 * same row by the `Author URI` header, and guideline 11 asks for
		 * anything resembling an advert to be kept to the plugin's own settings
		 * screen — which is where the "hire me" links live. A second one here,
		 * on a screen the site owner opens to manage twenty other plugins,
		 * would be the one that reads as pushy.
		 */
		$meta[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://wordpress.org/support/plugin/' . CURIO_SLUG . '/' ),
			esc_html__( 'Support', 'curio-ai-chat' )
		);

		return $meta;
	}
}
