<?php
/**
 * Conversation logging.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Optional, off by default, and mostly useful for one thing.
 *
 * The reason to keep this at all is the unanswered-question list. A grounded
 * assistant declines whatever is not in its knowledge base, so every decline is
 * a customer asking something the business has not written down yet. That list
 * is the single most useful thing this plugin can hand its owner: it turns the
 * bot's limitation into a to-do list that makes it better every week.
 *
 * It ships switched off because logging what visitors type is a decision with
 * privacy consequences, and that decision belongs to the site owner, made
 * knowingly, not to a default.
 */
final class Conversation_Log {

	/**
	 * Is logging switched on?
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return Options::flag( 'log_conversations' );
	}

	/**
	 * Write one exchange.
	 *
	 * @param array<string,mixed> $entry Exchange data.
	 * @return int Row id, or 0 when logging is off.
	 */
	public static function record( array $entry ): int {
		if ( ! self::enabled() ) {
			return 0;
		}

		global $wpdb;

		$matched = (array) ( $entry['matched'] ?? array() );

		$data = array(
			'user_id'    => get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
			'question'   => Text::truncate( sanitize_textarea_field( (string) ( $entry['question'] ?? '' ) ), 2000 ),
			'answer'     => Text::truncate( sanitize_textarea_field( (string) ( $entry['answer'] ?? '' ) ), 6000 ),
			'answered'   => empty( $entry['answered'] ) ? 0 : 1,
			'provider'   => sanitize_key( (string) ( $entry['provider'] ?? '' ) ),
			'model'      => sanitize_text_field( (string) ( $entry['model'] ?? '' ) ),
			'matched'    => substr( implode( ',', array_map( 'absint', $matched ) ), 0, 191 ),
			'tokens_in'  => absint( $entry['tokens_in'] ?? 0 ),
			'tokens_out' => absint( $entry['tokens_out'] ?? 0 ),
			'page_url'   => esc_url_raw( (string) ( $entry['page_url'] ?? '' ) ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; an append-only log is not a cacheable read.
		$wpdb->insert( Installer::log_table(), $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Recent exchanges for the admin screen.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'only_unanswered' => false,
				'per_page'        => 25,
				'page'            => 1,
			)
		);

		$table    = Installer::log_table();
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) ) * $per_page;

		if ( ! empty( $args['only_unanswered'] ) ) {
			$sql    = "SELECT * FROM `{$table}` WHERE answered = 0 ORDER BY created_at DESC LIMIT %d OFFSET %d";
			$values = array( $per_page, $offset );
		} else {
			$sql    = "SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d";
			$values = array( $per_page, $offset );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; both values are bound.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Questions the assistant could not answer, grouped so the same question
	 * asked forty times appears once with a count beside it.
	 *
	 * @param int $limit How many to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function gaps( int $limit = 30 ): array {
		global $wpdb;

		$table = Installer::log_table();
		$sql   = "SELECT question, COUNT(*) AS times, MAX(created_at) AS last_asked
			FROM `{$table}`
			WHERE answered = 0
			GROUP BY question
			ORDER BY times DESC, last_asked DESC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; the limit is bound.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, max( 1, min( 200, $limit ) ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many rows are stored, and how many are unanswered.
	 *
	 * @return array<string,int>
	 */
	public static function totals(): array {
		global $wpdb;
		$table = Installer::log_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; no user values in this query.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(answered = 0) AS unanswered FROM `{$table}`", ARRAY_A );

		return array(
			'total'      => (int) ( $row['total'] ?? 0 ),
			'unanswered' => (int) ( $row['unanswered'] ?? 0 ),
		);
	}

	/**
	 * Delete one row.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; append-only log.
		return (bool) $wpdb->delete( Installer::log_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function clear(): void {
		global $wpdb;
		$table = Installer::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; no user values in this query.
		$wpdb->query( "DELETE FROM `{$table}`" );
	}

	/**
	 * Drop rows older than the configured retention period.
	 *
	 * Runs daily. Retention that only happens when an administrator remembers
	 * to press a button is not retention, it is an intention.
	 *
	 * @return int Rows removed.
	 */
	public static function prune(): int {
		global $wpdb;

		$days = max( 1, Options::number( 'log_retention_days' ) );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$table = Installer::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; the value is prepared.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE created_at < %s", $cut ) );
	}

	/**
	 * Rows belonging to one registered user, for the privacy tools.
	 *
	 * @param int $user_id User id.
	 * @param int $page    Page number, 1 based.
	 * @param int $per_page Rows per page.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_user( int $user_id, int $page = 1, int $per_page = 100 ): array {
		global $wpdb;

		if ( $user_id < 1 ) {
			return array();
		}

		$table  = Installer::log_table();
		$offset = max( 0, $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; every value is bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $user_id, $per_page, $offset ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete every row belonging to one registered user.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function erase_user( int $user_id ): int {
		global $wpdb;
		if ( $user_id < 1 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; append-only log.
		return (int) $wpdb->delete( Installer::log_table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
