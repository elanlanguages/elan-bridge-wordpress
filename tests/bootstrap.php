<?php
/**
 * Test bootstrap: just enough of WordPress to exercise the plugin's pure-logic
 * classes (like the updater) without a database or a full WP install.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

define( 'ABSPATH', sys_get_temp_dir() . '/' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

// In-memory transient store + a swappable HTTP responder the tests drive.
$GLOBALS['__eb_transients'] = array();
$GLOBALS['__eb_http']       = static fn( $url, $args ) => array( 'response' => array( 'code' => 200 ), 'body' => '' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return implode( '/', array_slice( explode( '/', $file ), -2 ) );
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ): bool {
		return true; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ): bool {
		return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['__eb_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['__eb_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['__eb_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		return ( $GLOBALS['__eb_http'] )( $url, $args );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( string $filename = '' ): string {
		return (string) tempnam( sys_get_temp_dir(), 'eb' );
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): void {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

require __DIR__ . '/../includes/Updater/GitHubReleaseUpdater.php';

/** Reset shared test state between tests. */
function eb_reset_test_state(): void {
	$GLOBALS['__eb_transients'] = array();
	$GLOBALS['__eb_http']       = static fn( $url, $args ) => array( 'response' => array( 'code' => 200 ), 'body' => '' );
}

/** Point the fake HTTP layer at a responder. */
function eb_set_http( callable $responder ): void {
	$GLOBALS['__eb_http'] = $responder;
}
