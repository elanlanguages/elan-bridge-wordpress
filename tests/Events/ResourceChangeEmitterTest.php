<?php
/**
 * Source-change filtering and no-op tests.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Tests\Events;

use ElanBridge\Connection\ConnectionManager;
use ElanBridge\Events\LoopGuard;
use ElanBridge\Events\ResourceChangeEmitter;
use ElanBridge\Tests\Support\InMemoryOutbox;
use ElanBridge\Wpml\WpmlReader;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class ResourceChangeEmitterTest extends TestCase {

	private string $locale = 'en';

	protected function setUp(): void {
		eb_reset_test_state();
		update_option( ConnectionManager::CONNECTION_OPTION, array( 'status' => 'connected' ) );
		update_option( ConnectionManager::SETTINGS_OPTION, array( 'post_types' => array( 'page' ) ) );
		add_filter( 'wpml_default_language', static fn() => 'en' );
		add_filter(
			'wpml_element_language_code',
			function (): string {
				return $this->locale;
			},
			10,
			2
		);
	}

	private function post( int $id = 99, string $status = 'publish' ): WP_Post {
		return new WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => 'page',
				'post_status'  => $status,
				'post_title'   => 'Hello',
				'post_content' => '<p>Source body</p>',
				'post_excerpt' => '',
			)
		);
	}

	private function emitter( InMemoryOutbox $outbox ): ResourceChangeEmitter {
		return new ResourceChangeEmitter( new WpmlReader(), $outbox, new ConnectionManager() );
	}

	public function test_source_save_enqueues_once_and_noop_is_skipped(): void {
		$outbox  = new InMemoryOutbox();
		$emitter = $this->emitter( $outbox );
		$post    = $this->post();

		$emitter->handle_post_saved( $post->ID, $post, false );
		$emitter->handle_post_saved( $post->ID, $post, true );

		$this->assertCount( 1, $outbox->events );
		$this->assertSame( 'cms.resource.changed', $outbox->events[0]['type'] );
		$this->assertSame( 'en', $outbox->events[0]['resource']['source_locale'] );
		$this->assertSame(
			get_post_meta( $post->ID, ResourceChangeEmitter::LAST_DIGEST_META, true ),
			$outbox->events[0]['resource']['version']
		);
	}

	public function test_rapid_source_edits_coalesce_to_latest_version(): void {
		$outbox  = new InMemoryOutbox();
		$emitter = $this->emitter( $outbox );
		$post    = $this->post();
		$emitter->handle_post_saved( $post->ID, $post, false );
		$first_version      = $outbox->events[0]['resource']['version'];
		$post->post_content = '<p>Updated source body</p>';

		$emitter->handle_post_saved( $post->ID, $post, true );

		$this->assertCount( 1, $outbox->events );
		$this->assertNotSame( $first_version, $outbox->events[0]['resource']['version'] );
	}

	public function test_target_translation_save_is_ignored(): void {
		$this->locale = 'de';
		$outbox       = new InMemoryOutbox();

		$this->emitter( $outbox )->handle_post_saved( 99, $this->post(), true );

		$this->assertSame( array(), $outbox->events );
	}

	public function test_bridge_writeback_loop_guard_suppresses_event(): void {
		$outbox  = new InMemoryOutbox();
		$emitter = $this->emitter( $outbox );

		LoopGuard::without_events(
			function () use ( $emitter ): void {
				$this->assertTrue( LoopGuard::is_suppressed() );
				$emitter->handle_post_saved( 99, $this->post(), true );
			}
		);

		$this->assertFalse( LoopGuard::is_suppressed() );
		$this->assertSame( array(), $outbox->events );
	}

	public function test_all_supported_editorial_statuses_emit(): void {
		$outbox  = new InMemoryOutbox();
		$emitter = $this->emitter( $outbox );
		foreach ( array( 'publish', 'draft', 'pending', 'future', 'private' ) as $index => $status ) {
			$post = $this->post( 100 + $index, $status );
			$emitter->handle_post_saved( $post->ID, $post, false );
		}

		$this->assertCount( 5, $outbox->events );
	}

	public function test_extra_translation_keys_participate_in_digest(): void {
		$extra = 'first';
		add_filter(
			'elan_bridge_extra_translation_keys',
			static function ( array $keys ) use ( &$extra ): array {
				$keys[] = array(
					'key'           => 'builder.hero',
					'source_value'  => $extra,
					'source_locale' => 'en',
					'source_digest' => hash( 'sha256', $extra ),
				);
				return $keys;
			},
			10,
			3
		);
		$reader = new WpmlReader();
		$post   = $this->post();
		$first  = $reader->source_digest( $post, 'en' );
		$extra  = 'second';

		$this->assertNotSame( $first, $reader->source_digest( $post, 'en' ) );
	}
}
