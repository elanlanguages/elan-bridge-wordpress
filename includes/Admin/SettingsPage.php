<?php
/**
 * Admin settings screen — create and revoke the API keys that guard the REST API.
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

namespace TranslationApi\Admin;

use TranslationApi\Auth\ApiKeyManager;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Translation API.
 *
 * The whole page is API-key management: create a labelled key (shown once),
 * see the keys that exist, and revoke any of them. It also shows the REST base
 * URL so an integrator knows where to point their client. No external service,
 * no connect flow — the plugin is a self-contained REST server.
 */
final class SettingsPage {

	private const PAGE_SLUG = 'translation-api';

	public const CREATE_ACTION = 'translation_api_create_key';
	public const REVOKE_ACTION = 'translation_api_revoke_key';

	/** One-time store for a freshly minted key, so we can show it after the redirect. */
	private const NEW_KEY_TRANSIENT = 'translation_api_new_key_';
	private const NEW_KEY_TTL       = 60;

	private ApiKeyManager $api_keys;

	public function __construct( ApiKeyManager $api_keys ) {
		$this->api_keys = $api_keys;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle_create' ) );
		add_action( 'admin_post_' . self::REVOKE_ACTION, array( $this, 'handle_revoke' ) );
	}

	public function register_menu(): void {
		add_options_page(
			__( 'Translation API', 'translation-api' ),
			__( 'Translation API', 'translation-api' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Translation API', 'translation-api' ) . '</h1>';
		$this->render_notice();
		$this->render_new_key();
		$this->render_usage();
		$this->render_create_form();
		$this->render_key_table();
		echo '</div>';
	}

	// -- create ------------------------------------------------------------

	public function handle_create(): void {
		$this->guard( self::CREATE_ACTION );

		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );

		[ $plaintext ] = $this->api_keys->create( $label, get_current_user_id() );

		// Hand the plaintext to the next page load exactly once; it is the only
		// time it can ever be shown.
		set_transient( self::NEW_KEY_TRANSIENT . get_current_user_id(), $plaintext, self::NEW_KEY_TTL );

		$this->redirect( 'created' );
	}

	// -- revoke ------------------------------------------------------------

	public function handle_revoke(): void {
		$this->guard( self::REVOKE_ACTION );

		$id      = sanitize_text_field( wp_unslash( $_POST['key_id'] ?? '' ) );
		$revoked = '' !== $id && $this->api_keys->revoke( $id );

		$this->redirect( $revoked ? 'revoked' : 'error' );
	}

	// -- rendering ---------------------------------------------------------

	private function render_notice(): void {
		$notice = isset( $_GET['ta_notice'] ) ? sanitize_key( wp_unslash( $_GET['ta_notice'] ) ) : '';
		if ( 'created' === $notice ) {
			$this->notice( 'success', __( 'API key created.', 'translation-api' ) );
		} elseif ( 'revoked' === $notice ) {
			$this->notice( 'info', __( 'API key revoked.', 'translation-api' ) );
		} elseif ( 'error' === $notice ) {
			$this->notice( 'error', __( 'That key could not be found. It may already have been revoked.', 'translation-api' ) );
		}
	}

	/**
	 * Show a freshly minted key once, then burn it. This is the only moment the
	 * plaintext is ever visible — only its hash is stored.
	 */
	private function render_new_key(): void {
		$transient_key = self::NEW_KEY_TRANSIENT . get_current_user_id();
		$plaintext     = get_transient( $transient_key );
		if ( ! is_string( $plaintext ) || '' === $plaintext ) {
			return;
		}
		delete_transient( $transient_key );
		?>
		<div class="notice notice-success">
			<p><strong><?php esc_html_e( 'Copy your new API key now — it will not be shown again.', 'translation-api' ); ?></strong></p>
			<p><input type="text" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_attr( $plaintext ); ?>" /></p>
		</div>
		<?php
	}

	private function render_usage(): void {
		$base = esc_url( rest_url( 'translation/v1' ) );
		?>
		<h2><?php esc_html_e( 'How to connect', 'translation-api' ); ?></h2>
		<p><?php esc_html_e( 'Point your translation system at the REST base below and send an API key on every request, either as the X-API-Key header or as an Authorization: Bearer header.', 'translation-api' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'REST base', 'translation-api' ); ?></th>
				<td><code><?php echo esc_html( $base ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Example', 'translation-api' ); ?></th>
				<td><code>curl -H "X-API-Key: &lt;key&gt;" <?php echo esc_html( $base ); ?>/health</code></td>
			</tr>
		</table>
		<?php
	}

	private function render_create_form(): void {
		?>
		<h2><?php esc_html_e( 'Create an API key', 'translation-api' ); ?></h2>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE_ACTION ); ?>" />
			<?php wp_nonce_field( self::CREATE_ACTION ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ta_label"><?php esc_html_e( 'Label', 'translation-api' ); ?></label></th>
					<td>
						<input name="label" id="ta_label" type="text" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Acme TMS', 'translation-api' ); ?>" />
						<p class="description"><?php esc_html_e( 'A name to help you recognise this key later.', 'translation-api' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Create key', 'translation-api' ) ); ?>
		</form>
		<?php
	}

	private function render_key_table(): void {
		$keys = $this->api_keys->all();
		?>
		<h2><?php esc_html_e( 'API keys', 'translation-api' ); ?></h2>
		<?php if ( empty( $keys ) ) : ?>
			<p><?php esc_html_e( 'No API keys yet. Create one above to start using the API.', 'translation-api' ); ?></p>
			<?php
			return;
endif;
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'translation-api' ); ?></th>
					<th><?php esc_html_e( 'Key', 'translation-api' ); ?></th>
					<th><?php esc_html_e( 'Created', 'translation-api' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'translation-api' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $keys as $key ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $key['label'] ?? '' ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $key['prefix'] ?? '' ) ); ?>…</code></td>
						<td><?php echo esc_html( $this->format_date( (int) ( $key['created_at'] ?? 0 ) ) ); ?></td>
						<td>
							<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this key? Any client using it will stop working immediately.', 'translation-api' ) ); ?>');">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE_ACTION ); ?>" />
								<input type="hidden" name="key_id" value="<?php echo esc_attr( (string) ( $key['id'] ?? '' ) ); ?>" />
								<?php wp_nonce_field( self::REVOKE_ACTION ); ?>
								<?php submit_button( __( 'Revoke', 'translation-api' ), 'delete small', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -- helpers -----------------------------------------------------------

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'translation-api' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				'ta_notice',
				$notice,
				admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	private function format_date( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}
		return wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp );
	}

	private function notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
