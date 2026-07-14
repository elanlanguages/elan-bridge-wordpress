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
 * Starts the org-select pairing flow. Pairing sends credentials
 * server-to-server; after that, the event dispatcher posts only signed
 * resource identifiers while the bridge continues pulling content through the
 * WordPress REST API.
 */
final class BridgeClient {

	/**
	 * Bridge app base URL.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Create a Bridge client.
	 *
	 * @param string $base_url Bridge app base URL.
	 */
	public function __construct( string $base_url ) {
		$this->base_url = untrailingslashit( $base_url );
	}

	/**
	 * Step 1 of the org-select pairing: hand the freshly-minted App Password to
	 * the bridge server-to-server (no ELAN key) and get back an opaque handoff.
	 *
	 * @param array<string, mixed> $payload {site_url, username, app_password, label, post_types, webhook_secret}.
	 * @return array<string, mixed>|\WP_Error Decoded JSON (expects `handoff_id`) or error.
	 */
	public function initiate( array $payload ) {
		return $this->request( '/api/connectors/wordpress/initiate', $payload );
	}

	/**
	 * Send one JSON request to the Bridge app.
	 *
	 * @param string               $path Request path.
	 * @param array<string, mixed> $body JSON request body.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $path, array $body ) {
		$args = array(
			'method'  => 'POST',
			'timeout' => 20,
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		);

		$resp = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code   = (int) wp_remote_retrieve_response_code( $resp );
		$parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $parsed ) ? (string) ( $parsed['message'] ?? '' ) : '';
			if ( '' === $message ) {
				/* translators: %d: HTTP status code */
				$message = sprintf( __( 'Bridge returned HTTP %d.', 'elan-bridge' ), $code );
			}
			return new \WP_Error( 'elan_bridge_http', $message, array( 'status' => $code ) );
		}

		return is_array( $parsed ) ? $parsed : array();
	}
}
