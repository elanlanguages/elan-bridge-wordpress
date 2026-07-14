<?php
/**
 * Event delivery classification and signing tests.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Tests\Events;

use ElanBridge\Connection\ConnectionManager;
use ElanBridge\Events\EventDispatcher;
use ElanBridge\Events\EventSigner;
use ElanBridge\Tests\Support\InMemoryOutbox;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class EventDispatcherTest extends TestCase {

	protected function setUp(): void {
		eb_reset_test_state();
		update_option(
			ConnectionManager::CONNECTION_OPTION,
			array(
				'status'        => 'connected',
				'connection_id' => 'conn-wp',
				'bridge_url'    => 'https://bridge.example/',
			)
		);
		add_option( ConnectionManager::WEBHOOK_SECRET_OPTION, 'event-secret', '', 'no' );
	}

	/** @return array<string,mixed> */
	private function event(): array {
		$payload = '{"schema_version":1,"event_id":"evt-1","type":"cms.resource.changed","occurred_at":"2026-07-14T10:00:00Z","resource":{"id":"99","type":"page","source_locale":"en","version":"digest"}}';
		return array(
			'id'            => 7,
			'event_id'      => 'evt-1',
			'payload'       => $payload,
			'attempt_count' => 1,
			'resource_id'   => 99,
			'resource_type' => 'page',
		);
	}

	public function test_success_posts_exact_body_with_signed_headers(): void {
		$outbox     = new InMemoryOutbox();
		$capture    = array();
		$http       = static function ( string $url, array $args ) use ( &$capture ) {
			$capture = compact( 'url', 'args' );
			return array(
				'response' => array( 'code' => 202 ),
				'body'     => '{"status":"accepted"}',
			);
		};
		$dispatcher = new EventDispatcher( $outbox, new ConnectionManager(), $http );

		$dispatcher->dispatch( $this->event() );

		$this->assertSame( array( 7 ), $outbox->delivered );
		$this->assertSame( 'https://bridge.example/api/connectors/wordpress/webhook/conn-wp', $capture['url'] );
		$this->assertSame( $this->event()['payload'], $capture['args']['body'] );
		$this->assertSame( 'evt-1', $capture['args']['headers']['X-ELAN-Event-ID'] );
		$timestamp = $capture['args']['headers']['X-ELAN-Timestamp'];
		$this->assertSame(
			EventSigner::sign( 'event-secret', $timestamp, $this->event()['payload'] ),
			$capture['args']['headers']['X-ELAN-Signature']
		);
	}

	public function test_network_error_is_retried(): void {
		$outbox     = new InMemoryOutbox();
		$dispatcher = new EventDispatcher(
			$outbox,
			new ConnectionManager(),
			static fn() => new WP_Error( 'timeout', 'Bridge timed out' )
		);

		$dispatcher->dispatch( $this->event() );

		$this->assertCount( 1, $outbox->retried );
		$this->assertSame( 'Bridge timed out', $outbox->retried[0]['error'] );
		$this->assertGreaterThanOrEqual( 60, $outbox->retried[0]['delay'] );
		$this->assertLessThanOrEqual( 75, $outbox->retried[0]['delay'] );
	}

	public function test_permanent_signature_error_is_not_retried(): void {
		$outbox     = new InMemoryOutbox();
		$dispatcher = new EventDispatcher(
			$outbox,
			new ConnectionManager(),
			static fn() => array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"detail":"bad signature"}',
			)
		);

		$dispatcher->dispatch( $this->event() );

		$this->assertCount( 0, $outbox->retried );
		$this->assertSame( 7, $outbox->dead[0]['id'] );
		$this->assertStringContainsString( 'HTTP 401', $outbox->dead[0]['error'] );
	}

	/** @dataProvider status_provider */
	public function test_retry_classification( int $status, string $expected ): void {
		$this->assertSame( $expected, EventDispatcher::classify_status( $status ) );
	}

	/** @return array<string,array{0:int,1:string}> */
	public function status_provider(): array {
		return array(
			'accepted'           => array( 202, 'delivered' ),
			'duplicate accepted' => array( 200, 'delivered' ),
			'rate limited'       => array( 429, 'retry' ),
			'server error'       => array( 503, 'retry' ),
			'configuration'      => array( 409, 'permanent' ),
			'authentication'     => array( 401, 'permanent' ),
		);
	}

	public function test_backoff_is_exponential_and_capped(): void {
		$this->assertSame( 60, EventDispatcher::retry_delay( 1 ) );
		$this->assertSame( 150, EventDispatcher::retry_delay( 2, 30 ) );
		$this->assertSame( 3600, EventDispatcher::retry_delay( 20, 900 ) );
	}

	public function test_disconnect_cancels_delivery_and_clears_cron(): void {
		$outbox     = new InMemoryOutbox();
		$connection = new ConnectionManager();
		$dispatcher = new EventDispatcher( $outbox, $connection );
		$dispatcher->register_hooks();
		$dispatcher->ensure_scheduled();
		$dispatcher->schedule_now();
		$this->assertNotFalse( wp_next_scheduled( EventDispatcher::CRON_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( EventDispatcher::CRON_HOOK, array( 'immediate' ) ) );

		$connection->disconnect();

		$this->assertFalse( wp_next_scheduled( EventDispatcher::CRON_HOOK ) );
		$this->assertFalse( wp_next_scheduled( EventDispatcher::CRON_HOOK, array( 'immediate' ) ) );
		$this->assertSame( 'Connection was disconnected.', $outbox->cancel_reason );
	}
}
