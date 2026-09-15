<?php
/**
 * Settings schema, defaults and sanitisation.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * One option row, one sanitiser, one list of defaults.
 *
 * The plugin this replaces spread twenty-odd settings across twenty-odd
 * autoloaded option rows, six of which were registered without a
 * `sanitize_callback` at all. Everything a site owner can change now lives in a
 * single array behind one sanitiser, so there is exactly one place to audit and
 * exactly one place a new setting can be forgotten.
 *
 * API keys are the deliberate exception: they are stored in their own rows with
 * autoload off, so a secret is never pulled into memory on a front-end request
 * that has no reason to send one.
 */
final class Options {

	public const OPTION      = 'curio_settings';
	public const DB_VERSION  = 'curio_db_version';
	public const USAGE       = 'curio_usage';
	public const INDEX_STATE = 'curio_index_state';
	public const FULLTEXT    = 'curio_fulltext';

	/**
	 * Runtime cache of the merged settings array.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Every setting the plugin understands, with its shipped default.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// --- Connection -------------------------------------------------.
			'provider'                 => 'demo',
			'model'                    => '',
			'max_tokens'               => 500,
			'temperature'              => 0.2,

			// --- Who the assistant is ---------------------------------------.
			'business_name'            => '',
			'header_subtitle'          => '',
			'welcome_message'          => '',
			'contact_line'             => '',
			'persona'                  => '',
			'input_placeholder'        => '',

			// --- Reaching a person ------------------------------------------.
			'handoff_type'             => 'none',
			'handoff_page'             => 0,
			'handoff_email'            => '',
			'handoff_url'              => '',
			'handoff_label'            => '',
			'handoff_always'           => false,

			// --- How it answers ---------------------------------------------.
			'context_chunks'           => 4,
			'history_turns'            => 4,
			'show_sources'             => true,
			'cache_answers'            => true,
			'cache_ttl'                => 24,
			'rate_limit'               => 12,
			'rate_window'              => 300,
			'monthly_cap'              => 0,
			'max_question_length'      => 500,

			// --- Where it appears -------------------------------------------.
			'enabled'                  => true,
			'display_mode'             => 'all',
			'display_ids'              => array(),
			'hide_for_logged_in'       => false,
			'mobile_enabled'           => true,

			// --- Appearance -------------------------------------------------.
			'preset'                   => 'light',
			'accent'                   => '#1a1d21',
			'accent_text'              => '',
			'surface'                  => '#ffffff',
			'text_color'               => '#1a1d21',
			'radius'                   => 'soft',
			'position'                 => 'bottom-right',
			'offset_x'                 => 24,
			'offset_y'                 => 24,
			'size'                     => 'standard',
			'launcher_style'           => 'bubble',
			'launcher_label'           => '',
			'avatar_id'                => 0,
			'font'                     => 'inherit',
			'z_index'                  => 99999,
			'custom_css'               => '',
			'greeting_bubble'          => false,
			'greeting_bubble_text'     => '',

			// --- What it knows ----------------------------------------------.
			'index_post_types'         => array(),
			'index_products'           => false,
			'auto_index'               => true,
			'index_excerpt_only'       => false,

			// --- Privacy and housekeeping -----------------------------------.
			'log_conversations'        => false,
			'log_retention_days'       => 30,
			'show_credit'              => false,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings, defaults merged under whatever is stored.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			self::$cache = array_merge( self::defaults(), $stored );
		}
		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value to return when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Read one setting as a trimmed string.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function text( string $key ): string {
		return trim( (string) self::get( $key, '' ) );
	}

	/**
	 * Read one setting as a boolean.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function flag( string $key ): bool {
		return (bool) self::get( $key, false );
	}

	/**
	 * Read one setting as a positive integer.
	 *
	 * @param string $key Setting key.
	 * @return int
	 */
	public static function number( string $key ): int {
		return (int) self::get( $key, 0 );
	}

	/**
	 * Merge a partial array of settings over what is stored.
	 *
	 * @param array<string,mixed> $changes Raw, unsanitised input.
	 * @return array<string,mixed> The sanitised, saved settings.
	 */
	public static function update( array $changes ): array {
		$merged = array_merge( self::all(), $changes );
		$clean  = self::sanitize( $merged );
		update_option( self::OPTION, $clean, true );
		self::$cache = $clean;
		return $clean;
	}

