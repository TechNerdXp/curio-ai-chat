<?php
/**
 * Assistant tab.
 *
 * @package Curio
 */

namespace Curio\Admin;

use Curio\Options;

defined( 'ABSPATH' ) || exit;

$curio_pages = wp_dropdown_pages(
	array(
		'echo'              => 0,
		'name'              => Admin::name( 'handoff_page' ),
		'id'                => 'curio-handoff-page',
		'selected'          => Options::number( 'handoff_page' ),
		'show_option_none'  => __( 'Choose a page…', 'curio-ai-chat' ),
		'option_none_value' => '0',
	)
);
?>

<form method="post" action="options.php" class="curio-grid" data-curio-assistant>
	<?php
	settings_fields( Admin::GROUP );
	Admin::fields(
		array(
			'business_name',
			'header_subtitle',
			'welcome_message',
			'contact_line',
			'persona',
			'input_placeholder',
			'handoff_type',
			'handoff_page',
			'handoff_email',
			'handoff_url',
			'handoff_label',
			'handoff_always',
			'greeting_bubble',
			'greeting_bubble_text',
		)
	);
	?>

	<div class="curio-card">
		<h2><?php esc_html_e( 'Who the assistant is', 'curio-ai-chat' ); ?></h2>
		<p class="curio-lede">
			<?php esc_html_e( 'Nothing here is filled in for you. The plugin names no business anywhere in its code, so if you leave a field blank the widget simply says less rather than claiming something on your behalf.', 'curio-ai-chat' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="curio-business"><?php esc_html_e( 'Business name', 'curio-ai-chat' ); ?></label></th>
				<td>
					<input type="text" id="curio-business" class="regular-text" name="<?php echo esc_attr( Admin::name( 'business_name' ) ); ?>" value="<?php echo esc_attr( Options::text( 'business_name' ) ); ?>" maxlength="120" />
					<p class="description"><?php esc_html_e( 'Shown in the chat header and used in the greeting. Leave blank and the header just says "Chat".', 'curio-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="curio-subtitle"><?php esc_html_e( 'Header subtitle', 'curio-ai-chat' ); ?></label></th>
				<td>
					<input type="text" id="curio-subtitle" class="regular-text" name="<?php echo esc_attr( Admin::name( 'header_subtitle' ) ); ?>" value="<?php echo esc_attr( Options::text( 'header_subtitle' ) ); ?>" maxlength="120" placeholder="<?php esc_attr_e( 'Ask us anything', 'curio-ai-chat' ); ?>" />
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="curio-welcome"><?php esc_html_e( 'Opening line', 'curio-ai-chat' ); ?></label></th>
				<td>
					<textarea id="curio-welcome" class="large-text" rows="2" name="<?php echo esc_attr( Admin::name( 'welcome_message' ) ); ?>" maxlength="300"><?php echo esc_textarea( Options::text( 'welcome_message' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'The first thing a visitor reads, and what the assistant says back to somebody who opens with "hello" rather than a question. Leave blank for a plain greeting that uses your business name if you set one.', 'curio-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="curio-persona"><?php esc_html_e( 'Tone', 'curio-ai-chat' ); ?></label></th>
				<td>
					<textarea id="curio-persona" class="large-text" rows="4" name="<?php echo esc_attr( Admin::name( 'persona' ) ); ?>" maxlength="1500" placeholder="<?php esc_attr_e( 'Warm and unfussy. Use British spelling. Never use exclamation marks.', 'curio-ai-chat' ); ?>"><?php echo esc_textarea( Options::text( 'persona' ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Optional instructions about voice and style. The rules that stop the assistant inventing prices, dates and contact details are added on top of whatever you write here and cannot be edited away — a tone box that can accidentally delete "never invent a price" would be a trap, not a feature.', 'curio-ai-chat' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="curio-placeholder"><?php esc_html_e( 'Input placeholder', 'curio-ai-chat' ); ?></label></th>
				<td>
					<input type="text" id="curio-placeholder" class="regular-text" name="<?php echo esc_attr( Admin::name( 'input_placeholder' ) ); ?>" value="<?php echo esc_attr( Options::text( 'input_placeholder' ) ); ?>" maxlength="120" placeholder="<?php esc_attr_e( 'Ask a question…', 'curio-ai-chat' ); ?>" />
				</td>
			</tr>
		</table>
	</div>

	<div class="curio-card">
		<h2><?php esc_html_e( 'When it cannot help', 'curio-ai-chat' ); ?></h2>
		<p class="curio-lede">
			<?php esc_html_e( 'An assistant that will not guess declines more often than one that will. That is the trade this plugin is built around, and it makes the moment straight after a decline the most valuable screen in the widget. Two settings cover it: what the assistant says, and what the customer can press.', 'curio-ai-chat' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="curio-contact"><?php esc_html_e( 'Hand-off line', 'curio-ai-chat' ); ?></label></th>
				<td>
					<input type="text" id="curio-contact" class="large-text" name="<?php echo esc_attr( Admin::name( 'contact_line' ) ); ?>" value="<?php echo esc_attr( Options::text( 'contact_line' ) ); ?>" maxlength="300" placeholder="<?php esc_attr_e( 'Email hello@example.com or call 01234 567890 and we will come straight back to you.', 'curio-ai-chat' ); ?>" />
					<p class="description">
						<strong><?php esc_html_e( 'The single most valuable field on this page.', 'curio-ai-chat' ); ?></strong>
						<?php esc_html_e( 'This is what the assistant says when it cannot help. Without it, a customer with a question you have not covered gets a dead end instead of your phone number.', 'curio-ai-chat' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Offer a person', 'curio-ai-chat' ); ?></th>
				<td>
					<fieldset class="curio-segmented">
						<legend class="screen-reader-text"><?php esc_html_e( 'Where the button goes', 'curio-ai-chat' ); ?></legend>
						<?php
						foreach ( array(
							'none'  => __( 'No button', 'curio-ai-chat' ),
							'page'  => __( 'A page on this site', 'curio-ai-chat' ),
							'email' => __( 'An email address', 'curio-ai-chat' ),
							'url'   => __( 'Another web address', 'curio-ai-chat' ),
						) as $curio_value => $curio_label ) :
							?>
							<label>
								<input type="radio" name="<?php echo esc_attr( Admin::name( 'handoff_type' ) ); ?>" value="<?php echo esc_attr( $curio_value ); ?>" <?php checked( Options::text( 'handoff_type' ), $curio_value ); ?> />
								<span><?php echo esc_html( $curio_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'A button inside the chat, shown the first time the assistant has to say no. The hand-off line above tells somebody how to reach you; this saves them writing the address down and starting again somewhere else.', 'curio-ai-chat' ); ?>
					</p>
				</td>
			</tr>

			<tr data-curio-when="handoff_type:page">
				<th scope="row"><label for="curio-handoff-page"><?php esc_html_e( 'Contact page', 'curio-ai-chat' ); ?></label></th>
				<td>
					<?php if ( '' !== $curio_pages ) : ?>
						<?php
						echo wp_kses(
							$curio_pages,
							array(
								'select' => array(
									'name'  => true,
									'id'    => true,
									'class' => true,
								),
								'option' => array(
									'value'    => true,
									'selected' => true,
									'class'    => true,
								),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Unpublish that page later and the button quietly stops appearing, rather than sending somebody who has already been told "I cannot help" to a missing page.', 'curio-ai-chat' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'This site has no published pages yet. Publish your contact page, or use an email address instead.', 'curio-ai-chat' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>

			<tr data-curio-when="handoff_type:email">
				<th scope="row"><label for="curio-handoff-email"><?php esc_html_e( 'Email address', 'curio-ai-chat' ); ?></label></th>
				<td>
					<input type="email" id="curio-handoff-email" class="regular-text" name="<?php echo esc_attr( Admin::name( 'handoff_email' ) ); ?>" value="<?php echo esc_attr( Options::text( 'handoff_email' ) ); ?>" placeholder="hello@example.com" />
					<p class="description"><?php esc_html_e( 'Opens the visitor\'s own mail application with the address already filled in.', 'curio-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr data-curio-when="handoff_type:url">
				<th scope="row"><label for="curio-handoff-url"><?php esc_html_e( 'Web address', 'curio-ai-chat' ); ?></label></th>
				<td>
					<input type="url" id="curio-handoff-url" class="large-text" name="<?php echo esc_attr( Admin::name( 'handoff_url' ) ); ?>" value="<?php echo esc_attr( Options::text( 'handoff_url' ) ); ?>" placeholder="https://" />
					<p class="description"><?php esc_html_e( 'A help desk, a booking calendar, a messaging link — anywhere a real person is waiting.', 'curio-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr data-curio-when="handoff_type:page|email|url">
				<th scope="row"><label for="curio-handoff-label"><?php esc_html_e( 'Button text', 'curio-ai-chat' ); ?></label></th>
				<td>
					<input type="text" id="curio-handoff-label" class="regular-text" name="<?php echo esc_attr( Admin::name( 'handoff_label' ) ); ?>" value="<?php echo esc_attr( Options::text( 'handoff_label' ) ); ?>" maxlength="40" placeholder="<?php esc_attr_e( 'Talk to a person', 'curio-ai-chat' ); ?>" />
				</td>
			</tr>

			<tr data-curio-when="handoff_type:page|email|url">
				<th scope="row"><?php esc_html_e( 'When to show it', 'curio-ai-chat' ); ?></th>
				<td>
					<?php
					Admin::checkbox(
						'handoff_always',
						__( 'Show the button from the start', 'curio-ai-chat' ),
						__( 'Left off, it appears the first time the assistant cannot answer, which is when it is worth most. Turn it on if reaching you quickly matters more than trying the assistant first.', 'curio-ai-chat' )
					);
					?>
					<p class="description"><?php esc_html_e( 'Only applies once the button has somewhere to go.', 'curio-ai-chat' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<div class="curio-card">
		<h2><?php esc_html_e( 'Nudge', 'curio-ai-chat' ); ?></h2>
		<?php
		Admin::checkbox(
			'greeting_bubble',
			__( 'Show a small message beside the launcher', 'curio-ai-chat' ),
			__( 'Appears a few seconds after the page settles, and only until the visitor dismisses it or opens the chat.', 'curio-ai-chat' )
		);
		?>
		<p class="curio-field">
			<label for="curio-teaser-text"><?php esc_html_e( 'Nudge text', 'curio-ai-chat' ); ?></label>
			<input type="text" id="curio-teaser-text" class="large-text" name="<?php echo esc_attr( Admin::name( 'greeting_bubble_text' ) ); ?>" value="<?php echo esc_attr( Options::text( 'greeting_bubble_text' ) ); ?>" maxlength="140" placeholder="<?php esc_attr_e( 'Questions about pricing or availability? Ask away.', 'curio-ai-chat' ); ?>" />
		</p>
	</div>

	<?php submit_button( __( 'Save assistant settings', 'curio-ai-chat' ) ); ?>
</form>
