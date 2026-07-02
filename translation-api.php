<?php
/**
 * Plugin Name:       Translation API
 * Plugin URI:        https://github.com/elanlanguages/translation-api-wordpress
 * Description:       Exposes WPML-managed pages and their translations over a simple REST API so any external translation system can extract source strings and write translations back. Authenticated with an API key you create in the plugin's settings — no external service required.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            ELAN Languages
 * License:           Proprietary
 * Text Domain:       translation-api
 * Domain Path:       /languages
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

namespace TranslationApi;

// Block direct access — the WordPress canonical guard.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TRANSLATION_API_VERSION', '0.1.0' );
define( 'TRANSLATION_API_FILE', __FILE__ );
define( 'TRANSLATION_API_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRANSLATION_API_URL', plugin_dir_url( __FILE__ ) );

// Prefer Composer's autoloader; fall back to a tiny PSR-4 loader so the
// plugin still boots in a fresh wp-env before `composer install` has run.
if ( is_readable( TRANSLATION_API_DIR . 'vendor/autoload.php' ) ) {
	require TRANSLATION_API_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'TranslationApi\\';
			if ( 0 !== strpos( $class, $prefix ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$path     = TRANSLATION_API_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);
}

// Boot once WordPress (and plugins like WPML) are loaded.
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