	/**
	 * Forget the runtime cache. Used by the test harness and after imports.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Sanitise a submission that only covers part of the settings.
	 *
	 * The settings screen is tabbed, so any one save posts a handful of fields
	 * and nothing else. Running the full sanitiser over that would reset every
	 * setting on every other tab to its default — the classic tabbed-settings
	 * bug, and one that would quietly wipe somebody's API configuration the
	 * first time they changed a colour.
	 *
	 * Each tab therefore declares the fields it owns in a hidden `_fields`
	 * input. Those keys are taken from the submission; everything else is left
	 * exactly as stored. Declared keys that are missing from the POST are
	 * unchecked checkboxes and emptied multi-selects, which is the one case
	 * where "absent" genuinely means "off" rather than "not on this form".
	 *
	 * @param mixed $input Raw POST array for the option.
	 * @return array<string,mixed>
	 */
	public static function sanitize_form( $input ): array {
		$input = is_array( $input ) ? $input : array();

		if ( ! isset( $input['_fields'] ) ) {
			return self::sanitize( $input );
		}

		$declared = array_filter( array_map( 'trim', explode( ',', (string) $input['_fields'] ) ), 'strlen' );
		unset( $input['_fields'] );

		$defaults = self::defaults();
		$merged   = self::all();

		foreach ( $declared as $key ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			if ( array_key_exists( $key, $input ) ) {
				$merged[ $key ] = $input[ $key ];
				continue;
			}
			if ( is_bool( $defaults[ $key ] ) ) {
				$merged[ $key ] = false;
			} elseif ( is_array( $defaults[ $key ] ) ) {
				$merged[ $key ] = array();
			}
		}

		$clean = self::sanitize( $merged );

		// Anything the owner changes can alter what a correct answer looks
		// like, so no cached answer outlives a save.
		Cache::flush();
		self::$cache = $clean;

		return $clean;
	}

