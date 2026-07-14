<?php
/**
 * Lightweight WordPress test doubles for plugin unit tests.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

define( 'ABSPATH', sys_get_temp_dir() . '/' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['__eb_transients'] = array();
$GLOBALS['__eb_http']       = static fn( $url, $args ) => array(
	'response' => array( 'code' => 200 ),
	'body'     => '',
);
$GLOBALS['__eb_http_post']  = static fn( $url, $args ) => array(
	'response' => array( 'code' => 202 ),
	'body'     => '{}',
);
$GLOBALS['__eb_filters']    = array();
$GLOBALS['__eb_options']    = array();
$GLOBALS['__eb_post_meta']  = array();
$GLOBALS['__eb_scheduled']  = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID                   = 0;
		public string $post_type         = 'page';
		public string $post_status       = 'publish';
		public string $post_title        = '';
		public string $post_content      = '';
		public string $post_excerpt      = '';
		public string $post_modified_gmt = '2026-07-14 10:00:00';
		public string $post_name         = 'test';

		/** @param array<string,mixed> $data */
		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	class WP_Application_Passwords {
		/** @var array<int,array{0:int,1:string}> */
		public static array $deleted = array();

		public static function delete_application_password( int $user_id, string $uuid ): bool {
			self::$deleted[] = array( $user_id, $uuid );
			return true;
		}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;

		public function get_charset_collate(): string {
			return '';
		}

		public function prepare( string $query, ...$args ): string {
			$format = str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query );
			return vsprintf( $format, $args );
		}

		public function get_var( string $query ) {
			unset( $query );
			return null;
		}

		public function get_results( string $query, $output = null ): array {
			unset( $query, $output );
			return array();
		}

		public function get_row( string $query, $output = null ) {
			unset( $query, $output );
			return null;
		}

		public function get_col( string $query ): array {
			unset( $query );
			return array();
		}

		public function query( string $query ) {
			unset( $query );
			return 0;
		}

		public function insert( string $table, array $data ) {
			unset( $table, $data );
			return false;
		}

		public function update( string $table, array $data, array $where ) {
			unset( $table, $data, $where );
			return false;
		}
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return implode( '/', array_slice( explode( '/', $file ), -2 ) );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['__eb_filters'][ $tag ][ $priority ][] = array( $callback, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_filter( $tag, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value, ...$args ) {
		$callbacks = $GLOBALS['__eb_filters'][ $tag ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $at_priority ) {
			foreach ( $at_priority as [ $callback, $accepted_args ] ) {
				$all   = array_merge( array( $value ), $args );
				$value = $callback( ...array_slice( $all, 0, $accepted_args ) );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $tag, ...$args ): void {
		$callbacks = $GLOBALS['__eb_filters'][ $tag ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $at_priority ) {
			foreach ( $at_priority as [ $callback, $accepted_args ] ) {
				$callback( ...array_slice( $args, 0, $accepted_args ) );
			}
		}
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['__eb_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		unset( $ttl );
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

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		return ( $GLOBALS['__eb_http_post'] )( $url, $args );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( string $filename = '' ): string {
		unset( $filename );
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
		unset( $domain );
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

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['__eb_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $name, $value, string $deprecated = '', $autoload = 'yes' ): bool {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $name, $GLOBALS['__eb_options'] ) ) {
			return false;
		}
		$GLOBALS['__eb_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['__eb_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['__eb_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		$value = $GLOBALS['__eb_post_meta'][ $post_id ][ $key ] ?? '';
		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, $value ): bool {
		$GLOBALS['__eb_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( int $post_id ): bool {
		unset( $post_id );
		return false;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( int $post_id ): bool {
		unset( $post_id );
		return false;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		static $sequence = 0;
		++$sequence;
		return sprintf( '00000000-0000-4000-8000-%012d', $sequence );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		unset( $type, $gmt );
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = array() ) {
		return $GLOBALS['__eb_scheduled'][ $hook ][ md5( serialize( $args ) ) ] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		unset( $recurrence );
		$GLOBALS['__eb_scheduled'][ $hook ][ md5( serialize( $args ) ) ] = $timestamp;
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$GLOBALS['__eb_scheduled'][ $hook ][ md5( serialize( $args ) ) ] = $timestamp;
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = array() ): int {
		$key = md5( serialize( $args ) );
		if ( isset( $GLOBALS['__eb_scheduled'][ $hook ][ $key ] ) ) {
			unset( $GLOBALS['__eb_scheduled'][ $hook ][ $key ] );
			return 1;
		}
		return 0;
	}
}

spl_autoload_register(
	static function ( string $class ): void {
		$test_prefix = 'ElanBridge\\Tests\\';
		if ( 0 === strpos( $class, $test_prefix ) ) {
			$test_path = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, strlen( $test_prefix ) ) ) . '.php';
			if ( is_readable( $test_path ) ) {
				require $test_path;
			}
			return;
		}
		$prefix = 'ElanBridge\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$path = __DIR__ . '/../includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/** Reset shared test state between tests. */
function eb_reset_test_state(): void {
	$GLOBALS['__eb_transients']        = array();
	$GLOBALS['__eb_http']              = static fn( $url, $args ) => array(
		'response' => array( 'code' => 200 ),
		'body'     => '',
	);
	$GLOBALS['__eb_http_post']         = static fn( $url, $args ) => array(
		'response' => array( 'code' => 202 ),
		'body'     => '{}',
	);
	$GLOBALS['__eb_filters']           = array();
	$GLOBALS['__eb_options']           = array();
	$GLOBALS['__eb_post_meta']         = array();
	$GLOBALS['__eb_scheduled']         = array();
	WP_Application_Passwords::$deleted = array();
}

/** Point the fake updater HTTP layer at a responder. */
function eb_set_http( callable $responder ): void {
	$GLOBALS['__eb_http'] = $responder;
}
