<?php
/**
 * Tests for the campaign cache's sync stamp.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Api\Fields as ApiFields;
use Wynko\Cache;
use Wynko\Config;
use Wynko\Log;
use PHPUnit\Framework\TestCase;

/** Covers what "last sync" records, and what each path through fill() logs. */
final class CacheTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
	}

	public function test_a_successful_fill_records_the_sync_time(): void {
		wynko_test_queue_response( 200, '{"data":[]}' );

		Cache::refresh();

		$last = Cache::last_sync();
		$this->assertIsArray( $last );
		$this->assertTrue( $last['ok'] );
		$this->assertGreaterThan( 0, $last['at'] );
	}

	public function test_a_failed_fill_records_the_attempt_as_failed(): void {
		wynko_test_queue_response( 401, '{}' );

		Cache::refresh();

		$last = Cache::last_sync();
		$this->assertIsArray( $last );
		$this->assertFalse( $last['ok'] );
	}

	public function test_never_synced_reads_as_null(): void {
		$this->assertNull( Cache::last_sync() );
	}

	/**
	 * The messages of the stored entries, newest first.
	 *
	 * @return array<int,string>
	 */
	private function messages(): array {
		return array_map(
			static function ( array $entry ): string {
				return $entry['level'] . ': ' . $entry['message'];
			},
			Log::all()
		);
	}

	public function test_a_manual_sync_writes_exactly_one_entry(): void {
		wynko_test_queue_response( 200, '{"data":[{"campaign":{"campaign_id":"1","subject":"S","web":"https://l.nl/1","delivery_started":"2026-01-01 10:00:00"}}]}' );
		wynko_test_queue_response( 200, '{"data":[]}' );

		Cache::refresh();

		$this->assertSame( array( 'info: Sync succeeded.' ), $this->messages() );
	}

	public function test_an_automatic_sync_reports_itself_as_automatic(): void {
		wynko_test_queue_response( 200, '{"data":[{"campaign":{"campaign_id":"1","subject":"S","web":"https://l.nl/1","delivery_started":"2026-01-01 10:00:00"}}]}' );
		wynko_test_queue_response( 200, '{"data":[]}' );

		Cache::get();

		$this->assertSame( array( 'info: Automatic sync succeeded.' ), $this->messages() );
	}

	/**
	 * The first sync of an account is not news about every campaign in it, and
	 * the refill that follows a cache expiry is not news about any of them.
	 */
	public function test_a_refill_after_expiry_does_not_report_everything_as_new(): void {
		$body = '{"data":[{"campaign":{"campaign_id":"1","subject":"S","web":"https://l.nl/1"}},{"campaign":{"campaign_id":"2","subject":"T","web":"https://l.nl/2"}}]}';
		wynko_test_queue_response( 200, $body );
		wynko_test_queue_response( 200, '{"data":[]}' );
		Cache::refresh();
		Log::clear();

		delete_transient( Config::transient_key() );
		wynko_test_queue_response( 200, $body );
		wynko_test_queue_response( 200, '{"data":[]}' );
		Cache::get();

		$this->assertSame( array( 'info: Automatic sync succeeded.' ), $this->messages() );
	}

	public function test_the_entry_names_a_campaign_the_last_sync_had_not_seen(): void {
		wynko_test_queue_response( 200, '{"data":[{"campaign":{"campaign_id":"1","subject":"S","web":"https://l.nl/1"}}]}' );
		wynko_test_queue_response( 200, '{"data":[]}' );
		Cache::refresh();
		Log::clear();

		wynko_test_queue_response( 200, '{"data":[{"campaign":{"campaign_id":"1","subject":"S","web":"https://l.nl/1"}},{"campaign":{"campaign_id":"2","subject":"T","web":"https://l.nl/2"}}]}' );
		wynko_test_queue_response( 200, '{"data":[]}' );
		Cache::refresh();

		$this->assertSame( array( 'info: Sync succeeded: 1 new campaign.' ), $this->messages() );
	}

	public function test_the_entry_names_a_new_list_too(): void {
		wynko_test_queue_response( 200, '{"data":[]}' );
		wynko_test_queue_response( 200, '{"data":[{"list":{"list_id":"l1","name":"One"}}]}' );
		Cache::refresh();
		Log::clear();

		wynko_test_queue_response( 200, '{"data":[]}' );
		wynko_test_queue_response( 200, '{"data":[{"list":{"list_id":"l1","name":"One"}},{"list":{"list_id":"l2","name":"Two"}}]}' );
		Cache::refresh();

		$this->assertSame( array( 'info: Sync succeeded: 1 new list.' ), $this->messages() );
	}

	public function test_a_list_index_failure_is_logged_without_failing_the_sync(): void {
		wynko_test_queue_response( 200, '{"data":[]}' );
		wynko_test_queue_response( 500, '{}' );

		$result = Cache::refresh();

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'error: The list index could not be fetched', $this->messages()[1] );
	}

	public function test_a_campaign_failure_aborts_before_the_list_index(): void {
		wynko_test_queue_response( 401, '{}' );

		Cache::refresh();

		$this->assertSame( 1, wynko_test_http_calls() );
	}

	public function test_the_sync_remembers_the_names_of_referenced_lists(): void {
		$form_id = wynko_test_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
		update_post_meta( $form_id, Config::form_meta_key( 'list_id' ), 'l1' );
		wynko_test_queue_response( 200, '{"data":[]}' );
		wynko_test_queue_response( 200, '{"data":[{"list":{"list_id":"l1","name":"Newsletter"}}]}' );
		wynko_test_queue_response( 200, '{"data":[]}' );

		Cache::refresh();

		$this->assertSame( array( 'l1' => 'Newsletter' ), get_option( Config::option_key( 'list_names' ) ) );
	}

	/**
	 * An automatic refill runs inside an anonymous front-end request. Forcing a
	 * field refetch per referenced list would turn one page render into a
	 * blocking API call per list, at a 15-second timeout each.
	 */
	public function test_an_automatic_refill_does_not_refetch_every_list_s_fields(): void {
		$this->form_bound_to( 'l1' );
		$this->form_bound_to( 'l2' );
		set_transient(
			Config::fields_transient_key(),
			array(
				'l1' => array(
					'fields'  => array(),
					'error'   => false,
					'expires' => time() + 3600,
				),
				'l2' => array(
					'fields'  => array(),
					'error'   => false,
					'expires' => time() + 3600,
				),
			),
			3600
		);
		wynko_test_queue_response( 200, '{"data":[]}' );
		wynko_test_queue_response( 200, '{"data":[{"list":{"list_id":"l1","name":"One"}},{"list":{"list_id":"l2","name":"Two"}}]}' );

		Cache::get();

		// Campaigns and the list index only; the fresh field entries are read.
		$this->assertSame( 2, wynko_test_http_calls() );
	}

	/** Sync now is an operator waiting on a screen, so it does pay for the refetch. */
	public function test_a_manual_sync_does_refetch_every_list_s_fields(): void {
		$this->form_bound_to( 'l1' );
		set_transient(
			Config::fields_transient_key(),
			array(
				'l1' => array(
					'fields'  => array(),
					'error'   => false,
					'expires' => time() + 3600,
				),
			),
			3600
		);
		wynko_test_queue_response( 200, '{"data":[]}' );
		wynko_test_queue_response( 200, '{"data":[{"list":{"list_id":"l1","name":"One"}}]}' );
		wynko_test_queue_response( 200, '{"data":[]}' );

		Cache::refresh();

		$this->assertSame( 3, wynko_test_http_calls() );
	}

	/**
	 * Creates a published signup form bound to a list.
	 *
	 * @param string $list_id Bound list id.
	 * @return void
	 */
	private function form_bound_to( string $list_id ): void {
		$id = wynko_test_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, Config::form_meta_key( 'list_id' ), $list_id );
	}

	public function test_a_cache_hit_writes_nothing(): void {
		set_transient(
			Config::transient_key(),
			array(
				array(
					'list_ids' => array(),
					'sent_at'  => '',
					'name'     => '',
				),
			),
			60
		);

		Cache::get();

		$this->assertSame( array(), Log::all() );
	}

	/**
	 * An empty account caches an empty array, which get() must treat as a hit.
	 * If it counted as a miss, every anonymous front-end request holding the
	 * block would refetch and write another warning.
	 */
	public function test_an_empty_cached_list_is_a_hit_and_writes_nothing(): void {
		set_transient( Config::transient_key(), array(), 60 );

		Cache::get();
		Cache::get();

		$this->assertSame( 0, wynko_test_http_calls() );
		$this->assertSame( array(), Log::all() );
	}

	/** The same guarantee for the negative cache a failed fetch leaves behind. */
	public function test_a_negatively_cached_failure_is_not_retried_per_request(): void {
		wynko_test_queue_response( 401, '{}' );

		Cache::get();
		$after_first = count( Log::all() );
		Cache::get();
		Cache::get();

		$this->assertSame( 1, wynko_test_http_calls() );
		$this->assertCount( $after_first, Log::all() );
	}

	public function test_an_automatic_failure_is_an_error_naming_the_source(): void {
		wynko_test_queue_response( 401, '{}' );

		Cache::get();

		$this->assertStringContainsString( 'error: Automatic sync failed', $this->messages()[0] );
	}

	/**
	 * An account with no campaigns is a legitimate state, and warning about it
	 * every cache window is how an operator learns to ignore warnings.
	 */
	public function test_an_empty_account_is_not_a_warning(): void {
		wynko_test_queue_response( 200, '{"data":[]}' );
		wynko_test_queue_response( 200, '{"data":[]}' );

		Cache::refresh();

		$this->assertSame( array( 'info: Sync succeeded.' ), $this->messages() );
	}

	public function test_busting_the_cache_writes_nothing(): void {
		Cache::bust();

		$this->assertSame( array(), Log::all() );
	}

	public function test_a_forced_field_refetch_counts_as_a_sync(): void {
		// The drift retry a public signup can trigger, and the editor's Refresh
		// fields button: both fetch from Laposta, so both move the stamp.
		wynko_test_queue_response( 200, '{"data":[]}' );

		ApiFields::for_list( 'list_a', true );

		$last = Cache::last_sync();
		$this->assertIsArray( $last );
		$this->assertTrue( $last['ok'] );
	}

	public function test_an_ordinary_front_end_field_read_does_not_write_an_option(): void {
		// Every page view that renders a form takes this path on a cache miss.
		// Stamping there would mean an option write on an anonymous request.
		wynko_test_queue_response( 200, '{"data":[]}' );

		ApiFields::for_list( 'list_a' );

		$this->assertNull( Cache::last_sync() );
	}

	public function test_the_last_refresh_reads_as_a_sentence_for_the_settings_screen(): void {
		$this->assertStringContainsString( 'Nothing has been fetched', Cache::last_refresh_sentence() );

		wynko_test_queue_response( 200, '{"data":[]}' );
		Cache::refresh();

		$this->assertStringContainsString( 'Last refreshed', Cache::last_refresh_sentence() );
	}

	public function test_a_failed_refresh_says_so_rather_than_reporting_freshness(): void {
		wynko_test_queue_response( 401, '{}' );
		Cache::refresh();

		$this->assertStringContainsString( 'last refresh failed', Cache::last_refresh_sentence() );
	}
}
