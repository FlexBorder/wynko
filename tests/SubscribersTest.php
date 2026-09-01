<?php
/**
 * Tests for the /member resource.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Api\Subscribers;
use Wynko\Config;
use PHPUnit\Framework\TestCase;

/** Covers the request shape, especially the double-opt-in override. */
final class SubscribersTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
	}

	private function create( bool $skip_doi = false, array $custom_fields = array( 'first_name' => 'Ada' ) ) {
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"visitor@example.org"}}' );

		return Subscribers::create(
			'list_a',
			'visitor@example.org',
			'203.0.113.7',
			'https://example.org/signup/',
			$custom_fields,
			$skip_doi
		);
	}

	private function body(): array {
		$request = wynko_test_last_request();
		return $request['args']['body'];
	}

	public function test_create_posts_to_the_member_endpoint(): void {
		$this->create();

		$request = wynko_test_last_request();
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertStringEndsWith( '/member', $request['url'] );
	}

	public function test_create_sends_the_required_parameters(): void {
		$this->create();

		$body = $this->body();
		$this->assertSame( 'list_a', $body['list_id'] );
		$this->assertSame( 'visitor@example.org', $body['email'] );
		$this->assertSame( '203.0.113.7', $body['ip'] );
		$this->assertSame( 'https://example.org/signup/', $body['source_url'] );
	}

	public function test_create_sends_custom_fields_keyed_by_custom_name(): void {
		$this->create(
			false,
			array(
				'first_name' => 'Ada',
				'topics'     => array( 'A', 'B' ),
			)
		);

		$body = $this->body();
		$this->assertSame( 'Ada', $body['custom_fields']['first_name'] );
		$this->assertSame( array( 'A', 'B' ), $body['custom_fields']['topics'] );
	}

	public function test_create_omits_custom_fields_entirely_when_there_are_none(): void {
		$this->create( false, array() );

		$this->assertArrayNotHasKey( 'custom_fields', $this->body() );
	}

	public function test_create_omits_the_options_object_by_default(): void {
		$this->create( false );

		// Absence is the point: the list's own double-opt-in setting decides,
		// and sending nothing is what leaves it alone.
		$this->assertArrayNotHasKey( 'options', $this->body() );
	}

	public function test_create_sends_ignore_doubleoptin_only_when_asked(): void {
		$this->create( true );

		$this->assertTrue( $this->body()['options']['ignore_doubleoptin'] );
	}

	public function test_create_omits_an_empty_source_url(): void {
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
		Subscribers::create( 'list_a', 'visitor@example.org', '203.0.113.7', '', array(), false );

		$this->assertArrayNotHasKey( 'source_url', $this->body() );
	}

	public function test_create_returns_the_decoded_member_on_success(): void {
		$result = $this->create();

		$this->assertSame( 'm_1', $result['member']['member_id'] );
	}

	public function test_create_returns_a_wp_error_carrying_the_laposta_code(): void {
		wynko_test_queue_response( 400, '{"error":{"type":"invalid_input","message":"already exists","code":204,"parameter":"email"}}' );

		$result = Subscribers::create( 'list_a', 'visitor@example.org', '203.0.113.7', '', array(), false );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 204, $result->get_error_data()['code'] );
	}

	public function test_wynko_subscriber_data_filter_can_modify_the_request_body(): void {
		add_filter(
			'wynko_subscriber_data',
			static function ( $body, $list_id, $email ) {
				$body['tags'] = array( $list_id, $email );
				return $body;
			}
		);

		$this->create();

		$this->assertSame( array( 'list_a', 'visitor@example.org' ), $this->body()['tags'] );
	}
}
