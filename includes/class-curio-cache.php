<?php
/**
 * Answer and lookup caching.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * A thin, versioned layer over transients.
 *
 * Invalidation is done by bumping a version number that is baked into every
 * key, not by deleting rows. Deleting "all our transients" is a `LIKE '_transient_%'`
 * query that does not work at all against a persistent object cache, and a
 * plugin that silently serves stale answers after the owner edits their prices
 * is worse than one that does not cache.
 *
 * The saving is not theoretical. Real visitors ask the same eight questions,
 * and every repeat that is answered from here is an API call the site owner
 * does not pay for.
 */
final class Cache {

	private const GROUP       = 'curio';
	private const VERSION_KEY = 'curio_cache_version';

	/**
	 * Current cache generation.
	 *
	 * @return int
	 */
	public static function version(): int {
		$version = (int) get_option( self::VERSION_KEY, 0 );
		if ( $version < 1 ) {
			$version = 1;
			update_option( self::VERSION_KEY, $version, false );
		}
		return $version;
	}

	/**
	 * Invalidate everything cached so far.
	 *
	 * Called whenever the knowledge base or the assistant's instructions
	 * change, because both alter what a correct answer looks like.
	 *
	 * @return void
	 */
	public static function flush(): void {
		update_option( self::VERSION_KEY, self::version() + 1, false );
		wp_cache_flush_group( self::GROUP );
	}

	/**
	 * Build a namespaced, version-stamped transient key.
	 *
	 * @param string $bucket Logical bucket.
	 * @param string $seed   Anything that identifies the entry.
	 * @return string
	 */
	private static function key( string $bucket, string $seed ): string {
		return 'curio_' . $bucket . '_' . self::version() . '_' . md5( $seed );
	}

	/**
	 * Read a cached value.
	 *
	 * @param string $bucket Logical bucket.
	 * @param string $seed   Entry seed.
	 * @return mixed False when absent.
	 */
	public static function get( string $bucket, string $seed ) {
		return get_transient( self::key( $bucket, $seed ) );
	}

	/**
	 * Write a cached value.
	 *
	 * @param string $bucket  Logical bucket.
	 * @param string $seed    Entry seed.
	 * @param mixed  $value   Value to store.
	 * @param int    $seconds Lifetime.
	 * @return void
	 */
	public static function set( string $bucket, string $seed, $value, int $seconds ): void {
		set_transient( self::key( $bucket, $seed ), $value, max( 60, $seconds ) );
	}

	/**
	 * Forget one entry.
	 *
	 * @param string $bucket Logical bucket.
	 * @param string $seed   Entry seed.
	 * @return void
	 */
	public static function forget( string $bucket, string $seed ): void {
		delete_transient( self::key( $bucket, $seed ) );
	}

	/**
	 * Object-cache read for values that are cheap to recompute.
	 *
	 * @param string $key Cache key.
	 * @return mixed
	 */
	public static function memo( string $key ) {
		return wp_cache_get( $key, self::GROUP );
	}

	/**
	 * Object-cache write.
	 *
	 * @param string $key     Cache key.
	 * @param mixed  $value   Value.
	 * @param int    $seconds Lifetime.
	 * @return void
	 */
	public static function remember( string $key, $value, int $seconds = 300 ): void {
		wp_cache_set( $key, $value, self::GROUP, $seconds );
	}
}
