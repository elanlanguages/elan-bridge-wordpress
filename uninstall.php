<?php
/**
 * Fired when the plugin is deleted.
 *
 * @package ElanBridge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'elan_bridge_settings' );
