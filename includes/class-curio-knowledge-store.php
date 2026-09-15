<?php
/**
 * Knowledge base persistence.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Every read and write against the knowledge table.
 *
 * Kept deliberately dumb: it stores rows and hands them back. Scoring lives in
 * Retriever, chunking in Text, and deciding what is worth storing in Indexer,
 * so that changing how the plugin ranks an answer never risks changing what it
 * has on file.
 */
final class Knowledge_Store {

	public const SOURCE_MANUAL  = 'manual';
	public const SOURCE_POST    = 'post';
	public const SOURCE_PRODUCT = 'product';

	/**
	 * Insert or replace a row, keyed on its `ref`.
	 *
	 * @param array<string,mixed> $row Row data.
	 * @return int Row id, or 0 on failure.
	 */
	public static function upsert( array $row ): int {
		global $wpdb;

		$content = trim( (string) ( $row['content'] ?? '' ) );
		if ( '' === $content ) {
			return 0;
		}

		$ref = trim( (string) ( $row['ref'] ?? '' ) );
		if ( '' === $ref ) {
			$ref = self::SOURCE_MANUAL . ':' . md5( $content . wp_rand() );
		}

		$keywords = $row['keywords'] ?? array();
		if ( is_string( $keywords ) ) {
			$keywords = preg_split( '/[,\n]+/', $keywords ) ?: array();
		}
		$keywords = array_values( array_filter( array_map(
			static function ( $keyword ) {
				return Text::lower( trim( sanitize_text_field( (string) $keyword ) ) );
			},
			(array) $keywords
		), 'strlen' ) );

		if ( array() === $keywords ) {
			$keywords = Text::keywords( ( (string) ( $row['title'] ?? '' ) ) . ' ' . $content, 16 );
		}

		$data = array(
			'ref'         => substr( sanitize_text_field( $ref ), 0, 191 ),
			'source_type' => sanitize_key( (string) ( $row['source_type'] ?? self::SOURCE_MANUAL ) ),
			'source_id'   => absint( $row['source_id'] ?? 0 ),
			'chunk_index' => absint( $row['chunk_index'] ?? 0 ),
			'title'       => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
			'content'     => $content,
			'keywords'    => implode( ' ', array_slice( array_unique( $keywords ), 0, 40 ) ),
			'url'         => esc_url_raw( (string) ( $row['url'] ?? '' ) ),
			'checksum'    => md5( $content ),
			'updated_at'  => current_time( 'mysql', true ),
		);

		$table    = Installer::knowledge_table();
		$existing = self::id_for_ref( $data['ref'] );

		// An update that does not mention the source link must not delete it.
		// Callers legitimately save a subset of the fields — the bulk importer
		// accepts entries with no `url` at all — and silently blanking the
		// stored one costs the visitor the "where did this come from" link that
		// is the whole reason a grounded answer is checkable.
		if ( $existing > 0 && '' === $data['url'] ) {
			$previous = self::find( $existing );
			if ( $previous && '' !== (string) $previous['url'] ) {
				$data['url'] = (string) $previous['url'];
			}
		}

		if ( $existing > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $existing ), null, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; results are invalidated through Cache::flush().
			$id = $existing;
		} else {
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; results are invalidated through Cache::flush().
			$id = (int) $wpdb->insert_id;
		}

