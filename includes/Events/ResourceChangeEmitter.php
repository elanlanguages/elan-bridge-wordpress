<?php
/**
 * Source-post change detection and durable event emission.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Events;

use ElanBridge\Connection\ConnectionManager;
use ElanBridge\Wpml\WpmlReader;

defined( 'ABSPATH' ) || exit;

/**
 * Converts selected source saves into canonical outbox events without I/O.
 */
final class ResourceChangeEmitter {

	public const LAST_DIGEST_META = '_elan_bridge_last_event_digest';

	private const ELIGIBLE_STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/**
	 * WPML-backed canonical content reader.
	 *
	 * @var WpmlReader
	 */
	private WpmlReader $wpml;

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
	 * Build the source-save event emitter.
	 *
	 * @param WpmlReader        $wpml       Canonical content reader.
	 * @param OutboxStore       $outbox     Durable event store.
	 * @param ConnectionManager $connection Current Bridge connection.
	 */
	public function __construct( WpmlReader $wpml, OutboxStore $outbox, ConnectionManager $connection ) {
		$this->wpml       = $wpml;
		$this->outbox     = $outbox;
		$this->connection = $connection;
	}

	/** Register source-save capture. */
	public function register_hooks(): void {
		add_action( 'wp_after_insert_post', array( $this, 'handle_post_saved' ), 100, 4 );
	}

	/**
	 * Runs late in the save lifecycle so WPML has assigned language metadata.
	 *
	 * @param int           $post_id     Saved post ID.
	 * @param \WP_Post      $post        Saved post.
	 * @param bool          $update      Whether an existing post was updated.
	 * @param \WP_Post|null $post_before Post state before the save.
	 */
	public function handle_post_saved( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before = null ): void {
		unset( $update, $post_before );
		if ( LoopGuard::is_suppressed() || ! $this->connection->is_connected() ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_status, self::ELIGIBLE_STATUSES, true ) ) {
			return;
		}

		$settings   = get_option( ConnectionManager::SETTINGS_OPTION, array() );
		$post_types = is_array( $settings ) ? (array) ( $settings['post_types'] ?? array( 'page' ) ) : array( 'page' );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		$source_locale = $this->wpml->post_language( $post );
		if ( $source_locale !== $this->wpml->default_language() ) {
			return;
		}

		$digest       = $this->wpml->source_digest( $post, $source_locale );
		$last_emitted = (string) get_post_meta( $post_id, self::LAST_DIGEST_META, true );
		$should_emit  = '' !== $digest && ! hash_equals( $digest, $last_emitted );
		$should_emit  = (bool) apply_filters(
			'elan_bridge_should_emit_resource_change',
			$should_emit,
			$post,
			$source_locale,
			$digest,
			$last_emitted
		);
		if ( ! $should_emit ) {
			return;
		}

		$event_id = wp_generate_uuid4();
		$event    = array(
			'schema_version' => 1,
			'event_id'       => $event_id,
			'type'           => 'cms.resource.changed',
			'occurred_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'resource'       => array(
				'id'            => (string) $post_id,
				'type'          => $post->post_type,
				'source_locale' => $source_locale,
				'version'       => $digest,
			),
		);

		/**
		 * Adjust the canonical event before it enters the outbox.
		 *
		 * The receiver rejects unknown top-level keys, so extensions should only
		 * change values already present in schema version 1.
		 *
		 * @param array<string,mixed> $event Canonical event.
		 * @param WP_Post             $post  Saved source post.
		 */
		$event = apply_filters( 'elan_bridge_resource_change_event', $event, $post );
		if ( ! is_array( $event ) || ! $this->outbox->enqueue( $event ) ) {
			return;
		}

		update_post_meta( $post_id, self::LAST_DIGEST_META, $digest );
		do_action( 'elan_bridge_event_queued', $event_id, $post_id );
	}
}
