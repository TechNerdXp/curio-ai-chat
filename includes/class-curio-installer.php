<?php
/**
 * Activation, schema and upgrade routines.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the plugin's two tables.
 *
 * Knowledge lives in a table rather than an option because auto-indexing a
 * real site produces thousands of chunks, and an option that size is an
 * autoloaded, serialised blob that WordPress unserialises on every single
 * request. The predecessor stored its knowledge base exactly that way, which
 * was survivable for seven hand-typed entries and would not have been for
 * seven hundred.
 */
final class Installer {

	/**
	 * Knowledge table name.
	 *
	 * @return string
	 */
	public static function knowledge_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'curio_knowledge';
	}

	/**
	 * Conversation log table name.
	 *
	 * @return string
	 */
	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'curio_log';
	}

	/**
	 * Run on activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::install_tables();

		// Seeding nothing is the point. The plugin this replaces wrote seven
		// invented knowledge entries on activation — wedding prices, an hourly
		// rate, a phone number — so an unattended install quoted figures to
		// real customers that nobody at the business had ever agreed to. An
		// empty knowledge base is the only safe starting state: the assistant
		// knows nothing and says so.
		if ( false === get_option( Options::OPTION, false ) ) {
			add_option( Options::OPTION, Options::sanitize( Options::defaults() ), '', true );
		}

		add_option( 'curio_activated_at', time(), '', false );
		update_option( Options::DB_VERSION, CURIO_DB_VERSION, false );

		if ( ! wp_next_scheduled( 'curio_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'curio_daily_maintenance' );
		}
	}

	/**
	 * Run on deactivation.
	 *
	 * Data is deliberately left alone: deactivating to debug a theme conflict
	 * should not destroy a knowledge base somebody spent an afternoon writing.
	 * Deletion is opt-in and happens at uninstall.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'curio_daily_maintenance' );
		Cache::flush();
	}

	/**
	 * Bring the schema up to date if the plugin was updated in place.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( Options::DB_VERSION, 0 ) === (int) CURIO_DB_VERSION ) {
			return;
		}
		self::install_tables();
		update_option( Options::DB_VERSION, CURIO_DB_VERSION, false );
	}

	/**
	 * Create or migrate both tables.
	 *
	 * @return void
	 */
	public static function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$knowledge = self::knowledge_table();
		$log       = self::log_table();

		// `ref` is the natural key for indexed content: one row per source
		// chunk, so re-indexing a post replaces its rows instead of piling up
		// duplicates. `varchar(191)` because that is the widest a unique index
		// can be under utf8mb4 on MySQL 5.6 era row formats.
		$sql_knowledge = "CREATE TABLE {$knowledge} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ref varchar(191) NOT NULL DEFAULT '',
			source_type varchar(32) NOT NULL DEFAULT 'manual',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			chunk_index smallint(5) unsigned NOT NULL DEFAULT 0,
			title text NOT NULL,
			content longtext NOT NULL,
			keywords text NOT NULL,
			url varchar(255) NOT NULL DEFAULT '',
			checksum char(32) NOT NULL DEFAULT '',
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ref (ref),
			KEY source (source_type,source_id),
			KEY updated_at (updated_at),
			FULLTEXT KEY search (title,content,keywords)
		) {$charset};";

		// No IP address column, on purpose. Not storing it means there is no
		// personal data here to leak for an anonymous visitor, no retention
		// argument to have, and a much shorter privacy policy for the site
		// owner to write. `user_id` is recorded only when someone is logged in,
		// which is what makes the GDPR exporter and eraser able to find their
		// rows at all.
		$sql_log = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT NULL,
			question text NOT NULL,
			answer longtext NOT NULL,
			answered tinyint(1) NOT NULL DEFAULT 0,
			provider varchar(32) NOT NULL DEFAULT '',
			model varchar(120) NOT NULL DEFAULT '',
			matched varchar(191) NOT NULL DEFAULT '',
			tokens_in int(10) unsigned NOT NULL DEFAULT 0,
			tokens_out int(10) unsigned NOT NULL DEFAULT 0,
			page_url varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY answered (answered),
			KEY user_id (user_id)
		) {$charset};";

		dbDelta( $sql_knowledge );
		dbDelta( $sql_log );

		update_option( Options::FULLTEXT, self::detect_fulltext(), false );
	}

	/**
	 * Did the FULLTEXT index actually get created?
	 *
	 * MyISAM, ancient InnoDB and a handful of managed hosts will silently skip
	 * it. Retrieval has a LIKE path for exactly that case, and this is how it
	 * knows which one it is on instead of guessing.
	 *
	 * @return bool
	 */
	public static function detect_fulltext(): bool {
		global $wpdb;
		$table = self::knowledge_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix and cannot be parameterised.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $indexes ) ) {
			return false;
		}
		foreach ( $indexes as $index ) {
			if ( isset( $index->Key_name, $index->Index_type ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Column names come from MySQL.
				&& 'search' === $index->Key_name // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Column names come from MySQL.
				&& 'FULLTEXT' === strtoupper( (string) $index->Index_type ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Column names come from MySQL.
			) {
				return true;
			}
		}
		return false;
	}
}
