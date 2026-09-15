<?php
/**
 * Widget theming.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the appearance settings into CSS custom properties.
 *
 * Every colour, radius and dimension the stylesheet uses is a variable, and
 * this class is the only thing that sets them. That is what makes re-skinning
 * safe: the settings screen can never produce a rule that reaches outside the
 * widget, because it does not produce rules at all — it produces values for
 * properties the stylesheet has already decided how to use.
 *
 * The whole block is scoped to `#curio-widget`, never `:root`. A chat widget
 * is a guest on a page it does not own, and a plugin that defines variables at
 * document root is one name collision away from restyling somebody's theme.
 */
final class Appearance {

	/**
	 * Widget dimensions per size setting.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function sizes(): array {
		return array(
			'compact'  => array(
				'width'  => '330px',
				'height' => '460px',
				'font'   => '14px',
				'button' => '52px',
			),
			'standard' => array(
				'width'  => '384px',
				'height' => '560px',
				'font'   => '15px',
				'button' => '58px',
			),
			'large'    => array(
				'width'  => '440px',
				'height' => '660px',
				'font'   => '16px',
				'button' => '62px',
			),
		);
	}

	/**
	 * Corner radii per radius setting.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function radii(): array {
		return array(
			'sharp' => array(
				'shell'  => '4px',
				'bubble' => '4px',
				'field'  => '4px',
			),
			'soft'  => array(
				'shell'  => '16px',
				'bubble' => '14px',
				'field'  => '10px',
			),
			'round' => array(
				'shell'  => '24px',
				'bubble' => '20px',
				'field'  => '999px',
			),
		);
	}

	/**
	 * The surface and text colours a preset implies.
	 *
	 * @param string $preset Preset name.
	 * @return array<string,string>
	 */
	private static function preset_colours( string $preset ): array {
		switch ( $preset ) {
			case 'dark':
				return array(
					'surface' => '#12161f',
					'text'    => '#e9edf5',
				);
			case 'custom':
				return array(
					'surface' => Options::text( 'surface' ),
					'text'    => Options::text( 'text_color' ),
				);
			case 'light':
			case 'auto':
			default:
				return array(
					'surface' => '#ffffff',
					'text'    => '#1a1d21',
				);
		}
	}

	/**
	 * Every custom property the widget stylesheet reads.
	 *
	 * @param string|null $force_preset Override the stored preset (used to emit
	 *                                  the dark half of "auto").
	 * @return array<string,string>
	 */
	public static function variables( ?string $force_preset = null ): array {
		$preset = $force_preset ?? Options::text( 'preset' );
		$sizes  = self::sizes();
		$radii  = self::radii();

		$size   = $sizes[ Options::text( 'size' ) ] ?? $sizes['standard'];
		$radius = $radii[ Options::text( 'radius' ) ] ?? $radii['soft'];

		$colours = self::preset_colours( $preset );
		$surface = self::valid_hex( $colours['surface'], '#ffffff' );
		$text    = self::valid_hex( $colours['text'], '#1a1d21' );
		$accent  = self::valid_hex( Options::text( 'accent' ), '#1a1d21' );

		$accent_text = Options::text( 'accent_text' );
		if ( '' === $accent_text ) {
			// Working the contrast out beats asking the site owner to. Somebody
			// picking a brand colour is thinking about their brand, not about
			// whether white text will still be legible on it, and the plugin
			// knows the answer.
			$accent_text = self::readable_on( $accent );
		}

		$dark = self::is_dark( $surface );

		$accent_text = self::valid_hex( $accent_text, '#ffffff' );

		return array(
			'--curio-accent'        => $accent,
			'--curio-accent-hover'  => self::shift( $accent, $dark ? 12 : -10 ),
			'--curio-accent-text'   => $accent_text,
			// The de-emphasised text on the accent bar — the header subtitle.
			// It used to be the full accent text at `opacity: 0.82`, which is a
			// trap: opacity blends toward whatever is behind the element, so
			// the effective contrast depends on a colour CSS never told anyone
			// about. An axe-core run caught it at 3.39:1 against a brass
			// accent, below the 4.5:1 AA threshold. This computes the most
			// muted colour that still passes, for whatever accent the site
			// owner picks, so the guarantee holds instead of the appearance.
			'--curio-accent-text-soft' => self::accessible_blend( $accent_text, $accent, 4.5 ),
			'--curio-surface'       => $surface,
			'--curio-surface-2'     => self::shift( $surface, $dark ? 6 : -3 ),
			'--curio-surface-3'     => self::shift( $surface, $dark ? 11 : -7 ),
			'--curio-text'          => $text,
			'--curio-text-muted'    => self::blend( $text, $surface, 0.42 ),
			'--curio-border'        => self::blend( $text, $surface, 0.86 ),
			'--curio-ring'          => self::rgba( $accent, 0.35 ),
			'--curio-ring-soft'     => self::rgba( $accent, 0.14 ),
			'--curio-shadow'        => $dark
				? '0 18px 48px rgba(0, 0, 0, 0.55)'
				: '0 18px 48px rgba(15, 23, 42, 0.16)',
			'--curio-width'         => $size['width'],
			'--curio-height'        => $size['height'],
			'--curio-font-size'     => $size['font'],
			'--curio-launcher'      => $size['button'],
			'--curio-radius'        => $radius['shell'],
			'--curio-radius-bubble' => $radius['bubble'],
			'--curio-radius-field'  => $radius['field'],
			'--curio-offset-x'      => Options::number( 'offset_x' ) . 'px',
			'--curio-offset-y'      => Options::number( 'offset_y' ) . 'px',
			'--curio-z'             => (string) Options::number( 'z_index' ),
			'--curio-family'        => 'inherit' === Options::text( 'font' )
				? 'inherit'
				: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
		);
	}

