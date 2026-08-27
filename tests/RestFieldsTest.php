<?php
/**
 * Tests for the editor's field-loading route.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Rest\FieldsController;
use PHPUnit\Framework\TestCase;

/** The route reads Laposta's field definitions for whoever may edit forms. */
final class RestFieldsTest extends TestCase {

	/**
	 * The form under test.
	 *
	 * @var int
	 */
	private int $form_id;

	protected function setUp(): void {
		wynko_test_reset_store();
		wynko_test_set_can_manage( true );
		update_option( Config::option_key( 'api_key' ), 'test-key' );

		$this->form_id = wynko_test_insert_post(
			array(
				'post_title'  => 'Newsletter signup',
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
	}

	public function test_the_permission_callback_needs_the_capability(): void {
		$this->assertTrue( FieldsController::can_edit() );

		wynko_test_set_can_manage( false );
		$this->assertFalse( FieldsController::can_edit() );
	}

	public function test_it_returns_rows_for_the_requested_list(): void {
		wynko_test_queue_response(
			200,
			'{"data":[{"field":{"field_id":"f_1","name":"Company","custom_name":"company","datatype":"text","required":false}}]}'
		);

		$result = FieldsController::rows( $this->form_id, 'list_b' );

		$this->assertFalse( $result['error'] );
		$this->assertStringContainsString( 'Company', $result['html'] );
		// The whole table, not just its body: the editor's container may be
		// empty, so there is not always a body to swap.
		$this->assertStringContainsString( 'wynko-fields__body', $result['html'] );
		$this->assertStringContainsString( '<table', $result['html'] );
		$this->assertStringContainsString( 'wynko-fields__button', $result['html'] );
	}

	public function test_an_unreachable_list_reports_an_error_rather_than_empty_rows(): void {
		wynko_test_queue_response( 500, '{"error":{"message":"boom"}}' );

		$result = FieldsController::rows( $this->form_id, 'list_b' );

		$this->assertTrue( $result['error'] );
		$this->assertSame( '', $result['html'] );
	}

	public function test_an_unknown_form_reports_an_error(): void {
		$this->assertTrue( FieldsController::rows( 999999, 'list_b' )['error'] );
	}

	public function test_an_empty_list_id_reports_an_error_without_calling_the_api(): void {
		$this->assertTrue( FieldsController::rows( $this->form_id, '' )['error'] );
		$this->assertSame( 0, wynko_test_http_calls() );
	}

	public function test_the_route_registers_under_the_v1_namespace(): void {
		FieldsController::register();

		$this->assertArrayHasKey(
			'wynko/v1/forms/(?P<form_id>\d+)/fields',
			wynko_test_rest_routes()
		);
	}
}
