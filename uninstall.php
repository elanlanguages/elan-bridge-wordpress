<?php
/**
 * Fired when the plugin is deleted.
 *
 * @package ElanBridge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$connection = get_option( 'elan_bridge_connection', array() );
if ( is_array( $connection ) && ! empty( $connection['app_password_uuid'] ) && class_exists( 'WP_Application_Passwords' ) ) {
	WP_Application_Passwords::delete_application_password(
		(int) ( $connection['user_id'] ?? 0 ),
		(string) $connection['app_password_uuid']
	);
}

wp_clear_scheduled_hook( 'elan_bridge_deliver_events' );
wp_clear_scheduled_hook( 'elan_bridge_deliver_events', array( 'immediate' ) );

delete_option( 'elan_bridge_settings' );
delete_option( 'elan_bridge_connection' );
delete_option( 'elan_bridge_webhook_secret' );
delete_option( 'elan_bridge_event_outbox_version' );
delete_post_meta_by_key( '_elan_bridge_last_event_digest' );

global $wpdb;
$table = $wpdb->prefix . 'elan_bridge_event_outbox';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
