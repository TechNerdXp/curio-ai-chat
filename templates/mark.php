<?php
/**
 * The Curio mark.
 *
 * An editorial bracketed ellipsis. In print, square brackets around an ellipsis
 * mean "text omitted from a quotation" — the universal signal that what you are
 * reading came from a source, and that the source has been respected. That is
 * the whole product in one piece of punctuation: it answers from what is inside
 * the brackets and will not step outside them.
 *
 * It is the same mark as the directory icon and the listing banner, drawn from
 * the same geometry rather than redrawn to match, so the plugin a site owner
 * installs looks like the plugin they clicked on.
 *
 * Literal markup and nothing else: no variable reaches output from this file,
 * which is why the callers can include it without escaping anything.
 *
 * @package Curio
 */

defined( 'ABSPATH' ) || exit;
?>
<svg class="curio-mark-svg" viewBox="0 0 64 64" fill="none" aria-hidden="true" focusable="false">
	<path d="M23 12H15a1 1 0 0 0-1 1v38a1 1 0 0 0 1 1h8" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />
	<path d="M41 12h8a1 1 0 0 1 1 1v38a1 1 0 0 1-1 1h-8" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />
	<circle cx="24.2" cy="32" r="3.5" fill="currentColor" />
	<circle cx="32" cy="32" r="3.5" fill="var(--curio-mark-accent, #e9622d)" />
	<circle cx="39.8" cy="32" r="3.5" fill="currentColor" />
</svg>
