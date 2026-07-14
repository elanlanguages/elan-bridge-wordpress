<?php
/**
 * WordPress-database-backed CMS event outbox.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Persists events before any network I/O and provides atomic worker claims.
 */
final class EventOutbox implements OutboxStore {

	public const DB_VERSION_OPTION = 'elan_bridge_event_outbox_version';

	private const DB_VERSION         = '1';
	private const HISTORY_DAYS       = 30;
	private const HISTORY_MAX_ROWS   = 500;
	private const PROCESSING_TIMEOUT = 15 * MINUTE_IN_SECONDS;

	/**
	 * WordPress database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Fully qualified outbox table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Build a database-backed outbox.
	 *
	 * @param \wpdb|null $db Injectable database connection for tests.
	 */
	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->db    = $db ?? $wpdb;
		$this->table = $this->db->prefix . 'elan_bridge_event_outbox';
	}

	/** Install or upgrade the outbox when its schema version changes. */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			self::install();
		}
	}

	/** Create or upgrade the outbox database table. */
	public static function install(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'elan_bridge_event_outbox';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(64) NOT NULL,
			resource_id bigint(20) unsigned NOT NULL,
			resource_type varchar(64) NOT NULL,
			version varchar(64) NOT NULL,
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			next_attempt_gmt datetime NOT NULL,
			last_error text NULL,
			active_key varchar(191) NULL,
			created_gmt datetime NOT NULL,
			updated_gmt datetime NOT NULL,
			delivered_gmt datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			UNIQUE KEY active_key (active_key),
			KEY delivery_queue (status,next_attempt_gmt),
			KEY resource_history (resource_type,resource_id,id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Insert or coalesce one canonical resource-change event.
	 *
	 * @param array<string,mixed> $event Canonical event payload.
	 */
	public function enqueue( array $event ): bool {
		$resource = isset( $event['resource'] ) && is_array( $event['resource'] ) ? $event['resource'] : array();
		$event_id = (string) ( $event['event_id'] ?? '' );
		$id       = absint( $resource['id'] ?? 0 );
		$type     = sanitize_key( (string) ( $resource['type'] ?? '' ) );
		$version  = (string) ( $resource['version'] ?? '' );
		$payload  = wp_json_encode( $event, JSON_UNESCAPED_SLASHES );
		if ( '' === $event_id || $id <= 0 || '' === $type || '' === $version || ! is_string( $payload ) ) {
			return false;
		}

		$now        = current_time( 'mysql', true );
		$active_key = $type . ':' . $id;
		$data       = array(
			'event_id'         => $event_id,
			'resource_id'      => $id,
			'resource_type'    => $type,
			'version'          => $version,
			'payload'          => $payload,
			'status'           => 'pending',
			'attempt_count'    => 0,
			'next_attempt_gmt' => $now,
			'last_error'       => null,
			'active_key'       => $active_key,
			'updated_gmt'      => $now,
			'delivered_gmt'    => null,
		);

		$existing = $this->active_id( $active_key );
		if ( $existing > 0 ) {
			return false !== $this->db->update( $this->table, $data, array( 'id' => $existing ) );
		}

		$data['created_gmt'] = $now;
		if ( false !== $this->db->insert( $this->table, $data ) ) {
			return true;
		}

		// A concurrent save may have inserted the same active key after our read.
		$existing = $this->active_id( $active_key );
		if ( $existing <= 0 ) {
			return false;
		}
		unset( $data['created_gmt'] );
		return false !== $this->db->update( $this->table, $data, array( 'id' => $existing ) );
	}

	/**
	 * Atomically claim a bounded set of due delivery rows.
	 *
	 * @param int $limit Maximum rows to claim.
	 * @return array<int,array<string,mixed>>
	 */
	public function claim_due( int $limit = 10 ): array {
		$this->release_stale_claims();
		$now     = current_time( 'mysql', true );
		$rows    = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table}
				WHERE status IN ('pending','failed') AND next_attempt_gmt <= %s
				ORDER BY id ASC LIMIT %d",
				$now,
				max( 1, $limit )
			),
			ARRAY_A
		);
		$claimed = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id      = (int) $row['id'];
			$updated = $this->db->query(
				$this->db->prepare(
					"UPDATE {$this->table}
					SET status = 'processing', active_key = NULL,
						attempt_count = attempt_count + 1, updated_gmt = %s
					WHERE id = %d AND status IN ('pending','failed') AND next_attempt_gmt <= %s",
					$now,
					$id,
					$now
				)
			);
			if ( 1 !== $updated ) {
				continue;
			}
			$fresh = $this->db->get_row(
				$this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
				ARRAY_A
			);
			if ( is_array( $fresh ) ) {
				$claimed[] = $fresh;
			}
		}
		return $claimed;
	}

	/**
	 * Mark an event as successfully delivered.
	 *
	 * @param int $id Outbox row ID.
	 */
	public function mark_delivered( int $id ): void {
		$now = current_time( 'mysql', true );
		$this->db->update(
			$this->table,
			array(
				'status'        => 'delivered',
				'active_key'    => null,
				'last_error'    => null,
				'updated_gmt'   => $now,
				'delivered_gmt' => $now,
			),
			array(
				'id'     => $id,
				'status' => 'processing',
			)
		);
	}

	/**
	 * Return a transient failure to the delivery queue.
	 *
	 * @param array<string,mixed> $event Claimed outbox row.
	 * @param int                 $delay Retry delay in seconds.
	 * @param string              $error Last delivery error.
	 */
	public function mark_retry( array $event, int $delay, string $error ): void {
		$id         = (int) ( $event['id'] ?? 0 );
		$active_key = sanitize_key( (string) ( $event['resource_type'] ?? '' ) ) . ':' . absint( $event['resource_id'] ?? 0 );
		if ( $id <= 0 || ':' === $active_key ) {
			return;
		}

		if ( $this->active_id( $active_key, $id ) > 0 ) {
			$this->mark_superseded( $id, __( 'A newer resource version is queued.', 'elan-bridge' ), 'processing' );
			return;
		}

		$now    = current_time( 'mysql', true );
		$result = $this->db->update(
			$this->table,
			array(
				'status'           => 'failed',
				'active_key'       => $active_key,
				'next_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() + max( 1, $delay ) ),
				'last_error'       => $this->limit_error( $error ),
				'updated_gmt'      => $now,
			),
			array(
				'id'     => $id,
				'status' => 'processing',
			)
		);
		if ( false === $result ) {
			// Most commonly a concurrent newer event won the unique active key.
			$this->mark_superseded( $id, __( 'A newer resource version replaced this retry.', 'elan-bridge' ), 'processing' );
		}
	}

	/**
	 * Mark an event as permanently failed.
	 *
	 * @param int    $id    Outbox row ID.
	 * @param string $error Delivery error.
	 */
	public function mark_dead( int $id, string $error ): void {
		$this->db->update(
			$this->table,
			array(
				'status'      => 'dead',
				'active_key'  => null,
				'last_error'  => $this->limit_error( $error ),
				'updated_gmt' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $id,
				'status' => 'processing',
			)
		);
	}

	/**
	 * Return delivery health for the settings screen.
	 *
	 * @return array{queued:int,failed:int,last_delivered:?string,last_error:string}
	 */
	public function stats(): array {
		$queued         = (int) $this->db->get_var(
			"SELECT COUNT(*) FROM {$this->table} WHERE status IN ('pending','processing')"
		);
		$failed         = (int) $this->db->get_var(
			"SELECT COUNT(*) FROM {$this->table} WHERE status IN ('failed','dead')"
		);
		$last_delivered = $this->db->get_var(
			"SELECT MAX(delivered_gmt) FROM {$this->table} WHERE status = 'delivered'"
		);
		$last_error     = $this->db->get_var(
			"SELECT last_error FROM {$this->table}
			WHERE last_error IS NOT NULL AND last_error <> ''
			ORDER BY updated_gmt DESC, id DESC LIMIT 1"
		);
		return array(
			'queued'         => $queued,
			'failed'         => $failed,
			'last_delivered' => is_string( $last_delivered ) && '' !== $last_delivered ? $last_delivered : null,
			'last_error'     => is_string( $last_error ) ? $last_error : '',
		);
	}

	/** Requeue failed events without replacing newer resource versions. */
	public function retry_failures(): int {
		$now     = current_time( 'mysql', true );
		$updated = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table}
				SET status = 'pending', next_attempt_gmt = %s, last_error = NULL, updated_gmt = %s
				WHERE status = 'failed'",
				$now,
				$now
			)
		);

		$dead  = $this->db->get_results(
			"SELECT id, payload, resource_type, resource_id FROM {$this->table} WHERE status = 'dead' ORDER BY id ASC",
			ARRAY_A
		);
		$count = max( 0, (int) $updated );
		foreach ( is_array( $dead ) ? $dead : array() as $row ) {
			$active_key = sanitize_key( (string) ( $row['resource_type'] ?? '' ) ) . ':' . absint( $row['resource_id'] ?? 0 );
			if ( ':' !== $active_key && $this->active_id( $active_key ) > 0 ) {
				$this->mark_superseded( (int) $row['id'], __( 'A newer resource version is already queued.', 'elan-bridge' ), 'dead' );
				continue;
			}
			$payload = json_decode( (string) $row['payload'], true );
			if ( is_array( $payload ) ) {
				$payload['event_id']    = wp_generate_uuid4();
				$payload['occurred_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			}
			if ( is_array( $payload ) && $this->enqueue( $payload ) ) {
				$this->mark_superseded( (int) $row['id'], __( 'Manually retried as a new delivery.', 'elan-bridge' ), 'dead' );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Cancel every event that could still be delivered.
	 *
	 * @param string $reason Cancellation reason.
	 */
	public function cancel_active( string $reason ): void {
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table}
				SET status = 'cancelled', active_key = NULL, last_error = %s, updated_gmt = %s
				WHERE status IN ('pending','processing','failed')",
				$this->limit_error( $reason ),
				current_time( 'mysql', true )
			)
		);
	}

	/** Prune old and excess terminal delivery history. */
	public function cleanup(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::HISTORY_DAYS * DAY_IN_SECONDS ) );
		$this->db->query(
			$this->db->prepare(
				"DELETE FROM {$this->table}
				WHERE status IN ('delivered','dead','superseded','cancelled') AND updated_gmt < %s",
				$cutoff
			)
		);

		$stale_ids = $this->db->get_col(
			"SELECT id FROM {$this->table}
			WHERE status IN ('delivered','dead','superseded','cancelled')
			ORDER BY id DESC LIMIT " . self::HISTORY_MAX_ROWS . ', 18446744073709551615'
		);
		$stale_ids = array_values( array_filter( array_map( 'absint', is_array( $stale_ids ) ? $stale_ids : array() ) ) );
		if ( array() !== $stale_ids ) {
			$this->db->query( "DELETE FROM {$this->table} WHERE id IN (" . implode( ',', $stale_ids ) . ')' );
		}
	}

	/**
	 * Find the active row for a resource coalescing key.
	 *
	 * @param string $active_key Resource coalescing key.
	 * @param int    $exclude_id Row ID that must not count as a newer event.
	 */
	private function active_id( string $active_key, int $exclude_id = 0 ): int {
		if ( $exclude_id > 0 ) {
			return (int) $this->db->get_var(
				$this->db->prepare(
					"SELECT id FROM {$this->table} WHERE active_key = %s AND id <> %d LIMIT 1",
					$active_key,
					$exclude_id
				)
			);
		}
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT id FROM {$this->table} WHERE active_key = %s LIMIT 1",
				$active_key
			)
		);
	}

	/** Recover worker claims abandoned by an interrupted cron request. */
	private function release_stale_claims(): void {
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::PROCESSING_TIMEOUT );
		$rows  = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE status = 'processing' AND updated_gmt < %s",
				$stale
			),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$this->mark_retry( $row, 1, __( 'Recovered a stale delivery claim.', 'elan-bridge' ) );
		}
	}

	/**
	 * Mark an obsolete event as replaced by a newer resource version.
	 *
	 * @param int    $id              Outbox row ID.
	 * @param string $reason          Supersession reason.
	 * @param string $expected_status State that is allowed to transition.
	 */
	private function mark_superseded( int $id, string $reason, string $expected_status ): void {
		$this->db->update(
			$this->table,
			array(
				'status'      => 'superseded',
				'active_key'  => null,
				'last_error'  => $this->limit_error( $reason ),
				'updated_gmt' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $id,
				'status' => $expected_status,
			)
		);
	}

	/**
	 * Bound stored error text so history cannot grow without limit.
	 *
	 * @param string $error Full delivery error.
	 */
	private function limit_error( string $error ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 2000 ) : substr( $error, 0, 2000 );
	}
}
