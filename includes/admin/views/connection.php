<?php
/**
 * Connection tab.
 *
 * @package Curio
 */

namespace Curio\Admin;

use Curio\Options;
use Curio\Rate_Limiter;
use Curio\Secret;
use Curio\Providers\Registry;

defined( 'ABSPATH' ) || exit;

$curio_current   = Options::text( 'provider' );
$curio_providers = Registry::all();
$curio_usage     = Rate_Limiter::month_usage();
?>

<form method="post" action="options.php" class="curio-card">
	<?php
	settings_fields( Admin::GROUP );
	Admin::fields( array( 'provider', 'model', 'max_tokens', 'temperature' ) );
	?>

	<h2><?php esc_html_e( 'Which AI answers', 'curio-ai-chat' ); ?></h2>
	<p class="curio-lede">
		<?php esc_html_e( 'You bring your own key and pay the provider directly. Nothing routes through the developer, and no usage data is sent anywhere except to the provider you pick.', 'curio-ai-chat' ); ?>
	</p>

	<fieldset class="curio-providers" data-curio-providers>
		<legend class="screen-reader-text"><?php esc_html_e( 'AI provider', 'curio-ai-chat' ); ?></legend>

		<?php foreach ( $curio_providers as $curio_slug => $curio_provider ) : ?>
			<?php $curio_links = $curio_provider->links(); ?>
			<label class="<?php echo esc_attr( 'curio-provider' . ( $curio_current === $curio_slug ? ' curio-is-active' : '' ) ); ?>">
				<input
					type="radio"
					name="<?php echo esc_attr( Admin::name( 'provider' ) ); ?>"
					value="<?php echo esc_attr( $curio_slug ); ?>"
					<?php checked( $curio_current, $curio_slug ); ?>
					data-curio-provider-radio="<?php echo esc_attr( $curio_slug ); ?>"
				/>
				<span class="curio-provider-body">
					<strong><?php echo esc_html( $curio_provider->label() ); ?></strong>

					<?php if ( 'demo' === $curio_slug ) : ?>
						<span class="curio-hint"><?php esc_html_e( 'Answers straight from the knowledge base with no rewriting and no API call. Perfect for checking what it knows before you spend anything.', 'curio-ai-chat' ); ?></span>
					<?php else : ?>
						<span class="curio-hint">
							<?php if ( Secret::exists( $curio_slug ) ) : ?>
								<span class="curio-pill curio-pill-good"><?php esc_html_e( 'Key saved', 'curio-ai-chat' ); ?></span>
								<code><?php echo esc_html( Secret::mask( $curio_slug ) ); ?></code>
							<?php else : ?>
								<span class="curio-pill"><?php esc_html_e( 'No key yet', 'curio-ai-chat' ); ?></span>
							<?php endif; ?>
						</span>
						<?php if ( ! empty( $curio_links['pricing'] ) ) : ?>
							<span class="curio-provider-links">
								<a href="<?php echo esc_url( $curio_links['console'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get a key', 'curio-ai-chat' ); ?></a>
								<a href="<?php echo esc_url( $curio_links['pricing'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Pricing', 'curio-ai-chat' ); ?></a>
								<a href="<?php echo esc_url( $curio_links['terms'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms', 'curio-ai-chat' ); ?></a>
								<a href="<?php echo esc_url( $curio_links['privacy'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy', 'curio-ai-chat' ); ?></a>
							</span>
						<?php endif; ?>
					<?php endif; ?>
				</span>
			</label>
		<?php endforeach; ?>
	</fieldset>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="curio-model"><?php esc_html_e( 'Model', 'curio-ai-chat' ); ?></label></th>
			<td>
				<span class="curio-inline-fields">
					<select id="curio-model" name="<?php echo esc_attr( Admin::name( 'model' ) ); ?>" data-curio-model>
						<?php
						$curio_active = Registry::get( $curio_current ) ?? Registry::get( 'demo' );
						$curio_models = $curio_active ? $curio_active->models() : array();
						$curio_chosen = Options::text( 'model' );

						if ( array() === $curio_models ) :
							?>
							<option value=""><?php esc_html_e( 'Not applicable in demo mode', 'curio-ai-chat' ); ?></option>
							<?php
						else :
							foreach ( $curio_models as $curio_id => $curio_label ) :
								?>
								<option value="<?php echo esc_attr( $curio_id ); ?>" <?php selected( $curio_chosen, $curio_id ); ?>>
									<?php echo esc_html( $curio_label ); ?>
								</option>
								<?php
							endforeach;
						endif;
						?>
					</select>
					<button type="button" class="button" data-curio-refresh-models><?php esc_html_e( 'Refresh model list', 'curio-ai-chat' ); ?></button>
					<span class="curio-feedback" data-curio-models-feedback role="status" aria-live="polite"></span>
				</span>
				<p class="description">
					<?php esc_html_e( 'The cheapest model in each family is the right one for answering from a short knowledge base. The hard part is retrieval, and that happens on your server. "Refresh model list" asks your provider what your key can actually reach, so this dropdown never goes stale when they rename things.', 'curio-ai-chat' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="curio-max-tokens"><?php esc_html_e( 'Maximum reply length', 'curio-ai-chat' ); ?></label></th>
			<td>
				<input type="number" id="curio-max-tokens" min="64" max="4000" step="1" name="<?php echo esc_attr( Admin::name( 'max_tokens' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'max_tokens' ) ); ?>" />
				<span class="curio-hint"><?php esc_html_e( 'tokens, roughly three quarters of a word each', 'curio-ai-chat' ); ?></span>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="curio-temperature"><?php esc_html_e( 'Creativity', 'curio-ai-chat' ); ?></label></th>
			<td>
				<input type="range" id="curio-temperature" min="0" max="1" step="0.05" name="<?php echo esc_attr( Admin::name( 'temperature' ) ); ?>" value="<?php echo esc_attr( (string) Options::get( 'temperature', 0.2 ) ); ?>" data-curio-range />
				<output data-curio-range-out><?php echo esc_html( (string) Options::get( 'temperature', 0.2 ) ); ?></output>
				<p class="description"><?php esc_html_e( 'Low is correct here. This assistant is meant to repeat what it has been told, not to write something new. Anything above about 0.4 buys you variety in the wording and nothing else worth having.', 'curio-ai-chat' ); ?></p>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save connection', 'curio-ai-chat' ) ); ?>
</form>

<section class="curio-card" aria-labelledby="curio-key-heading">
	<h2 id="curio-key-heading"><?php esc_html_e( 'API keys', 'curio-ai-chat' ); ?></h2>
	<p class="curio-lede">
		<?php
		if ( Secret::is_encrypted() ) {
			esc_html_e( 'Keys are encrypted before they are written to the database, using a key derived from this site\'s own security salts, and are stored in rows that are not loaded on ordinary page requests. A saved key is never printed back into this page.', 'curio-ai-chat' );
		} else {
			esc_html_e( 'A saved key is never printed back into this page. Note that this server has no OpenSSL support, so keys are stored as plain text in the database. Ask your host to enable the OpenSSL PHP extension if that matters to you.', 'curio-ai-chat' );
		}
		?>
	</p>

	<?php foreach ( Registry::external() as $curio_slug => $curio_provider ) : ?>
		<?php $curio_links = $curio_provider->links(); ?>
		<div class="curio-key-row" data-curio-key-row="<?php echo esc_attr( $curio_slug ); ?>">
			<h3><?php echo esc_html( $curio_provider->label() ); ?></h3>

			<p class="curio-inline-fields">
				<label class="screen-reader-text" for="curio-key-<?php echo esc_attr( $curio_slug ); ?>">
					<?php
					printf(
						/* translators: %s: provider name. */
						esc_html__( '%s API key', 'curio-ai-chat' ),
						esc_html( $curio_provider->label() )
					);
					?>
				</label>
				<input
					type="password"
					id="curio-key-<?php echo esc_attr( $curio_slug ); ?>"
					class="regular-text"
					autocomplete="off"
					spellcheck="false"
					data-curio-key-input
					placeholder="<?php echo esc_attr( Secret::exists( $curio_slug ) ? Secret::mask( $curio_slug ) : __( 'Paste your key', 'curio-ai-chat' ) ); ?>"
				/>
				<button type="button" class="button button-primary" data-curio-key-save><?php esc_html_e( 'Save key', 'curio-ai-chat' ); ?></button>
				<button type="button" class="button" data-curio-key-test><?php esc_html_e( 'Test', 'curio-ai-chat' ); ?></button>
				<?php if ( Secret::exists( $curio_slug ) ) : ?>
					<button type="button" class="button button-link-delete" data-curio-key-delete><?php esc_html_e( 'Remove', 'curio-ai-chat' ); ?></button>
				<?php endif; ?>
			</p>

			<p class="curio-feedback" data-curio-key-feedback role="status" aria-live="polite"></p>

			<p class="curio-hint">
				<?php
				printf(
					/* translators: 1: link to the provider's key console, 2: link to the provider's pricing page. */
					esc_html__( 'Create a key at %1$s. Costs are billed by the provider; see %2$s.', 'curio-ai-chat' ),
					'<a href="' . esc_url( $curio_links['console'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( wp_parse_url( $curio_links['console'], PHP_URL_HOST ) ) . '</a>',
					'<a href="' . esc_url( $curio_links['pricing'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'their pricing page', 'curio-ai-chat' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endforeach; ?>
</section>

<form method="post" action="options.php" class="curio-card">
	<?php
	settings_fields( Admin::GROUP );
	Admin::fields( array( 'rate_limit', 'rate_window', 'monthly_cap', 'max_question_length', 'cache_answers', 'cache_ttl', 'context_chunks', 'history_turns', 'show_sources' ) );
	?>

	<h2><?php esc_html_e( 'Cost and abuse controls', 'curio-ai-chat' ); ?></h2>
	<p class="curio-lede">
		<?php esc_html_e( 'The chat endpoint is public and sits in front of a metered service. Without ceilings, one visitor with a script is an unbounded charge on your card, so there are two, and they are on by default.', 'curio-ai-chat' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Per visitor', 'curio-ai-chat' ); ?></th>
			<td class="curio-inline-fields">
				<label for="curio-rate-limit"><?php esc_html_e( 'At most', 'curio-ai-chat' ); ?></label>
				<input type="number" id="curio-rate-limit" min="1" max="200" name="<?php echo esc_attr( Admin::name( 'rate_limit' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'rate_limit' ) ); ?>" />
				<label for="curio-rate-window"><?php esc_html_e( 'messages every', 'curio-ai-chat' ); ?></label>
				<input type="number" id="curio-rate-window" min="30" max="86400" step="30" name="<?php echo esc_attr( Admin::name( 'rate_window' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'rate_window' ) ); ?>" />
				<?php esc_html_e( 'seconds', 'curio-ai-chat' ); ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="curio-cap"><?php esc_html_e( 'Monthly ceiling', 'curio-ai-chat' ); ?></label></th>
			<td>
				<input type="number" id="curio-cap" min="0" max="1000000" name="<?php echo esc_attr( Admin::name( 'monthly_cap' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'monthly_cap' ) ); ?>" />
				<p class="description">
					<?php
					printf(
						/* translators: %s: number of API calls used this month. */
						esc_html__( 'Total API calls allowed per calendar month. Zero means no ceiling. Used so far this month: %s.', 'curio-ai-chat' ),
						'<strong>' . esc_html( number_format_i18n( $curio_usage['calls'] ) ) . '</strong>'
					);
					?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="curio-max-question"><?php esc_html_e( 'Longest question accepted', 'curio-ai-chat' ); ?></label></th>
			<td>
				<input type="number" id="curio-max-question" min="40" max="4000" name="<?php echo esc_attr( Admin::name( 'max_question_length' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'max_question_length' ) ); ?>" />
				<span class="curio-hint"><?php esc_html_e( 'characters', 'curio-ai-chat' ); ?></span>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Caching', 'curio-ai-chat' ); ?></th>
			<td>
				<?php
				Admin::checkbox(
					'cache_answers',
					__( 'Reuse answers to repeat questions', 'curio-ai-chat' ),
					__( 'Real visitors ask the same handful of questions. Every repeat served from cache is a call you do not pay for. Cleared automatically whenever the knowledge base or your settings change.', 'curio-ai-chat' )
				);
				?>
				<p class="curio-inline-fields">
					<label for="curio-cache-ttl"><?php esc_html_e( 'Keep for', 'curio-ai-chat' ); ?></label>
					<input type="number" id="curio-cache-ttl" min="1" max="720" name="<?php echo esc_attr( Admin::name( 'cache_ttl' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'cache_ttl' ) ); ?>" />
					<?php esc_html_e( 'hours', 'curio-ai-chat' ); ?>
					<span class="curio-hint">
						<?php
						printf(
							/* translators: %s: number of cached answers served this month. */
							esc_html__( 'Served free this month: %s', 'curio-ai-chat' ),
							esc_html( number_format_i18n( $curio_usage['cached'] ) )
						);
						?>
					</span>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Retrieval', 'curio-ai-chat' ); ?></th>
			<td class="curio-inline-fields">
				<label for="curio-chunks"><?php esc_html_e( 'Pass at most', 'curio-ai-chat' ); ?></label>
				<input type="number" id="curio-chunks" min="1" max="10" name="<?php echo esc_attr( Admin::name( 'context_chunks' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'context_chunks' ) ); ?>" />
				<?php esc_html_e( 'passages, and remember', 'curio-ai-chat' ); ?>
				<label class="screen-reader-text" for="curio-turns"><?php esc_html_e( 'Conversation turns remembered', 'curio-ai-chat' ); ?></label>
				<input type="number" id="curio-turns" min="0" max="12" name="<?php echo esc_attr( Admin::name( 'history_turns' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'history_turns' ) ); ?>" />
				<?php esc_html_e( 'previous exchanges.', 'curio-ai-chat' ); ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Sources', 'curio-ai-chat' ); ?></th>
			<td>
				<?php
				Admin::checkbox(
					'show_sources',
					__( 'Show where the answer came from', 'curio-ai-chat' ),
					__( 'Adds a small link under the reply pointing at the page it was drawn from. It is what makes a grounded answer checkable rather than merely asserted, and it sends people to your content.', 'curio-ai-chat' )
				);
				?>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save limits', 'curio-ai-chat' ) ); ?>
</form>
