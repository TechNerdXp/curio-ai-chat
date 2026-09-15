<?php
/**
 * API key storage.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Keys at rest, encrypted with a key derived from the site's own salts.
 *
 * This is honest about what it is. `wp-config.php` lives on the same disk as
 * the database dump in most hosting setups, so anyone who has both has the key
 * too. What it does buy is real: a leaked `wp_options` table, a careless
 * `SELECT * FROM wp_options` in a support ticket, a shared staging snapshot or
 * a plugin that logs option reads no longer hands out a live billing
 * credential in plain text. The threat this defends against is casual
 * exposure, which is the one that actually happens.
 *
 * Keys are stored in their own rows with autoload off, so a front-end page
 * request never pulls a secret into memory unless it is about to send one.
 */
final class Secret {

	private const CIPHER = 'aes-256-cbc';
	private const PREFIX = 'curio:v1:';

	/**
	 * Option row for a provider's key.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private static function option_name( string $provider ): string {
		return 'curio_key_' . sanitize_key( $provider );
	}

	/**
	 * Store a key, encrypted when the platform allows it.
	 *
	 * An empty string deletes the row rather than storing an encrypted empty
	 * string, so "no key" is one state and not two.
	 *
	 * @param string $provider Provider slug.
	 * @param string $key      Raw API key.
	 * @return bool
	 */
	public static function put( string $provider, string $key ): bool {
		$option = self::option_name( $provider );
		$key    = trim( $key );

		if ( '' === $key ) {
			return delete_option( $option );
		}

		$stored = self::encrypt( $key );

		// autoload `false`: a secret has no business being loaded on every
		// front-end request, and only the REST handler and the settings screen
		// ever ask for it.
		if ( false === get_option( $option, false ) ) {
			return add_option( $option, $stored, '', false );
		}
		return update_option( $option, $stored, false );
	}

	/**
	 * Read a key back.
	 *
	 * @param string $provider Provider slug.
	 * @return string Empty string when absent or undecryptable.
	 */
	public static function get( string $provider ): string {
		$stored = get_option( self::option_name( $provider ), '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}
		return self::decrypt( $stored );
	}

	/**
	 * Is a key on file for this provider?
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function exists( string $provider ): bool {
		return '' !== self::get( $provider );
	}

	/**
	 * Remove a key.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function forget( string $provider ): bool {
		return delete_option( self::option_name( $provider ) );
	}

	/**
	 * A display form that identifies the key without revealing it.
	 *
	 * The settings screen renders this instead of the key, so the secret is
	 * never present in the page source, never in a browser cache and never in
	 * a screen share.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function mask( string $provider ): string {
		$key = self::get( $provider );
		if ( '' === $key ) {
			return '';
		}
		$length = strlen( $key );
		if ( $length <= 8 ) {
			return str_repeat( '•', $length );
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	/**
	 * Is real encryption available on this host?
	 *
	 * @return bool
	 */
	public static function is_encrypted(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& in_array( self::CIPHER, (array) openssl_get_cipher_methods(), true )
			&& '' !== self::derive_key();
	}

	/**
	 * Derive a 256 bit key from whichever salts the install defines.
	 *
	 * @return string Raw binary key, or an empty string if no salt is usable.
	 */
	private static function derive_key(): string {
		$material = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$value = constant( $constant );
				if ( is_string( $value ) && '' !== $value && 0 !== strpos( $value, 'put your unique phrase here' ) ) {
					$material .= $value;
				}
			}
		}
		if ( '' === $material ) {
			return '';
		}
		return hash( 'sha256', 'curio|' . $material, true );
	}

	/**
	 * Encrypt, or pass through when the platform cannot.
	 *
	 * The version prefix is what makes `decrypt()` able to tell an encrypted
	 * value from a plain one, which is what lets a site that gains OpenSSL
	 * later keep reading keys it stored before.
	 *
	 * @param string $plain Raw key.
	 * @return string
	 */
	private static function encrypt( string $plain ): string {
		if ( ! self::is_encrypted() ) {
			return $plain;
		}
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		if ( ! $iv_length ) {
			return $plain;
		}
		$iv     = random_bytes( $iv_length );
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::derive_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return $plain;
		}
		// Binary ciphertext and its initialisation vector, made safe to store in
		// a text column. Nothing here is hidden from anyone: the input is
		// already encrypted, and the only thing base64 does is stop a byte
		// stream being mangled on its way through the database.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::PREFIX . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a stored value, tolerating the plain-text case.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	private static function decrypt( string $stored ): string {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}
		if ( ! self::is_encrypted() ) {
			return '';
		}
		// The other half of the same transport step: back to the raw bytes
		// OpenSSL produced. Strict mode, so anything that is not the value this
		// class wrote is rejected rather than silently decoded into rubbish.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw ) {
			return '';
		}
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		if ( ! $iv_length || strlen( $raw ) <= $iv_length ) {
			return '';
		}
		$plain = openssl_decrypt(
			substr( $raw, $iv_length ),
			self::CIPHER,
			self::derive_key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, $iv_length )
		);
		// A false here means the salts changed since the key was saved. The
		// settings screen reads that as "no key on file" and asks for it again,
		// which is the only honest recovery.
		return false === $plain ? '' : $plain;
	}
}
