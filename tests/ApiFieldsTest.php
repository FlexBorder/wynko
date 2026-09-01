<?php
/**
 * Tests for the /field resource.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Api\Fields;
use Wynko\Cache;
use Wynko\Config;
use Wynko\Support\Fields as FieldData;
use PHPUnit\Framework\TestCase;

/** Covers the per-list cache, its degradation, and the request it makes. */
final class ApiFieldsTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
	}

	private function queue_fields( string $custom_name = 'first_name' ): void {
		wynko_test_queue_response(
			200,
			'{"data":[{"field":{"field_id":"f_1","name":"First name","custom_name":"' . $custom_name . '","datatype":"text","required":true}}]}'
		);
	}

	public function test_for_list_returns_normalized_fields(): void {
		$this->queue_fields();

		$result = Fields::for_list( 'list_a' );

		$this->assertFalse( $result['error'] );
		$this->assertSame( 'first_name', $result['fields'][0]['custom_name'] );
		$this->assertSame( FieldData::TYPE_TEXT, $result['fields'][0]['type'] );
	}

	public function test_for_list_requests_the_field_endpoint_for_that_list(): void {
		$this->queue_fields();

		Fields::for_list( 'list_a' );

		$request = wynko_test_last_request();
		$this->assertNotNull( $request );
		$this->assertStringEndsWith( '/field?list_id=list_a', $request['url'] );
		$this->assertSame( 'GET', $request['args']['method'] );
	}

	public function test_for_list_serves_the_second_call_from_the_cache(): void {
		$this->queue_fields();

		Fields::for_list( 'list_a' );
		Fields::for_list( 'list_a' );

		$this->assertSame( 1, wynko_test_http_calls() );
	}

	public function test_forcing_a_refetch_bypasses_the_cache_for_that_list_alone(): void {
		$this->queue_fields( 'first_name' );
		$this->queue_fields( 'company' );
		$this->queue_fields( 'company' );

		Fields::for_list( 'list_a' );
		Fields::for_list( 'list_b' );

		$forced = Fields::for_list( 'list_b', true );
		$this->assertSame( 3, wynko_test_http_calls() );
		$this->assertSame( 'company', $forced['fields'][0]['custom_name'] );

		// The other list's entry survived the refetch.
		Fields::for_list( 'list_a' );
		$this->assertSame( 3, wynko_test_http_calls() );
	}

	public function test_two_lists_do_not_share_a_cache_entry(): void {
		$this->queue_fields( 'first_name' );
		$this->queue_fields( 'company' );

		$a = Fields::for_list( 'list_a' );
		$b = Fields::for_list( 'list_b' );

		$this->assertSame( 2, wynko_test_http_calls() );
		$this->assertSame( 'first_name', $a['fields'][0]['custom_name'] );
		$this->assertSame( 'company', $b['fields'][0]['custom_name'] );
	}

	public function test_a_failure_degrades_to_no_fields_with_an_error_flag(): void {
		wynko_test_queue_response( 401, '' );

		$result = Fields::for_list( 'list_a' );

		$this->assertSame( array(), $result['fields'] );
		$this->assertTrue( $result['error'] );
	}

	public function test_a_failure_is_negative_cached(): void {
		wynko_test_queue_response( 500, '' );

		Fields::for_list( 'list_a' );
		Fields::for_list( 'list_a' );

		$this->assertSame( 1, wynko_test_http_calls() );
	}

	public function test_an_empty_list_id_makes_no_request(): void {
		$result = Fields::for_list( '' );

		$this->assertSame( 0, wynko_test_http_calls() );
		$this->assertSame( array(), $result['fields'] );
		$this->assertTrue( $result['error'] );
	}

	public function test_a_stale_entry_is_refetched(): void {
		$this->queue_fields();
		Fields::for_list( 'list_a' );

		$cached                      = get_transient( Config::fields_transient_key() );
		$cached['list_a']['expires'] = time() - 1;
		set_transient( Config::fields_transient_key(), $cached, 60 );

		$this->queue_fields();
		Fields::for_list( 'list_a' );

		$this->assertSame( 2, wynko_test_http_calls() );
	}

	public function test_bust_clears_the_field_cache(): void {
		$this->queue_fields();
		Fields::for_list( 'list_a' );

		Cache::bust();
		$this->queue_fields();
		Fields::for_list( 'list_a' );

		$this->assertSame( 2, wynko_test_http_calls() );
	}
	public function test_a_successful_fetch_reports_no_reason(): void {
		wynko_test_queue_response( 200, '{"data":[]}' );

		$result = Fields::for_list( 'abc' );

		$this->assertFalse( $result['error'] );
		$this->assertSame( FieldData::FETCH_OK, $result['reason'] );
	}

	public function test_a_deleted_list_reports_gone(): void {
		wynko_test_queue_response( 404, '{"error":{"code":0,"message":"not found"}}' );

		$result = Fields::for_list( 'abc' );

		$this->assertTrue( $result['error'] );
		$this->assertSame( FieldData::FETCH_GONE, $result['reason'] );
	}

	public function test_the_reason_survives_the_negative_cache(): void {
		wynko_test_queue_response( 404, '{}' );
		Fields::for_list( 'abc' );

		$again = Fields::for_list( 'abc' );

		$this->assertSame( FieldData::FETCH_GONE, $again['reason'] );
		$this->assertSame( 1, $GLOBALS['wynko_test_http_calls'] );
	}

	public function test_an_empty_list_id_reports_gone_without_a_request(): void {
		$result = Fields::for_list( '' );

		$this->assertSame( FieldData::FETCH_GONE, $result['reason'] );
		$this->assertSame( 0, $GLOBALS['wynko_test_http_calls'] );
	}

	public function test_wynko_fields_filter_can_modify_the_cached_fields(): void {
		add_filter(
			'wynko_fields',
			static function ( $fields, $list_id ) {
				$fields[0]['custom_name'] = 'overridden_for_' . $list_id;
				return $fields;
			}
		);
		$this->queue_fields();

		$result = Fields::for_list( 'list_a' );

		$this->assertSame( 'overridden_for_list_a', $result['fields'][0]['custom_name'] );
	}
}
