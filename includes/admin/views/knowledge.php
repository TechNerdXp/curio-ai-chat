<?php
/**
 * Knowledge tab.
 *
 * @package Curio
 */

namespace Curio\Admin;

use Curio\Knowledge_Store;

defined( 'ABSPATH' ) || exit;

$curio_counts = Knowledge_Store::counts_by_type();
?>

<div class="curio-grid curio-grid-split">

	<section class="curio-card" aria-labelledby="curio-add-heading">
		<h2 id="curio-add-heading"><?php esc_html_e( 'Add an answer', 'curio-ai-chat' ); ?></h2>
		<p class="curio-lede">
			<?php esc_html_e( 'Everything the assistant is allowed to say that is not already written on the site: prices, opening hours, turnaround times, policies. Write the answer the way you would say it to a customer.', 'curio-ai-chat' ); ?>
		</p>

		<form class="curio-entry-form" data-curio-entry-form>
			<input type="hidden" data-curio-entry-id value="0" />

			<p class="curio-field">
				<label for="curio-entry-title"><?php esc_html_e( 'Question or topic', 'curio-ai-chat' ); ?></label>
				<input type="text" id="curio-entry-title" data-curio-entry-title class="regular-text" maxlength="180" placeholder="<?php esc_attr_e( 'How much is a wedding shoot?', 'curio-ai-chat' ); ?>" required />
			</p>

			<p class="curio-field">
				<label for="curio-entry-content"><?php esc_html_e( 'Answer', 'curio-ai-chat' ); ?></label>
				<textarea id="curio-entry-content" data-curio-entry-content rows="6" class="large-text" required placeholder="<?php esc_attr_e( 'Wedding coverage starts at £1,200 for six hours, including an online gallery within three weeks.', 'curio-ai-chat' ); ?>"></textarea>
				<span class="curio-hint"><?php esc_html_e( 'Be specific. The assistant will quote this rather than paraphrase it, and it will never add a figure that is not here.', 'curio-ai-chat' ); ?></span>
			</p>

			<p class="curio-field">
				<label for="curio-entry-keywords"><?php esc_html_e( 'Keywords', 'curio-ai-chat' ); ?></label>
				<input type="text" id="curio-entry-keywords" data-curio-entry-keywords class="regular-text" placeholder="<?php esc_attr_e( 'price, cost, wedding, package', 'curio-ai-chat' ); ?>" />
				<span class="curio-hint"><?php esc_html_e( 'Optional, comma separated. Words a customer might use that do not appear in the answer itself. Leave blank and they will be worked out for you.', 'curio-ai-chat' ); ?></span>
			</p>

			<p class="curio-field">
				<label for="curio-entry-url"><?php esc_html_e( 'Read more link', 'curio-ai-chat' ); ?></label>
				<input type="url" id="curio-entry-url" data-curio-entry-url class="regular-text" placeholder="https://" />
				<span class="curio-hint"><?php esc_html_e( 'Optional. Shown as a source chip under the answer so the visitor can check it.', 'curio-ai-chat' ); ?></span>
			</p>

			<p class="curio-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save answer', 'curio-ai-chat' ); ?></button>
				<button type="button" class="button button-link" data-curio-entry-reset hidden><?php esc_html_e( 'Cancel edit', 'curio-ai-chat' ); ?></button>
				<span class="curio-feedback" data-curio-entry-feedback role="status" aria-live="polite"></span>
			</p>
		</form>
	</section>

	<section class="curio-card" aria-labelledby="curio-bulk-heading">
		<h2 id="curio-bulk-heading"><?php esc_html_e( 'Import and export', 'curio-ai-chat' ); ?></h2>
		<p class="curio-lede">
			<?php esc_html_e( 'Paste a JSON array to load answers in bulk. Both title/content and question/answer key names are accepted, so an export from most FAQ tools will import without editing.', 'curio-ai-chat' ); ?>
		</p>

		<p class="curio-field">
			<label for="curio-import" class="screen-reader-text"><?php esc_html_e( 'JSON to import', 'curio-ai-chat' ); ?></label>
			<?php
			/*
			 * The JSON structure is fixed, so the keys stay literal; only the
			 * example question and answer are translated, because a sample in a
			 * language the site owner does not read teaches nothing.
			 */
			$curio_import_example = (string) wp_json_encode(
				array(
					array(
						'question' => __( 'Do you travel?', 'curio-ai-chat' ),
						'answer'   => __( 'Yes, anywhere in the country. Travel beyond 50 miles is charged per mile.', 'curio-ai-chat' ),
					),
				)
			);
			?>
			<textarea id="curio-import" data-curio-import rows="9" class="large-text code" spellcheck="false" placeholder="<?php echo esc_attr( $curio_import_example ); ?>"></textarea>
		</p>

		<p class="curio-actions">
			<button type="button" class="button button-primary" data-curio-import-run><?php esc_html_e( 'Import', 'curio-ai-chat' ); ?></button>
			<button type="button" class="button" data-curio-export><?php esc_html_e( 'Export mine', 'curio-ai-chat' ); ?></button>
			<span class="curio-feedback" data-curio-import-feedback role="status" aria-live="polite"></span>
		</p>

		<hr />

		<h3><?php esc_html_e( 'Danger zone', 'curio-ai-chat' ); ?></h3>
		<p class="curio-hint">
			<?php esc_html_e( 'There is no "restore defaults" here, and that is deliberate: the only safe thing for an assistant that answers customers to know by default is nothing at all.', 'curio-ai-chat' ); ?>
		</p>
		<p class="curio-actions">
			<button type="button" class="button button-link-delete" data-curio-clear="manual">
				<?php
				printf(
					/* translators: %s: number of hand-written entries. */
					esc_html__( 'Delete my %s written answers', 'curio-ai-chat' ),
					esc_html( number_format_i18n( (int) ( $curio_counts[ Knowledge_Store::SOURCE_MANUAL ] ?? 0 ) ) )
				);
				?>
			</button>
			<span class="curio-feedback" data-curio-clear-feedback role="status" aria-live="polite"></span>
		</p>
	</section>
