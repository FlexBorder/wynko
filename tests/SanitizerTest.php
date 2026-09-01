<?php
/**
 * Tests for the clamps and status classification.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\Sanitizer;
use PHPUnit\Framework\TestCase;

/** Tests for the clamps and status classification. */
final class SanitizerTest extends TestCase {

	public function test_clamp_int_defaults_on_non_numeric(): void {
		$this->assertSame( 60, Sanitizer::clamp_int( 'abc', 1, 1440, 60 ) );
		$this->assertSame( 5, Sanitizer::clamp_int( 'x', 1, 10, 5 ) );
	}

	public function test_clamp_int_bounds(): void {
		$this->assertSame( 1, Sanitizer::clamp_int( 0, 1, 1440, 60 ) );
		$this->assertSame( 1440, Sanitizer::clamp_int( 99999, 1, 1440, 60 ) );
		$this->assertSame( 30, Sanitizer::clamp_int( '30', 1, 1440, 60 ) );
		$this->assertSame( 10, Sanitizer::clamp_int( 50, 1, 10, 5 ) );
		$this->assertSame( 7, Sanitizer::clamp_int( 7, 1, 10, 5 ) );
	}

	public function test_trim_log_keeps_newest_max(): void {
		$entries = array( 'e1', 'e2', 'e3', 'e4' ); // Newest-first.
		$this->assertSame( array( 'e1', 'e2' ), Sanitizer::trim_log( $entries, 2 ) );
	}

	public function test_log_level_coerces_an_unknown_level(): void {
		$this->assertSame( 'warning', Sanitizer::log_level( 'warning', 'info' ) );
		$this->assertSame( 'info', Sanitizer::log_level( 'chatter', 'info' ) );
		$this->assertSame( 'error', Sanitizer::log_level( array(), 'error' ) );
	}

	public function test_level_meets_compares_severity(): void {
		$this->assertTrue( Sanitizer::level_meets( 'error', 'warning' ) );
		$this->assertTrue( Sanitizer::level_meets( 'warning', 'warning' ) );
		$this->assertFalse( Sanitizer::level_meets( 'info', 'warning' ) );
		$this->assertFalse( Sanitizer::level_meets( 'warning', 'error' ) );
		$this->assertTrue( Sanitizer::level_meets( 'info', 'info' ) );
	}

	public function test_level_meets_passes_everything_for_all(): void {
		$this->assertTrue( Sanitizer::level_meets( 'info', Sanitizer::LEVEL_ALL ) );
		$this->assertTrue( Sanitizer::level_meets( 'info', '' ) );
	}

	public function test_level_meets_keeps_an_unrecognised_level(): void {
		$this->assertTrue( Sanitizer::level_meets( 'debug', 'error' ) );
	}

	public function test_filter_log_narrows_by_level_and_text(): void {
		$entries = array(
			array(
				'level'   => 'error',
				'message' => 'Sync failed: timeout',
			),
			array(
				'level'   => 'warning',
				'message' => 'No campaigns',
			),
			array(
				'level'   => 'info',
				'message' => 'Sync succeeded',
			),
		);

		$this->assertCount( 2, Sanitizer::filter_log( $entries, 'warning', '' ) );
		$this->assertCount( 3, Sanitizer::filter_log( $entries, Sanitizer::LEVEL_ALL, '' ) );
		$this->assertCount( 2, Sanitizer::filter_log( $entries, Sanitizer::LEVEL_ALL, 'sync' ) );
		$this->assertCount( 1, Sanitizer::filter_log( $entries, 'error', 'SYNC' ) );
		$this->assertSame( array(), Sanitizer::filter_log( $entries, 'error', 'nothing' ) );
	}

	public function test_filter_log_reindexes_the_result(): void {
		$entries = array(
			array(
				'level'   => 'info',
				'message' => 'a',
			),
			array(
				'level'   => 'error',
				'message' => 'b',
			),
		);
		$this->assertSame( array( 0 ), array_keys( Sanitizer::filter_log( $entries, 'error', '' ) ) );
	}

	/**
	 * The ranking lives in Sanitizer and the enum in config/settings.php. They
	 * are two files describing one list, so drift has to fail here.
	 */
	public function test_log_levels_match_the_configured_enum(): void {
		$settings = require dirname( __DIR__ ) . '/config/settings.php';
		$this->assertSame( $settings['options']['log_level']['allowed'], Sanitizer::LOG_LEVELS );
	}

	public function test_classify_status_treats_401_and_403_as_a_key_problem(): void {
		$this->assertSame( Sanitizer::STATUS_INVALID_KEY, Sanitizer::classify_status( 401 ) );
		$this->assertSame( Sanitizer::STATUS_INVALID_KEY, Sanitizer::classify_status( 403 ) );
	}

	public function test_classify_status_treats_other_failures_as_unexpected(): void {
		$this->assertSame( Sanitizer::STATUS_UNEXPECTED, Sanitizer::classify_status( 500 ) );
		$this->assertSame( Sanitizer::STATUS_UNEXPECTED, Sanitizer::classify_status( 404 ) );
	}

	public function test_enum_passes_an_allowed_value(): void {
		$this->assertSame( 'asc', Sanitizer::enum( 'asc', array( 'asc', 'desc' ), 'desc' ) );
	}

	public function test_enum_falls_back_on_an_unknown_value(): void {
		$this->assertSame( 'desc', Sanitizer::enum( 'sideways', array( 'asc', 'desc' ), 'desc' ) );
	}

	public function test_enum_falls_back_on_an_empty_string(): void {
		$this->assertSame( 'desc', Sanitizer::enum( '', array( 'asc', 'desc' ), 'desc' ) );
	}

	public function test_enum_falls_back_on_a_non_string(): void {
		$this->assertSame( 'desc', Sanitizer::enum( array( 'asc' ), array( 'asc', 'desc' ), 'desc' ) );
		$this->assertSame( 'desc', Sanitizer::enum( null, array( 'asc', 'desc' ), 'desc' ) );
	}

	public function test_enum_does_not_match_loosely(): void {
		$this->assertSame( 'desc', Sanitizer::enum( 0, array( 'asc', 'desc' ), 'desc' ) );
	}
	public function test_429_classifies_as_rate_limited(): void {
		$this->assertSame( Sanitizer::STATUS_RATE_LIMITED, Sanitizer::classify_status( 429 ) );
	}

	public function test_500_still_classifies_as_unexpected(): void {
		$this->assertSame( Sanitizer::STATUS_UNEXPECTED, Sanitizer::classify_status( 500 ) );
	}

	public function test_single_line_strips_embedded_newlines(): void {
		$this->assertSame(
			'Evil Plugin == Section == [fail] forged',
			Sanitizer::single_line( "Evil Plugin\n== Section ==\n[fail] forged" )
		);
	}

	public function test_single_line_strips_other_control_characters(): void {
		$this->assertSame( 'a b', Sanitizer::single_line( "a\tb" ) );
		$this->assertSame( 'a b', Sanitizer::single_line( "a\rb" ) );
	}

	public function test_single_line_trims_surrounding_whitespace(): void {
		$this->assertSame( 'value', Sanitizer::single_line( "  value  \n" ) );
	}

	public function test_single_line_leaves_an_ordinary_string_untouched(): void {
		$this->assertSame( 'Acme Bridge 1.2.3', Sanitizer::single_line( 'Acme Bridge 1.2.3' ) );
	}
}
