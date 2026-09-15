<?php
/**
 * Sources tab.
 *
 * @package Curio
 */

namespace Curio\Admin;

use Curio\Indexer;
use Curio\Knowledge_Store;
use Curio\Options;

defined( 'ABSPATH' ) || exit;

$curio_types    = Indexer::available_post_types();
$curio_selected = (array) Options::get( 'index_post_types', array() );
$curio_counts   = Knowledge_Store::counts_by_type();
?>

<form method="post" action="options.php" class="curio-card">
	<?php
	settings_fields( Admin::GROUP );
	Admin::fields( array( 'index_post_types', 'index_products', 'auto_index', 'index_excerpt_only' ) );
	?>

	<h2><?php esc_html_e( 'Read from the site', 'curio-ai-chat' ); ?></h2>
	<p class="curio-lede">
		<?php esc_html_e( 'Tick what the assistant may read. Each item is split into passages and stored, so a question can be matched against a paragraph rather than a whole page. Nothing is read that you do not tick here.', 'curio-ai-chat' ); ?>
	</p>

	<fieldset class="curio-fieldset">
		<legend class="screen-reader-text"><?php esc_html_e( 'Content to index', 'curio-ai-chat' ); ?></legend>

		<?php if ( array() === $curio_types ) : ?>
			<p><?php esc_html_e( 'No public content types were found on this site.', 'curio-ai-chat' ); ?></p>
		<?php else : ?>
			<div class="curio-checkgrid">
				<?php
				foreach ( $curio_types as $curio_slug => $curio_label ) :
					// Products get their own switch below, with WooCommerce
					// specific handling, so they are not offered twice.
					if ( 'product' === $curio_slug && Indexer::woocommerce_active() ) {
						continue;
					}
					$curio_tally = wp_count_posts( $curio_slug );
					$curio_count = $curio_tally ? (int) $curio_tally->publish : 0;
					?>
					<label class="curio-check">
						<input
							type="checkbox"
							name="<?php echo esc_attr( Admin::name( 'index_post_types' ) ); ?>[]"
							value="<?php echo esc_attr( $curio_slug ); ?>"
							<?php checked( in_array( $curio_slug, $curio_selected, true ) ); ?>
						/>
						<span>
							<strong><?php echo esc_html( $curio_label ); ?></strong>
							<span class="curio-hint">
								<?php
								printf(
									/* translators: %s: number of published items. */
									esc_html( _n( '%s published item', '%s published items', $curio_count, 'curio-ai-chat' ) ),
									esc_html( number_format_i18n( $curio_count ) )
								);
								?>
							</span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</fieldset>

	<?php if ( Indexer::woocommerce_active() ) : ?>
		<h3><?php esc_html_e( 'WooCommerce', 'curio-ai-chat' ); ?></h3>
		<?php
		Admin::checkbox(
			'index_products',
			__( 'Index products', 'curio-ai-chat' ),
			__( 'Descriptions, price, SKU, stock status, categories and tags. Prices are written out as sentences so the assistant can quote them, and are refreshed whenever a product is saved.', 'curio-ai-chat' )
		);
		?>
	<?php else : ?>
		<p class="curio-hint"><?php esc_html_e( 'WooCommerce is not active on this site, so product indexing is unavailable.', 'curio-ai-chat' ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'How it keeps up', 'curio-ai-chat' ); ?></h3>
	<?php
	Admin::checkbox(
		'auto_index',
		__( 'Re-index automatically when content is saved', 'curio-ai-chat' ),
		__( 'Recommended. An edited page is current within seconds, and an unpublished one stops being quoted immediately.', 'curio-ai-chat' )
	);
	Admin::checkbox(
		'index_excerpt_only',
		__( 'Use excerpts only, where one exists', 'curio-ai-chat' ),
		__( 'Smaller knowledge base and cheaper prompts, at the cost of detail. Useful on sites with very long posts.', 'curio-ai-chat' )
	);
	?>

	<?php submit_button( __( 'Save source settings', 'curio-ai-chat' ) ); ?>
</form>

<section class="curio-card" aria-labelledby="curio-index-heading">
	<h2 id="curio-index-heading"><?php esc_html_e( 'Build the index', 'curio-ai-chat' ); ?></h2>
	<p class="curio-lede">
		<?php esc_html_e( 'Save your choices above first, then run this once. It works through the site in small batches so nothing times out, and you can leave the page when the bar finishes.', 'curio-ai-chat' ); ?>
	</p>

	<div class="curio-progress" data-curio-progress hidden>
		<div class="curio-progress-bar"><span data-curio-progress-fill style="width:0%"></span></div>
		<p class="curio-feedback" data-curio-progress-label role="status" aria-live="polite"></p>
	</div>

	<p class="curio-actions">
		<button type="button" class="button button-primary" data-curio-reindex><?php esc_html_e( 'Index site content now', 'curio-ai-chat' ); ?></button>
		<button type="button" class="button button-link-delete" data-curio-clear="post"><?php esc_html_e( 'Remove indexed content', 'curio-ai-chat' ); ?></button>
	</p>

	<ul class="curio-inline-stats">
		<li>
			<strong><?php echo esc_html( number_format_i18n( (int) ( $curio_counts[ Knowledge_Store::SOURCE_POST ] ?? 0 ) ) ); ?></strong>
			<?php esc_html_e( 'passages from site content', 'curio-ai-chat' ); ?>
		</li>
		<li>
			<strong><?php echo esc_html( number_format_i18n( (int) ( $curio_counts[ Knowledge_Store::SOURCE_PRODUCT ] ?? 0 ) ) ); ?></strong>
			<?php esc_html_e( 'passages from products', 'curio-ai-chat' ); ?>
		</li>
		<li>
			<strong><?php echo esc_html( number_format_i18n( (int) ( $curio_counts[ Knowledge_Store::SOURCE_MANUAL ] ?? 0 ) ) ); ?></strong>
			<?php esc_html_e( 'answers you wrote', 'curio-ai-chat' ); ?>
		</li>
	</ul>
</section>