</div>

<section class="curio-card" aria-labelledby="curio-list-heading">
	<div class="curio-card-header">
		<h2 id="curio-list-heading"><?php esc_html_e( 'Everything it knows', 'curio-ai-chat' ); ?></h2>

		<div class="curio-filters">
			<label class="screen-reader-text" for="curio-search"><?php esc_html_e( 'Search the knowledge base', 'curio-ai-chat' ); ?></label>
			<input type="search" id="curio-search" data-curio-search placeholder="<?php esc_attr_e( 'Search…', 'curio-ai-chat' ); ?>" />

			<label class="screen-reader-text" for="curio-filter-source"><?php esc_html_e( 'Filter by source', 'curio-ai-chat' ); ?></label>
			<select id="curio-filter-source" data-curio-filter-source>
				<option value=""><?php esc_html_e( 'All sources', 'curio-ai-chat' ); ?></option>
				<option value="manual"><?php esc_html_e( 'Written by you', 'curio-ai-chat' ); ?></option>
				<option value="post"><?php esc_html_e( 'Indexed site content', 'curio-ai-chat' ); ?></option>
				<option value="product"><?php esc_html_e( 'Products', 'curio-ai-chat' ); ?></option>
			</select>
		</div>
	</div>

	<div data-curio-list class="curio-list" aria-live="polite"></div>

	<p class="curio-actions">
		<button type="button" class="button" data-curio-prev disabled><?php esc_html_e( '← Previous', 'curio-ai-chat' ); ?></button>
		<button type="button" class="button" data-curio-next disabled><?php esc_html_e( 'Next →', 'curio-ai-chat' ); ?></button>
		<span class="curio-feedback" data-curio-page-label role="status" aria-live="polite"></span>
	</p>
</section>
