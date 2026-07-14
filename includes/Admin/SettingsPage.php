<?php
/**
 * Admin settings screen — one-click connect to the ELAN AI Bridge.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Admin;

use ElanBridge\Connection\ConnectionManager;
use ElanBridge\Events\OutboxStore;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → ELAN AI Bridge. Disconnected: a one-field connect form (ELAN
 * API key) plus post-type selection. Connected: a status panel + Disconnect.
 *
 * The whole point is minimal WordPress setup — the admin pastes one key and
 * clicks Connect; the plugin mints the Application Password and registers
 * with the bridge. No credential copying, no WPML Translation Management.
 */
final class SettingsPage {

	private const PAGE_SLUG = 'elan-bridge';

	/** Default ELAN app URL the plugin registers against. */
	private const DEFAULT_BRIDGE_URL = 'https://app.elanlanguages.ai';
	private const RETRY_ACTION       = 'elan_bridge_retry_events';

	private ConnectionManager $connection;
	private OutboxStore $outbox;

	public function __construct( ConnectionManager $connection, OutboxStore $outbox ) {
		$this->connection = $connection;
		$this->outbox     = $outbox;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::RETRY_ACTION, array( $this, 'handle_retry' ) );
	}

	public function register_menu(): void {
		add_options_page(
			__( 'ELAN AI Bridge', 'elan-bridge' ),
			__( 'ELAN AI Bridge', 'elan-bridge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'ELAN AI Bridge', 'elan-bridge' ) . '</h1>';
		$this->render_notice();
		if ( $this->connection->is_connected() ) {
			$this->render_connected();
		} else {
			$this->render_connect_form();
		}
		echo '</div>';
	}

	private function render_notice(): void {
		$notice = isset( $_GET['elan_notice'] ) ? sanitize_key( wp_unslash( $_GET['elan_notice'] ) ) : '';
		if ( 'connected' === $notice ) {
			$this->notice( 'success', __( 'Connected to the ELAN AI Bridge.', 'elan-bridge' ) );
		} elseif ( 'disconnected' === $notice ) {
			$this->notice( 'info', __( 'Disconnected from the ELAN AI Bridge.', 'elan-bridge' ) );
		} elseif ( 'error' === $notice ) {
			$err = (string) ( $this->connection->connection()['last_error'] ?? __( 'Could not connect.', 'elan-bridge' ) );
			$this->notice( 'error', $err );
		} elseif ( 'retried' === $notice ) {
			$count = absint( $_GET['elan_count'] ?? 0 );
			/* translators: %d: number of retried events */
			$this->notice( 'success', sprintf( _n( '%d event queued for retry.', '%d events queued for retry.', $count, 'elan-bridge' ), $count ) );
		}
	}

	private function render_connect_form(): void {
		// A deliberate white-label override (distinct from ELAN_BRIDGE_URL, which
		// is the plugin's own folder URL defined in the bootstrap file).
		$preset_url = defined( 'ELAN_BRIDGE_APP_URL' ) ? (string) ELAN_BRIDGE_APP_URL : '';
		$saved      = (array) get_option( ConnectionManager::SETTINGS_OPTION, array() );
		$bridge_url = $preset_url ?: ( (string) ( $saved['bridge_url'] ?? '' ) ?: self::DEFAULT_BRIDGE_URL );
		$selected   = (array) ( $saved['post_types'] ?? array( 'page' ) );
		?>
		<p><?php esc_html_e( 'Click Connect, then sign in to ELAN and choose the organization this site belongs to. No API key to copy.', 'elan-bridge' ); ?></p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( ConnectionManager::CONNECT_INIT_ACTION ); ?>" />
			<?php wp_nonce_field( ConnectionManager::CONNECT_INIT_ACTION ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bridge_url"><?php esc_html_e( 'ELAN URL', 'elan-bridge' ); ?></label></th>
					<td>
						<input name="bridge_url" id="bridge_url" type="url" class="regular-text"
							value="<?php echo esc_attr( $bridge_url ); ?>"
							placeholder="https://app.elanlanguages.ai"
							<?php echo $preset_url ? 'readonly' : ''; ?> required />
						<?php if ( $preset_url ) : ?>
							<p class="description"><?php esc_html_e( 'Set by the site configuration.', 'elan-bridge' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Content to translate', 'elan-bridge' ); ?></th>
					<td><?php $this->render_post_type_checkboxes( $selected ); ?></td>
				</tr>
			</table>
			<?php submit_button( __( 'Connect with ELAN', 'elan-bridge' ) ); ?>
		</form>
		<?php
	}

	private function render_connected(): void {
		$conn  = $this->connection->connection();
		$saved = (array) get_option( ConnectionManager::SETTINGS_OPTION, array() );
		$types = implode( ', ', (array) ( $saved['post_types'] ?? array( 'page' ) ) );
		$stats = $this->outbox->stats();
		?>
		<div class="notice notice-success inline"><p>
			<strong><?php esc_html_e( 'Connected', 'elan-bridge' ); ?></strong>
			<?php
			if ( ! empty( $conn['organization'] ) ) {
				echo ' — ' . esc_html( (string) $conn['organization'] );
			}
			?>
		</p></div>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Connection ID', 'elan-bridge' ); ?></th><td><code><?php echo esc_html( (string) ( $conn['connection_id'] ?? '' ) ); ?></code></td></tr>
			<tr><th><?php esc_html_e( 'Watching', 'elan-bridge' ); ?></th><td><?php echo esc_html( $types ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Connected at', 'elan-bridge' ); ?></th><td><?php echo esc_html( (string) ( $conn['connected_at'] ?? '' ) ); ?> UTC</td></tr>
			<tr><th><?php esc_html_e( 'Last event delivered', 'elan-bridge' ); ?></th><td><?php echo esc_html( $stats['last_delivered'] ?? __( 'Never', 'elan-bridge' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Queued events', 'elan-bridge' ); ?></th><td><?php echo esc_html( number_format_i18n( $stats['queued'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Failed events', 'elan-bridge' ); ?></th><td><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></td></tr>
			<?php if ( '' !== $stats['last_error'] ) : ?>
				<tr><th><?php esc_html_e( 'Most recent delivery error', 'elan-bridge' ); ?></th><td><code><?php echo esc_html( $stats['last_error'] ); ?></code></td></tr>
			<?php endif; ?>
		</table>
		<?php if ( $stats['failed'] > 0 ) : ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RETRY_ACTION ); ?>" />
				<?php wp_nonce_field( self::RETRY_ACTION ); ?>
				<?php submit_button( __( 'Retry failed events', 'elan-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( ConnectionManager::DISCONNECT_ACTION ); ?>" />
			<?php wp_nonce_field( ConnectionManager::DISCONNECT_ACTION ); ?>
			<?php submit_button( __( 'Disconnect', 'elan-bridge' ), 'delete' ); ?>
		</form>
		<?php
	}

	public function handle_retry(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'elan-bridge' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::RETRY_ACTION );
		$count = $this->outbox->retry_failures();
		if ( $count > 0 ) {
			do_action( 'elan_bridge_event_queued', '', 0 );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'elan_notice' => 'retried',
					'elan_count'  => $count,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * @param array<int,string> $selected
	 */
	private function render_post_type_checkboxes( array $selected ): void {
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			printf(
				'<label style="display:block;margin:.2rem 0"><input type="checkbox" name="post_types[]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $type->name ),
				checked( in_array( $type->name, $selected, true ), true, false ),
				esc_html( $type->labels->singular_name . ' (' . $type->name . ')' )
			);
		}
	}

	private function notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
