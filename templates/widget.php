<?php
/**
 * Front-end widget markup.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

$curio_title    = Widget::header_title();
$curio_subtitle = Widget::header_subtitle();
$curio_avatar   = Widget::avatar_html();
$curio_launcher = Widget::launcher_label();
$curio_position = Options::text( 'position' );
$curio_style    = Options::text( 'launcher_style' );
$curio_greeting = Options::flag( 'greeting_bubble' ) ? Options::text( 'greeting_bubble_text' ) : '';

// Rendered as a real link in the markup rather than built in JavaScript, so it
// works on a page whose script has failed and is announced by a screen reader
// as the link it is.
$curio_handoff  = Handoff::url();
?>
<div
	id="curio-widget"
	class="curio-widget curio-pos-<?php echo esc_attr( $curio_position ); ?> curio-launcher-<?php echo esc_attr( $curio_style ); ?>"
	data-curio-state="closed"
>
<?php if ( '' !== $curio_greeting ) : ?>
		<div class="curio-teaser" data-curio-teaser hidden>
			<button type="button" class="curio-teaser-dismiss" data-curio-teaser-dismiss aria-label="<?php esc_attr_e( 'Dismiss message', 'curio-ai-chat' ); ?>">
				<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
			</button>
			<p class="curio-teaser-text"><?php echo esc_html( $curio_greeting ); ?></p>
		</div>
<?php endif; ?>

	<div class="curio-panel" id="curio-panel" role="dialog" aria-modal="false" aria-labelledby="curio-panel-title" hidden>
		<div class="curio-header">
			<div class="curio-identity">
				<span class="curio-avatar" aria-hidden="true">
<?php if ( '' !== $curio_avatar ) : ?>
						<?php echo wp_kses_post( $curio_avatar ); ?>
<?php else : ?>
						<svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
							<path d="M21 12a8.5 8.5 0 0 1-12.9 7.3L3.5 20.5l1.2-4.6A8.5 8.5 0 1 1 21 12Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
							<circle cx="8.6" cy="12" r="1" fill="currentColor"/>
							<circle cx="12" cy="12" r="1" fill="currentColor"/>
							<circle cx="15.4" cy="12" r="1" fill="currentColor"/>
						</svg>
<?php endif; ?>
				</span>
				<span class="curio-identity-text">
					<span class="curio-title" id="curio-panel-title"><?php echo esc_html( $curio_title ); ?></span>
<?php if ( '' !== $curio_subtitle ) : ?>
						<span class="curio-subtitle"><?php echo esc_html( $curio_subtitle ); ?></span>
<?php endif; ?>
				</span>
			</div>

			<div class="curio-header-actions">
				<button type="button" class="curio-icon-button" data-curio-reset aria-label="<?php esc_attr_e( 'Clear this conversation', 'curio-ai-chat' ); ?>">
					<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 6h12M8 6V4.6A1.6 1.6 0 0 1 9.6 3h0.8A1.6 1.6 0 0 1 12 4.6V6M6.6 6l.6 9.1a1.5 1.5 0 0 0 1.5 1.4h2.6a1.5 1.5 0 0 0 1.5-1.4L13.4 6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</button>
				<?php
				/*
				 * Two icons, not one. Arrows out means "this will expand"; the
				 * same arrows still pointing out once expanded would be telling
				 * the visitor the opposite of what pressing it does. The label
				 * already swaps; the picture has to as well.
				 */
				?>
				<button type="button" class="curio-icon-button" data-curio-expand aria-label="<?php esc_attr_e( 'Expand chat', 'curio-ai-chat' ); ?>">
					<svg class="curio-expand-out" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M12 3h5v5M8 17H3v-5M17 3l-6 6M3 17l6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
					<svg class="curio-expand-in" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M17 8h-5V3M3 12h5v5M12 8l5-5M8 12l-5 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</button>
				<button type="button" class="curio-icon-button" data-curio-close aria-label="<?php esc_attr_e( 'Close chat', 'curio-ai-chat' ); ?>">
					<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
				</button>
			</div>
		</div>

		<div
			class="curio-log"
			data-curio-log
			role="log"
			aria-live="polite"
			aria-relevant="additions text"
			aria-label="<?php esc_attr_e( 'Conversation', 'curio-ai-chat' ); ?>"
			tabindex="0"
		></div>

		<?php
		/*
		 * Emptying the log is a removal, and removals are not announced. A
		 * screen-reader user who has just cleared a conversation would
		 * otherwise be told nothing at all and have no way to know whether it
		 * worked or the widget broke.
		 */
		?>
		<p class="curio-visually-hidden" data-curio-status role="status"></p>

		<p class="curio-typing" data-curio-typing hidden>
			<span class="curio-typing-dots" aria-hidden="true"><span></span><span></span><span></span></span>
			<span class="curio-visually-hidden"><?php esc_html_e( 'Assistant is typing', 'curio-ai-chat' ); ?></span>
		</p>

