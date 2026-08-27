<?php
/**
 * Tests for the public submit route.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Frontend\FormSubmitHandler;
use Wynko\Log;
use Wynko\Rest\SubmitController;
use PHPUnit\Framework\TestCase;

/**
 * The REST path must answer exactly what the redirect path decides — it runs
 * the same process() — and must give a prober no more than the redirect path
 * does.
 */
final class RestSubmitTest extends TestCase {

	/**
	 * The signup form under test.
	 *
	 * @var int
	 */
	private int $form_id;

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$this->form_id = wynko_test_insert_post(
			array(
				'post_title'  => 'Newsletter signup',
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
		FormData::load( $this->form_id )->save_list_id( 'list_a' );
	}

	private function queue_fields(): void {
		wynko_test_queue_response(
			200,
			'{"data":[{"field":{"field_id":"f_1","name":"Company","custom_name":"company","datatype":"text","required":false}}]}'
		);
	}

	/**
	 * A submission body.
	 *
	 * @param array<string,mixed> $overrides Values to change.
	 * @return array<string,mixed>
	 */
	private function raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'wynko_form_id'                => (string) $this->form_id,
				FormSubmitHandler::NONCE_FIELD => wp_create_nonce( FormSubmitHandler::nonce_action( $this->form_id ) ),
				'wynko_email'                  => 'visitor@example.org',
			),
			$overrides
		);
	}

	public function test_a_valid_submission_answers_success_with_markup(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","state":"active"}}' );
		$this->queue_fields();

		$answer = SubmitController::answer( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $answer['status'] );
		$this->assertSame( '', $answer['redirect'] );
		$this->assertStringContainsString( 'wynko-form__notice--success', $answer['html'] );
	}

	/** Both transports run process(), so both must reach the activity log. */
	public function test_this_transport_logs_the_outcome_too(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","state":"active"}}' );
		$this->queue_fields();

		SubmitController::answer( $this->raw() );

		$entries = Log::all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'info', $entries[0]['level'] );
		$this->assertStringNotContainsString( 'visitor@example.org', $entries[0]['message'] );
	}

	public function test_an_invalid_email_answers_invalid_with_the_form_redisplayed(): void {
		$this->queue_fields();
		$this->queue_fields();

		$answer = SubmitController::answer( $this->raw( array( 'wynko_email' => 'not-an-email' ) ) );

		$this->assertSame( FormSubmitHandler::STATUS_INVALID, $answer['status'] );
		$this->assertSame( '', $answer['redirect'] );
		$this->assertStringContainsString( 'wynko-form__error', $answer['html'] );
		// The typed value comes back so the visitor need not retype it.
		$this->assertStringContainsString( 'not-an-email', $answer['html'] );
	}

	public function test_a_bad_nonce_answers_not_found_and_says_nothing_else(): void {
		$answer = SubmitController::answer( $this->raw( array( FormSubmitHandler::NONCE_FIELD => 'wrong' ) ) );

		$this->assertSame( FormSubmitHandler::STATUS_NOT_FOUND, $answer['status'] );
		$this->assertSame( '', $answer['html'] );
		$this->assertSame( '', $answer['redirect'] );
	}

	public function test_an_unknown_form_answers_exactly_as_a_bad_nonce_does(): void {
		$unknown = SubmitController::answer(
			array(
				'wynko_form_id'                => '999999',
				FormSubmitHandler::NONCE_FIELD => wp_create_nonce( FormSubmitHandler::nonce_action( 999999 ) ),
				'wynko_email'                  => 'visitor@example.org',
			)
		);

		$this->assertSame( SubmitController::answer( $this->raw( array( FormSubmitHandler::NONCE_FIELD => 'wrong' ) ) ), $unknown );
	}

	public function test_a_configured_redirect_is_returned_rather_than_markup(): void {
		FormData::load( $this->form_id )->save_settings(
			array(
				'redirect_type' => 'url',
				'redirect_url'  => 'https://example.org/thanks/',
			)
		);

		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","state":"active"}}' );

		$answer = SubmitController::answer( $this->raw() );

		$this->assertSame( 'https://example.org/thanks/', $answer['redirect'] );
		$this->assertSame( '', $answer['html'] );
	}

	public function test_hide_after_submit_returns_only_the_message(): void {
		FormData::load( $this->form_id )->save_settings( array( 'hide_after_submit' => true ) );

		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","state":"active"}}' );

		$answer = SubmitController::answer( $this->raw() );

		$this->assertStringContainsString( 'wynko-form--done', $answer['html'] );
		$this->assertStringNotContainsString( '<form', $answer['html'] );
	}

	public function test_a_throttled_submission_answers_429(): void {
		$attempts = Config::throttle_max( 'ip' ) + 1;
		for ( $i = 0; $i <= $attempts; $i++ ) {
			$this->queue_fields();
			wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","state":"active"}}' );
			$this->queue_fields();
			$answer = SubmitController::answer( $this->raw() );
		}

		$this->assertSame( FormSubmitHandler::STATUS_THROTTLED, $answer['status'] );
		$this->assertSame( 429, SubmitController::status_code( $answer['status'] ) );
	}

	/**
	 * The reply must say the visitor was refused, not which form or address the
	 * server recognised — the refusal happens before either is looked at.
	 */
	public function test_a_throttled_reply_carries_no_submitted_detail(): void {
		$attempts = Config::throttle_max( 'ip' ) + 1;
		for ( $i = 0; $i <= $attempts; $i++ ) {
			$this->queue_fields();
			wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","state":"active"}}' );
			$this->queue_fields();
			$answer = SubmitController::answer( $this->raw() );
		}

		$this->assertSame( '', $answer['redirect'] );
		$this->assertStringNotContainsString( 'visitor@example.org', $answer['html'] );
	}

	public function test_an_ordinary_rejection_stays_a_200(): void {
		$this->assertSame( 200, SubmitController::status_code( FormSubmitHandler::STATUS_INVALID ) );
		$this->assertSame( 404, SubmitController::status_code( FormSubmitHandler::STATUS_NOT_FOUND ) );
	}

	public function test_the_route_registers_under_the_v1_namespace(): void {
		SubmitController::register();

		$this->assertArrayHasKey(
			'wynko/v1/forms/(?P<form_id>\d+)/submit',
			wynko_test_rest_routes()
		);
	}
	/**
	 * Both refusals happen before the form or the address is looked at, so an
	 * entry would say nothing an operator could act on — and the id comes from
	 * outside, so logging it would let anyone fill a 200-entry log at will.
	 */
	public function test_a_bad_nonce_and_an_unknown_form_are_both_silent(): void {
		SubmitController::answer( $this->raw( array( FormSubmitHandler::NONCE_FIELD => 'wrong' ) ) );
		SubmitController::answer(
			array(
				'wynko_form_id'                => '999999',
				FormSubmitHandler::NONCE_FIELD => wp_create_nonce( FormSubmitHandler::nonce_action( 999999 ) ),
				'wynko_email'                  => 'visitor@example.org',
			)
		);

		$this->assertSame( array(), Log::all() );
	}

	/** This transport reaches the same throttle, so it reaches the same entry. */
	public function test_a_throttled_submission_is_logged_on_this_transport_too(): void {
		$attempts = Config::throttle_max( 'ip' ) + 1;
		for ( $i = 0; $i <= $attempts; $i++ ) {
			$this->queue_fields();
			wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","state":"active"}}' );
			$this->queue_fields();
			SubmitController::answer( $this->raw() );
		}

		$messages = array_map(
			static function ( array $entry ): string {
				return $entry['level'] . ': ' . $entry['message'];
			},
			Log::all()
		);

		$this->assertNotEmpty(
			array_filter(
				$messages,
				static function ( string $message ): bool {
					return false !== strpos( $message, 'are being rate limited' );
				}
			)
		);
	}
}
