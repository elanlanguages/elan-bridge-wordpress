<?php
/**
 * Tests for the API-key store and verifier.
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

namespace TranslationApi\Tests\Auth;

use PHPUnit\Framework\TestCase;
use TranslationApi\Auth\ApiKeyManager;

final class ApiKeyManagerTest extends TestCase {

	private ApiKeyManager $manager;

	protected function setUp(): void {
		ta_reset_test_state();
		$this->manager = new ApiKeyManager();
	}

	public function test_create_returns_prefixed_plaintext_and_stores_a_record(): void {
		[ $plaintext, $record ] = $this->manager->create( 'Acme TMS', 7 );

		$this->assertStringStartsWith( 'tapi_', $plaintext );
		$this->assertSame( 'Acme TMS', $record['label'] );
		$this->assertSame( 7, $record['user_id'] );
		$this->assertSame( hash( 'sha256', $plaintext ), $record['hash'] );
		// The stored prefix is a non-secret fragment, never the whole key.
		$this->assertStringStartsWith( 'tapi_', $record['prefix'] );
		$this->assertNotSame( $plaintext, $record['prefix'] );
	}

	public function test_verify_matches_a_created_key_and_returns_its_owner(): void {
		[ $plaintext ] = $this->manager->create( 'CI', 42 );

		$this->assertSame( 42, $this->manager->verify( $plaintext ) );
	}

	public function test_verify_trims_surrounding_whitespace(): void {
		[ $plaintext ] = $this->manager->create( 'CI', 42 );

		$this->assertSame( 42, $this->manager->verify( "  {$plaintext}\n" ) );
	}

	public function test_verify_rejects_unknown_and_empty_keys(): void {
		$this->manager->create( 'CI', 42 );

		$this->assertNull( $this->manager->verify( 'tapi_not-a-real-key' ) );
		$this->assertNull( $this->manager->verify( '' ) );
		$this->assertNull( $this->manager->verify( '   ' ) );
	}

	public function test_two_keys_resolve_to_their_own_owners(): void {
		[ $a ] = $this->manager->create( 'A', 1 );
		[ $b ] = $this->manager->create( 'B', 2 );

		$this->assertSame( 1, $this->manager->verify( $a ) );
		$this->assertSame( 2, $this->manager->verify( $b ) );
	}

	public function test_revoke_removes_the_key_so_it_no_longer_verifies(): void {
		[ $plaintext, $record ] = $this->manager->create( 'temp', 9 );
		$this->assertSame( 9, $this->manager->verify( $plaintext ) );

		$this->assertTrue( $this->manager->revoke( $record['id'] ) );
		$this->assertNull( $this->manager->verify( $plaintext ) );
	}

	public function test_revoke_unknown_id_is_a_no_op(): void {
		$this->assertFalse( $this->manager->revoke( 'does-not-exist' ) );
	}

	public function test_all_lists_every_key_newest_first(): void {
		[ , $older ] = $this->manager->create( 'older', 1 );
		[ , $newer ] = $this->manager->create( 'newer', 1 );
		// Force a deterministic ordering regardless of same-second creation.
		$this->stampCreatedAt( $older['id'], 1000 );
		$this->stampCreatedAt( $newer['id'], 2000 );

		$ids = array_keys( $this->manager->all() );

		$this->assertSame( array( $newer['id'], $older['id'] ), $ids );
	}

	/** Rewrite a stored record's created_at directly in the in-memory option. */
	private function stampCreatedAt( string $id, int $timestamp ): void {
		$keys                      = $GLOBALS['__ta_options'][ ApiKeyManager::OPTION ];
		$keys[ $id ]['created_at'] = $timestamp;
		$GLOBALS['__ta_options'][ ApiKeyManager::OPTION ] = $keys;
	}
}
