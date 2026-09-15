<?php
/**
 * Settings screen footer.
 *
 * @package Curio
 */

namespace Curio\Admin;

defined( 'ABSPATH' ) || exit;
?>
	</div><!-- .curio-body -->

	<?php
	/*
	 * Guideline 10 is about credits shown to site *visitors*, which must be
	 * opt-in and default to off — and the front-end badge that does that is a
	 * setting, switched off, on the Appearance tab. This is the plugin's own
	 * settings screen, seen only by an administrator who deliberately navigated
	 * here, which is where a developer is allowed to sign their work.
	 */
	?>
	<p class="curio-colophon">
		<?php
		printf(
			/* translators: %s: link to the developer's freelance profile. */
			esc_html__( 'Curio is built and maintained by TechNerdXp. %s', 'curio-ai-chat' ),
			'<a href="' . esc_url( CURIO_AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'Need it customised, integrated, or built into something bigger? Hire me on Upwork.', 'curio-ai-chat' )
				. '</a>'
		);
		?>
	</p>
</div><!-- .wrap -->
