<?php
/**
 * Sliding-window rate-limit arithmetic.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-free counting. A counter is a list of unix timestamps, one per
 * recorded attempt; where those lists are kept is Wynko\Throttle's business.
 *
 * The window slides rather than resetting on a fixed boundary, which would let
 * twice the cap through across the seam. `$now` is a parameter so the edge of a
 * window is testable.
 */
final class RateLimiter {

	/**
	 * Drops the timestamps that have fallen out of the window, and anything
	 * that is not one. The input is whatever came back out of storage, so this
	 * is also where a counter stops being untrusted: everything downstream can
	 * assume a list of ints.
	 *
	 * @param array<int,mixed> $hits   Recorded attempt timestamps, as stored.
	 * @param int              $now    Current unix time.
	 * @param int              $window Window length in seconds.
	 * @return array<int,int>
	 */
	public static function prune( array $hits, int $now, int $window ): array {
		$cutoff = $now - $window;

		return array_values(
			array_filter(
				$hits,
				static function ( $hit ) use ( $cutoff ): bool {
					return is_int( $hit ) && $hit > $cutoff;
				}
			)
		);
	}

	/**
	 * Whether a further attempt would exceed the cap.
	 *
	 * @param array<int,int> $hits Pruned attempt timestamps.
	 * @param int            $max  Attempts allowed per window.
	 * @return bool
	 */
	public static function exceeded( array $hits, int $max ): bool {
		return count( $hits ) >= $max;
	}

	/**
	 * Appends one attempt.
	 *
	 * @param array<int,int> $hits Pruned attempt timestamps.
	 * @param int            $now  Current unix time.
	 * @return array<int,int>
	 */
	public static function record( array $hits, int $now ): array {
		$hits[] = $now;

		return array_values( $hits );
	}
}
