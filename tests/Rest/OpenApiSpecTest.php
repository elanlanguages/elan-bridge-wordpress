<?php
/**
 * Tests for the published OpenAPI document.
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

namespace TranslationApi\Tests\Rest;

use PHPUnit\Framework\TestCase;
use TranslationApi\Rest\OpenApiController;

final class OpenApiSpecTest extends TestCase {

	private array $spec;

	protected function setUp(): void {
		$this->spec = ( new OpenApiController() )->spec();
	}

	public function test_it_is_an_openapi_3_1_document_versioned_from_the_plugin(): void {
		$this->assertSame( '3.1.0', $this->spec['openapi'] );
		$this->assertSame( 'Translation API', $this->spec['info']['title'] );
		$this->assertSame( TRANSLATION_API_VERSION, $this->spec['info']['version'] );
	}

	public function test_server_url_is_the_versioned_rest_namespace_without_trailing_slash(): void {
		$url = $this->spec['servers'][0]['url'];

		$this->assertSame( 'https://example.test/wp-json/translation/v1', $url );
		$this->assertStringEndsNotWith( '/', $url );
	}

	public function test_it_documents_every_route(): void {
		$paths = array_keys( $this->spec['paths'] );

		$this->assertContains( '/health', $paths );
		$this->assertContains( '/locales', $paths );
		$this->assertContains( '/resources', $paths );
		$this->assertContains( '/resources/{id}/translations', $paths );
		$this->assertContains( '/openapi', $paths );

		// The translations path carries both read and write-back operations.
		$this->assertArrayHasKey( 'get', $this->spec['paths']['/resources/{id}/translations'] );
		$this->assertArrayHasKey( 'post', $this->spec['paths']['/resources/{id}/translations'] );
	}

	public function test_the_only_security_scheme_is_bearer(): void {
		$schemes = $this->spec['components']['securitySchemes'];

		$this->assertSame( array( 'BearerAuth' ), array_keys( $schemes ) );
		$this->assertSame( 'http', $schemes['BearerAuth']['type'] );
		$this->assertSame( 'bearer', $schemes['BearerAuth']['scheme'] );
	}

	public function test_global_security_is_required_but_the_spec_route_is_public(): void {
		// The document requires a key by default...
		$this->assertNotEmpty( $this->spec['security'] );
		// ...except /openapi, which opts out with an empty security array.
		$this->assertSame( array(), $this->spec['paths']['/openapi']['get']['security'] );
	}

	public function test_write_back_declares_its_request_body_and_error_responses(): void {
		$post = $this->spec['paths']['/resources/{id}/translations']['post'];

		$this->assertSame(
			'#/components/schemas/SetTranslationsRequest',
			$post['requestBody']['content']['application/json']['schema']['$ref']
		);
		foreach ( array( '200', '400', '401', '404', '409', '422' ) as $status ) {
			$this->assertArrayHasKey( $status, $post['responses'], "missing $status response" );
		}
	}

	public function test_the_document_is_json_serialisable(): void {
		$json = json_encode( $this->spec );

		$this->assertIsString( $json );
		$this->assertNotFalse( $json );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
	}
}
