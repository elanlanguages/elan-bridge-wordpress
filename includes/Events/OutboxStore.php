<?php
/**
 * Durable CMS event outbox contract.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence boundary shared by the save-hook emitter and cron dispatcher.
 */
interface OutboxStore {

	/**
	 * Insert or coalesce one canonical resource-change event.
	 *
	 * @param array<string,mixed> $event Canonical event payload.
	 */
	public function enqueue( array $event ): bool;

	/**
	 * Atomically claim due events for delivery.
	 *
	 * @param int $limit Maximum number of events to claim.
	 * @return array<int,array<string,mixed>>
	 */
	public function claim_due( int $limit = 10 ): array;

	/**
	 * Mark an event as successfully delivered.
	 *
	 * @param int $id Outbox row ID.
	 */
	public function mark_delivered( int $id ): void;

	/**
	 * Return an event to the delivery queue after a transient failure.
	 *
	 * @param array<string,mixed> $event Claimed outbox row.
	 * @param int                 $delay Retry delay in seconds.
	 * @param string              $error Last delivery error.
	 */
	public function mark_retry( array $event, int $delay, string $error ): void;

	/**
	 * Mark an event as permanently failed.
	 *
	 * @param int    $id    Outbox row ID.
	 * @param string $error Delivery error.
	 */
	public function mark_dead( int $id, string $error ): void;

	/**
	 * Return delivery health counters for the admin screen.
	 *
	 * @return array{queued:int,failed:int,last_delivered:?string,last_error:string}
	 */
	public function stats(): array;

	/** Requeue failed events requested by an administrator. */
	public function retry_failures(): int;

	/**
	 * Cancel every queued or in-flight event.
	 *
	 * @param string $reason Cancellation reason.
	 */
	public function cancel_active( string $reason ): void;

	/** Prune bounded delivery history. */
	public function cleanup(): void;
}