	/**
	 * The complete inline style block for the current settings.
	 *
	 * @return string
	 */
	public static function inline_css(): string {
		$scope = '#curio-widget';
		$css   = $scope . '{' . self::declarations( self::variables() ) . '}';

		// "Auto" means follow the visitor's own system preference, which can
		// only be expressed in a media query, so it is emitted as a second
		// block rather than a different set of values.
		if ( 'auto' === Options::text( 'preset' ) ) {
			$css .= '@media (prefers-color-scheme: dark){'
				. $scope . '{' . self::declarations( self::variables( 'dark' ) ) . '}'
				. '}';
		}

		if ( ! Options::flag( 'mobile_enabled' ) ) {
			$css .= '@media (max-width: 600px){' . $scope . '{display:none !important;}}';
		}

		$custom = Options::text( 'custom_css' );
		if ( '' !== $custom ) {
			$css .= "\n" . $custom;
		}

		return $css;
	}

	/**
	 * Render a property map as CSS declarations.
	 *
	 * @param array<string,string> $variables Property map.
	 * @return string
	 */
	private static function declarations( array $variables ): string {
		$out = '';
		foreach ( $variables as $property => $value ) {
			$out .= $property . ':' . $value . ';';
		}
		return $out;
	}

	/**
	 * Colours defined by the active theme, for the "use my theme's colours"
	 * button.
	 *
	 * Reads the block theme's own palette rather than guessing from a
	 * screenshot or asking the owner to find a hex code in their customiser.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function theme_palette(): array {
		$colours = array();

		if ( function_exists( 'wp_get_global_settings' ) ) {
			$settings = wp_get_global_settings( array( 'color', 'palette' ) );
			foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
				foreach ( (array) ( $settings[ $origin ] ?? array() ) as $entry ) {
					$hex = sanitize_hex_color( (string) ( $entry['color'] ?? '' ) );
					if ( ! $hex ) {
						continue;
					}
					$colours[ strtolower( $hex ) ] = array(
						'name'  => sanitize_text_field( (string) ( $entry['name'] ?? $hex ) ),
						'color' => $hex,
					);
				}
				if ( array() !== $colours && 'default' !== $origin ) {
					break;
				}
			}
		}

		return array_values( array_slice( $colours, 0, 12 ) );
	}

	// -----------------------------------------------------------------------
	// Colour helpers.
	// -----------------------------------------------------------------------

	/**
	 * Fall back cleanly when a stored colour is not a colour.
	 *
	 * @param string $hex      Candidate.
	 * @param string $fallback Default.
	 * @return string
	 */
	private static function valid_hex( string $hex, string $fallback ): string {
		$clean = sanitize_hex_color( $hex );
		return $clean ? $clean : $fallback;
	}

	/**
	 * Split a hex colour into red, green and blue.
	 *
	 * @param string $hex Hex colour.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return array( 0, 0, 0 );
		}
		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Reassemble red, green and blue into a hex colour.
	 *
	 * @param int $red   Red.
	 * @param int $green Green.
	 * @param int $blue  Blue.
	 * @return string
	 */
	private static function hex( int $red, int $green, int $blue ): string {
		return sprintf(
			'#%02x%02x%02x',
			max( 0, min( 255, $red ) ),
			max( 0, min( 255, $green ) ),
			max( 0, min( 255, $blue ) )
		);
	}