<?php if ( '' !== $curio_handoff ) : ?>
			<?php
			/*
			 * A live region, because the script reveals this row by clearing
			 * `hidden` and a screen-reader user reading the conversation would
			 * otherwise never learn that a way out had appeared. Revealing
			 * hidden content counts as an addition, so it is announced once,
			 * after the decline that prompted it.
			 */
			?>
			<div class="curio-handoff" data-curio-handoff aria-live="polite" <?php echo esc_attr( Handoff::always() ? '' : 'hidden' ); ?>>
				<a class="curio-handoff-link" href="<?php echo esc_url( $curio_handoff ); ?>"<?php if ( Handoff::opens_new_tab() ) : ?> target="_blank" rel="noopener"<?php endif; ?>>
					<span class="curio-handoff-icon" aria-hidden="true">
						<svg viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
							<path d="M4.4 10.6V10a5.6 5.6 0 0 1 11.2 0v0.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
							<rect x="2.6" y="10.4" width="3.3" height="5" rx="1.65" stroke="currentColor" stroke-width="1.5"/>
							<rect x="14.1" y="10.4" width="3.3" height="5" rx="1.65" stroke="currentColor" stroke-width="1.5"/>
							<path d="M15.8 15.4v0.5a2 2 0 0 1-2 2h-2.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
						</svg>
					</span>
					<span class="curio-handoff-text"><?php echo esc_html( Handoff::label() ); ?></span>
<?php if ( Handoff::opens_new_tab() ) : ?>
						<span class="curio-visually-hidden"><?php esc_html_e( '(opens in a new tab)', 'curio-ai-chat' ); ?></span>
<?php endif; ?>
				</a>
			</div>
<?php endif; ?>

		<form class="curio-composer" data-curio-form>
			<label class="curio-visually-hidden" for="curio-input"><?php esc_html_e( 'Your message', 'curio-ai-chat' ); ?></label>
			<textarea
				id="curio-input"
				class="curio-input"
				data-curio-input
				rows="1"
				autocomplete="off"
				maxlength="<?php echo esc_attr( (string) Options::number( 'max_question_length' ) ); ?>"
				placeholder="<?php echo esc_attr( Options::text( 'input_placeholder' ) !== '' ? Options::text( 'input_placeholder' ) : __( 'Ask a question…', 'curio-ai-chat' ) ); ?>"
			></textarea>
			<button type="submit" class="curio-send" data-curio-send aria-label="<?php esc_attr_e( 'Send message', 'curio-ai-chat' ); ?>">
				<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M3 10 17 3l-4 14-3.2-5.2L3 10Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
			</button>
		</form>

		<?php
		/*
		 * Guideline 10: a "powered by" credit has to be opt-in and off by
		 * default. It is, and this is the only place in the plugin that can
		 * put the author's name in front of a site visitor at all.
		 */
		if ( Options::flag( 'show_credit' ) ) :
			?>
			<p class="curio-credit">
				<a href="<?php echo esc_url( CURIO_AUTHOR_URL ); ?>" target="_blank" rel="noopener nofollow">
					<?php esc_html_e( 'Chat by Curio', 'curio-ai-chat' ); ?>
				</a>
			</p>
<?php endif; ?>
	</div>

	<button
		type="button"
		class="curio-launcher"
		data-curio-toggle
		aria-expanded="false"
		aria-controls="curio-panel"
		aria-label="<?php echo esc_attr( $curio_launcher ); ?>"
	>
		<span class="curio-launcher-icon curio-launcher-open" aria-hidden="true">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
				<path d="M21 12a8.5 8.5 0 0 1-12.9 7.3L3.5 20.5l1.2-4.6A8.5 8.5 0 1 1 21 12Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
			</svg>
		</span>
<?php if ( 'pill' === $curio_style ) : ?>
			<span class="curio-launcher-text"><?php echo esc_html( $curio_launcher ); ?></span>
<?php endif; ?>
	</button>
</div>
