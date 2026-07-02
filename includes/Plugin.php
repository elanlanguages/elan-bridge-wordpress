<?php
/**
 * Plugin bootstrap.
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

namespace TranslationApi;

use TranslationApi\Admin\SettingsPage;
use TranslationApi\Auth\ApiKeyManager;
use TranslationApi\Rest\CmsController;
use TranslationApi\Rest\OpenApiController;
use TranslationApi\Wpml\WpmlReader;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's pieces together: the REST surface an external translation
 * system pulls from, the API-key store that authenticates it, and the admin
 * screen where those keys are created and revoked.
 *
 * Topology: this plugin is a self-contained REST *server*. Any client that
 * holds a valid API key (sent as `X-API-Key`, or `Authorization: Bearer`) may
 * read source strings from `/wp-json/translation/v1/...` and write
 * translations back. There is no outbound coupling to any external service.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private WpmlReader $wpml;

	private ApiKeyManager $api_keys;

	private function __construct() {
		$this->wpml     = new WpmlReader();
		$this->api_keys = new ApiKeyManager();
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. Called on `plugins_loaded`.
	 */
	public function boot(): void {
		$controller = new CmsController( $this->wpml, $this->api_keys );
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );

		// Public OpenAPI (Swagger) document describing the routes above.
		$openapi = new OpenApiController();
		add_action( 'rest_api_init', array( $openapi, 'register_routes' ) );

		// Allow the API-key header through CORS preflight, so browser tools on
		// another origin can send it. WordPress already permits `Authorization`
		// (our Bearer form); this gives `X-API-Key` the same reach.
		add_filter(
			'rest_allowed_cors_headers',
			static function ( array $headers ): array {
				$headers[] = 'X-API-Key';
				return $headers;
			}
		);

		if ( is_admin() ) {
			$settings = new SettingsPage( $this->api_keys );
			$settings->register_hooks();
		}

		load_plugin_textdomain( 'translation-api', false, dirname( plugin_basename( TRANSLATION_API_FILE ) ) . '/languages' );
	}

	public function wpml(): WpmlReader {
		return $this->wpml;
	}

	public function api_keys(): ApiKeyManager {
		return $this->api_keys;
	}
}
