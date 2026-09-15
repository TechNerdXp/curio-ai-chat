<?php
/**
 * Provider registry.
 *
 * @package Curio
 */

namespace Curio\Providers;

use Curio\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that knows which providers exist.
 */
final class Registry {

	/**
	 * Instantiated providers, keyed by slug.
	 *
	 * @var array<string,Provider_Interface>|null
	 */
	private static $providers = null;

	/**
	 * Every provider the plugin ships with.
	 *
	 * @return array<string,Provider_Interface>
	 */
	public static function all(): array {
		if ( null === self::$providers ) {
			$providers = array(
				'demo'      => new Demo(),
				'anthropic' => new Anthropic(),
				'openai'    => new OpenAI(),
				'gemini'    => new Gemini(),
			);

			/**
			 * Filter the available providers.
			 *
			 * Anything added here must implement Provider_Interface. This is
			 * the supported way to point the plugin at a self-hosted model or
			 * an OpenAI-compatible gateway without forking it.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string,Provider_Interface> $providers Keyed by slug.
			 */
			$providers = (array) apply_filters( 'curio_providers', $providers );

			self::$providers = array();
			foreach ( $providers as $slug => $provider ) {
				if ( $provider instanceof Provider_Interface ) {
					self::$providers[ (string) $slug ] = $provider;
				}
			}
		}

		return self::$providers;
	}

	/**
	 * One provider by slug.
	 *
	 * @param string $slug Provider slug.
	 * @return Provider_Interface|null
	 */
	public static function get( string $slug ): ?Provider_Interface {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * The provider the site is configured to use.
	 *
	 * Falls back to demo mode rather than erroring: an unrecognised setting
	 * should make the widget cost nothing, not make it break.
	 *
	 * @return Provider_Interface
	 */
	public static function current(): Provider_Interface {
		$provider = self::get( Options::text( 'provider' ) );
		return $provider ?? self::all()['demo'];
	}

	/**
	 * Providers that talk to an outside service, for the settings screen and
	 * the readme disclosure.
	 *
	 * @return array<string,Provider_Interface>
	 */
	public static function external(): array {
		$out = array();
		foreach ( self::all() as $slug => $provider ) {
			if ( 'demo' !== $slug ) {
				$out[ $slug ] = $provider;
			}
		}
		return $out;
	}
}
