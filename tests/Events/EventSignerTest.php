<?php
/**
 * Event signing contract tests.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Tests\Events;

use ElanBridge\Events\EventSigner;
use PHPUnit\Framework\TestCase;

final class EventSignerTest extends TestCase {

	public function test_matches_bridge_receiver_contract_vector(): void {
		$body = '{"schema_version":1,"event_id":"evt-1","type":"cms.resource.changed"}';

		$signature = EventSigner::sign( 'event-secret', '1720951200', $body );

		$this->assertSame(
			'sha256=764f7fce68161dbd414af499e4060f1597202d9b04f47cb6e8812b6740c0d95a',
			$signature
		);
	}
}
