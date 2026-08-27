<?php
/**
 * Tests for the sliding-window rate-limit arithmetic.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\RateLimiter;
use PHPUnit\Framework\TestCase;

/** Covers pruning, the cap comparison, and recording. */
final class RateLimiterTest extends TestCase {

	public function test_hits_older_than_the_window_are_pruned(): void {
		$hits = array( 1000, 1500, 1900 );

		$this->assertSame( array( 1500, 1900 ), RateLimiter::prune( $hits, 2000, 600 ) );
	}

	/**
	 * The window slides: a hit exactly one window old is out, so a caller
	 * cannot get a free extra attempt by arriving on the boundary.
	 */
	public function test_a_hit_exactly_one_window_old_is_out(): void {
		$this->assertSame( array(), RateLimiter::prune( array( 1400 ), 2000, 600 ) );
	}

	public function test_pruning_drops_values_that_are_not_timestamps(): void {
		$hits = array( 1900, 'nonsense', null, array( 1950 ) );

		$this->assertSame( array( 1900 ), RateLimiter::prune( $hits, 2000, 600 ) );
	}

	public function test_pruning_reindexes_so_the_list_stays_a_json_array(): void {
		$pruned = RateLimiter::prune( array( 1000, 1900 ), 2000, 600 );

		$this->assertSame( array( 0 ), array_keys( $pruned ) );
	}

	public function test_a_count_below_the_cap_is_allowed(): void {
		$this->assertFalse( RateLimiter::exceeded( array( 1, 2 ), 3 ) );
	}

	/**
	 * The cap counts what has already happened, so the attempt that would be
	 * the (max + 1)th is the one refused.
	 */
	public function test_a_count_at_the_cap_is_refused(): void {
		$this->assertTrue( RateLimiter::exceeded( array( 1, 2, 3 ), 3 ) );
	}

	public function test_recording_appends_the_current_time(): void {
		$this->assertSame( array( 1900, 2000 ), RateLimiter::record( array( 1900 ), 2000 ) );
	}

	public function test_an_empty_counter_allows_the_first_attempt(): void {
		$this->assertFalse( RateLimiter::exceeded( RateLimiter::prune( array(), 2000, 600 ), 1 ) );
	}
}
