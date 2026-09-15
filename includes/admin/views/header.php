<?php
/**
 * Settings screen header and tab bar.
 *
 * @package Curio
 */

namespace Curio\Admin;

use Curio\Options;

defined( 'ABSPATH' ) || exit;

$curio_tab   = Admin::current_tab();
$curio_stats = Admin::stats();
$curio_ready = $curio_stats['total'] > 0;
?>
<div class="wrap curio-wrap">

	<h1 class="curio-heading">
		<span class="curio-mark">
			<?php require CURIO_DIR . 'templates/mark.php'; ?>
		</span>
		<span class="curio-wordmark">
			<span class="curio-wordmark-name">Curio</span>
			<span class="curio-wordmark-tag"><?php esc_html_e( 'Grounded AI chat', 'curio-ai-chat' ); ?></span>
		</span>
	</h1>

	<?php settings_errors(); ?>

	<?php
	/*
	 * The one thing a new user most needs told, said once, at the top, and
	 * gone the moment it stops being true. An assistant with an empty
	 * knowledge base is switched on and silent, which is indistinguishable
	 * from broken unless somebody says so out loud.
	 */
	if ( ! $curio_ready ) :
		?>
		<div class="curio-banner curio-banner-attention">
			<p>
				<strong><?php esc_html_e( 'The assistant knows nothing yet, so it will decline every question.', 'curio-ai-chat' ); ?></strong>
				<?php esc_html_e( 'That is the correct starting state — it will never invent an answer. Give it something to work from on the Sources or Knowledge tab.', 'curio-ai-chat' ); ?>
			</p>
		</div>
		<?php
	endif;

	if ( ! Options::flag( 'enabled' ) ) :
		?>
		<div class="curio-banner curio-banner-muted">
			<p><?php esc_html_e( 'The widget is currently switched off and is not appearing on the site. Turn it back on from the Appearance tab.', 'curio-ai-chat' ); ?></p>
		</div>
		<?php
	endif;
	?>

	<div class="curio-stats" role="list">
		<span class="curio-stat" role="listitem">
			<strong><?php echo esc_html( number_format_i18n( $curio_stats['total'] ) ); ?></strong>
			<span><?php esc_html_e( 'things it knows', 'curio-ai-chat' ); ?></span>
		</span>
		<span class="curio-stat" role="listitem">
			<strong><?php echo esc_html( $curio_stats['provider']->label() ); ?></strong>
			<span><?php esc_html_e( 'answering', 'curio-ai-chat' ); ?></span>
		</span>
		<span class="curio-stat" role="listitem">
			<strong><?php echo esc_html( number_format_i18n( $curio_stats['usage']['calls'] ) ); ?></strong>
			<span><?php esc_html_e( 'API calls this month', 'curio-ai-chat' ); ?></span>
		</span>
		<span class="curio-stat" role="listitem">
			<strong><?php echo esc_html( number_format_i18n( $curio_stats['usage']['cached'] ) ); ?></strong>
			<span><?php esc_html_e( 'answered free from cache', 'curio-ai-chat' ); ?></span>
		</span>
	</div>

	<nav class="nav-tab-wrapper curio-tabs" aria-label="<?php esc_attr_e( 'Chat assistant settings', 'curio-ai-chat' ); ?>">
		<?php
		foreach ( Admin::tabs() as $curio_slug => $curio_label ) :
			$curio_is_current = ( $curio_tab === $curio_slug );
			?>
			<a
				href="<?php echo esc_url( Admin::tab_url( $curio_slug ) ); ?>"
				class="<?php echo esc_attr( $curio_is_current ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>"
				<?php
				if ( $curio_is_current ) {
					echo 'aria-current="page"';
				}
				?>
			>
				<?php echo esc_html( $curio_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="curio-body">
