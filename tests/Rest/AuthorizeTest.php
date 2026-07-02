<?php
/**
 * Tests for the REST permission gate: API key extraction + verification.
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

namespace TranslationApi\Tests\Rest;

use PHPUnit\Framework\TestCase;
use TranslationApi\Auth\ApiKeyManager;
use TranslationApi\Rest\CmsController;
use TranslationApi\Wpml\WpmlReader;
use WP_Error;
use WP_REST_Request;

final class AuthorizeTest extends TestCase {

	private ApiKeyManager $keys;
	private CmsController $controller;

	protected function setUp(): void {
		ta_reset_test_state();
		$this->keys       = new ApiKeyManager();
		$this->controller = new CmsController( new WpmlReader(), $this->keys );
	}

	public function test_valid_key_via_x_api_key_header_authorizes_as_owner(): void {
		[ $plaintext ] = $this->keys->create( 'CI', 5 );

		$result = $this->controller->authorize( new WP_REST_Request( array( 'X-API-Key' => $plaintext ) ) );

		$this->assertTrue( $result );
		$this->assertSame( 5, $GLOBALS['__ta_current_user'] );
	}

	public function test_valid_key_via_authorization_bearer_header_authorizes(): void {
		[ $plaintext ] = $this->keys->create( 'CI', 8 );

		$result = $this->controller->authorize( new WP_REST_Request( array( 'Authorization' => 'Bearer ' . $plaintext ) ) );

		$this->assertTrue( $result );
		$this->assertSame( 8, $GLOBALS['__ta_current_user'] );
	}

	public function test_missing_key_is_rejected_and_sets_no_user(): void {
		$result = $this->controller->authorize( new WP_REST_Request( array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'translation_api_forbidden', $result->get_error_code() );
		$this->assertSame( 401, $result->data['status'] );
		$this->assertSame( 0, $GLOBALS['__ta_current_user'] );
	}

	public function test_wrong_key_is_rejected(): void {
		$this->keys->create( 'CI', 5 );

		$result = $this->controller->authorize( new WP_REST_Request( array( 'X-API-Key' => 'tapi_wrong' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, $GLOBALS['__ta_current_user'] );
	}

	public function test_revoked_key_stops_authorizing(): void {
		[ $plaintext, $record ] = $this->keys->create( 'CI', 5 );
		$this->keys->revoke( $record['id'] );

		$result = $this->controller->authorize( new WP_REST_Request( array( 'X-API-Key' => $plaintext ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_x_api_key_takes_precedence_over_bearer(): void {
		[ $good ] = $this->keys->create( 'good', 3 );

		$result = $this->controller->authorize(
			new WP_REST_Request(
				array(
					'X-API-Key'     => $good,
					'Authorization' => 'Bearer tapi_ignored',
				)
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( 3, $GLOBALS['__ta_current_user'] );
	}
}
