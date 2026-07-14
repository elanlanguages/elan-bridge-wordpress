<?php
/**
 * Connection secret lifecycle tests.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Tests\Connection;

use ElanBridge\Connection\ConnectionManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Application_Passwords;

final class ConnectionManagerTest extends TestCase {

	protected function setUp(): void {
		eb_reset_test_state();
	}

	public function test_disconnect_revokes_password_secret_and_notifies_workers(): void {
		update_option(
			ConnectionManager::CONNECTION_OPTION,
			array(
				'status'            => 'connected',
				'user_id'           => 42,
				'app_password_uuid' => 'app-uuid',
			)
		);
		add_option( ConnectionManager::WEBHOOK_SECRET_OPTION, 'secret', '', 'no' );
		$disconnected = false;
		add_action(
			'elan_bridge_disconnected',
			static function () use ( &$disconnected ): void {
				$disconnected = true;
			}
		);

		( new ConnectionManager() )->disconnect();

		$this->assertSame( array( array( 42, 'app-uuid' ) ), WP_Application_Passwords::$deleted );
		$this->assertFalse( get_option( ConnectionManager::CONNECTION_OPTION, false ) );
		$this->assertFalse( get_option( ConnectionManager::WEBHOOK_SECRET_OPTION, false ) );
		$this->assertTrue( $disconnected );
	}

	public function test_pairing_secret_is_high_entropy_and_rotates(): void {
		$manager  = new ConnectionManager();
		$generate = new ReflectionMethod( $manager, 'new_webhook_secret' );
		$first    = $generate->invoke( $manager );
		$second   = $generate->invoke( $manager );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $second );
		$this->assertNotSame( $first, $second );
	}
}
