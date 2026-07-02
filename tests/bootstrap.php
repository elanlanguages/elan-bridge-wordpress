<?php
/**
 * Test bootstrap: just enough of WordPress to exercise the plugin's pure-logic
 * classes (like the API-key manager) without a database or a full WP install.
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

define( 'ABSPATH', sys_get_temp_dir() . '/' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

// In-memory options store the fake get_option/update_option operate on.
$GLOBALS['__ta_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['__ta_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['__ta_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['__ta_options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

// Records the last user id CmsController::authorize() assumed on a valid key.
$GLOBALS['__ta_current_user'] = 0;

if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( int $id ): void {
		$GLOBALS['__ta_current_user'] = $id;
	}
}
if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	function rest_authorization_required_code(): int {
		return 401;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		/** @var array<string,mixed> */
		public array $data;
		/** @param array<string,mixed> $data */
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/** A minimal request double: just enough header lookup for auth tests. */
	class WP_REST_Request {
		/** @var array<string,string> */
		private array $headers;
		/** @param array<string,string> $headers */
		public function __construct( array $headers = array() ) {
			// Normalise keys the way get_header() matches: lowercase.
			$this->headers = array();
			foreach ( $headers as $name => $value ) {
				$this->headers[ strtolower( (string) $name ) ] = (string) $value;
			}
		}
		public function get_header( string $name ): ?string {
			return $this->headers[ strtolower( $name ) ] ?? null;
		}
	}
}

/** Reset shared test state between tests. */
function ta_reset_test_state(): void {
	$GLOBALS['__ta_options']      = array();
	$GLOBALS['__ta_current_user'] = 0;
}

require __DIR__ . '/../includes/Auth/ApiKeyManager.php';
require __DIR__ . '/../includes/Wpml/WpmlReader.php';
require __DIR__ . '/../includes/Rest/CmsController.php';
