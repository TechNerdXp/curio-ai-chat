<?php
/**
 * Appearance tab.
 *
 * @package Curio
 */

namespace Curio\Admin;

use Curio\Appearance;
use Curio\Options;

defined( 'ABSPATH' ) || exit;

$curio_palette = Appearance::theme_palette();
$curio_avatar  = Options::number( 'avatar_id' );
?>

<form method="post" action="options.php" data-curio-appearance>
	<?php
	settings_fields( Admin::GROUP );
	Admin::fields(
		array(
			'enabled',
			'preset',
			'accent',
			'accent_text',
			'surface',
			'text_color',
			'radius',
			'position',
			'offset_x',
			'offset_y',
			'size',
			'launcher_style',
			'launcher_label',
			'avatar_id',
			'font',
			'z_index',
			'custom_css',
			'display_mode',
			'display_ids',
			'hide_for_logged_in',
			'mobile_enabled',
			'show_credit',
		)
	);
	?>

	<div class="curio-grid curio-grid-preview">

		<div class="curio-card">
			<h2><?php esc_html_e( 'Skin', 'curio-ai-chat' ); ?></h2>
			<p class="curio-lede">
				<?php esc_html_e( 'Every value here becomes a CSS variable scoped to the widget. Nothing it produces can reach your theme, and nothing in the widget stylesheet hard-codes a colour — so a skin either works everywhere or nowhere, never halfway.', 'curio-ai-chat' ); ?>
			</p>

			<?php Admin::checkbox( 'enabled', __( 'Show the chat widget on the site', 'curio-ai-chat' ), __( 'Untick to hide it everywhere without deactivating the plugin or losing anything.', 'curio-ai-chat' ) ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Colour scheme', 'curio-ai-chat' ); ?></th>
					<td>
						<fieldset class="curio-segmented" data-curio-preset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Colour scheme', 'curio-ai-chat' ); ?></legend>
							<?php
							$curio_presets = array(
								'light'  => __( 'Light', 'curio-ai-chat' ),
								'dark'   => __( 'Dark', 'curio-ai-chat' ),
								'auto'   => __( 'Match the visitor', 'curio-ai-chat' ),
								'custom' => __( 'Custom', 'curio-ai-chat' ),
							);
							foreach ( $curio_presets as $curio_value => $curio_label ) :
								?>
								<label>
									<input type="radio" name="<?php echo esc_attr( Admin::name( 'preset' ) ); ?>" value="<?php echo esc_attr( $curio_value ); ?>" <?php checked( Options::text( 'preset' ), $curio_value ); ?> />
									<span><?php echo esc_html( $curio_label ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( '"Match the visitor" follows their operating system light or dark setting.', 'curio-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="curio-accent"><?php esc_html_e( 'Accent colour', 'curio-ai-chat' ); ?></label></th>
					<td>
						<input type="text" id="curio-accent" class="curio-color" data-curio-var="--curio-accent" name="<?php echo esc_attr( Admin::name( 'accent' ) ); ?>" value="<?php echo esc_attr( Options::text( 'accent' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'The launcher, the header and the visitor\'s own message bubbles. Text on top of it is chosen automatically for contrast unless you override it below.', 'curio-ai-chat' ); ?></p>

						<?php if ( array() !== $curio_palette ) : ?>
							<div class="curio-swatches">
								<span class="curio-swatch-label"><?php esc_html_e( 'From your theme:', 'curio-ai-chat' ); ?></span>
								<?php foreach ( $curio_palette as $curio_colour ) : ?>
									<button
										type="button"
										class="curio-swatch"
										data-curio-swatch="<?php echo esc_attr( $curio_colour['color'] ); ?>"
										style="background-color: <?php echo esc_attr( $curio_colour['color'] ); ?>"
										title="<?php echo esc_attr( $curio_colour['name'] . ' — ' . $curio_colour['color'] ); ?>"
									>
										<span class="screen-reader-text"><?php echo esc_html( $curio_colour['name'] ); ?></span>
									</button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="curio-accent-text"><?php esc_html_e( 'Text on the accent', 'curio-ai-chat' ); ?></label></th>
					<td>
						<input type="text" id="curio-accent-text" class="curio-color" data-curio-var="--curio-accent-text" name="<?php echo esc_attr( Admin::name( 'accent_text' ) ); ?>" value="<?php echo esc_attr( Options::text( 'accent_text' ) ); ?>" data-allow-empty="1" />
						<p class="description"><?php esc_html_e( 'Leave empty and the readable option is picked for you. Only set it if you are sure.', 'curio-ai-chat' ); ?></p>
						<p class="curio-contrast" data-curio-contrast role="status" aria-live="polite"></p>
					</td>
				</tr>

				<?php
				/*
				 * Light, dark and "match the visitor" each define their own
				 * panel and body colours, so these two controls do nothing at
				 * all unless the scheme is Custom. They are disabled rather
				 * than hidden: a control that vanishes leaves somebody hunting
				 * for the setting they remember, and a control that is present
				 * but does nothing is worse again. Disabled says which it is
				 * and what to change to get it back, and the stored value is
				 * kept either way.
				 */
				?>
				<tr data-curio-when="preset:custom">
					<th scope="row"><label for="curio-surface"><?php esc_html_e( 'Panel background', 'curio-ai-chat' ); ?></label></th>
					<td>
						<input type="text" id="curio-surface" class="curio-color" data-curio-var="--curio-surface" name="<?php echo esc_attr( Admin::name( 'surface' ) ); ?>" value="<?php echo esc_attr( Options::text( 'surface' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Used only by the Custom scheme. Light, Dark and "Match the visitor" bring their own.', 'curio-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr data-curio-when="preset:custom">
					<th scope="row"><label for="curio-text-color"><?php esc_html_e( 'Body text', 'curio-ai-chat' ); ?></label></th>
					<td>
						<input type="text" id="curio-text-color" class="curio-color" data-curio-var="--curio-text" name="<?php echo esc_attr( Admin::name( 'text_color' ) ); ?>" value="<?php echo esc_attr( Options::text( 'text_color' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Used only by the Custom scheme.', 'curio-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Corners', 'curio-ai-chat' ); ?></th>
					<td>
						<fieldset class="curio-segmented">
							<legend class="screen-reader-text"><?php esc_html_e( 'Corner style', 'curio-ai-chat' ); ?></legend>
							<?php
							foreach ( array(
								'sharp' => __( 'Sharp', 'curio-ai-chat' ),
								'soft'  => __( 'Soft', 'curio-ai-chat' ),
								'round' => __( 'Round', 'curio-ai-chat' ),
							) as $curio_value => $curio_label ) :
								?>
								<label>
									<input type="radio" name="<?php echo esc_attr( Admin::name( 'radius' ) ); ?>" value="<?php echo esc_attr( $curio_value ); ?>" <?php checked( Options::text( 'radius' ), $curio_value ); ?> data-curio-live="radius" />
									<span><?php echo esc_html( $curio_label ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Size', 'curio-ai-chat' ); ?></th>
					<td>
						<fieldset class="curio-segmented">
							<legend class="screen-reader-text"><?php esc_html_e( 'Widget size', 'curio-ai-chat' ); ?></legend>
							<?php
							foreach ( array(
								'compact'  => __( 'Compact', 'curio-ai-chat' ),
								'standard' => __( 'Standard', 'curio-ai-chat' ),
								'large'    => __( 'Large', 'curio-ai-chat' ),
							) as $curio_value => $curio_label ) :
								?>
								<label>
									<input type="radio" name="<?php echo esc_attr( Admin::name( 'size' ) ); ?>" value="<?php echo esc_attr( $curio_value ); ?>" <?php checked( Options::text( 'size' ), $curio_value ); ?> data-curio-live="size" />
									<span><?php echo esc_html( $curio_label ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Corner', 'curio-ai-chat' ); ?></th>
					<td>
						<fieldset class="curio-segmented">
							<legend class="screen-reader-text"><?php esc_html_e( 'Screen position', 'curio-ai-chat' ); ?></legend>
							<?php
							foreach ( array(
								'bottom-right' => __( 'Bottom right', 'curio-ai-chat' ),
								'bottom-left'  => __( 'Bottom left', 'curio-ai-chat' ),
							) as $curio_value => $curio_label ) :
								?>
								<label>
									<input type="radio" name="<?php echo esc_attr( Admin::name( 'position' ) ); ?>" value="<?php echo esc_attr( $curio_value ); ?>" <?php checked( Options::text( 'position' ), $curio_value ); ?> data-curio-live="position" />
									<span><?php echo esc_html( $curio_label ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="curio-inline-fields">
							<label for="curio-offset-x"><?php esc_html_e( 'Side gap', 'curio-ai-chat' ); ?></label>
							<input type="number" id="curio-offset-x" min="0" max="200" name="<?php echo esc_attr( Admin::name( 'offset_x' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'offset_x' ) ); ?>" /> px

							<label for="curio-offset-y"><?php esc_html_e( 'Bottom gap', 'curio-ai-chat' ); ?></label>
							<input type="number" id="curio-offset-y" min="0" max="200" name="<?php echo esc_attr( Admin::name( 'offset_y' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'offset_y' ) ); ?>" /> px
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Launcher', 'curio-ai-chat' ); ?></th>
					<td>
						<fieldset class="curio-segmented">
							<legend class="screen-reader-text"><?php esc_html_e( 'Launcher style', 'curio-ai-chat' ); ?></legend>
							<?php
							foreach ( array(
								'bubble' => __( 'Icon only', 'curio-ai-chat' ),
								'pill'   => __( 'Icon and label', 'curio-ai-chat' ),
							) as $curio_value => $curio_label ) :
								?>
								<label>
									<input type="radio" name="<?php echo esc_attr( Admin::name( 'launcher_style' ) ); ?>" value="<?php echo esc_attr( $curio_value ); ?>" <?php checked( Options::text( 'launcher_style' ), $curio_value ); ?> data-curio-live="launcher" />
									<span><?php echo esc_html( $curio_label ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="curio-field">
							<label for="curio-launcher-label"><?php esc_html_e( 'Label', 'curio-ai-chat' ); ?></label>
							<input type="text" id="curio-launcher-label" class="regular-text" name="<?php echo esc_attr( Admin::name( 'launcher_label' ) ); ?>" value="<?php echo esc_attr( Options::text( 'launcher_label' ) ); ?>" maxlength="40" placeholder="<?php esc_attr_e( 'Open chat', 'curio-ai-chat' ); ?>" data-curio-live="launcherLabel" />
							<span class="curio-hint"><?php esc_html_e( 'Also read out by screen readers, so keep it descriptive whichever style you choose.', 'curio-ai-chat' ); ?></span>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Avatar', 'curio-ai-chat' ); ?></th>
					<td>
						<div class="curio-avatar-picker">
							<span class="curio-avatar-preview" data-curio-avatar-preview>
								<?php
								if ( $curio_avatar > 0 ) {
									echo wp_kses_post( wp_get_attachment_image( $curio_avatar, array( 48, 48 ), false, array( 'alt' => '' ) ) );
								}
								?>
							</span>
							<input type="hidden" name="<?php echo esc_attr( Admin::name( 'avatar_id' ) ); ?>" value="<?php echo esc_attr( (string) $curio_avatar ); ?>" data-curio-avatar-id />
							<button type="button" class="button" data-curio-avatar-choose><?php esc_html_e( 'Choose image', 'curio-ai-chat' ); ?></button>
							<button type="button" class="button button-link-delete" data-curio-avatar-clear <?php echo esc_attr( $curio_avatar > 0 ? '' : 'hidden' ); ?>><?php esc_html_e( 'Remove', 'curio-ai-chat' ); ?></button>
						</div>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Typeface', 'curio-ai-chat' ); ?></th>
					<td>
						<fieldset class="curio-segmented">
							<legend class="screen-reader-text"><?php esc_html_e( 'Typeface', 'curio-ai-chat' ); ?></legend>
							<?php
							foreach ( array(
								'inherit' => __( 'Use my theme\'s font', 'curio-ai-chat' ),
								'system'  => __( 'System font', 'curio-ai-chat' ),
							) as $curio_value => $curio_label ) :
								?>
								<label>
									<input type="radio" name="<?php echo esc_attr( Admin::name( 'font' ) ); ?>" value="<?php echo esc_attr( $curio_value ); ?>" <?php checked( Options::text( 'font' ), $curio_value ); ?> />
									<span><?php echo esc_html( $curio_label ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
			</table>
		</div>

		<div class="curio-card curio-preview-card">
			<h2><?php esc_html_e( 'Preview', 'curio-ai-chat' ); ?></h2>
			<p class="curio-hint"><?php esc_html_e( 'Updates as you change colours. Save to apply it to the site.', 'curio-ai-chat' ); ?></p>

			<div class="curio-preview" data-curio-preview>
				<div class="curio-preview-panel">
					<div class="curio-preview-header">
						<span class="curio-preview-avatar" aria-hidden="true"></span>
						<span>
							<strong data-curio-preview-title><?php echo esc_html( Options::text( 'business_name' ) !== '' ? Options::text( 'business_name' ) : __( 'Chat', 'curio-ai-chat' ) ); ?></strong>
							<small><?php echo esc_html( Options::text( 'header_subtitle' ) !== '' ? Options::text( 'header_subtitle' ) : __( 'Ask us anything', 'curio-ai-chat' ) ); ?></small>
						</span>
					</div>
					<div class="curio-preview-log">
						<p class="curio-preview-bubble curio-preview-bot"><?php esc_html_e( 'Hello. How can I help?', 'curio-ai-chat' ); ?></p>
						<p class="curio-preview-bubble curio-preview-user"><?php esc_html_e( 'Do you cover weekends?', 'curio-ai-chat' ); ?></p>
						<p class="curio-preview-bubble curio-preview-bot"><?php esc_html_e( 'I do not have that detail. Drop us a line and someone will come straight back to you.', 'curio-ai-chat' ); ?></p>
					</div>
					<div class="curio-preview-composer">
						<span><?php esc_html_e( 'Ask a question…', 'curio-ai-chat' ); ?></span>
						<span class="curio-preview-send" aria-hidden="true"></span>
					</div>
				</div>
				<span class="curio-preview-launcher" data-curio-preview-launcher aria-hidden="true"></span>
			</div>
		</div>
	</div>

	<div class="curio-card">
		<h2><?php esc_html_e( 'Where it appears', 'curio-ai-chat' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Pages', 'curio-ai-chat' ); ?></th>
				<td>
					<fieldset class="curio-segmented">
						<legend class="screen-reader-text"><?php esc_html_e( 'Where the widget appears', 'curio-ai-chat' ); ?></legend>
						<?php
						foreach ( array(
							'all'     => __( 'Everywhere', 'curio-ai-chat' ),
							'include' => __( 'Only on these', 'curio-ai-chat' ),
							'exclude' => __( 'Everywhere except these', 'curio-ai-chat' ),
						) as $curio_value => $curio_label ) :
							?>
							<label>
								<input type="radio" name="<?php echo esc_attr( Admin::name( 'display_mode' ) ); ?>" value="<?php echo esc_attr( $curio_value ); ?>" <?php checked( Options::text( 'display_mode' ), $curio_value ); ?> />
								<span><?php echo esc_html( $curio_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="curio-field">
						<label for="curio-display-ids"><?php esc_html_e( 'Page or post IDs', 'curio-ai-chat' ); ?></label>
						<input type="text" id="curio-display-ids" class="regular-text" name="<?php echo esc_attr( Admin::name( 'display_ids' ) ); ?>" value="<?php echo esc_attr( implode( ', ', (array) Options::get( 'display_ids', array() ) ) ); ?>" placeholder="12, 48, 91" />
						<span class="curio-hint"><?php esc_html_e( 'Comma separated. The ID is in the URL when you edit a page.', 'curio-ai-chat' ); ?></span>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Audience', 'curio-ai-chat' ); ?></th>
				<td>
					<?php
					Admin::checkbox( 'mobile_enabled', __( 'Show on phones', 'curio-ai-chat' ), __( 'On small screens the panel takes the whole viewport, as every chat product people already use does.', 'curio-ai-chat' ) );
					Admin::checkbox( 'hide_for_logged_in', __( 'Hide from logged-in users', 'curio-ai-chat' ), __( 'Useful on membership sites, and while you are still testing.', 'curio-ai-chat' ) );
					Admin::checkbox( 'show_credit', __( 'Show a small "Chat by Curio" credit in the widget', 'curio-ai-chat' ), __( 'Off by default and entirely optional. It links to the developer and helps other people find the plugin.', 'curio-ai-chat' ) );
					?>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="curio-z"><?php esc_html_e( 'Stacking order', 'curio-ai-chat' ); ?></label></th>
				<td>
					<input type="number" id="curio-z" min="1" max="2147483647" name="<?php echo esc_attr( Admin::name( 'z_index' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'z_index' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Raise this only if something on your site sits on top of the widget. Lower it if the widget sits on top of your own cookie banner or menu.', 'curio-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="curio-custom-css"><?php esc_html_e( 'Custom CSS', 'curio-ai-chat' ); ?></label></th>
				<td>
					<textarea id="curio-custom-css" class="large-text code" rows="6" spellcheck="false" name="<?php echo esc_attr( Admin::name( 'custom_css' ) ); ?>"><?php echo esc_textarea( Options::text( 'custom_css' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'For anything the controls above do not cover. Scope your rules to #curio-widget so they cannot affect the rest of the page.', 'curio-ai-chat' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<?php submit_button( __( 'Save appearance', 'curio-ai-chat' ) ); ?>
</form>
