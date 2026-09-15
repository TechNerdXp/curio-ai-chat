<?php
/**
 * Admin screen.
 *
 * @package Curio
 */

namespace Curio\Admin;

use Curio\Appearance;
use Curio\Conversation_Log;
use Curio\Indexer;
use Curio\Knowledge_Store;
use Curio\Options;
use Curio\Rate_Limiter;
use Curio\REST;
use Curio\Secret;
use Curio\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The settings screen: menu, tabs, assets and the Settings API registration.
 *
 * Ordered by what a new user needs first. Knowledge comes before Connection
 * because an assistant with an API key and an empty knowledge base is switched
 * on and mute, which looks identical to broken — and because demo mode means
 * the knowledge tab is genuinely usable before anyone has spent a penny.
 */
final class Admin {

	public const GROUP = 'curio_settings_group';

	/**
	 * Hook the admin screen up.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Every tab, in the order they appear.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			'knowledge'  => __( 'Knowledge', 'curio-ai-chat' ),
			'sources'    => __( 'Sources', 'curio-ai-chat' ),
			'assistant'  => __( 'Assistant', 'curio-ai-chat' ),
			'appearance' => __( 'Appearance', 'curio-ai-chat' ),
			'connection' => __( 'Connection', 'curio-ai-chat' ),
			'insights'   => __( 'Insights', 'curio-ai-chat' ),
			'help'       => __( 'Help', 'curio-ai-chat' ),
		);
	}

	/**
	 * Which tab is being viewed.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		// Reading a tab name to pick which view file to include. It is checked
		// against a fixed list below, and changes nothing, so there is no state
		// for a nonce to protect here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'knowledge';
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'knowledge';
	}

	/**
	 * URL of one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => CURIO_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Add the menu entry.
	 *
	 * @return void
	 */
	public static function menu(): void {
		$hook = add_options_page(
			__( 'Curio — Grounded AI Chat', 'curio-ai-chat' ),
			__( 'Chat Assistant', 'curio-ai-chat' ),
			'manage_options',
			CURIO_SLUG,
			array( __CLASS__, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'help_tab' ) );
		}
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * One option, one sanitiser. The form posts to `options.php`, so WordPress
	 * handles the nonce, the capability check and the redirect, and this plugin
	 * does not reimplement three things core already does correctly.
	 *
	 * @return void
	 */
	public static function settings(): void {
		register_setting(
			self::GROUP,
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize_form' ),
				'default'           => Options::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Contextual help, and the author credit that belongs with it.
	 *
	 * @return void
	 */
	public static function help_tab(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'curio-getting-started',
				'title'   => __( 'Getting started', 'curio-ai-chat' ),
				'content' => '<p>' . esc_html__( 'Three steps, in this order:', 'curio-ai-chat' ) . '</p><ol>'
					. '<li>' . esc_html__( 'Sources: tick the pages and posts the assistant may read, then run the index.', 'curio-ai-chat' ) . '</li>'
					. '<li>' . esc_html__( 'Knowledge: add the answers that are not written down anywhere on the site — prices, hours, policies.', 'curio-ai-chat' ) . '</li>'
					. '<li>' . esc_html__( 'Connection: paste an API key when you are happy with what it knows. Until then it runs in demo mode and costs nothing.', 'curio-ai-chat' ) . '</li>'
					. '</ol>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'curio-grounding',
				'title'   => __( 'Why it declines', 'curio-ai-chat' ),
				'content' => '<p>' . esc_html__( 'The assistant answers only from the knowledge base. When nothing in it matches a question, it says so rather than guessing — no request is even sent to the AI provider. That is deliberate: a chat widget that invents a price costs a business more than one that occasionally says "I do not have that detail".', 'curio-ai-chat' ) . '</p>'
					. '<p>' . esc_html__( 'The Insights tab lists every question it could not answer. That list is your to-do list.', 'curio-ai-chat' ) . '</p>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'Curio', 'curio-ai-chat' ) . '</strong></p>'
			. '<p>' . esc_html__( 'Built by TechNerdXp.', 'curio-ai-chat' ) . '</p>'
			. '<p><a href="' . esc_url( CURIO_AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Need this customised, or a bigger build? Hire me on Upwork.', 'curio-ai-chat' )
			. '</a></p>'
			. '<p><a href="' . esc_url( 'https://wordpress.org/support/plugin/' . CURIO_SLUG . '/' ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Support forum', 'curio-ai-chat' ) . '</a></p>'
		);
	}

	/**
	 * Enqueue the screen's own assets, and nothing anywhere else.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ): void {
		if ( 'settings_page_' . CURIO_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'curio-admin',
			CURIO_URL . 'assets/css/curio-admin.css',
			array( 'wp-color-picker' ),
			CURIO_VERSION
		);

		wp_enqueue_media();

		wp_enqueue_script(
			'curio-admin',
			CURIO_URL . 'assets/js/curio-admin.js',
			array( 'wp-color-picker', 'jquery' ),
			CURIO_VERSION,
			true
		);

		wp_localize_script(
			'curio-admin',
			'curioAdmin',
			array(
				'root'    => esc_url_raw( rest_url( REST::NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'tab'     => self::current_tab(),
				'strings' => array(
					'confirmDelete'    => __( 'Delete this entry?', 'curio-ai-chat' ),
					'confirmClear'     => __( 'This removes every entry in this group. There is no undo. Continue?', 'curio-ai-chat' ),
					'confirmClearLog'  => __( 'Delete every stored conversation? There is no undo.', 'curio-ai-chat' ),
					'saving'           => __( 'Saving…', 'curio-ai-chat' ),
					'saved'            => __( 'Saved.', 'curio-ai-chat' ),
					'testing'          => __( 'Testing…', 'curio-ai-chat' ),
					'refreshing'       => __( 'Asking the provider…', 'curio-ai-chat' ),
					'indexing'         => __( 'Indexing…', 'curio-ai-chat' ),
					/* translators: 1: items done, 2: items total. */
					'progress'         => __( 'Indexed %1$d of %2$d', 'curio-ai-chat' ),
					'indexDone'        => __( 'Indexing finished.', 'curio-ai-chat' ),
					'networkError'     => __( 'That request did not get through. Check your connection and try again.', 'curio-ai-chat' ),
					'chooseAvatar'     => __( 'Choose an avatar', 'curio-ai-chat' ),
					'useImage'         => __( 'Use this image', 'curio-ai-chat' ),
					'nothingToExport'  => __( 'There is nothing to export yet.', 'curio-ai-chat' ),
					'copied'           => __( 'Copied to the clipboard.', 'curio-ai-chat' ),
					'empty'            => __( 'Nothing here yet.', 'curio-ai-chat' ),
					/* translators: %s: the keywords stored against one knowledge entry. Written as one string rather than a label joined to a value, because the punctuation between them is not a colon in every language. */
					'keywordsPill'     => __( 'Keywords: %s', 'curio-ai-chat' ),
					'edit'             => __( 'Edit', 'curio-ai-chat' ),
					'delete'           => __( 'Delete', 'curio-ai-chat' ),
					'sourceManual'     => __( 'Written by you', 'curio-ai-chat' ),
					'sourcePost'       => __( 'Site content', 'curio-ai-chat' ),
					'sourceProduct'    => __( 'Product', 'curio-ai-chat' ),
					/* translators: %s: a contrast ratio, such as 7.4. */
					'contrastPass'     => __( 'Contrast %s:1 — passes WCAG AA.', 'curio-ai-chat' ),
					/* translators: %s: a contrast ratio, such as 3.4. */
					'contrastLarge'    => __( 'Contrast %s:1 — large text only. Small text will be hard to read.', 'curio-ai-chat' ),
					/* translators: %s: a contrast ratio, such as 1.9. */
					'contrastFail'     => __( 'Contrast %s:1 — fails WCAG AA. Pick a darker or lighter accent.', 'curio-ai-chat' ),
					'contrastUnknown'  => __( 'Finish the colour to see its contrast.', 'curio-ai-chat' ),
				),
			)
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this plugin.', 'curio-ai-chat' ) );
		}

