<?php
/**
 * Abuse and spend controls.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Stands between an anonymous form field and somebody else's billing account.
 *
 * This is the control that stops a site owner waking up to a four-figure API
 * bill. The chat endpoint is public by design, sits in front of a metered
 * service, and takes a couple of seconds per call, so a single visitor with a
 * loop is an unbounded charge on a card that is not theirs. Two independent
 * ceilings: a short per-visitor window that blunts a burst, and a monthly total
 * that caps the worst month possible.
 */
final class Rate_Limiter {

	/**
	 * Has this visitor used up the current window?
	 *
	 * Counts before the request is made rather than after. What is being
	 * rationed is spend, and a call that fails still costs one.
	 *
	 * @return bool
	 */
	public static function visitor_throttled(): bool {
		$limit = Options::number( 'rate_limit' );
		if ( $limit < 1 ) {
			return false;
		}

		$fingerprint = self::fingerprint();
		if ( '' === $fingerprint ) {
			return false;
		}

		$key  = 'curio_rate_' . $fingerprint;
		$hits = (int) get_transient( $key );

		if ( $hits >= $limit ) {
			return true;
		}

		set_transient( $key, $hits + 1, max( 30, Options::number( 'rate_window' ) ) );
		return false;
	}

	/**
	 * Has the site hit its own monthly ceiling?
	 *
	 * @return bool
	 */
	public static function capped(): bool {
		$cap = Options::number( 'monthly_cap' );
		if ( $cap < 1 ) {
			return false;
		}
		return self::used_this_month() >= $cap;
	}

	/**
	 * Messages answered by a provider so far this month.
	 *
	 * @return int
	 */
	public static function used_this_month(): int {
		$usage = get_option( Options::USAGE, array() );
		$usage = is_array( $usage ) ? $usage : array();
		return (int) ( $usage[ self::period() ][ 'calls' ] ?? 0 );
	}

	/**
	 * Token totals for the current month, for the usage panel.
	 *
	 * @return array<string,int>
	 */
	public static function month_usage(): array {
		$usage  = get_option( Options::USAGE, array() );
		$usage  = is_array( $usage ) ? $usage : array();
		$period = self::period();

		return array(
			'calls'      => (int) ( $usage[ $period ]['calls'] ?? 0 ),
			'cached'     => (int) ( $usage[ $period ]['cached'] ?? 0 ),
			'tokens_in'  => (int) ( $usage[ $period ]['tokens_in'] ?? 0 ),
			'tokens_out' => (int) ( $usage[ $period ]['tokens_out'] ?? 0 ),
		);
	}

	/**
	 * Record one billable call.
	 *
	 * @param int $tokens_in  Prompt tokens.
	 * @param int $tokens_out Completion tokens.
	 * @return void
	 */
	public static function record( int $tokens_in = 0, int $tokens_out = 0 ): void {
		self::bump( 'calls', 1 );
		if ( $tokens_in > 0 ) {
			self::bump( 'tokens_in', $tokens_in );
		}
		if ( $tokens_out > 0 ) {
			self::bump( 'tokens_out', $tokens_out );
		}
	}

	/**
	 * Record one answer served from cache — free, and worth showing off.
	 *
	 * @return void
	 */
	public static function record_cached(): void {
		self::bump( 'cached', 1 );
	}

	/**
	 * Increment a counter for the current month.
	 *
	 * @param string $key    Counter name.
	 * @param int    $amount Amount to add.
	 * @return void
	 */
	private static function bump( string $key, int $amount ): void {
		$usage  = get_option( Options::USAGE, array() );
		$usage  = is_array( $usage ) ? $usage : array();
		$period = self::period();

		if ( ! isset( $usage[ $period ] ) || ! is_array( $usage[ $period ] ) ) {
			$usage[ $period ] = array();
		}
		$usage[ $period ][ $key ] = (int) ( $usage[ $period ][ $key ] ?? 0 ) + $amount;

		// Keep a year of history and no more; this row is not a data warehouse.
		if ( count( $usage ) > 12 ) {
			ksort( $usage );
			$usage = array_slice( $usage, -12, null, true );
		}

		update_option( Options::USAGE, $usage, false );
	}

	/**
	 * Current accounting period, in the site's own timezone.
	 *
	 * @return string
	 */
	private static function period(): string {
		return (string) wp_date( 'Y-m' );
	}

	/**
	 * A stable, non-reversible handle for one visitor.
	 *
	 * The IP address is hashed with the site's own salt and never written
	 * anywhere in the clear. Rate limiting needs to know "is this the same
	 * caller as a moment ago", which a hash answers perfectly well; it does not
	 * need to know who they are, and storing an address it does not need would
	 * make this plugin a data-protection problem for every site that installs
	 * it.
	 *
	 * @return string
	 */
	private static function fingerprint(): string {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		/**
		 * Filter the raw client address used for rate limiting.
		 *
		 * Sites behind Cloudflare, a load balancer or a reverse proxy see the
		 * proxy's address in REMOTE_ADDR, which would rate limit every visitor
		 * as though they were one person. Those sites should return the real
		 * client address here — from a header they control and trust, never one
		 * a visitor can set for themselves.
		 *
		 * @since 1.0.0
		 *
		 * @param string $ip Client address as seen by PHP.
		 */
		$ip = (string) apply_filters( 'curio_client_ip', $ip );

		if ( '' === $ip ) {
			return '';
		}

		$salt = defined( 'NONCE_SALT' ) ? (string) NONCE_SALT : CURIO_SLUG;
		return md5( 'curio|' . $salt . '|' . $ip );
	}
}
