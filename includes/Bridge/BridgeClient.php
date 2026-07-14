<?php
/**
 * Thin HTTP client for the ELAN AI Bridge.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the bridge's connector endpoints, authenticated with the
 * customer's ELAN API key. Pairing sends credentials server-to-server; after
 * that, the event dispatcher posts only signed resource identifiers while the
 * bridge continues pulling content through the WordPress REST API.
 */
final class BridgeClient {

	private string $base_url;

	public function __construct( string $base_url ) {
		$this->base_url = untrailingslashit( $base_url );
	}

	/**
	 * Register this WordPress site as a connection on the bridge.
	 *
	 * The bridge authenticates the ELAN key, resolves the org, and stores a
	 * `wordpress` / `app_password` connection pointing back at this site.
	 *
	 * @param string               $elan_key Customer ELAN API key (Bearer).
	 * @param array<string, mixed> $payload  {site_url, username, app_password, label, post_types}.
	 * @return array<string, mixed>|\WP_Error Decoded JSON (expects `connection_id`) or error.
	 */
	public function register( string $elan_key, array $payload ) {
		return $this->request( 'POST', '/api/connectors/wordpress/register', $elan_key, $payload );
	}

	/**
	 * Step 1 of the org-select pairing: hand the freshly-minted App Password to
	 * the bridge server-to-server (no ELAN key) and get back an opaque handoff.
	 *
	 * @param array<string, mixed> $payload {site_url, username, app_password, label, post_types, webhook_secret}.
	 * @return array<string, mixed>|\WP_Error Decoded JSON (expects `handoff_id`) or error.
	 */
	public function initiate( array $payload ) {
		return $this->request( 'POST', '/api/connectors/wordpress/initiate', '', $payload );
	}

	/**
	 * Best-effort de-registration of a connection on the bridge.
	 *
	 * @return true|\WP_Error
	 */
	public function unregister( string $elan_key, string $connection_id ) {
		$result = $this->request(
			'DELETE',
			'/api/connectors/wordpress/' . rawurlencode( $connection_id ),
			$elan_key,
			null
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path, string $elan_key, ?array $body ) {
		$headers = array( 'Accept' => 'application/json' );
		// The org-select pairing has no ELAN key; only send a Bearer when present.
		if ( '' !== $elan_key ) {
			$headers['Authorization'] = 'Bearer ' . $elan_key;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$resp = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code   = (int) wp_remote_retrieve_response_code( $resp );
		$parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code < 200 || $code >= 300 ) {
			// Nitro surfaces the reason in `message`; FastAPI uses `detail`.
			$message = '';
			if ( is_array( $parsed ) ) {
				$message = (string) ( $parsed['message'] ?? $parsed['detail'] ?? '' );
			}
			if ( '' === $message ) {
				/* translators: %d: HTTP status code */
				$message = sprintf( __( 'Bridge returned HTTP %d.', 'elan-bridge' ), $code );
			}
			return new \WP_Error( 'elan_bridge_http', $message, array( 'status' => $code ) );
		}

		return is_array( $parsed ) ? $parsed : array();
	}
}
