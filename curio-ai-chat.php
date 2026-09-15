<?php
/**
 * Plugin Name:       Curio AI Chat
 * Description:       Answers visitors only from knowledge you control, and says it does not know rather than inventing a price, a date or a phone number.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            TechNerdXp
 * Author URI:        https://technerdxp.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       curio-ai-chat
 * Domain Path:       /languages
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Everything below is prefixed `CURIO_` / `Curio\` / `curio_`.
 *
 * WordPress.org's "common issues" page is explicit that two and three letter
 * prefixes are no longer acceptable at the current scale of the directory. The
 * predecessor of this plugin used `GCA_`, which is three, so every constant,
 * class, option key, table name, hook, script handle and CSS class was renamed.
 */
define( 'CURIO_VERSION', '1.0.0' );
define( 'CURIO_DB_VERSION', 1 );
define( 'CURIO_FILE', __FILE__ );
define( 'CURIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'CURIO_URL', plugin_dir_url( __FILE__ ) );
define( 'CURIO_BASENAME', plugin_basename( __FILE__ ) );
define( 'CURIO_SLUG', 'curio-ai-chat' );

/**
 * Where "hire the developer" points, in one place.
 *
 * The author's own site rather than a marketplace profile: a marketplace can
 * rename a profile URL or close an account, and every link shipped inside an
 * installed plugin would rot with it. This page is under the author's control
 * and forwards to whichever profile is current.
 *
 * Guideline 10 forbids front-end credits that are not opt-in, so this is used
 * on the plugin's own settings screen and in its Help tab only. The front-end
 * badge that uses it is a separate setting and ships switched off.
 */
define( 'CURIO_AUTHOR_URL', 'https://technerdxp.com/upwork' );

require_once CURIO_DIR . 'includes/class-curio-autoloader.php';
Autoloader::register();

/**
 * Boot the plugin.
 *
 * `plugins_loaded` rather than an immediate call: WooCommerce, custom post
 * types and any other plugin this one reads from are not registered until then,
 * and the source list in the admin screen is built from what actually exists.
 *
 * @return Plugin
 */
function curio(): Plugin {
	return Plugin::instance();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\curio' );

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Installer', 'deactivate' ) );
