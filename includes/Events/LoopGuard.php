<?php
/**
 * Prevent outbound events while Bridge writes translations back.
 *
 * @package ElanBridge
 */

declare( strict_types=1 );

namespace ElanBridge\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Request-local, nestable suppression guard around WordPress write APIs.
 */
final class LoopGuard {

	/**
	 * Current suppression nesting depth.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Whether resource-change capture is currently suppressed.
	 */
	public static function is_suppressed(): bool {
		return self::$depth > 0;
	}

	/**
	 * Run write-back work without emitting outbound source events.
	 *
	 * @template T
	 * @param callable():T $callback Work that must not produce source events.
	 * @return T
	 */
	public static function without_events( callable $callback ) {
		++self::$depth;
		try {
			return $callback();
		} finally {
			--self::$depth;
		}
	}
}
