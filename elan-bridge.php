<?php
/**
 * Plugin Name:       ELAN AI Bridge
 * Plugin URI:        https://elanlanguages.com
 * Description:       Exposes WPML-managed pages and their translations to the ELAN AI Bridge in the canonical CMS-content shape, so the bridge can pull source segments and write translations back.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            ELAN Languages
 * License:           Proprietary
 * Text Domain:       elan-bridge
 * Domain Path:       /languages
 * Update URI:        https://github.com/elanlanguages/elan-bridge-wordpress
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge;

// Block direct access — the WordPress canonical guard.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ELAN_BRIDGE_VERSION', '0.1.0' );
define( 'ELAN_BRIDGE_FILE', __FILE__ );
define( 'ELAN_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ELAN_BRIDGE_URL', plugin_dir_url( __FILE__ ) );

// Prefer Composer's autoloader; fall back to a tiny PSR-4 loader so the
// plugin still boots in a fresh wp-env before `composer install` has run.
if ( is_readable( ELAN_BRIDGE_DIR . 'vendor/autoload.php' ) ) {
	require ELAN_BRIDGE_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'ElanBridge\\';
			if ( 0 !== strpos( $class, $prefix ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$path     = ELAN_BRIDGE_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);
}

register_activation_hook( ELAN_BRIDGE_FILE, array( Plugin::class, 'activate' ) );
register_deactivation_hook( ELAN_BRIDGE_FILE, array( Plugin::class, 'deactivate' ) );

// Boot once WordPress (and plugins like WPML) are loaded.
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
