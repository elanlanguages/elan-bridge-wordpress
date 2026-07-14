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
}

/**
 * Minimal wpdb double that enforces the outbox's unique active_key.
 */
final class CoalescingWpdb extends \wpdb {

	/** @var array<int,array<string,mixed>> */
	public array $rows = array();

	public function get_var( string $query ) {
		if ( preg_match( "/active_key = '([^']+)'/", $query, $matches ) ) {
			foreach ( $this->rows as $id => $row ) {
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
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
		return 1;
	}
}
