<?php
/**
 * Asynchronous signed delivery of CMS events.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Events;

use ElanBridge\Connection\ConnectionManager;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron worker for the durable event outbox.
 */
final class EventDispatcher {

	public const CRON_HOOK = 'elan_bridge_deliver_events';

	private const CRON_SCHEDULE = 'elan_bridge_every_minute';
	private const BATCH_SIZE    = 10;
	private const BASE_DELAY    = 60;
	private const MAX_DELAY     = 3600;

	/**
	 * Durable event store.
	 *
	 * @var OutboxStore
	 */
	private OutboxStore $outbox;

	/**
	 * Current Bridge connection.
	 *
	 * @var ConnectionManager
	 */
	private ConnectionManager $connection;

	/**
	 * HTTP POST implementation.
	 *
	 * @var callable(string,array<string,mixed>):mixed
	 */
	private $http_post;

	/**
	 * Build the background dispatcher.
	 *
	 * @param OutboxStore                                       $outbox     Durable event store.
	 * @param ConnectionManager                                 $connection Current Bridge connection.
	 * @param (callable(string,array<string,mixed>):mixed)|null $http_post  Injectable HTTP client for tests.
	 */
	public function __construct( OutboxStore $outbox, ConnectionManager $connection, ?callable $http_post = null ) {
		$this->outbox     = $outbox;
		$this->connection = $connection;
		$this->http_post  = $http_post ?? static fn( string $url, array $args ) => wp_remote_post( $url, $args );
	}

	/** Register cron and lifecycle hooks. */
	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Low-latency asynchronous delivery is the feature.
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( self::CRON_HOOK, array( $this, 'run' ), 10, 1 );
		add_action( 'elan_bridge_event_queued', array( $this, 'schedule_now' ), 10, 0 );
		add_action( 'elan_bridge_connected', array( $this, 'ensure_scheduled' ), 10, 0 );
		add_action( 'elan_bridge_disconnected', array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Add the event worker's minute schedule.
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function cron_schedules( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS, // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Low-latency asynchronous delivery is the feature.
			'display'  => __( 'Every minute (ELAN event delivery)', 'elan-bridge' ),
		);
		return $schedules;
	}

	/** Ensure the recurring worker is scheduled while connected. */
	public function ensure_scheduled(): void {
		if ( ! $this->connection->is_connected() || wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
	}

	/** Schedule a near-immediate worker after an event is queued. */
	public function schedule_now(): void {
		if ( ! $this->connection->is_connected() || wp_next_scheduled( self::CRON_HOOK, array( 'immediate' ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + 1, self::CRON_HOOK, array( 'immediate' ) );
	}

	/** Stop delivery and cancel queued events after disconnect. */
	public function handle_disconnect(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( 'immediate' ) );
		$this->outbox->cancel_active( __( 'Connection was disconnected.', 'elan-bridge' ) );
	}

	/** Clear delivery cron while the plugin is inactive. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( 'immediate' ) );
	}

	/**
	 * Run one bounded delivery batch.
	 *
	 * @param string $reason Scheduling reason, reserved for diagnostics.
	 */
	public function run( string $reason = 'scheduled' ): void {
		unset( $reason );
		if ( ! $this->connection->is_connected() ) {
			return;
		}
		foreach ( $this->outbox->claim_due( self::BATCH_SIZE ) as $event ) {
			$this->dispatch( $event );
		}
		$this->outbox->cleanup();
	}

	/**
	 * Deliver one already-claimed event.
	 *
	 * @param array<string,mixed> $event Outbox row.
	 */
	public function dispatch( array $event ): void {
		$id       = (int) ( $event['id'] ?? 0 );
		$payload  = (string) ( $event['payload'] ?? '' );
		$event_id = (string) ( $event['event_id'] ?? '' );
		$conn     = $this->connection->connection();
		$secret   = (string) get_option( ConnectionManager::WEBHOOK_SECRET_OPTION, '' );
		if ( $id <= 0 || '' === $payload || '' === $event_id ) {
			$this->outbox->mark_dead( $id, __( 'Outbox row is incomplete.', 'elan-bridge' ) );
			return;
		}
		if ( '' === $secret || empty( $conn['connection_id'] ) || empty( $conn['bridge_url'] ) ) {
			$this->outbox->mark_dead( $id, __( 'Event delivery is not configured.', 'elan-bridge' ) );
			return;
		}

		$timestamp = (string) time();
		$url       = untrailingslashit( (string) $conn['bridge_url'] )
			. '/api/connectors/wordpress/webhook/' . rawurlencode( (string) $conn['connection_id'] );
		$response  = ( $this->http_post )(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Accept'           => 'application/json',
					'Content-Type'     => 'application/json',
					'X-ELAN-Event-ID'  => $event_id,
					'X-ELAN-Timestamp' => $timestamp,
					'X-ELAN-Signature' => EventSigner::sign( $secret, $timestamp, $payload ),
				),
				'body'        => $payload,
			),
		);

		if ( is_wp_error( $response ) ) {
			$this->retry( $event, $response->get_error_message() );
			return;
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$result = self::classify_status( $code );
		if ( 'delivered' === $result ) {
			$this->outbox->mark_delivered( $id );
			return;
		}

		$body  = trim( wp_strip_all_tags( wp_remote_retrieve_body( $response ) ) );
		$error = sprintf( 'Bridge returned HTTP %d%s', $code, '' !== $body ? ': ' . $body : '.' );
		if ( 'retry' === $result ) {
			$this->retry( $event, $error );
			return;
		}
		$this->outbox->mark_dead( $id, $error );
	}

	/**
	 * Classify an HTTP status according to the delivery contract.
	 *
	 * @param int $status HTTP response status.
	 */
	public static function classify_status( int $status ): string {
		if ( $status >= 200 && $status < 300 ) {
			return 'delivered';
		}
		if ( 429 === $status || $status >= 500 || 0 === $status ) {
			return 'retry';
		}
		return 'permanent';
	}

	/**
	 * Exponential delay with capped proportional jitter.
	 *
	 * @param int $attempt One-based delivery attempt.
	 * @param int $jitter Deterministic jitter in tests; production supplies random jitter.
	 */
	public static function retry_delay( int $attempt, int $jitter = 0 ): int {
		$power = max( 0, min( 10, $attempt - 1 ) );
		$base  = min( self::MAX_DELAY, self::BASE_DELAY * ( 2 ** $power ) );
		return min( self::MAX_DELAY, $base + max( 0, min( intdiv( $base, 4 ), $jitter ) ) );
	}

	/**
	 * Requeue a transiently failed delivery.
	 *
	 * @param array<string,mixed> $event Claimed outbox row.
	 * @param string              $error Delivery error.
	 */
	private function retry( array $event, string $error ): void {
		$attempt = max( 1, (int) ( $event['attempt_count'] ?? 1 ) );
		$base    = min( self::MAX_DELAY, self::BASE_DELAY * ( 2 ** max( 0, min( 10, $attempt - 1 ) ) ) );
		$jitter  = random_int( 0, max( 1, intdiv( $base, 4 ) ) );
		$this->outbox->mark_retry( $event, self::retry_delay( $attempt, $jitter ), $error );
	}
}
