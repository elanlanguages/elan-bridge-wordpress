<?php
/**
 * HMAC signing for outbound CMS events.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the Bridge receiver contract: HMAC-SHA256(timestamp + raw body).
 */
final class EventSigner {

	/**
	 * Sign an exact serialized event body for the Bridge receiver.
	 *
	 * @param string $secret    Pairing secret shared server-to-server.
	 * @param string $timestamp Unix timestamp sent in the request header.
	 * @param string $raw_body  Exact JSON bytes sent as the request body.
	 */
	public static function sign( string $secret, string $timestamp, string $raw_body ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . $raw_body, $secret );
	}
}
