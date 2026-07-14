<?php
/**
 * "Connect with org-select" onboarding against the ELAN AI Bridge.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Connection;

use ElanBridge\Bridge\BridgeClient;
use WP_Application_Passwords;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Browser-redirect pairing (mirrors the ELAN MCP OAuth flow):
 *
 *  1. Admin clicks "Connect" → we mint a WP Application Password, hand it to the
 *     bridge server-to-server (`initiate`), get an opaque handoff, and redirect
 *     the browser to the ELAN consent page carrying the handoff + a `state` nonce.
 *  2. The admin logs into ELAN, picks the organization, approves. ELAN creates
 *     the connection under that org and redirects back to this settings page.
 *  3. Our callback validates `state` and records "Connected — <org>".
 *
 * No ELAN key is ever pasted; the org is chosen explicitly in ELAN. The App
 * Password never travels in a browser URL — only in the server-to-server
 * `initiate` body.
 */
final class ConnectionManager {

	public const CONNECTION_OPTION     = 'elan_bridge_connection';
	public const SETTINGS_OPTION       = 'elan_bridge_settings';
	public const WEBHOOK_SECRET_OPTION = 'elan_bridge_webhook_secret';

	public const CONNECT_INIT_ACTION = 'elan_bridge_connect_init';
	public const DISCONNECT_ACTION   = 'elan_bridge_disconnect';

