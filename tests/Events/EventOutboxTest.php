<?php
/**
 * Production outbox coalescing tests.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Tests\Events;

use ElanBridge\Events\EventOutbox;
use PHPUnit\Framework\TestCase;

final class EventOutboxTest extends TestCase {

	protected function setUp(): void {
		eb_reset_test_state();
	}

	/** @return array<string,mixed> */
	private function event( string $event_id, string $version, int $resource_id = 99 ): array {
		return array(
			'schema_version' => 1,
			'event_id'       => $event_id,
			'type'           => 'cms.resource.changed',
			'occurred_at'    => '2026-07-14T10:00:00Z',
			'resource'       => array(
				'id'            => (string) $resource_id,
				'type'          => 'page',
				'source_locale' => 'en',
				'version'       => $version,
			),
		);
	}

	public function test_pending_saves_for_same_resource_coalesce_to_latest(): void {
		$db     = new CoalescingWpdb();
		$outbox = new EventOutbox( $db );

		$this->assertTrue( $outbox->enqueue( $this->event( 'evt-1', 'digest-1' ) ) );
		$this->assertTrue( $outbox->enqueue( $this->event( 'evt-2', 'digest-2' ) ) );

		$this->assertCount( 1, $db->rows );
		$this->assertSame( 'evt-2', $db->rows[1]['event_id'] );
		$this->assertSame( 'digest-2', $db->rows[1]['version'] );
		$this->assertSame( 0, $db->rows[1]['attempt_count'] );
		$this->assertStringContainsString( '"event_id":"evt-2"', $db->rows[1]['payload'] );
	}

	public function test_different_resources_keep_independent_pending_events(): void {
		$db     = new CoalescingWpdb();
		$outbox = new EventOutbox( $db );

		$outbox->enqueue( $this->event( 'evt-1', 'digest-1', 99 ) );
		$outbox->enqueue( $this->event( 'evt-2', 'digest-2', 100 ) );

		$this->assertCount( 2, $db->rows );
	}

	public function test_manual_retry_does_not_replace_newer_queued_version(): void {
		$db     = new CoalescingWpdb();
		$outbox = new EventOutbox( $db );
		$outbox->enqueue( $this->event( 'evt-new', 'digest-new' ) );
		$db->rows[2] = array(
			'id'            => 2,
			'event_id'      => 'evt-old',
			'resource_id'   => 99,
			'resource_type' => 'page',
			'version'       => 'digest-old',
			'payload'       => wp_json_encode( $this->event( 'evt-old', 'digest-old' ) ),
			'status'        => 'dead',
			'active_key'    => null,
		);

		$this->assertSame( 0, $outbox->retry_failures() );
		$this->assertSame( 'evt-new', $db->rows[1]['event_id'] );
		$this->assertSame( 'digest-new', $db->rows[1]['version'] );
		$this->assertSame( 'superseded', $db->rows[2]['status'] );
	}

	public function test_delivery_completion_cannot_overwrite_cancelled_rows(): void {
		$db          = new CoalescingWpdb();
		$outbox      = new EventOutbox( $db );
		$event       = $this->processing_row( 1 );
		$db->rows[1] = $event;

		$db->rows[1]['status'] = 'cancelled';
		$outbox->mark_delivered( 1 );
		$this->assertSame( 'cancelled', $db->rows[1]['status'] );

		$outbox->mark_dead( 1, 'Permanent failure after disconnect' );
		$this->assertSame( 'cancelled', $db->rows[1]['status'] );

		$outbox->mark_retry( $event, 60, 'Transient failure after disconnect' );
		$this->assertSame( 'cancelled', $db->rows[1]['status'] );
		$this->assertNull( $db->rows[1]['active_key'] );
	}

	public function test_duplicate_stale_recovery_does_not_supersede_same_row(): void {
		$db          = new CoalescingWpdb();
		$outbox      = new EventOutbox( $db );
		$event       = $this->processing_row( 1 );
		$db->rows[1] = $event;

		$outbox->mark_retry( $event, 1, 'First stale recovery' );
		$outbox->mark_retry( $event, 1, 'Concurrent stale recovery' );

		$this->assertSame( 'failed', $db->rows[1]['status'] );
		$this->assertSame( 'page:99', $db->rows[1]['active_key'] );
		$this->assertSame( 'First stale recovery', $db->rows[1]['last_error'] );
	}

	public function test_retry_supersedes_processing_row_when_newer_event_exists(): void {
		$db          = new CoalescingWpdb();
		$outbox      = new EventOutbox( $db );
		$old         = $this->processing_row( 1 );
		$db->rows[1] = $old;
		$db->rows[2] = array_merge(
			$this->processing_row( 2 ),
			array(
				'status'     => 'pending',
				'active_key' => 'page:99',
			)
		);

		$outbox->mark_retry( $old, 60, 'Old delivery failed' );

		$this->assertSame( 'superseded', $db->rows[1]['status'] );
		$this->assertSame( 'pending', $db->rows[2]['status'] );
	}

	/** @return array<string,mixed> */
	private function processing_row( int $id ): array {
		return array(
			'id'            => $id,
			'event_id'      => 'evt-' . $id,
			'resource_id'   => 99,
			'resource_type' => 'page',
			'version'       => 'digest-' . $id,
			'payload'       => wp_json_encode( $this->event( 'evt-' . $id, 'digest-' . $id ) ),
			'status'        => 'processing',
			'active_key'    => null,
			'attempt_count' => 1,
		);
	}
}

/**
 * Minimal wpdb double that enforces the outbox's unique active_key.
 */
final class CoalescingWpdb extends \wpdb {

	/** @var array<int,array<string,mixed>> */
	public array $rows = array();

	public function get_var( string $query ) {
		if ( preg_match( "/active_key = '([^']+)'/", $query, $matches ) ) {
			$excluded = preg_match( '/id <> (\d+)/', $query, $excluded_match ) ? (int) $excluded_match[1] : 0;
			foreach ( $this->rows as $id => $row ) {
				if ( $id === $excluded ) {
					continue;
				}
				if ( ( $row['active_key'] ?? null ) === $matches[1] ) {
					return $id;
				}
			}
		}
		return null;
	}

	public function get_results( string $query, $output = null ): array {
		unset( $output );
		if ( str_contains( $query, "status = 'dead'" ) ) {
			return array_values(
				array_filter(
					$this->rows,
					static fn( array $row ): bool => 'dead' === ( $row['status'] ?? '' )
				)
			);
		}
		return array();
	}

	public function insert( string $table, array $data ) {
		unset( $table );
		foreach ( $this->rows as $row ) {
			if ( null !== $data['active_key'] && $row['active_key'] === $data['active_key'] ) {
				return false;
			}
		}
		$this->insert_id                = count( $this->rows ) + 1;
		$this->rows[ $this->insert_id ] = $data + array( 'id' => $this->insert_id );
		return 1;
	}

	public function update( string $table, array $data, array $where ) {
		unset( $table );
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		foreach ( $where as $key => $value ) {
			if ( ! array_key_exists( $key, $this->rows[ $id ] ) || $this->rows[ $id ][ $key ] !== $value ) {
				return 0;
			}
		}
		if ( isset( $data['active_key'] ) && null !== $data['active_key'] ) {
			foreach ( $this->rows as $other_id => $row ) {
				if ( $other_id !== $id && ( $row['active_key'] ?? null ) === $data['active_key'] ) {
					return false;
				}
			}
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
		return 1;
	}
}