	/**
	 * Coerce every key to the type and range the rest of the plugin assumes.
	 *
	 * Written as a whitelist: a key that is not named here is dropped, so a
	 * crafted POST cannot smuggle extra array members into the option row.
	 *
	 * @param mixed $input Raw settings array.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$out['provider']   = in_array( (string) ( $input['provider'] ?? '' ), array( 'demo', 'anthropic', 'openai', 'gemini' ), true )
			? (string) $input['provider']
			: $defaults['provider'];
		$out['model']      = self::clean_model( $input['model'] ?? '' );
		$out['max_tokens'] = self::clamp_int( $input['max_tokens'] ?? null, 64, 4000, $defaults['max_tokens'] );

		$temperature        = isset( $input['temperature'] ) ? (float) $input['temperature'] : $defaults['temperature'];
		$out['temperature'] = max( 0.0, min( 1.0, round( $temperature, 2 ) ) );

		foreach ( array( 'business_name', 'header_subtitle', 'contact_line', 'input_placeholder', 'launcher_label', 'greeting_bubble_text', 'handoff_label' ) as $key ) {
			$out[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
		}
		foreach ( array( 'welcome_message', 'persona' ) as $key ) {
			$out[ $key ] = sanitize_textarea_field( (string) ( $input[ $key ] ?? '' ) );
		}

		$out['context_chunks']      = self::clamp_int( $input['context_chunks'] ?? null, 1, 10, $defaults['context_chunks'] );
		$out['history_turns']       = self::clamp_int( $input['history_turns'] ?? null, 0, 12, $defaults['history_turns'] );
		$out['cache_ttl']           = self::clamp_int( $input['cache_ttl'] ?? null, 1, 720, $defaults['cache_ttl'] );
		$out['rate_limit']          = self::clamp_int( $input['rate_limit'] ?? null, 1, 200, $defaults['rate_limit'] );
		$out['rate_window']         = self::clamp_int( $input['rate_window'] ?? null, 30, 86400, $defaults['rate_window'] );
		$out['monthly_cap']         = self::clamp_int( $input['monthly_cap'] ?? null, 0, 1000000, $defaults['monthly_cap'] );
		$out['max_question_length'] = self::clamp_int( $input['max_question_length'] ?? null, 40, 4000, $defaults['max_question_length'] );

		$out['handoff_type']  = in_array( (string) ( $input['handoff_type'] ?? '' ), array( 'none', 'page', 'email', 'url' ), true )
			? (string) $input['handoff_type']
			: $defaults['handoff_type'];
		$out['handoff_page']  = absint( $input['handoff_page'] ?? 0 );
		$out['handoff_email'] = sanitize_email( (string) ( $input['handoff_email'] ?? '' ) );
		// Only http and https. Left open, this field is a stored redirect to
		// anywhere, offered to visitors from inside the site owner's own brand.
		$out['handoff_url']   = esc_url_raw( trim( (string) ( $input['handoff_url'] ?? '' ) ), array( 'http', 'https' ) );

		$out['display_mode'] = in_array( (string) ( $input['display_mode'] ?? '' ), array( 'all', 'include', 'exclude' ), true )
			? (string) $input['display_mode']
			: $defaults['display_mode'];
		$out['display_ids']  = self::clean_id_list( $input['display_ids'] ?? array() );

		$out['preset']         = in_array( (string) ( $input['preset'] ?? '' ), array( 'light', 'dark', 'auto', 'custom' ), true )
			? (string) $input['preset']
			: $defaults['preset'];
		$out['radius']         = in_array( (string) ( $input['radius'] ?? '' ), array( 'sharp', 'soft', 'round' ), true )
			? (string) $input['radius']
			: $defaults['radius'];
		$out['position']       = in_array( (string) ( $input['position'] ?? '' ), array( 'bottom-right', 'bottom-left' ), true )
			? (string) $input['position']
			: $defaults['position'];
		$out['size']           = in_array( (string) ( $input['size'] ?? '' ), array( 'compact', 'standard', 'large' ), true )
			? (string) $input['size']
			: $defaults['size'];
		$out['launcher_style'] = in_array( (string) ( $input['launcher_style'] ?? '' ), array( 'bubble', 'pill' ), true )
			? (string) $input['launcher_style']
			: $defaults['launcher_style'];
		$out['font']           = in_array( (string) ( $input['font'] ?? '' ), array( 'inherit', 'system' ), true )
			? (string) $input['font']
			: $defaults['font'];

		foreach ( array( 'accent', 'surface', 'text_color' ) as $key ) {
			$colour      = sanitize_hex_color( (string) ( $input[ $key ] ?? '' ) );
			$out[ $key ] = $colour ? $colour : $defaults[ $key ];
		}
		// Empty is meaningful here: it means "work the contrast out for me".
		$accent_text        = sanitize_hex_color( (string) ( $input['accent_text'] ?? '' ) );
		$out['accent_text'] = $accent_text ? $accent_text : '';

		$out['offset_x']   = self::clamp_int( $input['offset_x'] ?? null, 0, 200, $defaults['offset_x'] );
		$out['offset_y']   = self::clamp_int( $input['offset_y'] ?? null, 0, 200, $defaults['offset_y'] );
		$out['z_index']    = self::clamp_int( $input['z_index'] ?? null, 1, 2147483647, $defaults['z_index'] );
		$out['avatar_id']  = absint( $input['avatar_id'] ?? 0 );
		$out['custom_css'] = self::clean_css( $input['custom_css'] ?? '' );

		$out['index_post_types'] = self::clean_post_types( $input['index_post_types'] ?? array() );

		$out['log_retention_days'] = self::clamp_int( $input['log_retention_days'] ?? null, 1, 3650, $defaults['log_retention_days'] );

		foreach ( array(
			'show_sources',
			'cache_answers',
			'enabled',
			'hide_for_logged_in',
			'mobile_enabled',
			'greeting_bubble',
			'handoff_always',
			'index_products',
			'auto_index',
			'index_excerpt_only',
			'log_conversations',
			'show_credit',
			'delete_data_on_uninstall',
		) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] ) && 'false' !== $input[ $key ] && '0' !== (string) $input[ $key ];
		}

		return $out;
	}

	/**
	 * Model identifiers are provider-defined strings; allow their real
	 * character set and nothing else.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean_model( $value ): string {
		$value = trim( (string) $value );
		$value = preg_replace( '/[^A-Za-z0-9._\-\/:]/', '', $value );
		return (string) substr( (string) $value, 0, 120 );
	}

	/**
	 * Clamp a value into a range, falling back when it is not numeric at all.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @param int   $fallback Value to use when input is not numeric.
	 * @return int
	 */
	private static function clamp_int( $value, int $min, int $max, int $fallback ): int {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return $fallback;
		}
		return (int) max( $min, min( $max, (int) $value ) );
	}

	/**
	 * A list of post IDs, from either an array or a comma separated string.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	private static function clean_id_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value ) ?: array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array_map( 'absint', $value );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		return array_slice( $ids, 0, 500 );
	}

	/**
	 * Only post types that are actually registered and publicly viewable.
	 *
	 * Filtering against `get_post_types()` rather than trusting the POST body
	 * is what stops a crafted request pointing the indexer at a private type
	 * and copying its contents into a knowledge base that answers the public.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function clean_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$allowed = function_exists( 'get_post_types' )
			? get_post_types( array( 'public' => true ), 'names' )
			: array();
		$clean   = array();
		foreach ( $value as $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' !== $type && ( empty( $allowed ) || in_array( $type, (array) $allowed, true ) ) ) {
				$clean[] = $type;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Custom CSS, with the handful of constructs that turn a stylesheet into a
	 * script vector removed.
	 *
	 * Only `manage_options` can reach this field, so it is defence in depth
	 * rather than the primary control, but a stored stylesheet renders on every
	 * page of the site and is worth being unromantic about.
	 *
	 * @param mixed $value Raw CSS.
	 * @return string
	 */
	private static function clean_css( $value ): string {
		$css = (string) $value;
		$css = wp_strip_all_tags( $css );
		$css = str_replace( array( "\0", '\\0' ), '', $css );
		$css = preg_replace( '#(javascript|vbscript|data)\s*:#i', '', $css );
		$css = preg_replace( '#(expression|behaviou?r|@import|-moz-binding)\s*[:(]#i', '', $css );
		return (string) substr( (string) $css, 0, 20000 );
	}
}
