<?php
/**
 * Tests for the cached connection verdict.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\KeyStatus;
use Wynko\Log;
use PHPUnit\Framework\TestCase;

/**
 * Covers fingerprint matching on rotation, that the raw key is never stored,
 * and that only a live probe reaches the activity log.
 */
final class KeyStatusTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_fingerprint_is_a_sha256_and_never_the_key(): void {
		$this->assertSame( hash( 'sha256', 'secret-key' ), KeyStatus::fingerprint( 'secret-key' ) );
		$this->assertSame( '', KeyStatus::fingerprint( '' ) );
	}

	public function test_record_then_cached_round_trip(): void {
		KeyStatus::record( 'secret-key', true );

		$this->assertSame(
			array(
				'ok'      => true,
				'message' => '',
				'code'    => '',
			),
			KeyStatus::cached( 'secret-key' )
		);
	}

	public function test_a_recorded_failure_keeps_its_error_code(): void {
		KeyStatus::record( 'abc', false, 'Could not connect to Laposta: timed out', 'wynko_http' );

		$cached = KeyStatus::cached( 'abc' );
		$this->assertSame( 'wynko_http', $cached['code'] );
	}

	public function test_a_record_written_without_a_code_reads_as_an_empty_code(): void {
		set_transient(
			KeyStatus::TRANSIENT,
			array(
				'fingerprint' => KeyStatus::fingerprint( 'abc' ),
				'ok'          => false,
				'message'     => 'older record',
			),
			60
		);

		$cached = KeyStatus::cached( 'abc' );
		$this->assertSame( '', $cached['code'] );
		$this->assertFalse( $cached['ok'] );
	}

	public function test_stored_record_never_contains_the_raw_key(): void {
		KeyStatus::record( 'secret-key', false, 'Invalid API key.' );

		$stored = get_transient( KeyStatus::TRANSIENT );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Flattens the stored record so the assertion covers every nested value.
		$this->assertStringNotContainsString( 'secret-key', serialize( $stored ), 'The raw key must never be persisted.' );
		$this->assertSame( hash( 'sha256', 'secret-key' ), $stored['fingerprint'] );
	}

	public function test_a_rotated_key_invalidates_the_cached_verdict(): void {
		KeyStatus::record( 'old-key', true );

		$this->assertNull( KeyStatus::cached( 'new-key' ) );
	}

	public function test_no_cached_verdict_returns_null(): void {
		$this->assertNull( KeyStatus::cached( 'secret-key' ) );
	}

	public function test_failure_message_is_preserved(): void {
		KeyStatus::record( 'secret-key', false, 'Invalid API key.' );

		$this->assertSame(
			array(
				'ok'      => false,
				'message' => 'Invalid API key.',
				'code'    => '',
			),
			KeyStatus::cached( 'secret-key' )
		);
	}

	public function test_verify_probes_once_and_then_serves_the_cached_verdict(): void {
		wynko_test_queue_response( 200, '{"data":[]}' );

		$first  = KeyStatus::verify( 'a-key' );
		$second = KeyStatus::verify( 'a-key' );

		$this->assertTrue( $first['ok'] );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, wynko_test_http_calls() );
	}

	public function test_verify_reprobes_for_a_different_key(): void {
		wynko_test_queue_response( 200, '{"data":[]}' );
		wynko_test_queue_response( 401, '' );

		$this->assertTrue( KeyStatus::verify( 'first-key' )['ok'] );
		$this->assertFalse( KeyStatus::verify( 'second-key' )['ok'] );
		$this->assertSame( 2, wynko_test_http_calls() );
	}

	public function test_verify_caches_the_failure_message_too(): void {
		wynko_test_queue_response( 401, '' );

		$verdict = KeyStatus::verify( 'bad-key' );

		$this->assertFalse( $verdict['ok'] );
		$this->assertStringContainsStringIgnoringCase( 'invalid api key', $verdict['message'] );
		$this->assertSame( $verdict, KeyStatus::verify( 'bad-key' ) );
		$this->assertSame( 1, wynko_test_http_calls() );
	}

	public function test_a_live_probe_is_logged_but_a_cached_verdict_is_not(): void {
		wynko_test_queue_response( 200, '{"data":[]}' );

		KeyStatus::verify( 'a-key' );
		KeyStatus::verify( 'a-key' );

		$entries = Log::all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'info', $entries[0]['level'] );
		$this->assertStringContainsString( 'Connected to the Laposta API.', $entries[0]['message'] );
	}

	public function test_a_rejected_key_is_logged_as_an_error(): void {
		wynko_test_queue_response( 401, '' );

		KeyStatus::verify( 'bad-key' );

		$this->assertSame( 'error', Log::all()[0]['level'] );
		$this->assertStringContainsString( 'Connection to the Laposta API failed', Log::all()[0]['message'] );
	}

	/**
	 * A transport failure is an error here too: Cache and the signup handler
	 * log the same unreachable API as an error, and a level that depends on
	 * which surface noticed cannot be filtered on.
	 */
	public function test_an_unreachable_api_is_logged_as_an_error(): void {
		// No queued response, so the transport itself fails.
		KeyStatus::verify( 'a-key' );

		$this->assertSame( 'error', Log::all()[0]['level'] );
	}

	public function test_verify_of_an_empty_key_never_logs(): void {
		KeyStatus::verify( '' );

		$this->assertSame( array(), Log::all() );
	}

	public function test_verify_of_an_empty_key_never_probes(): void {
		$verdict = KeyStatus::verify( '' );

		$this->assertSame(
			array(
				'ok'      => false,
				'message' => '',
				'code'    => '',
			),
			$verdict
		);
		$this->assertSame( 0, wynko_test_http_calls() );
	}
}
