<?php
/**
 * Self-hosted plugin updates from GitHub Releases.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Teaches WordPress to update this plugin from GitHub Releases instead of
 * wordpress.org — without any third-party library.
 *
 * On each update check it asks the GitHub API for the latest `v*` release and,
 * if that release is newer than the installed version, hands WordPress an
 * update record pointing at the release's `elan-bridge.zip` asset. The
 * "View details" modal is filled from the release notes. The download is
 * fetched through the GitHub asset API so it works for both public repos
 * (no credentials) and private repos (with a token — see below).
 *
 * The plugin header also carries an `Update URI`, which stops wordpress.org
 * from ever trying to claim the `elan-bridge` slug.
 *
 * Private repos: define `ELAN_BRIDGE_UPDATE_TOKEN` (a GitHub token with read
 * access) in wp-config.php so the API + asset download authenticate. Note that
 * ships the token to every site — for a customer-distributed plugin prefer a
 * public/source-available repo, or front updates with a license-gated proxy.
 * See RELEASING.md.
 */
final class GitHubReleaseUpdater {

	private const TRANSIENT = 'elan_bridge_gh_release';
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	private const MISS_TTL  = 15 * MINUTE_IN_SECONDS;

	private string $owner;
	private string $repo;
	private string $plugin_basename;
	private string $slug;
	private string $version;
	private ?string $token;

	/**
	 * @param string      $owner       GitHub org/user that owns the repo.
	 * @param string      $repo        GitHub repository name.
	 * @param string      $plugin_file Absolute path to the main plugin file.
	 * @param string      $version     Installed plugin version.
	 * @param string|null $token       Optional GitHub token (for private repos).
	 */
	public function __construct( string $owner, string $repo, string $plugin_file, string $version, ?string $token = null ) {
		$this->owner           = $owner;
		$this->repo            = $repo;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->slug            = dirname( $this->plugin_basename );
		$this->version         = $version;
		$this->token           = ( null !== $token && '' !== $token ) ? $token : null;
	}

