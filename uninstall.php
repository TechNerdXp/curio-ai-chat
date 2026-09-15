<?php
/**
 * Uninstall routine.
 *
 * @package Curio
 */

// WordPress defines this when it invokes an uninstall script. Without the
// guard the file is a publicly reachable URL that drops database tables.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Nothing is deleted unless the site owner asked for it.
 *
 * Deleting a plugin is often a step in debugging — remove it, confirm the
 * conflict, put it back. A plugin that treats that as permission to destroy an
 * afternoon of somebody's written answers has made a decision that was not its
 * to make. The switch is on the Insights tab, off by default, and this reads it.
 */
$curio_settings = get_option( 'curio_settings', array() );
$curio_wipe     = is_array( $curio_settings ) && ! empty( $curio_settings['delete_data_on_uninstall'] );

// API keys are removed either way. A credential has no reason to outlive the
// plugin that used it, and leaving one in the database of a site that no longer
// has this plugin installed is a liability with no upside.
foreach ( array( 'anthropic', 'openai', 'gemini' ) as $curio_provider ) {
	delete_option( 'curio_key_' . $curio_provider );
}

if ( ! $curio_wipe ) {
	return;
}

global $wpdb;

foreach ( array(
	'curio_settings',
	'curio_db_version',
	'curio_usage',
	'curio_index_state',
	'curio_fulltext',
	'curio_cache_version',
	'curio_activated_at',
) as $curio_option ) {
	delete_option( $curio_option );
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall runs once, outside any cache, and table names cannot be parameterised.
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'curio_knowledge`' );
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'curio_log`' );

// Transients this plugin created: session tokens, rate-limit counters, cached
// answers and cached model lists. They expire on their own, but leaving a few
// thousand rows behind in the options table is untidy in exactly the way that
// gets a plugin blamed for a slow site it is no longer even installed on.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_curio_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_curio_' ) . '%'
	)
);
// phpcs:enable

wp_clear_scheduled_hook( 'curio_daily_maintenance' );
