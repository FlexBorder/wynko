<?php
/**
 * Tests for the /list resource.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Api\Lists;
use Wynko\Cache;
use Wynko\Config;
use Wynko\Log;
use PHPUnit\Framework\TestCase;

/** Covers the editor's cached list options and its failure behaviour. */
final class ApiListsTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
	}

	private function queue_lists(): void {
		wynko_test_queue_response(
			200,
			'{"data":[{"list":{"list_id":"list_a","name":"Newsletter"}}]}'
		);
	}

	public function test_for_editor_returns_select_options(): void {
		$this->queue_lists();

		$result = Lists::for_editor();

		$this->assertFalse( $result['error'] );
		$this->assertSame(
			array(
				array(
					'value' => 'list_a',
					'label' => 'Newsletter',
				),
			),
			$result['options']
		);
	}

	public function test_for_editor_serves_the_second_call_from_the_cache(): void {
		$this->queue_lists();

		Lists::for_editor();
		Lists::for_editor();

		$this->assertSame( 1, wynko_test_http_calls() );
	}

	public function test_for_editor_degrades_to_no_options_with_an_error_flag(): void {
		wynko_test_queue_response( 401, '' );

		$result = Lists::for_editor();

		$this->assertSame( array(), $result['options'] );
		$this->assertTrue( $result['error'] );
	}

	public function test_a_failure_is_negative_cached(): void {
		wynko_test_queue_response( 500, '' );

		Lists::for_editor();
		Lists::for_editor();

		$this->assertSame( 1, wynko_test_http_calls() );
	}

	public function test_bust_clears_the_list_cache(): void {
		$this->queue_lists();
		Lists::for_editor();

		Cache::bust();
		$this->queue_lists();
		Lists::for_editor();

		$this->assertSame( 2, wynko_test_http_calls() );
	}
	/**
	 * The editor degrades to "All lists" with a note beside it, so the person
	 * who would read a log entry is already looking at the problem. The timed
	 * sync is where a list-index failure is worth recording, and Cache does it.
	 */
	public function test_the_editor_s_own_failure_is_deliberately_not_logged(): void {
		wynko_test_queue_response( 500, '{}' );

		Lists::for_editor();

		$this->assertSame( array(), Log::all() );
	}
}