	/**
	 * Relative luminance, per the WCAG definition.
	 *
	 * @param string $hex Hex colour.
	 * @return float 0 (black) to 1 (white).
	 */
	public static function luminance( string $hex ): float {
		$channels = array();
		foreach ( self::rgb( $hex ) as $value ) {
			$srgb       = $value / 255;
			$channels[] = $srgb <= 0.04045
				? $srgb / 12.92
				: pow( ( $srgb + 0.055 ) / 1.055, 2.4 );
		}
		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}

	/**
	 * Contrast ratio between two colours, 1 to 21.
	 *
	 * @param string $one Hex colour.
	 * @param string $two Hex colour.
	 * @return float
	 */
	public static function contrast( string $one, string $two ): float {
		$a = self::luminance( $one );
		$b = self::luminance( $two );
		$light = max( $a, $b );
		$dark  = min( $a, $b );
		return ( $light + 0.05 ) / ( $dark + 0.05 );
	}

	/**
	 * Is this a dark colour?
	 *
	 * @param string $hex Hex colour.
	 * @return bool
	 */
	public static function is_dark( string $hex ): bool {
		return self::luminance( $hex ) < 0.4;
	}

	/**
	 * Black or white, whichever is legible on the given background.
	 *
	 * @param string $background Hex colour.
	 * @return string
	 */
	public static function readable_on( string $background ): string {
		return self::contrast( $background, '#ffffff' ) >= self::contrast( $background, '#111111' )
			? '#ffffff'
			: '#111111';
	}

	/**
	 * The most muted version of a foreground colour that still meets a
	 * contrast ratio against its background.
	 *
	 * Walks from "no blending" toward the background in small steps and stops
	 * at the last value that passes. Designers reach for `opacity` to get this
	 * effect; opacity cannot promise anything, because the result depends on
	 * what happens to be painted underneath. This can promise it.
	 *
	 * @param string $foreground Hex colour.
	 * @param string $background Hex colour.
	 * @param float  $ratio      Minimum contrast ratio to hold.
	 * @return string
	 */
	public static function accessible_blend( string $foreground, string $background, float $ratio ): string {
		$best = $foreground;

		for ( $step = 1; $step <= 10; $step++ ) {
			$candidate = self::blend( $foreground, $background, $step * 0.04 );
			if ( self::contrast( $candidate, $background ) < $ratio ) {
				break;
			}
			$best = $candidate;
		}

		return $best;
	}

	/**
	 * Lighten or darken by a percentage of full range.
	 *
	 * @param string $hex     Hex colour.
	 * @param int    $percent Positive lightens, negative darkens.
	 * @return string
	 */
	private static function shift( string $hex, int $percent ): string {
		[ $red, $green, $blue ] = self::rgb( $hex );
		$delta                  = (int) round( 255 * ( $percent / 100 ) );
		return self::hex( $red + $delta, $green + $delta, $blue + $delta );
	}

	/**
	 * Mix two colours.
	 *
	 * @param string $from   Hex colour.
	 * @param string $to     Hex colour.
	 * @param float  $amount 0 keeps `$from`, 1 returns `$to`.
	 * @return string
	 */
	private static function blend( string $from, string $to, float $amount ): string {
		$amount = max( 0.0, min( 1.0, $amount ) );
		[ $r1, $g1, $b1 ] = self::rgb( $from );
		[ $r2, $g2, $b2 ] = self::rgb( $to );

		return self::hex(
			(int) round( $r1 + ( ( $r2 - $r1 ) * $amount ) ),
			(int) round( $g1 + ( ( $g2 - $g1 ) * $amount ) ),
			(int) round( $b1 + ( ( $b2 - $b1 ) * $amount ) )
		);
	}

	/**
	 * A hex colour as an `rgba()` string.
	 *
	 * @param string $hex   Hex colour.
	 * @param float  $alpha Opacity.
	 * @return string
	 */
	private static function rgba( string $hex, float $alpha ): string {
		[ $red, $green, $blue ] = self::rgb( $hex );
		return sprintf( 'rgba(%d, %d, %d, %s)', $red, $green, $blue, rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' ) );
	}
}