		Cache::flush();
		return $id;
	}

	/**
	 * Look up a row id by its natural key.
	 *
	 * @param string $ref Reference.
	 * @return int
	 */
	public static function id_for_ref( string $ref ): int {
		global $wpdb;
		$table = Installer::knowledge_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; the value is prepared.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE ref = %s LIMIT 1", $ref ) );
	}

	/**
	 * Fetch a single row.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$table = Installer::knowledge_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; the value is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Paged listing for the admin screen.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public static function paged( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'      => '',
				'source_type' => '',
				'per_page'    => 20,
				'page'        => 1,
			)
		);

		$table    = Installer::knowledge_table();
		$where    = array( '1=1' );
		$values   = array();
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) ) * $per_page;

		if ( '' !== trim( (string) $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[]  = '(title LIKE %s OR content LIKE %s OR keywords LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}
		if ( '' !== trim( (string) $args['source_type'] ) ) {
			$where[]  = 'source_type = %s';
			$values[] = sanitize_key( (string) $args['source_type'] );
		}

		$values[] = $per_page;
		$values[] = $offset;

		$sql = "SELECT * FROM `{$table}` WHERE " . implode( ' AND ', $where )
			. ' ORDER BY source_type ASC, source_id ASC, chunk_index ASC, id ASC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is assembled from fixed fragments; every value is bound below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count rows, optionally per source type.
	 *
	 * @param string $source_type Source type, or empty for all.
	 * @return int
	 */
	public static function count( string $source_type = '' ): int {
		global $wpdb;
		$table = Installer::knowledge_table();

		$memo_key = 'count_' . ( '' === $source_type ? 'all' : sanitize_key( $source_type ) );
		$cached   = Cache::memo( $memo_key );
		if ( is_numeric( $cached ) ) {
			return (int) $cached;
		}

		if ( '' === $source_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; no user values in this query.
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; the value is prepared.
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE source_type = %s", sanitize_key( $source_type ) ) );
		}

		Cache::remember( $memo_key, $count, 120 );
		return $count;
	}

	/**
	 * How many distinct sources are represented.
	 *
	 * @return array<string,int>
	 */
	public static function counts_by_type(): array {
		global $wpdb;
		$table = Installer::knowledge_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; no user values in this query.
		$rows = $wpdb->get_results( "SELECT source_type, COUNT(*) AS total FROM `{$table}` GROUP BY source_type", ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['source_type'] ] = (int) $row['total'];
		}
		return $out;
	}

	/**
	 * Delete a single row.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache is invalidated immediately below.
		$deleted = $wpdb->delete( Installer::knowledge_table(), array( 'id' => $id ), array( '%d' ) );
		Cache::flush();
		return (bool) $deleted;
	}

	/**
	 * Delete every chunk belonging to one source item.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source id.
	 * @return int Rows removed.
	 */
	public static function delete_source( string $source_type, int $source_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache is invalidated immediately below.
		$deleted = $wpdb->delete(
			Installer::knowledge_table(),
			array(
				'source_type' => sanitize_key( $source_type ),
				'source_id'   => $source_id,
			),
			array( '%s', '%d' )
		);
		Cache::flush();
		return (int) $deleted;
	}

	/**
	 * Delete every chunk of a source item beyond a given chunk index.
	 *
	 * Re-indexing a post that got shorter has to remove the tail, or the
	 * assistant keeps answering from paragraphs the owner deleted. That is the
	 * kind of bug nobody reports and everybody notices.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source id.
	 * @param int    $keep_upto   Highest chunk index to keep.
	 * @return int
	 */
	public static function prune_chunks( string $source_type, int $source_id, int $keep_upto ): int {
		global $wpdb;
		$table = Installer::knowledge_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; every value is prepared.
		$deleted = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$table}` WHERE source_type = %s AND source_id = %d AND chunk_index >= %d",
			sanitize_key( $source_type ),
			$source_id,
			$keep_upto
		) );
		if ( $deleted > 0 ) {
			Cache::flush();
		}
		return $deleted;
	}

	/**
	 * Remove every row of a given source type.
	 *
	 * @param string $source_type Source type.
	 * @return int
	 */
	public static function clear_type( string $source_type ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache is invalidated immediately below.
		$deleted = (int) $wpdb->delete( Installer::knowledge_table(), array( 'source_type' => sanitize_key( $source_type ) ), array( '%s' ) );
		Cache::flush();
		return $deleted;
	}

	/**
	 * Empty the knowledge base.
	 *
	 * There is no "restore defaults" because there are no defaults. The only
	 * safe thing for a bot that answers customers to know by default is
	 * nothing.
	 *
	 * @return void
	 */
	public static function clear_all(): void {
		global $wpdb;
		$table = Installer::knowledge_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; no user values in this query.
		$wpdb->query( "DELETE FROM `{$table}`" );
		Cache::flush();
	}

	/**
	 * Export every row in the shape the importer accepts.
	 *
	 * @param bool $manual_only Restrict to hand-written entries.
	 * @return array<int,array<string,mixed>>
	 */
	public static function export( bool $manual_only = true ): array {
		global $wpdb;
		$table = Installer::knowledge_table();

		if ( $manual_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; the value is prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT title, content, keywords, url FROM `{$table}` WHERE source_type = %s ORDER BY id ASC", self::SOURCE_MANUAL ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; no user values in this query.
			$rows = $wpdb->get_results( "SELECT title, content, keywords, url FROM `{$table}` ORDER BY id ASC", ARRAY_A );
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'title'    => (string) $row['title'],
				'content'  => (string) $row['content'],
				'keywords' => array_values( array_filter( explode( ' ', (string) $row['keywords'] ), 'strlen' ) ),
				'url'      => (string) $row['url'],
			);
		}
		return $out;
	}

	/**
	 * Rows for the ids returned by a search, in the order given.
	 *
	 * @param int[] $ids Row ids.
	 * @return array<int,array<string,mixed>>
	 */
	public static function by_ids( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$table        = Installer::knowledge_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated from a count, not from input; every id is bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id IN ({$placeholders})", $ids ), ARRAY_A );

		$keyed = array();
		foreach ( (array) $rows as $row ) {
			$keyed[ (int) $row['id'] ] = $row;
		}

		$ordered = array();
		foreach ( $ids as $id ) {
			if ( isset( $keyed[ $id ] ) ) {
				$ordered[] = $keyed[ $id ];
			}
		}
		return $ordered;
	}
}
