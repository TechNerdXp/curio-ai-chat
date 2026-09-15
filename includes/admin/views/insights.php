<?php
/**
 * Insights tab.
 *
 * @package Curio
 */

namespace Curio\Admin;

use Curio\Conversation_Log;
use Curio\Options;
use Curio\Rate_Limiter;

defined( 'ABSPATH' ) || exit;

$curio_usage  = Rate_Limiter::month_usage();
$curio_totals = Conversation_Log::totals();
$curio_gaps   = Conversation_Log::enabled() ? Conversation_Log::gaps( 25 ) : array();
?>

<section class="curio-card">
	<h2><?php esc_html_e( 'This month', 'curio-ai-chat' ); ?></h2>

	<div class="curio-metrics">
		<div class="curio-metric">
			<strong><?php echo esc_html( number_format_i18n( $curio_usage['calls'] ) ); ?></strong>
			<span><?php esc_html_e( 'API calls made', 'curio-ai-chat' ); ?></span>
		</div>
		<div class="curio-metric">
			<strong><?php echo esc_html( number_format_i18n( $curio_usage['cached'] ) ); ?></strong>
			<span><?php esc_html_e( 'answered from cache, free', 'curio-ai-chat' ); ?></span>
		</div>
		<div class="curio-metric">
			<strong><?php echo esc_html( number_format_i18n( $curio_usage['tokens_in'] ) ); ?></strong>
			<span><?php esc_html_e( 'input tokens', 'curio-ai-chat' ); ?></span>
		</div>
		<div class="curio-metric">
			<strong><?php echo esc_html( number_format_i18n( $curio_usage['tokens_out'] ) ); ?></strong>
			<span><?php esc_html_e( 'output tokens', 'curio-ai-chat' ); ?></span>
		</div>
	</div>

	<p class="curio-hint">
		<?php esc_html_e( 'Token counts are reported by the provider itself, so they are what you are actually billed for. Multiply by your provider\'s per-million rate for the month\'s cost — on the cheapest model of any of the three, a busy small-business month is usually well under the price of a coffee.', 'curio-ai-chat' ); ?>
	</p>
</section>

<section class="curio-card">
	<h2><?php esc_html_e( 'Questions it could not answer', 'curio-ai-chat' ); ?></h2>
	<p class="curio-lede">
		<?php esc_html_e( 'This is the most useful screen in the plugin. Because the assistant declines anything it has not been told, every line here is a real customer asking something your site does not answer yet. Work down the list, add the answers on the Knowledge tab, and the bot gets better every week without anyone tuning a model.', 'curio-ai-chat' ); ?>
	</p>

	<?php if ( ! Conversation_Log::enabled() ) : ?>
		<div class="curio-banner curio-banner-muted">
			<p><?php esc_html_e( 'Conversation logging is switched off, so there is nothing to show. Turn it on below if you want this list — it is the only reason the log exists.', 'curio-ai-chat' ); ?></p>
		</div>
	<?php elseif ( array() === $curio_gaps ) : ?>
		<p><?php esc_html_e( 'Nothing yet. Either no one has asked something it could not answer, or no one has asked anything at all.', 'curio-ai-chat' ); ?></p>
	<?php else : ?>
		<table class="widefat striped curio-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Question', 'curio-ai-chat' ); ?></th>
					<th scope="col" class="curio-numeric"><?php esc_html_e( 'Times asked', 'curio-ai-chat' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last asked', 'curio-ai-chat' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'curio-ai-chat' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $curio_gaps as $curio_gap ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $curio_gap['question'] ); ?></td>
						<td class="curio-numeric"><?php echo esc_html( number_format_i18n( (int) $curio_gap['times'] ) ); ?></td>
						<td>
							<?php
							$curio_when = strtotime( (string) $curio_gap['last_asked'] . ' UTC' );
							echo esc_html(
								$curio_when
									? sprintf(
										/* translators: %s: human readable time difference, e.g. "2 hours". */
										__( '%s ago', 'curio-ai-chat' ),
										human_time_diff( $curio_when )
									)
									: '—'
							);
							?>
						</td>
						<td>
							<a
								class="button button-small"
								href="<?php echo esc_url( add_query_arg( 'curio_prefill', rawurlencode( (string) $curio_gap['question'] ), Admin::tab_url( 'knowledge' ) ) ); ?>"
							>
								<?php esc_html_e( 'Answer this', 'curio-ai-chat' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<form method="post" action="options.php" class="curio-card">
	<?php
	settings_fields( Admin::GROUP );
	Admin::fields( array( 'log_conversations', 'log_retention_days', 'delete_data_on_uninstall' ) );
	?>

	<h2><?php esc_html_e( 'Logging and data', 'curio-ai-chat' ); ?></h2>

	<?php
	Admin::checkbox(
		'log_conversations',
		__( 'Store questions and answers', 'curio-ai-chat' ),
		__( 'Off by default, because recording what visitors type is a decision with privacy consequences and it is yours to make knowingly. No IP address is ever stored, for anyone.', 'curio-ai-chat' )
	);
	?>

	<p class="curio-inline-fields">
		<label for="curio-retention"><?php esc_html_e( 'Delete stored conversations after', 'curio-ai-chat' ); ?></label>
		<input type="number" id="curio-retention" min="1" max="3650" name="<?php echo esc_attr( Admin::name( 'log_retention_days' ) ); ?>" value="<?php echo esc_attr( (string) Options::number( 'log_retention_days' ) ); ?>" />
		<?php esc_html_e( 'days', 'curio-ai-chat' ); ?>
		<span class="curio-hint"><?php esc_html_e( 'Enforced by a daily scheduled task, not by you remembering.', 'curio-ai-chat' ); ?></span>
	</p>

	<p class="curio-hint">
		<?php
		printf(
			/* translators: %s: number of stored conversations. */
			esc_html__( 'Stored right now: %s.', 'curio-ai-chat' ),
			'<strong>' . esc_html( number_format_i18n( $curio_totals['total'] ) ) . '</strong>'
		);
		?>
		<?php esc_html_e( 'These records are wired into WordPress\'s own privacy tools, so a data export or erasure request for a registered user picks them up automatically.', 'curio-ai-chat' ); ?>
	</p>

	<h3><?php esc_html_e( 'On uninstall', 'curio-ai-chat' ); ?></h3>
	<?php
	Admin::checkbox(
		'delete_data_on_uninstall',
		__( 'Delete everything when the plugin is deleted', 'curio-ai-chat' ),
		__( 'Off by default. Deactivating to debug a theme conflict should never destroy a knowledge base somebody spent an afternoon writing, so nothing is removed unless you ask for it here.', 'curio-ai-chat' )
	);
	?>

	<p class="curio-actions">
		<button type="button" class="button button-link-delete" data-curio-clear-log><?php esc_html_e( 'Delete stored conversations now', 'curio-ai-chat' ); ?></button>
		<span class="curio-feedback" data-curio-log-feedback role="status" aria-live="polite"></span>
	</p>

	<?php submit_button( __( 'Save data settings', 'curio-ai-chat' ) ); ?>
</form>
