<?php
/**
 * API-key store and verifier.
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

namespace TranslationApi\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, lists, revokes and verifies the API keys that guard the REST
 * surface. Keys are shown to the admin exactly once at creation; only a
 * SHA-256 hash is ever stored, so a leaked options table does not leak usable
 * keys. Verification is timing-safe (`hash_equals`).
 *
 * Each key remembers the WordPress user who created it. On a successful
 * request the REST controller assumes that user's identity, so capability
 * checks inside WP_Query (drafts, private posts) and write-back authorship
 * behave exactly as they would for that admin — mirroring how an Application
 * Password authenticates as its owner.
 *
 * Stored option shape (`translation_api_keys`):
 *   [ id => { id, label, hash, prefix, user_id, created_at } ]
 */
final class ApiKeyManager {

	public const OPTION = 'translation_api_keys';

	/** Human-recognisable, greppable prefix on every generated key. */
	private const KEY_PREFIX = 'tapi_';

	/** Length of the random secret appended after the prefix. */
	private const SECRET_LENGTH = 40;

	/**
	 * Mint a new key. Returns the plaintext key (show it once — it is never
	 * recoverable) alongside the stored record.
	 *
	 * @param string $label   Admin-facing label, e.g. "Acme TMS".
	 * @param int    $user_id WordPress user the key acts as.
	 * @return array{0:string,1:array<string,mixed>} [ plaintext_key, record ].
	 */
	public function create( string $label, int $user_id ): array {
		$plaintext = self::KEY_PREFIX . wp_generate_password( self::SECRET_LENGTH, false );

		$record = array(
			'id'         => $this->random_id(),
			'label'      => '' !== $label ? $label : __( 'Unnamed key', 'translation-api' ),
			'hash'       => hash( 'sha256', $plaintext ),
			// A non-secret fragment for the UI so admins can tell keys apart.
			'prefix'     => substr( $plaintext, 0, strlen( self::KEY_PREFIX ) + 4 ),
			'user_id'    => $user_id,
			'created_at' => time(),
		);

		$keys                  = $this->all();
		$keys[ $record['id'] ] = $record;
		update_option( self::OPTION, $keys, false );

		return array( $plaintext, $record );
	}

	/**
	 * Every stored key record, newest first.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$keys = get_option( self::OPTION, array() );
		if ( ! is_array( $keys ) ) {
			return array();
		}
		uasort(
			$keys,
			static fn( $a, $b ): int => (int) ( $b['created_at'] ?? 0 ) <=> (int) ( $a['created_at'] ?? 0 )
		);
		return $keys;
	}

	/**
	 * Delete a key by id. Returns true if a key was removed.
	 */
	public function revoke( string $id ): bool {
		$keys = $this->all();
		if ( ! isset( $keys[ $id ] ) ) {
			return false;
		}
		unset( $keys[ $id ] );
		update_option( self::OPTION, $keys, false );
		return true;
	}

	/**
	 * Verify a presented key. Returns the owning user id on a match, else null.
	 *
	 * The loop compares against every stored hash with `hash_equals` so a
	 * wrong key takes the same time regardless of which byte first differs.
	 */
	public function verify( string $presented ): ?int {
		$presented = trim( $presented );
		if ( '' === $presented ) {
			return null;
		}
		$candidate = hash( 'sha256', $presented );

		$match = null;
		foreach ( $this->all() as $record ) {
			if ( hash_equals( (string) ( $record['hash'] ?? '' ), $candidate ) ) {
				$match = (int) ( $record['user_id'] ?? 0 );
			}
		}
		return ( null !== $match && $match > 0 ) ? $match : null;
	}

	/**
	 * Opaque, URL-safe id for a stored record. Not a secret — it only keys the
	 * option array and appears in revoke links.
	 */
	private function random_id(): string {
		return bin2hex( random_bytes( 8 ) );
	}
}
