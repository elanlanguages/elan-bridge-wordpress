<?php
/**
 * Tests for the self-hosted GitHub Releases updater.
 *
 * These drive the updater the way WordPress would, with a fake HTTP layer fed
 * the *real* `releases/latest` payload (tests/fixtures/release-latest.json), so
 * we verify parsing against the actual GitHub API shape — not a hand-written
 * approximation of it.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

use ElanBridge\Updater\GitHubReleaseUpdater;
use PHPUnit\Framework\TestCase;

final class GitHubReleaseUpdaterTest extends TestCase {

	private const BASENAME = 'elan-bridge/elan-bridge.php';
	private const ASSET_PREFIX = 'https://api.github.com/repos/elanlanguages/elan-bridge-wordpress/releases/assets/';

	protected function setUp(): void {
		eb_reset_test_state();
	}

	private function updater( string $installed_version ): GitHubReleaseUpdater {
		return new GitHubReleaseUpdater(
			'elanlanguages',
			'elan-bridge-wordpress',
			'/var/www/html/wp-content/plugins/elan-bridge/elan-bridge.php',
			$installed_version
		);
	}

	private function serve_release( string $json ): void {
		eb_set_http( static fn( $url, $args ) => array( 'response' => array( 'code' => 200 ), 'body' => $json ) );
	}

	private function fixture(): string {
		return (string) file_get_contents( __DIR__ . '/../fixtures/release-latest.json' );
	}

	private function fresh_transient(): object {
		$t              = new \stdClass();
		$t->response    = array();
		$t->no_update   = array();
		return $t;
	}

	public function test_real_fixture_produces_an_update_when_newer(): void {
		$this->serve_release( $this->fixture() );

		$transient = $this->updater( '0.0.9' )->inject_update( $this->fresh_transient() );

		$this->assertArrayHasKey( self::BASENAME, $transient->response, 'an update should be offered' );
		$entry = $transient->response[ self::BASENAME ];
		$this->assertSame( '0.1.0', $entry->new_version );
		$this->assertSame( 'elan-bridge', $entry->slug );
		$this->assertSame( self::BASENAME, $entry->plugin );
		$this->assertStringStartsWith( self::ASSET_PREFIX, $entry->package, 'package must be the zip asset API URL' );
		$this->assertArrayNotHasKey( self::BASENAME, $transient->no_update );
	}

	public function test_no_update_when_installed_version_is_current(): void {
		$this->serve_release( $this->fixture() );

		$transient = $this->updater( '0.1.0' )->inject_update( $this->fresh_transient() );

		$this->assertArrayNotHasKey( self::BASENAME, $transient->response, 'no update at the same version' );
		$this->assertArrayHasKey( self::BASENAME, $transient->no_update, 'should record as up to date' );
		$this->assertSame( '', $transient->no_update[ self::BASENAME ]->package );
	}

	public function test_no_update_when_installed_version_is_newer(): void {
		$this->serve_release( $this->fixture() );

		$transient = $this->updater( '9.9.9' )->inject_update( $this->fresh_transient() );

		$this->assertArrayNotHasKey( self::BASENAME, $transient->response );
		$this->assertArrayHasKey( self::BASENAME, $transient->no_update );
	}

	public function test_http_failure_is_non_fatal_and_injects_nothing(): void {
		eb_set_http( static fn( $url, $args ) => new \WP_Error( 'http', 'boom' ) );

		$transient = $this->updater( '0.0.1' )->inject_update( $this->fresh_transient() );

		$this->assertSame( array(), $transient->response );
		$this->assertSame( array(), $transient->no_update );
	}

	public function test_missing_zip_asset_is_ignored(): void {
		$release = wp_json_encode_compat(
			array(
				'tag_name' => 'v2.0.0',
				'html_url' => 'https://example.com/r',
				'assets'   => array( array( 'name' => 'notes.txt', 'url' => 'https://x/y' ) ),
				'body'     => 'no zip here',
			)
		);
		$this->serve_release( $release );

		$transient = $this->updater( '0.0.1' )->inject_update( $this->fresh_transient() );

		$this->assertArrayNotHasKey( self::BASENAME, $transient->response, 'a release with no zip is not installable' );
	}

	public function test_plugin_info_renders_changelog_markdown(): void {
		$release = wp_json_encode_compat(
			array(
				'tag_name'     => 'v0.2.0',
				'html_url'     => 'https://example.com/r',
				'published_at' => '2026-06-07T00:00:00Z',
				'assets'       => array( array( 'name' => 'elan-bridge.zip', 'url' => self::ASSET_PREFIX . '42' ) ),
				'body'         => "## Highlights\n- First bullet\n- Second bullet\n\nA closing paragraph.",
			)
		);
		$this->serve_release( $release );

		$info = $this->updater( '0.1.0' )->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'elan-bridge' ) );

		$this->assertSame( '0.2.0', $info->version );
		$changelog = $info->sections['changelog'];
		$this->assertStringContainsString( '<h4>Highlights</h4>', $changelog );
		$this->assertStringContainsString( '<li>First bullet</li>', $changelog );
		$this->assertStringContainsString( '<p>A closing paragraph.</p>', $changelog );
	}

	public function test_changelog_escapes_html_in_release_notes(): void {
		// Release notes are attacker-influenceable content; they must be escaped.
		$release = wp_json_encode_compat(
			array(
				'tag_name' => 'v0.2.0',
				'html_url' => 'https://example.com/r',
				'assets'   => array( array( 'name' => 'elan-bridge.zip', 'url' => self::ASSET_PREFIX . '7' ) ),
				'body'     => "- <script>alert('xss')</script>",
			)
		);
		$this->serve_release( $release );

		$info = $this->updater( '0.1.0' )->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'elan-bridge' ) );

		$this->assertStringNotContainsString( '<script>', $info->sections['changelog'] );
		$this->assertStringContainsString( '&lt;script&gt;', $info->sections['changelog'] );
	}

	public function test_plugin_info_passes_through_other_slugs(): void {
		$this->serve_release( $this->fixture() );

		$result = $this->updater( '0.1.0' )->plugin_info( 'untouched', 'plugin_information', (object) array( 'slug' => 'something-else' ) );

		$this->assertSame( 'untouched', $result );
	}

	public function test_authenticate_download_ignores_foreign_packages(): void {
		$reply = $this->updater( '0.1.0' )->authenticate_download( false, 'https://example.com/random.zip', null );
		$this->assertFalse( $reply );
	}

	public function test_authenticate_download_streams_asset_to_a_temp_file(): void {
		eb_set_http( static fn( $url, $args ) => array( 'response' => array( 'code' => 200 ), 'body' => 'ZIP-BYTES' ) );

		$path = $this->updater( '0.1.0' )->authenticate_download( false, self::ASSET_PREFIX . '99', null );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );
		$this->assertSame( 'ZIP-BYTES', file_get_contents( $path ) );
		@unlink( $path );
	}
}

/**
 * Tiny json_encode shim so the test reads naturally without depending on WP's
 * wp_json_encode (the updater itself only ever *decodes*).
 */
function wp_json_encode_compat( array $data ): string {
	return (string) json_encode( $data );
}
