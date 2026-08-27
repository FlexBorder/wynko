<?php
/**
 * Tests for the activity log's levels, threshold, and cap.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Log;
use PHPUnit\Framework\TestCase;

/** Covers what the log stores, what it refuses to store, and what it drops. */
final class LogTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	/**
	 * The levels of the stored entries, newest first.
	 *
	 * @return array<int,string>
	 */
	private function levels(): array {
		return array_map(
			static function ( array $entry ): string {
				return (string) $entry['level'];
			},
			Log::all()
		);
	}

	public function test_each_helper_stores_its_own_level(): void {
		Log::info( 'i' );
		Log::warning( 'w' );
		Log::error( 'e' );

		$this->assertSame( array( 'error', 'warning', 'info' ), $this->levels() );
	}

	public function test_an_entry_carries_a_time_and_the_message_verbatim(): void {
		Log::error( 'Sync failed: timeout' );

		$entry = Log::all()[0];
		$this->assertSame( 'Sync failed: timeout', $entry['message'] );
		$this->assertNotSame( '', $entry['time'] );
	}

	public function test_an_unknown_level_is_stored_as_info(): void {
		Log::add( 'chatter', 'm' );

		$this->assertSame( array( 'info' ), $this->levels() );
	}

	public function test_the_default_threshold_records_everything(): void {
		Log::info( 'i' );

		$this->assertSame( 'info', Log::threshold() );
		$this->assertCount( 1, Log::all() );
	}

	public function test_the_warning_threshold_drops_info(): void {
		update_option( Config::option_key( 'log_level' ), 'warning' );

		Log::info( 'i' );
		Log::warning( 'w' );
		Log::error( 'e' );

		$this->assertSame( array( 'error', 'warning' ), $this->levels() );
	}

	public function test_the_error_threshold_keeps_errors_alone(): void {
		update_option( Config::option_key( 'log_level' ), 'error' );

		Log::info( 'i' );
		Log::warning( 'w' );
		Log::error( 'e' );

		$this->assertSame( array( 'error' ), $this->levels() );
	}

	public function test_an_unreadable_threshold_falls_back_to_the_default(): void {
		update_option( Config::option_key( 'log_level' ), 'nonsense' );

		$this->assertSame( 'info', Log::threshold() );
	}

	/**
	 * The threshold decides what is written, not what is shown: entries stored
	 * under a looser setting survive a stricter one.
	 */
	public function test_raising_the_threshold_leaves_stored_entries_alone(): void {
		Log::info( 'i' );
		update_option( Config::option_key( 'log_level' ), 'error' );

		$this->assertCount( 1, Log::all() );
	}

	public function test_the_log_is_capped_at_the_configured_maximum(): void {
		$max   = Config::log_max();
		$total = $max + 5;
		for ( $i = 0; $i < $total; $i++ ) {
			Log::info( 'entry ' . $i );
		}

		$entries = Log::all();
		$this->assertCount( $max, $entries );
		$this->assertSame( 'entry ' . ( $total - 1 ), $entries[0]['message'] );
	}

	public function test_clear_empties_the_log(): void {
		Log::info( 'i' );
		Log::clear();

		$this->assertSame( array(), Log::all() );
	}
}
