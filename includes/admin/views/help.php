<?php
/**
 * Help tab.
 *
 * @package Curio
 */

namespace Curio\Admin;

use Curio\Providers\Registry;

defined( 'ABSPATH' ) || exit;
?>

<div class="curio-grid curio-grid-split">

	<section class="curio-card">
		<h2><?php esc_html_e( 'Five minutes to a working assistant', 'curio-ai-chat' ); ?></h2>

		<ol class="curio-steps">
			<li>
				<strong><?php esc_html_e( 'Sources', 'curio-ai-chat' ); ?></strong>
				<span><?php esc_html_e( 'Tick your pages, and your products if you sell any. Press "Index site content now" and wait for the bar. The assistant can now answer from everything you have already written.', 'curio-ai-chat' ); ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Knowledge', 'curio-ai-chat' ); ?></strong>
				<span><?php esc_html_e( 'Add the things that are not written anywhere: prices you quote by email, opening hours, your cancellation policy, how long delivery takes. Ten entries covers most of what a small business gets asked.', 'curio-ai-chat' ); ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Assistant', 'curio-ai-chat' ); ?></strong>
				<span><?php esc_html_e( 'Fill in your business name and — most importantly — the hand-off line. That is what it says when it cannot help, and it is the difference between a dead end and a lead.', 'curio-ai-chat' ); ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Try it in demo mode', 'curio-ai-chat' ); ?></strong>
				<span><?php esc_html_e( 'Open your site and ask it things. Demo mode makes no API calls and costs nothing, and it declines exactly where the real thing will decline, so it is an honest rehearsal.', 'curio-ai-chat' ); ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Connection', 'curio-ai-chat' ); ?></strong>
				<span><?php esc_html_e( 'Once you like what it knows, paste an API key and switch provider. Now it answers in sentences instead of quoting entries.', 'curio-ai-chat' ); ?></span>
			</li>
		</ol>
	</section>

	<section class="curio-card">
		<h2><?php esc_html_e( 'Common questions', 'curio-ai-chat' ); ?></h2>

		<h3><?php esc_html_e( 'It says it does not know something that is on my site.', 'curio-ai-chat' ); ?></h3>
		<p><?php esc_html_e( 'Either that page type is not ticked on the Sources tab, or the index has not been run since you wrote it, or the wording is different enough that retrieval missed it. Add the question as a written answer on the Knowledge tab — hand-written entries are weighted above indexed page text on purpose.', 'curio-ai-chat' ); ?></p>

		<h3><?php esc_html_e( 'Will it ever make up a price?', 'curio-ai-chat' ); ?></h3>
		<p><?php esc_html_e( 'When retrieval finds nothing relevant, no request is sent to the AI at all — the decline is written by this plugin, not by a model asked nicely to behave. When retrieval does find something, the model is given those passages and a set of rules it cannot edit away, including an explicit ban on estimating any figure not in front of it.', 'curio-ai-chat' ); ?></p>

		<h3><?php esc_html_e( 'Can I move it, resize it, or match my brand?', 'curio-ai-chat' ); ?></h3>
		<p><?php esc_html_e( 'The Appearance tab covers colour, corners, size, position, launcher style, avatar and font, with a live preview, and it can read the palette straight out of a block theme. Anything beyond that goes in the custom CSS box.', 'curio-ai-chat' ); ?></p>

		<h3><?php esc_html_e( 'Does it slow my site down?', 'curio-ai-chat' ); ?></h3>
		<p><?php esc_html_e( 'One stylesheet and one deferred script — about 42KB of source, roughly 12KB over the wire once your server compresses them — loaded only on pages the widget actually appears on. No jQuery, no framework, no external CDN, and no request to any AI provider until a visitor sends a message.', 'curio-ai-chat' ); ?></p>
	</section>
</div>

