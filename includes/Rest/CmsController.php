<?php
/**
 * REST controller — the surface an external translation system pulls from.
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

namespace TranslationApi\Rest;

use TranslationApi\Auth\ApiKeyManager;
use TranslationApi\Wpml\WpmlReader;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `/wp-json/translation/v1/...`, returning source strings and their
 * translations in a stable, provider-neutral shape. This controller does the
 * WPML work; the client is a thin consumer.
 *
 * Auth: an API key created in the plugin's settings, sent as either
 * `X-API-Key: <key>` or `Authorization: Bearer <key>`. A valid key makes the
 * request act as the WordPress user who created it, so listing drafts/private
 * posts and writing translations back behave exactly as they would for that
 * admin.
 */
final class CmsController {

	private const NAMESPACE = 'translation/v1';

	private WpmlReader $wpml;

	private ApiKeyManager $api_keys;

	public function __construct( WpmlReader $wpml, ApiKeyManager $api_keys ) {
		$this->wpml     = $wpml;
		$this->api_keys = $api_keys;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'health' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/locales',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'locales' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/resources',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_resources' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'type'   => array(
						'type'              => 'string',
						'default'           => 'page',
						'sanitize_callback' => 'sanitize_key',
					),
					'locale' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'cursor' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'  => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 200,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/resources/(?P<id>\d+)/translations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_resource_translations' ),
					'permission_callback' => array( $this, 'authorize' ),
					'args'                => array(
						'id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'locales' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Comma-separated locale codes; empty means all.',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_resource_translations' ),
					'permission_callback' => array( $this, 'authorize' ),
					'args'                => array(
						'id'     => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'locale' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Target WPML locale code, e.g. "de".',
						),
						'values' => array(
							'type'        => 'object',
							'required'    => true,
							'description' => 'Map of canonical key -> translated value for this locale.',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission gate. Verifies the presented API key and, on success, assumes
	 * the identity of the user that key belongs to for the rest of the request.
	 */
	public function authorize( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->api_keys->verify( $this->presented_key( $request ) );
		if ( $user_id ) {
			wp_set_current_user( $user_id );
			return true;
		}
		return new WP_Error(
			'translation_api_forbidden',
			__( 'A valid API key is required. Send it as the X-API-Key header.', 'translation-api' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Read the API key from the request: prefer the dedicated `X-API-Key`
	 * header, and also accept `Authorization: Bearer <key>`.
	 */
	private function presented_key( WP_REST_Request $request ): string {
		$direct = trim( (string) $request->get_header( 'X-API-Key' ) );
		if ( '' !== $direct ) {
			return $direct;
		}
		$authorization = trim( (string) $request->get_header( 'Authorization' ) );
		if ( 1 === preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	public function health(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'               => true,
				'plugin_version'   => TRANSLATION_API_VERSION,
				'wpml_active'      => $this->wpml->is_active(),
				'default_language' => $this->wpml->default_language(),
			)
		);
	}

	public function locales(): WP_REST_Response|WP_Error {
		if ( ! $this->wpml->is_active() ) {
			return $this->wpml_inactive();
		}
		return new WP_REST_Response( array( 'locales' => $this->wpml->locales() ) );
	}

	public function list_resources( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->wpml->is_active() ) {
			return $this->wpml_inactive();
		}

		$post_type = (string) $request->get_param( 'type' );
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'translation_api_unknown_type',
				/* translators: %s: post type */
				sprintf( __( 'Unknown post type: %s', 'translation-api' ), $post_type ),
				array( 'status' => 400 )
			);
		}

		$cursor = $request->get_param( 'cursor' );
		$offset = is_numeric( $cursor ) ? max( 0, (int) $cursor ) : 0;

		$result = $this->wpml->list_resources(
			$post_type,
			$request->get_param( 'locale' ),
			$offset,
			(int) $request->get_param( 'limit' )
		);

		return new WP_REST_Response( $result );
	}

	public function get_resource_translations( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->wpml->is_active() ) {
			return $this->wpml_inactive();
		}

		$post_id = (int) $request->get_param( 'id' );
		$locales = array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'locales' ) ) ) );

		$result = $this->wpml->get_resource_translations( $post_id, $locales );
		if ( null === $result ) {
			return new WP_Error(
				'translation_api_not_found',
				__( 'Resource not found.', 'translation-api' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Write-back: create or update the WPML translation for one locale with the
	 * values the client produced. Destructive — the caller should gate this
	 * behind its own review/approval before calling.
	 */
	public function set_resource_translations( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->wpml->is_active() ) {
			return $this->wpml_inactive();
		}

		$post_id = (int) $request->get_param( 'id' );
		$locale  = (string) $request->get_param( 'locale' );
		$values  = $request->get_param( 'values' );

		if ( ! is_array( $values ) || array() === $values ) {
			return new WP_Error(
				'translation_api_bad_request',
				__( '`values` must be a non-empty object of key -> translated value.', 'translation-api' ),
				array( 'status' => 400 )
			);
		}

		// Keep only string values keyed by string; drop anything malformed.
		$clean = array();
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$clean[ $key ] = (string) $value;
			}
		}

		$result = $this->wpml->set_resource_translations( $post_id, $locale, $clean );
		if ( null === $result ) {
			return new WP_Error(
				'translation_api_not_found',
				__( 'Resource not found.', 'translation-api' ),
				array( 'status' => 404 )
			);
		}

		$status = empty( $result['errors'] ) ? 200 : 422;
		return new WP_REST_Response( $result, $status );
	}

	private function wpml_inactive(): WP_Error {
		return new WP_Error(
			'translation_api_wpml_inactive',
			__( 'WPML is not active on this site.', 'translation-api' ),
			array( 'status' => 409 )
		);
	}
}
