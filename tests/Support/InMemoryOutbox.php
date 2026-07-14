<?php
/**
 * In-memory outbox test double.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Tests\Support;

use ElanBridge\Events\OutboxStore;

final class InMemoryOutbox implements OutboxStore {

	/** @var array<int,array<string,mixed>> */
	public array $events = array();

	/** @var array<int,int> */
	public array $delivered = array();

	/** @var array<int,array{event:array<string,mixed>,delay:int,error:string}> */
	public array $retried = array();

	/** @var array<int,array{id:int,error:string}> */
	public array $dead = array();

	public string $cancel_reason = '';

	private int $next_id = 1;

	public function enqueue( array $event ): bool {
		$resource = (array) ( $event['resource'] ?? array() );
		$key      = (string) ( $resource['type'] ?? '' ) . ':' . (string) ( $resource['id'] ?? '' );
		foreach ( $this->events as $index => $stored ) {
			$stored_resource = (array) ( $stored['resource'] ?? array() );
			$stored_key      = (string) ( $stored_resource['type'] ?? '' ) . ':' . (string) ( $stored_resource['id'] ?? '' );
			if ( $key === $stored_key ) {
				$this->events[ $index ] = $event + array( 'id' => $stored['id'] );
				return true;
			}
		}
		$this->events[] = $event + array( 'id' => $this->next_id++ );
		return true;
	}

	public function claim_due( int $limit = 10 ): array {
		return array_slice( $this->events, 0, $limit );
	}

	public function mark_delivered( int $id ): void {
		$this->delivered[] = $id;
	}

	public function mark_retry( array $event, int $delay, string $error ): void {
		$this->retried[] = compact( 'event', 'delay', 'error' );
	}

	public function mark_dead( int $id, string $error ): void {
		$this->dead[] = compact( 'id', 'error' );
	}

	public function stats(): array {
		return array(
			'queued'         => count( $this->events ),
			'failed'         => count( $this->dead ),
			'last_delivered' => null,
			'last_error'     => '',
		);
	}

	public function retry_failures(): int {
		return count( $this->dead );
	}

	public function cancel_active( string $reason ): void {
		$this->cancel_reason = $reason;
		$this->events        = array();
	}

	public function cleanup(): void {
	}
}