<section class="curio-card">
	<h2><?php esc_html_e( 'What this plugin sends outside your site', 'curio-ai-chat' ); ?></h2>
	<p class="curio-lede">
		<?php esc_html_e( 'Nothing leaves your server until you save an API key and select a provider. In demo mode there is no outbound request of any kind. When a provider is selected, this is exactly what is sent and to whom.', 'curio-ai-chat' ); ?>
	</p>

	<table class="widefat striped curio-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Service', 'curio-ai-chat' ); ?></th>
				<th scope="col"><?php esc_html_e( 'When', 'curio-ai-chat' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What is sent', 'curio-ai-chat' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Their terms', 'curio-ai-chat' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( Registry::external() as $curio_provider ) : ?>
				<?php $curio_links = $curio_provider->links(); ?>
				<tr>
					<td><strong><?php echo esc_html( $curio_provider->label() ); ?></strong></td>
					<td><?php esc_html_e( 'Only while it is the selected provider, and only when a visitor sends a message or you press Test.', 'curio-ai-chat' ); ?></td>
					<td><?php esc_html_e( 'The visitor\'s message, the recent turns of that conversation, and the knowledge passages retrieved for it. No IP address, no visitor identity, no site credentials.', 'curio-ai-chat' ); ?></td>
					<td>
						<a href="<?php echo esc_url( $curio_links['terms'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms', 'curio-ai-chat' ); ?></a> ·
						<a href="<?php echo esc_url( $curio_links['privacy'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy', 'curio-ai-chat' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="curio-hint">
		<?php esc_html_e( 'This plugin sends nothing to its developer, contains no analytics, no tracking and no phone-home of any kind, and asks WordPress.org for updates the same way every other plugin does.', 'curio-ai-chat' ); ?>
	</p>
</section>

<section class="curio-card">
	<h2><?php esc_html_e( 'For developers', 'curio-ai-chat' ); ?></h2>
	<p class="curio-lede"><?php esc_html_e( 'Extension points, so you can change behaviour without forking anything.', 'curio-ai-chat' ); ?></p>

	<dl class="curio-hooks">
		<dt><code>curio_providers</code></dt>
		<dd><?php esc_html_e( 'Add your own provider — a self-hosted model, an OpenAI-compatible gateway, an internal endpoint. Implement the provider interface and add it to the array.', 'curio-ai-chat' ); ?></dd>

		<dt><code>curio_relevance_threshold</code></dt>
		<dd><?php esc_html_e( 'The score a passage needs before it counts as context. Raise it and the assistant declines more; lower it and it answers from weaker matches. The one dial that trades safety against helpfulness.', 'curio-ai-chat' ); ?></dd>

		<dt><code>curio_retrieved_context</code></dt>
		<dd><?php esc_html_e( 'Inspect or replace the passages selected for a question, just before they reach the model.', 'curio-ai-chat' ); ?></dd>

		<dt><code>curio_system_prompt</code></dt>
		<dd><?php esc_html_e( 'The complete assembled prompt, grounding rules included.', 'curio-ai-chat' ); ?></dd>

		<dt><code>curio_should_display</code></dt>
		<dd><?php esc_html_e( 'Decide per request whether the widget renders — hide it on checkout, restrict it to a role, limit it to one language.', 'curio-ai-chat' ); ?></dd>

		<dt><code>curio_should_index_post</code></dt>
		<dd><?php esc_html_e( 'Veto individual posts during indexing.', 'curio-ai-chat' ); ?></dd>

		<dt><code>curio_client_ip</code></dt>
		<dd><?php esc_html_e( 'Essential behind Cloudflare, a load balancer or any reverse proxy: return the real client address, or every visitor is rate limited as though they were one person.', 'curio-ai-chat' ); ?></dd>

		<dt><code>curio_stopwords</code> · <code>curio_greetings</code> · <code>curio_answer</code></dt>
		<dd><?php esc_html_e( 'Retune retrieval for another language, change what counts as a greeting, or post-process the finished reply.', 'curio-ai-chat' ); ?></dd>
	</dl>
</section>

<section class="curio-card curio-card-author">
	<span class="curio-mark curio-mark-inline">
		<?php require CURIO_DIR . 'templates/mark.php'; ?>
	</span>
	<h2><?php esc_html_e( 'Support and custom work', 'curio-ai-chat' ); ?></h2>
	<p>
		<?php esc_html_e( 'Curio is free and GPL licensed. Bugs and questions are best raised on the WordPress.org support forum so the answers help the next person too.', 'curio-ai-chat' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'If you need something this plugin does not do — a bespoke integration, a different provider, an assistant wired into your booking system or CRM, or a WordPress build of any size — I take that work.', 'curio-ai-chat' ); ?>
	</p>
	<p class="curio-actions">
		<a class="button button-primary" href="<?php echo esc_url( CURIO_AUTHOR_URL ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Hire TechNerdXp on Upwork', 'curio-ai-chat' ); ?>
		</a>
		<a class="button" href="<?php echo esc_url( 'https://wordpress.org/support/plugin/' . CURIO_SLUG . '/' ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Support forum', 'curio-ai-chat' ); ?>
		</a>
		<a class="button" href="<?php echo esc_url( 'https://wordpress.org/support/plugin/' . CURIO_SLUG . '/reviews/#new-post' ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Leave a review', 'curio-ai-chat' ); ?>
		</a>
	</p>
</section>
