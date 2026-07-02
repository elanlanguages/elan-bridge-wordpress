<?php
/**
 * Publishes an OpenAPI 3.1 document describing the REST surface.
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

namespace TranslationApi\Rest;

use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Serves a machine-readable OpenAPI (Swagger) description of the API at
 * `/wp-json/translation/v1/openapi`. This route is intentionally public so
 * tooling (Swagger UI, Postman, client generators) can read it without a key;
 * the endpoints it documents still require one.
 *
 * The document is hand-authored to mirror the README reference — WordPress's
 * own route args describe request parameters but not response shapes, so a
 * generated spec would be too thin to be useful.
 */
final class OpenApiController {

	private const NAMESPACE = 'translation/v1';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/openapi',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'document' ),
				// Public: the spec is documentation, not a protected resource.
				'permission_callback' => '__return_true',
			)
		);
	}

	public function document(): WP_REST_Response {
		return new WP_REST_Response( $this->spec() );
	}

	/**
	 * The full OpenAPI 3.1 document as a PHP array.
	 *
	 * @return array<string, mixed>
	 */
	public function spec(): array {
		$base = untrailingslashit( rest_url( self::NAMESPACE ) );

		return array(
			'openapi'    => '3.1.0',
			'info'       => array(
				'title'       => 'Translation API',
				'version'     => TRANSLATION_API_VERSION,
				'description' => 'Extract WPML source strings from a WordPress site and write translations back, over a simple REST API. Authenticate with an API key created under Settings → Translation API, sent as the `X-API-Key` header or as `Authorization: Bearer`.',
			),
			'servers'    => array(
				array( 'url' => $base ),
			),
			'security'   => array(
				array( 'ApiKeyAuth' => array() ),
				array( 'BearerAuth' => array() ),
			),
			'tags'       => array(
				array(
					'name'        => 'System',
					'description' => 'Health and discovery.',
				),
				array(
					'name'        => 'Content',
					'description' => 'Read source strings and write translations back.',
				),
			),
			'paths'      => $this->paths(),
			'components' => $this->components(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function paths(): array {
		return array(
			'/health'                       => array(
				'get' => array(
					'tags'        => array( 'System' ),
					'operationId' => 'getHealth',
					'summary'     => 'Health and environment check',
					'responses'   => array(
						'200' => $this->json_response( 'Plugin version, WPML status, default language.', 'Health' ),
						'401' => $this->ref_response( 'Unauthorized' ),
					),
				),
			),
			'/locales'                      => array(
				'get' => array(
					'tags'        => array( 'System' ),
					'operationId' => 'listLocales',
					'summary'     => "The site's configured WPML locales",
					'responses'   => array(
						'200' => $this->json_response( 'Configured locales.', 'LocaleList' ),
						'401' => $this->ref_response( 'Unauthorized' ),
						'409' => $this->ref_response( 'WpmlInactive' ),
					),
				),
			),
			'/resources'                    => array(
				'get' => array(
					'tags'        => array( 'Content' ),
					'operationId' => 'listResources',
					'summary'     => 'List source-language resources of a post type',
					'parameters'  => array(
						$this->param( 'type', 'query', 'Post type to list.', array( 'type' => 'string', 'default' => 'page' ) ),
						$this->param( 'locale', 'query', 'Source locale; defaults to the site default.', array( 'type' => 'string' ) ),
						$this->param( 'cursor', 'query', 'Opaque cursor from a previous response.', array( 'type' => 'string' ) ),
						$this->param( 'limit', 'query', 'Page size.', array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ) ),
					),
					'responses'   => array(
						'200' => $this->json_response( 'A page of resources.', 'ResourceList' ),
						'400' => $this->ref_response( 'BadRequest' ),
						'401' => $this->ref_response( 'Unauthorized' ),
						'409' => $this->ref_response( 'WpmlInactive' ),
					),
				),
			),
			'/resources/{id}/translations' => array(
				'get'  => array(
					'tags'        => array( 'Content' ),
					'operationId' => 'getResourceTranslations',
					'summary'     => "A resource's source keys and every known translation",
					'parameters'  => array(
						$this->param( 'id', 'path', 'Resource id.', array( 'type' => 'integer' ), true ),
						$this->param( 'locales', 'query', 'Comma-separated locale codes; empty means all.', array( 'type' => 'string' ) ),
					),
					'responses'   => array(
						'200' => $this->json_response( 'Keys and translations.', 'ResourceTranslations' ),
						'401' => $this->ref_response( 'Unauthorized' ),
						'404' => $this->ref_response( 'NotFound' ),
						'409' => $this->ref_response( 'WpmlInactive' ),
					),
				),
				'post' => array(
					'tags'        => array( 'Content' ),
					'operationId' => 'setResourceTranslations',
					'summary'     => 'Create or update the translation for one locale',
					'parameters'  => array(
						$this->param( 'id', 'path', 'Source resource id.', array( 'type' => 'integer' ), true ),
					),
					'requestBody' => array(
						'required' => true,
						'content'  => array(
							'application/json' => array(
								'schema' => array( '$ref' => '#/components/schemas/SetTranslationsRequest' ),
							),
						),
					),
					'responses'   => array(
						'200' => $this->json_response( 'All keys written.', 'SetTranslationsResult' ),
						'400' => $this->ref_response( 'BadRequest' ),
						'401' => $this->ref_response( 'Unauthorized' ),
						'404' => $this->ref_response( 'NotFound' ),
						'409' => $this->ref_response( 'WpmlInactive' ),
						'422' => $this->json_response( 'Written with per-key errors.', 'SetTranslationsResult' ),
					),
				),
			),
			'/openapi'                      => array(
				'get' => array(
					'tags'        => array( 'System' ),
					'operationId' => 'getOpenApi',
					'summary'     => 'This OpenAPI document',
					'security'    => array(),
					'responses'   => array(
						'200' => array(
							'description' => 'The OpenAPI 3.1 document.',
							'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
						),
					),
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function components(): array {
		return array(
			'securitySchemes' => array(
				'ApiKeyAuth' => array(
					'type' => 'apiKey',
					'in'   => 'header',
					'name' => 'X-API-Key',
				),
				'BearerAuth' => array(
					'type'   => 'http',
					'scheme' => 'bearer',
				),
			),
			'schemas'         => array(
				'Health'                 => $this->object(
					array(
						'ok'               => array( 'type' => 'boolean' ),
						'plugin_version'   => array( 'type' => 'string' ),
						'wpml_active'      => array( 'type' => 'boolean' ),
						'default_language' => array( 'type' => 'string' ),
					)
				),
				'Locale'                 => $this->object(
					array(
						'code'       => array( 'type' => 'string', 'description' => 'WPML language code — use this everywhere else.' ),
						'name'       => array( 'type' => 'string' ),
						'is_default' => array( 'type' => 'boolean' ),
						'locale'     => array( 'type' => array( 'string', 'null' ), 'description' => 'WordPress locale, e.g. de_DE.' ),
					)
				),
				'LocaleList'             => $this->object(
					array(
						'locales' => array( 'type' => 'array', 'items' => array( '$ref' => '#/components/schemas/Locale' ) ),
					)
				),
				'Metadata'               => $this->object(
					array(
						'modified_gmt' => array( 'type' => 'string' ),
						'status'       => array( 'type' => 'string' ),
						'slug'         => array( 'type' => 'string' ),
						'link'         => array( 'type' => 'string', 'format' => 'uri' ),
					)
				),
				'ResourceSummary'        => $this->object(
					array(
						'id'       => array( 'type' => 'string', 'description' => 'Numeric WordPress id, as a string.' ),
						'type'     => array( 'type' => 'string' ),
						'title'    => array( 'type' => array( 'string', 'null' ) ),
						'metadata' => array( '$ref' => '#/components/schemas/Metadata' ),
					)
				),
				'ResourceList'           => $this->object(
					array(
						'resources'   => array( 'type' => 'array', 'items' => array( '$ref' => '#/components/schemas/ResourceSummary' ) ),
						'next_cursor' => array( 'type' => array( 'string', 'null' ), 'description' => 'Pass back as `cursor`; null on the last page.' ),
					)
				),
				'TranslationKey'         => $this->object(
					array(
						'key'           => array( 'type' => 'string' ),
						'source_value'  => array( 'type' => 'string' ),
						'source_locale' => array( 'type' => 'string' ),
						'source_digest' => array( 'type' => 'string', 'description' => 'SHA-256 hex of source_value — use it to detect source changes.' ),
					)
				),
				'ResourceTranslations'   => $this->object(
					array(
						'resource'      => array( '$ref' => '#/components/schemas/ResourceSummary' ),
						'source_locale' => array( 'type' => 'string' ),
						'keys'          => array( 'type' => 'array', 'items' => array( '$ref' => '#/components/schemas/TranslationKey' ) ),
						'translations'  => array(
							'type'                 => 'object',
							'description'          => 'key -> { locale: translated_value }. Empty object when none exist.',
							'additionalProperties' => array(
								'type'                 => 'object',
								'additionalProperties' => array( 'type' => 'string' ),
							),
						),
					)
				),
				'SetTranslationsRequest' => array(
					'type'       => 'object',
					'required'   => array( 'locale', 'values' ),
					'properties' => array(
						'locale' => array( 'type' => 'string', 'description' => 'Target WPML locale code, e.g. "de".' ),
						'values' => array(
							'type'                 => 'object',
							'description'          => 'Map of canonical key -> translated string.',
							'additionalProperties' => array( 'type' => 'string' ),
						),
					),
				),
				'SetTranslationsResult'  => $this->object(
					array(
						'resource_id'  => array( 'type' => 'string' ),
						'locale'       => array( 'type' => 'string' ),
						'keys_written' => array( 'type' => 'integer' ),
						'keys_skipped' => array( 'type' => 'integer' ),
						'errors'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					)
				),
				'Error'                  => $this->object(
					array(
						'code'    => array( 'type' => 'string' ),
						'message' => array( 'type' => 'string' ),
						'data'    => $this->object( array( 'status' => array( 'type' => 'integer' ) ) ),
					)
				),
			),
			'responses'       => array(
				'Unauthorized' => $this->error_response( 'A valid API key is required.' ),
				'BadRequest'   => $this->error_response( 'The request was malformed.' ),
				'NotFound'     => $this->error_response( 'The resource was not found.' ),
				'WpmlInactive' => $this->error_response( 'WPML is not active on this site.' ),
			),
		);
	}

	// -- small builders ----------------------------------------------------

	/**
	 * @param array<string, mixed> $properties
	 * @return array<string, mixed>
	 */
	private function object( array $properties ): array {
		return array(
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	/**
	 * @param array<string, mixed> $schema
	 * @return array<string, mixed>
	 */
	private function param( string $name, string $in, string $description, array $schema, bool $required = false ): array {
		return array(
			'name'        => $name,
			'in'          => $in,
			'description' => $description,
			'required'    => $required || 'path' === $in,
			'schema'      => $schema,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function json_response( string $description, string $schema ): array {
		return array(
			'description' => $description,
			'content'     => array(
				'application/json' => array(
					'schema' => array( '$ref' => '#/components/schemas/' . $schema ),
				),
			),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function ref_response( string $name ): array {
		return array( '$ref' => '#/components/responses/' . $name );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function error_response( string $description ): array {
		return array(
			'description' => $description,
			'content'     => array(
				'application/json' => array(
					'schema' => array( '$ref' => '#/components/schemas/Error' ),
				),
			),
		);
	}
}
