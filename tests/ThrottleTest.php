<?php
/**
 * Tests for the submission-rate limiter.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Throttle;
use PHPUnit\Framework\TestCase;

/** Covers the wynko_throttle_allowed filter. */
final class ThrottleTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_wynko_throttle_allowed_filter_can_deny_an_otherwise_allowed_submission(): void {
		add_filter(
			'wynko_throttle_allowed',
			static function ( $allowed, $form_id, $ip ) {
				return false;
			}
		);

		$this->assertFalse( Throttle::allows( 1, '203.0.113.1' ) );
	}

	public function test_wynko_throttle_allowed_filter_receives_the_form_and_ip(): void {
		$seen = array();
		add_filter(
			'wynko_throttle_allowed',
			static function ( $allowed, $form_id, $ip ) use ( &$seen ) {
				$seen[] = array( $form_id, $ip );
				return $allowed;
			}
		);

		Throttle::allows( 42, '203.0.113.9' );

		$this->assertSame( array( array( 42, '203.0.113.9' ) ), $seen );
	}

	public function test_allows_ip_permits_and_records_within_the_cap(): void {
		update_option( 'wynko_throttle_ip_max', 2 );

		$this->assertTrue( Throttle::allows_ip( '203.0.113.5' ) );
		$this->assertTrue( Throttle::allows_ip( '203.0.113.5' ) );
		$this->assertFalse( Throttle::allows_ip( '203.0.113.5' ) );
	}

	public function test_allows_ip_shares_the_counter_with_allows(): void {
		update_option( 'wynko_throttle_ip_max', 1 );

		$this->assertTrue( Throttle::allows( 1, '203.0.113.9' ) );
		$this->assertFalse( Throttle::allows_ip( '203.0.113.9' ) );
	}

	public function test_allows_ip_does_not_touch_any_per_form_counter(): void {
		update_option( 'wynko_throttle_form_max', 1 );

		$this->assertTrue( Throttle::allows_ip( '203.0.113.10' ) );
		// A real form's own counter is untouched by an IP-only check.
		$this->assertSame( 0, Throttle::form_hits( 42 ) );
	}
}