	private const APP_PASSWORD_NAME = 'ELAN AI Bridge';
	private const STATE_TRANSIENT   = 'elan_bridge_state_';
	private const STATE_TTL         = 15 * MINUTE_IN_SECONDS;
	private const ABANDON_AFTER     = 20 * MINUTE_IN_SECONDS;

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::CONNECT_INIT_ACTION, array( $this, 'handle_connect_init' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'handle_disconnect' ) );
		// The cross-site return from ELAN lands as a GET on our settings page.
		add_action( 'admin_init', array( $this, 'handle_callback' ) );
		add_action( 'admin_init', array( $this, 'cleanup_abandoned' ) );
	}

	/** @return array<string, mixed> */
	public function connection(): array {
		$conn = get_option( self::CONNECTION_OPTION, array() );
		return is_array( $conn ) ? $conn : array();
	}

	public function is_connected(): bool {
		return 'connected' === ( $this->connection()['status'] ?? '' );
	}

	// -- step 1: start the pairing -----------------------------------------

	public function handle_connect_init(): void {
		$this->guard( self::CONNECT_INIT_ACTION );

		$bridge_url = esc_url_raw( wp_unslash( $_POST['bridge_url'] ?? '' ) );
		$post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) ) ) )
			: array( 'page' );
		$post_types = $post_types ?: array( 'page' );

		update_option(
			self::SETTINGS_OPTION,
			array(
				'bridge_url' => $bridge_url,
				'post_types' => $post_types,
			)
		);

		if ( '' === $bridge_url ) {
			$this->fail( __( 'Enter the ELAN AI Bridge URL.', 'elan-bridge' ) );
			$this->redirect( 'error' );
		}

		$user = wp_get_current_user();

		// A new pairing always rotates both integration credentials. This also
		// cleans up any unfinished attempt before minting the replacement.
		$prev = $this->connection();
		if ( ! empty( $prev['app_password_uuid'] ) ) {
			WP_Application_Passwords::delete_application_password(
				(int) ( $prev['user_id'] ?? $user->ID ),
				(string) $prev['app_password_uuid']
			);
		}
		if ( ! empty( $prev['state'] ) ) {
			delete_transient( self::STATE_TRANSIENT . $prev['state'] );
		}
		$this->clear_webhook_secret();

		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => self::APP_PASSWORD_NAME )
		);
		if ( is_wp_error( $created ) ) {
			$this->fail( $created->get_error_message() );
			$this->redirect( 'error' );
		}
		[ $password, $item ] = $created;
		$webhook_secret      = $this->new_webhook_secret();
		$this->store_webhook_secret( $webhook_secret );

		$resp = ( new BridgeClient( $bridge_url ) )->initiate(
			array(
				'site_url'       => home_url(),
				'username'       => $user->user_login,
				'app_password'   => $password,
				'label'          => get_bloginfo( 'name' ) . ' — ' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'post_types'     => $post_types,
				'webhook_secret' => $webhook_secret,
			)
		);

		if ( is_wp_error( $resp ) || empty( $resp['handoff_id'] ) ) {
			// Roll back the password we just minted so nothing dangles.
			WP_Application_Passwords::delete_application_password( $user->ID, $item['uuid'] );
			$this->clear_webhook_secret();
			$this->fail(
				is_wp_error( $resp )
					? $resp->get_error_message()
					: __( 'The bridge did not return a handoff.', 'elan-bridge' )
			);
			$this->redirect( 'error' );
		}

		$handoff = (string) $resp['handoff_id'];
		$state   = wp_generate_password( 32, false );

		update_option(
			self::CONNECTION_OPTION,
			array(
				'status'            => 'pending',
				'app_password_uuid' => $item['uuid'],
				'user_id'           => $user->ID,
				'state'             => $state,
				'bridge_url'        => $bridge_url,
				'created_at_ts'     => time(),
				'last_error'        => '',
			)
		);
		set_transient( self::STATE_TRANSIENT . $state, $user->ID, self::STATE_TTL );

		// Send the browser to the ELAN consent page (external origin → wp_redirect).
		$connect_url = add_query_arg(
			array(
				'handoff' => $handoff,
				'site'    => home_url(),
				'state'   => $state,
			),
			untrailingslashit( $bridge_url ) . '/connect/wordpress'
		);
		wp_redirect( $connect_url );
		exit;
	}

	// -- step 3: the return from ELAN --------------------------------------

	/**
	 * Detects the cross-site return (a GET on our settings page) and records the
	 * connection. No WP nonce can survive the redirect, so we bind it to the
	 * single-use `state` transient minted in step 1.
	 */
	public function handle_callback(): void {
		if ( ( $_GET['page'] ?? '' ) !== 'elan-bridge' ) {
			return;
		}
		if ( ( $_GET['elan_connected'] ?? '' ) !== '1' ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$conn = $this->connection();
		if ( 'pending' !== ( $conn['status'] ?? '' ) ) {
			// Already handled (e.g. a refresh) — just clean the URL.
			$this->redirect( $this->is_connected() ? 'connected' : 'error' );
		}

		$state         = sanitize_text_field( wp_unslash( $_GET['elan_state'] ?? '' ) );
		$transient_key = self::STATE_TRANSIENT . $state;
		if ( '' === $state || $state !== (string) ( $conn['state'] ?? '' ) || ! get_transient( $transient_key ) ) {
			$this->fail( __( 'Could not verify the connection. Please try connecting again.', 'elan-bridge' ) );
			$this->redirect( 'error' );
		}
		delete_transient( $transient_key ); // single use

		update_option(
			self::CONNECTION_OPTION,
			array(
				'status'            => 'connected',
				'connection_id'     => sanitize_text_field( wp_unslash( $_GET['connection_id'] ?? '' ) ),
				'organization'      => sanitize_text_field( wp_unslash( $_GET['organization'] ?? '' ) ),
				'user_id'           => (int) ( $conn['user_id'] ?? get_current_user_id() ),
				'app_password_uuid' => (string) ( $conn['app_password_uuid'] ?? '' ),
				'bridge_url'        => (string) ( $conn['bridge_url'] ?? '' ),
				'connected_at'      => current_time( 'mysql', true ),
				'last_error'        => '',
			)
		);
		do_action( 'elan_bridge_connected', (string) ( $_GET['connection_id'] ?? '' ) );

		$this->redirect( 'connected' );
	}

	/**
	 * Revoke the App Password from any pairing the admin never finished, so an
	 * abandoned consent doesn't leave a dangling credential in WordPress.
	 */
	public function cleanup_abandoned(): void {
		$conn = $this->connection();
		if ( 'pending' !== ( $conn['status'] ?? '' ) ) {
			return;
		}
		if ( ( time() - (int) ( $conn['created_at_ts'] ?? 0 ) ) <= self::ABANDON_AFTER ) {
			return;
		}
		if ( ! empty( $conn['app_password_uuid'] ) ) {
			WP_Application_Passwords::delete_application_password(
				(int) ( $conn['user_id'] ?? 0 ),
				(string) $conn['app_password_uuid']
			);
		}
		if ( ! empty( $conn['state'] ) ) {
			delete_transient( self::STATE_TRANSIENT . $conn['state'] );
		}
		$this->clear_webhook_secret();
		delete_option( self::CONNECTION_OPTION );
	}

	// -- disconnect --------------------------------------------------------

	public function handle_disconnect(): void {
		$this->guard( self::DISCONNECT_ACTION );
		$this->disconnect();
		$this->redirect( 'disconnected' );
	}

	/**
	 * Local-only disconnect: revoking the App Password is enough — the stored
	 * connection on the bridge instantly stops authenticating, and the next
	 * connect supersedes it (the bridge keeps one active WordPress connection
	 * per org). This flow holds no ELAN key to call the bridge with.
	 */
	public function disconnect(): void {
		$conn = $this->connection();
		if ( ! empty( $conn['app_password_uuid'] ) ) {
			WP_Application_Passwords::delete_application_password(
				(int) ( $conn['user_id'] ?? get_current_user_id() ),
				(string) $conn['app_password_uuid']
			);
		}
		if ( ! empty( $conn['state'] ) ) {
			delete_transient( self::STATE_TRANSIENT . $conn['state'] );
		}
		$this->clear_webhook_secret();
		delete_option( self::CONNECTION_OPTION );
		do_action( 'elan_bridge_disconnected' );
	}

	// -- helpers -----------------------------------------------------------

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'elan-bridge' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private function fail( string $message ): WP_Error {
		$conn = $this->connection();
		if ( ! empty( $conn['app_password_uuid'] ) ) {
			WP_Application_Passwords::delete_application_password(
				(int) ( $conn['user_id'] ?? get_current_user_id() ),
				(string) $conn['app_password_uuid']
			);
		}
		if ( ! empty( $conn['state'] ) ) {
			delete_transient( self::STATE_TRANSIENT . $conn['state'] );
		}
		$this->clear_webhook_secret();

		// Reset to a clean disconnected state carrying the error for display.
		update_option(
			self::CONNECTION_OPTION,
			array(
				'status'     => 'disconnected',
				'last_error' => $message,
			)
		);
		return new WP_Error( 'elan_bridge_connect_failed', $message );
	}

	private function new_webhook_secret(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return wp_generate_password( 64, false, false );
		}
	}

	private function store_webhook_secret( string $secret ): void {
		delete_option( self::WEBHOOK_SECRET_OPTION );
		// Explicitly non-autoloaded: this credential is only needed by cron.
		add_option( self::WEBHOOK_SECRET_OPTION, $secret, '', 'no' );
	}

	private function clear_webhook_secret(): void {
		delete_option( self::WEBHOOK_SECRET_OPTION );
	}

	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				'elan_notice',
				$notice,
				admin_url( 'options-general.php?page=elan-bridge' )
			)
		);
		exit;
	}
}