	/**
	 * Register with WordPress's update machinery. Cheap: the filters only do
	 * real work during an update-data refresh, and the GitHub call is cached.
	 */
	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'authenticate_download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ) );
	}

	/**
	 * Add (or clear) this plugin's entry in the update transient.
	 *
	 * @param mixed $transient The `update_plugins` transient, or false.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return $transient;
		}

		$has_update = version_compare( $release['version'], $this->version, '>' );

		$item = (object) array(
			'id'           => 'github.com/' . $this->owner . '/' . $this->repo,
			'slug'         => $this->slug,
			'plugin'       => $this->plugin_basename,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $has_update ? $release['package'] : '',
			'icons'        => array(),
			'banners'      => array(),
			'tested'       => $release['tested'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
		);

		if ( $has_update ) {
			$transient->response[ $this->plugin_basename ] = $item;
		} else {
			// Mark "no update" so core shows it as current and never falls
			// back to wordpress.org for this slug.
			$transient->no_update[ $this->plugin_basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Fill the "View details" modal from the release notes.
	 *
	 * @param mixed  $result Default false (let core handle it).
	 * @param string $action The requested plugins_api action.
	 * @param object $args   Request args; `slug` is what we match on.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'ELAN AI Bridge',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://elanlanguages.com">ELAN Languages</a>',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'trunk'         => $release['package'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => $release['tested'],
			'last_updated'  => $release['date'],
			'sections'      => array( 'changelog' => $release['changelog'] ),
		);
	}

	/**
	 * Download our release asset through the GitHub API (so it works for
	 * private repos and authenticated rate limits). Returns a local temp file
	 * for core to install, or passes through for anything that isn't ours.
	 *
	 * @param mixed  $reply   Default false.
	 * @param string $package The package URL core is about to download.
	 * @param object $upgrader The upgrader instance (unused).
	 * @return mixed string temp path, WP_Error, or the untouched $reply.
	 */
	public function authenticate_download( $reply, $package, $upgrader ) {
		$asset_prefix = sprintf( 'https://api.github.com/repos/%s/%s/releases/assets/', $this->owner, $this->repo );
		if ( ! is_string( $package ) || 0 !== strpos( $package, $asset_prefix ) ) {
			return $reply;
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 300,
				'redirection' => 5,
				'headers'     => $this->headers( 'application/octet-stream' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error(
				'elan_bridge_download_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Could not download the ELAN AI Bridge update (HTTP %d).', 'elan-bridge' ),
					(int) wp_remote_retrieve_response_code( $response )
				)
			);
		}

		$tmp = wp_tempnam( $this->slug . '-update.zip' );
		if ( ! $tmp ) {
			return new \WP_Error( 'elan_bridge_tmp_failed', __( 'Could not create a temporary file for the update.', 'elan-bridge' ) );
		}

		if ( false === file_put_contents( $tmp, wp_remote_retrieve_body( $response ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $tmp );
			return new \WP_Error( 'elan_bridge_write_failed', __( 'Could not write the downloaded update to disk.', 'elan-bridge' ) );
		}

		return $tmp;
	}

	/**
	 * Defensively rename the extracted folder to the plugin slug, so an
	 * upgrade always lands in `wp-content/plugins/elan-bridge/` regardless of
	 * how the zip's top-level folder happens to be named.
	 *
	 * @param string $source        Extracted source directory.
	 * @param string $remote_source The parent temp directory.
	 * @param object $upgrader      The upgrader instance (unused).
	 * @param array  $hook_extra    Contextual data; `plugin` identifies the target.
	 * @return string|\WP_Error
	 */
	public function normalize_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}
		if ( ! $wp_filesystem || trailingslashit( basename( untrailingslashit( $source ) ) ) === trailingslashit( $this->slug ) ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;
		if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ), true ) ) {
			return trailingslashit( $desired );
		}
		return $source;
	}

	/**
	 * Drop the cached release after any install/upgrade so the next check is fresh.
	 */
	public function flush_cache(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Fetch + cache the latest release, normalised to the fields we feed WP.
	 * Returns null on any failure (network, rate-limit, no release, no zip
	 * asset). Failures are non-fatal: updates just don't appear.
	 *
	 * @return array<string,string>|null
	 */
	private function latest_release(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return array() === $cached ? null : $cached;
		}

		$endpoint = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo )
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 10,
				'headers' => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::TRANSIENT, array(), self::MISS_TTL );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::TRANSIENT, array(), self::MISS_TTL );
			return null;
		}

		$package = $this->find_zip_asset( isset( $body['assets'] ) && is_array( $body['assets'] ) ? $body['assets'] : array() );
		if ( null === $package ) {
			set_transient( self::TRANSIENT, array(), self::MISS_TTL );
			return null;
		}

		$release = array(
			'version'      => ltrim( (string) $body['tag_name'], 'vV' ),
			'url'          => (string) ( $body['html_url'] ?? '' ),
			'package'      => $package,
			'changelog'    => $this->render_changelog( (string) ( $body['body'] ?? '' ) ),
			'date'         => (string) ( $body['published_at'] ?? '' ),
			'tested'       => '',
			'requires'     => '6.4',
			'requires_php' => '8.1',
		);

		set_transient( self::TRANSIENT, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * Pick the `.zip` asset's API URL (the one the asset download endpoint
	 * understands for both public and private repos).
	 *
	 * @param array<int,array<string,mixed>> $assets Release assets.
	 * @return string|null
	 */
	private function find_zip_asset( array $assets ): ?string {
		foreach ( $assets as $asset ) {
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			if ( '.zip' === strtolower( substr( $name, -4 ) ) && ! empty( $asset['url'] ) ) {
				return (string) $asset['url'];
			}
		}
		return null;
	}

	/**
	 * Convert the release-notes Markdown into the minimal HTML the update
	 * modal expects (headings + lists + paragraphs). Kept deliberately small.
	 *
	 * @param string $markdown Raw release body.
	 * @return string
	 */
	private function render_changelog( string $markdown ): string {
		$markdown = trim( $markdown );
		if ( '' === $markdown ) {
			return __( 'See the GitHub release for details.', 'elan-bridge' );
		}

		$lines = preg_split( '/\r\n|\r|\n/', $markdown );
		$html  = '';
		$in_ul = false;
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^[-*]\s+(.*)/', $line, $m ) ) {
				$html .= ( $in_ul ? '' : '<ul>' ) . '<li>' . esc_html( $m[1] ) . '</li>';
				$in_ul = true;
				continue;
			}
			if ( $in_ul ) {
				$html .= '</ul>';
				$in_ul = false;
			}
			if ( preg_match( '/^#{1,6}\s+(.*)/', $line, $m ) ) {
				$html .= '<h4>' . esc_html( $m[1] ) . '</h4>';
				continue;
			}
			$html .= '<p>' . esc_html( $line ) . '</p>';
		}
		if ( $in_ul ) {
			$html .= '</ul>';
		}
		return $html;
	}

	/**
	 * Common request headers: a User-Agent (GitHub requires one) and, when a
	 * token is configured, Bearer auth. `$accept` switches between JSON
	 * metadata and the raw asset binary.
	 *
	 * @param string $accept The Accept header value.
	 * @return array<string,string>
	 */
	private function headers( string $accept = 'application/vnd.github+json' ): array {
		$headers = array(
			'Accept'               => $accept,
			'User-Agent'           => 'ELAN-AI-Bridge-WordPress/' . $this->version,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( null !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		return $headers;
	}
}