		$tab  = self::current_tab();
		$view = CURIO_DIR . 'includes/admin/views/' . $tab . '.php';

		require CURIO_DIR . 'includes/admin/views/header.php';

		if ( is_readable( $view ) ) {
			require $view;
		}

		require CURIO_DIR . 'includes/admin/views/footer.php';
	}

	/**
	 * Emit the hidden field that tells the sanitiser which keys this form owns.
	 *
	 * @param string[] $fields Setting keys on this form.
	 * @return void
	 */
	public static function fields( array $fields ): void {
		printf(
			'<input type="hidden" name="%s[_fields]" value="%s" />',
			esc_attr( Options::OPTION ),
			esc_attr( implode( ',', $fields ) )
		);
	}

	/**
	 * The `name` attribute for one setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function name( string $key ): string {
		return Options::OPTION . '[' . $key . ']';
	}

	/**
	 * A checkbox bound to one boolean setting.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label text.
	 * @param string $description Optional help text.
	 * @return void
	 */
	public static function checkbox( string $key, string $label, string $description = '' ): void {
		?>
		<label class="curio-check">
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::name( $key ) ); ?>"
				value="1"
				<?php checked( Options::flag( $key ) ); ?>
			/>
			<span>
				<strong><?php echo esc_html( $label ); ?></strong>
				<?php if ( '' !== $description ) : ?>
					<span class="curio-hint"><?php echo esc_html( $description ); ?></span>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * Headline numbers used across several tabs.
	 *
	 * @return array<string,mixed>
	 */
	public static function stats(): array {
		$counts = Knowledge_Store::counts_by_type();

		return array(
			'total'     => Knowledge_Store::count(),
			'manual'    => (int) ( $counts[ Knowledge_Store::SOURCE_MANUAL ] ?? 0 ),
			'posts'     => (int) ( $counts[ Knowledge_Store::SOURCE_POST ] ?? 0 ),
			'products'  => (int) ( $counts[ Knowledge_Store::SOURCE_PRODUCT ] ?? 0 ),
			'usage'     => Rate_Limiter::month_usage(),
			'log'       => Conversation_Log::totals(),
			'indexing'  => Indexer::state(),
			'provider'  => Registry::current(),
			'encrypted' => Secret::is_encrypted(),
		);
	}
}
