<?php
/**
 * Tests for the submit-nonce refresh route.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Frontend\FormSubmitHandler;
use Wynko\Rest\Headers;
use Wynko\Rest\NonceController;
use PHPUnit\Framework\TestCase;

/**
 * The route the JS submit path calls when a page's embedded nonce turns out
 * to be stale — its whole job is to answer with one that verifies.
 */
final class RestNonceTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_it_returns_a_nonce_that_verifies_for_the_forms_own_action(): void {
		$answer = NonceController::answer( 42 );

		$this->assertNotSame( '', $answer['nonce'] );
		$this->assertNotFalse( wp_verify_nonce( $answer['nonce'], FormSubmitHandler::nonce_action( 42 ) ) );
	}

	public function test_it_does_not_verify_against_a_different_forms_action(): void {
		$answer = NonceController::answer( 42 );

		$this->assertFalse( wp_verify_nonce( $answer['nonce'], FormSubmitHandler::nonce_action( 43 ) ) );
	}

	public function test_the_route_registers_under_the_v1_namespace(): void {
		NonceController::register();

		$this->assertArrayHasKey(
			'wynko/v1/forms/(?P<form_id>\d+)/nonce',
			wynko_test_rest_routes()
		);
	}

	public function test_the_route_is_public(): void {
		NonceController::register();

		$args = wynko_test_rest_routes()['wynko/v1/forms/(?P<form_id>\d+)/nonce'];

		$this->assertSame( '__return_true', $args['permission_callback'] );
	}

	public function test_the_route_only_answers_get(): void {
		NonceController::register();

		$args = wynko_test_rest_routes()['wynko/v1/forms/(?P<form_id>\d+)/nonce'];

		$this->assertSame( 'GET', $args['methods'] );
	}

	/**
	 * The whole point of this route is to answer with a live value, so its own
	 * reply must never be cached either.
	 */
	public function test_its_own_reply_is_covered_by_the_no_cache_headers(): void {
		$this->assertTrue( Headers::is_ours( '/wynko/v1/forms/42/nonce' ) );
	}
}
