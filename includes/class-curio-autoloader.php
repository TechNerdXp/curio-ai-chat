<?php
/**
 * Class autoloader.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `Curio\Sub\Name` to `includes/sub/class-curio-name.php`.
 *
 * A hand-written loader rather than Composer's: shipping a vendor directory to
 * load fourteen files of our own is weight the review team has to read through
 * and the user has to download, and Composer's autoloader is one more
 * third-party dependency to keep current under guideline 13.
 */
final class Autoloader {

	/**
	 * Register the loader with SPL.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve and require a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );

		$directory = CURIO_DIR . 'includes/';
		foreach ( $parts as $part ) {
			$directory .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}

		$file_base = strtolower( str_replace( '_', '-', $short ) );

		// Interfaces are named `Foo_Interface` and live in `interface-curio-foo.php`.
		if ( '-interface' === substr( $file_base, -10 ) ) {
			$candidates = array( 'interface-curio-' . substr( $file_base, 0, -10 ) . '.php' );
		} else {
			$candidates = array( 'class-curio-' . $file_base . '.php' );
		}

		foreach ( $candidates as $candidate ) {
			$path = $directory . $candidate;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
