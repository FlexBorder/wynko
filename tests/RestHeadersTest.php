<?php
/**
 * Tests for the namespace's response headers.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Rest\Headers;
use PHPUnit\Framework\TestCase;
use WP_REST_Response;

/**
 * The public submit route answers a logged-out visitor with their own
 * submission rendered back and a live nonce, and core sends no cache headers
 * for a logged-out caller. Nothing between here and the browser may keep it.
 */
final class RestHeadersTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	/**
	 * A request whose route is all this filter reads.
	 *
	 * @param string $route Dispatched route.
	 * @return object
	 */
	private function request( string $route ): object {
		return new class( $route ) {
			/**
			 * The route.
			 *
			 * @var string
			 */
			private string $route;

			/**
			 * Stores the route.
			 *
			 * @param string $route Dispatched route.
			 */
			public function __construct( string $route ) {
				$this->route = $route;
			}

			/**
			 * The route.
			 *
			 * @return string
			 */
			public function get_route(): string {
				return $this->route;
			}
		};
	}

	/**
	 * Routes this plugin owns.
	 *
	 * @return array<int,array{0:string}>
	 */
	public function our_routes(): array {
		return array(
			array( '/wynko/v1' ),
			array( '/wynko/v1/forms/1/submit' ),
			array( '/wynko/v1/forms/1/fields' ),
		);
	}

	/**
	 * Routes it does not.
	 *
	 * @return array<int,array{0:string}>
	 */
	public function foreign_routes(): array {
		return array(
			array( '' ),
			array( '/' ),
			array( '/wp/v2/posts' ),
			array( '/wynko/v1x/forms/1/submit' ),
			array( '/other/wynko/v1/forms/1/submit' ),
		);
	}

	/**
	 * @dataProvider our_routes
	 * @param string $route Dispatched route.
	 */
	public function test_our_routes_are_recognised( string $route ): void {
		$this->assertTrue( Headers::is_ours( $route ) );
	}

	/**
	 * @dataProvider foreign_routes
	 * @param string $route Dispatched route.
	 */
	public function test_a_foreign_route_is_left_alone( string $route ): void {
		$this->assertFalse( Headers::is_ours( $route ) );

		$response = new WP_REST_Response( array(), 200 );
		$filtered = Headers::filter( $response, null, $this->request( $route ) );

		$this->assertSame( array(), $filtered->get_headers() );
	}

	public function test_a_submission_reply_may_not_be_cached(): void {
		$response = Headers::filter( new WP_REST_Response( array(), 200 ), null, $this->request( '/wynko/v1/forms/1/submit' ) );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'private', $headers['Cache-Control'] );
		$this->assertLessThan( time(), strtotime( $headers['Expires'] ) );
	}

	public function test_no_header_is_sent_empty(): void {
		// An empty value means "remove this header" to core, which a response's
		// header bag cannot say: sent as-is it would emit a bare header name.
		foreach ( Headers::nocache() as $key => $value ) {
			$this->assertNotSame( '', (string) $value, $key . ' was sent empty' );
		}

		$this->assertArrayNotHasKey( 'Last-Modified', Headers::nocache() );
	}

	public function test_an_empty_value_another_plugin_adds_is_dropped_too(): void {
		// core's nocache_headers filter lets any plugin extend the array, and
		// an empty value there means the same "remove it" a response cannot say.
		$GLOBALS['wynko_test_nocache_headers']['Pragma'] = '';

		$response = Headers::filter( new WP_REST_Response( array(), 200 ), null, $this->request( '/wynko/v1/forms/1/submit' ) );

		$this->assertArrayNotHasKey( 'Pragma', $response->get_headers() );
		$this->assertArrayHasKey( 'Cache-Control', $response->get_headers() );
	}

	public function test_something_that_is_not_a_response_passes_through(): void {
		$result = Headers::filter( 'not a response', null, $this->request( '/wynko/v1/forms/1/submit' ) );

		$this->assertSame( 'not a response', $result );
	}

	public function test_the_filter_is_registered_on_the_rest_request(): void {
		Headers::register();

		$this->assertContains( 'rest_post_dispatch|Wynko\Rest\Headers::filter', wynko_test_hooks() );
	}
}
