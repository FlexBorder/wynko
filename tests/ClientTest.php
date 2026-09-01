<?php
/**
 * Tests for the API transport.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Api\Client;
use Wynko\Config;
use PHPUnit\Framework\TestCase;

/** Covers the HTTP Basic authorization header, the status wording, and the request path. */
final class ClientTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_auth_header_uses_key_as_username_with_empty_password(): void {
		$this->assertSame(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Recomputes the expected HTTP Basic credential, not obfuscation.
			'Basic ' . base64_encode( 'ABC123:' ),
			Client::auth_header( 'ABC123' )
		);
	}

	public function test_status_message_names_the_key_on_401(): void {
		$this->assertStringContainsStringIgnoringCase( 'invalid api key', Client::status_message( 401 ) );
	}

	public function test_status_message_includes_the_code(): void {
		$this->assertStringContainsString( '500', Client::status_message( 500 ) );
		$this->assertStringContainsString( '403', Client::status_message( 403 ) );
	}

	public function test_request_decodes_a_successful_json_body(): void {
		update_option( Config::option_key( 'api_key' ), 'valid-key' );
		wynko_test_queue_response( 200, '{"data":[]}' );

		$this->assertSame( array( 'data' => array() ), Client::request( 'GET', 'campaign' ) );
		$this->assertSame( 1, wynko_test_http_calls() );
	}

	public function test_request_refuses_to_call_out_without_a_key(): void {
		$result = Client::request( 'GET', 'campaign' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'wynko_no_key', $result->get_error_code() );
		$this->assertSame( 0, wynko_test_http_calls() );
	}

	public function test_request_maps_a_401_to_the_invalid_key_message(): void {
		wynko_test_queue_response( 401, '' );

		$result = Client::request( 'GET', 'campaign', array( 'key' => 'bad-key' ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertStringContainsStringIgnoringCase( 'invalid api key', $result->get_error_message() );
	}

	public function test_request_reports_unparseable_json(): void {
		wynko_test_queue_response( 200, 'not json' );

		$result = Client::request( 'GET', 'campaign', array( 'key' => 'valid-key' ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'wynko_parse', $result->get_error_code() );
	}

	public function test_a_laposta_error_body_travels_on_the_wp_error(): void {
		update_option( Config::option_key( 'api_key' ), 'test-key' );
		wynko_test_queue_response( 400, '{"error":{"type":"invalid_input","message":"Email address already exists","code":204,"parameter":"email"}}' );

		$result = Client::request( 'POST', 'member' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wynko_status', $result->get_error_code() );
		$this->assertSame( 204, $result->get_error_data()['code'] );
	}

	public function test_a_non_2xx_without_an_error_body_carries_only_its_status(): void {
		update_option( Config::option_key( 'api_key' ), 'test-key' );
		wynko_test_queue_response( 500, 'gateway exploded' );

		$result = Client::request( 'GET', 'list' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'http_status' => 500 ), $result->get_error_data() );
	}

	public function test_a_failed_request_carries_its_http_status(): void {
		wynko_test_queue_response( 400, '{"error":{"code":203,"message":"unknown parameter"}}' );

		$result = Client::request( 'GET', 'list', array( 'key' => 'k' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['http_status'] );
		$this->assertSame( 203, $data['code'] );
	}

	public function test_a_failure_without_an_error_body_still_carries_its_status(): void {
		wynko_test_queue_response( 503, 'nope' );

		$data = Client::request( 'GET', 'list', array( 'key' => 'k' ) )->get_error_data();

		$this->assertSame( array( 'http_status' => 503 ), $data );
	}

	public function test_a_429_says_it_is_a_rate_limit(): void {
		$this->assertStringContainsString( 'rate limiting', Client::status_message( 429 ) );
	}

	public function test_wynko_api_status_message_filter_can_override_the_message(): void {
		add_filter(
			'wynko_api_status_message',
			static function ( $message, $status ) {
				return 'custom:' . $status;
			}
		);

		$this->assertSame( 'custom:500', Client::status_message( 500 ) );
	}

	public function test_wynko_api_request_args_filter_can_modify_the_outgoing_request(): void {
		add_filter(
			'wynko_api_request_args',
			static function ( $args ) {
				$args['headers']['X-Test'] = 'yes';
				return $args;
			}
		);
		wynko_test_queue_response( 200, '{}' );

		Client::request( 'GET', 'campaign', array( 'key' => 'k' ) );

		$this->assertSame( 'yes', wynko_test_last_request()['args']['headers']['X-Test'] );
	}

	public function test_wynko_api_response_filter_receives_the_raw_response(): void {
		add_filter(
			'wynko_api_response',
			static function ( $response ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"filtered":true}',
				);
			}
		);
		wynko_test_queue_response( 200, '{"filtered":false}' );

		$result = Client::request( 'GET', 'campaign', array( 'key' => 'k' ) );

		$this->assertSame( array( 'filtered' => true ), $result );
	}
}
