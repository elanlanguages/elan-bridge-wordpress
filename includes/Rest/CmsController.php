<?php
/**
 * REST controller — the surface the ELAN AI Bridge pulls from.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Rest;

use ElanBridge\Wpml\WpmlReader;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `/wp-json/elan/v1/...`, returning the canonical CMS-content
 * vocabulary the bridge's `_common/cms.py` defines. The connector on the
 * bridge side is a thin pass-through, so this controller does the WPML work.
 *
 * Auth: standard WordPress authentication via Application Passwords. Every
 * route requires `manage_options` (or the `elan_bridge_pull` capability if a
 * site maps it to a service role). The bridge sends HTTP Basic
 * `username:application-password`; WordPress validates it before we run.
 */
final class CmsController {

	private const NAMESPACE = 'elan/v1';

	private WpmlReader $wpml;

	public function __construct( WpmlReader $wpml ) {
		$this->wpml = $wpml;
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
	 * Permission gate. Application Passwords get the request authenticated as
	 * a real user; we require an editorial capability on top.
	 */
	public function authorize(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'elan_bridge_pull' ) ) {
			return true;
		}
		return new WP_Error(
			'elan_bridge_forbidden',
			__( 'Application Password lacks the required capability.', 'elan-bridge' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function health(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'               => true,
				'plugin_version'   => ELAN_BRIDGE_VERSION,
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
				'elan_bridge_unknown_type',
				/* translators: %s: post type */
				sprintf( __( 'Unknown post type: %s', 'elan-bridge' ), $post_type ),
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
				'elan_bridge_not_found',
				__( 'Resource not found.', 'elan-bridge' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Write-back: create or update the WPML translation for one locale with
	 * the values the bridge produced. Destructive — the bridge gates this
	 * behind its approval queue before calling.
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
				'elan_bridge_bad_request',
				__( '`values` must be a non-empty object of key -> translated value.', 'elan-bridge' ),
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
				'elan_bridge_not_found',
				__( 'Resource not found.', 'elan-bridge' ),
				array( 'status' => 404 )
			);
		}

		$status = empty( $result['errors'] ) ? 200 : 422;
		return new WP_REST_Response( $result, $status );
	}

	private function wpml_inactive(): WP_Error {
		return new WP_Error(
			'elan_bridge_wpml_inactive',
			__( 'WPML is not active on this site.', 'elan-bridge' ),
			array( 'status' => 409 )
		);
	}
}
