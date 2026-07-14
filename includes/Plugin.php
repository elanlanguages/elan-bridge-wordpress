<?php
/**
 * Plugin bootstrap.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge;

use ElanBridge\Admin\SettingsPage;
use ElanBridge\Connection\ConnectionManager;
use ElanBridge\Events\EventDispatcher;
use ElanBridge\Events\EventOutbox;
use ElanBridge\Events\ResourceChangeEmitter;
use ElanBridge\Rest\CmsController;
use ElanBridge\Updater\GitHubReleaseUpdater;
use ElanBridge\Wpml\WpmlReader;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's pieces together: the REST surface the ELAN AI Bridge
 * pulls from, and the admin settings screen that configures it.
 *
 * Content remains pull-based through `/wp-json/elan/v1/...`; small signed
 * resource-change events provide the low-latency trigger without coupling
 * WordPress editor requests to Bridge availability.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private WpmlReader $wpml;

	private function __construct() {
		$this->wpml = new WpmlReader();
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
		EventOutbox::maybe_install();

		$controller = new CmsController( $this->wpml );
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );

		$connection = new ConnectionManager();
		$connection->register_hooks();
		$outbox     = new EventOutbox();
		$dispatcher = new EventDispatcher( $outbox, $connection );
		$dispatcher->register_hooks();
		( new ResourceChangeEmitter( $this->wpml, $outbox, $connection ) )->register_hooks();

		if ( is_admin() ) {
			( new SettingsPage( $connection, $outbox ) )->register_hooks();
		}

		// Self-hosted updates from GitHub Releases. Registered unconditionally
		// (not just in admin) so WP-Cron's background update checks see it too.
		( new GitHubReleaseUpdater(
			'elanlanguages',
			'elan-bridge-wordpress',
			ELAN_BRIDGE_FILE,
			ELAN_BRIDGE_VERSION,
			defined( 'ELAN_BRIDGE_UPDATE_TOKEN' ) ? (string) ELAN_BRIDGE_UPDATE_TOKEN : null
		) )->register_hooks();

		load_plugin_textdomain( 'elan-bridge', false, dirname( plugin_basename( ELAN_BRIDGE_FILE ) ) . '/languages' );
	}

	public function wpml(): WpmlReader {
		return $this->wpml;
	}

	public static function activate(): void {
		EventOutbox::install();
	}

	public static function deactivate(): void {
		EventDispatcher::deactivate();
	}
}
