<?php
/**
 * Tests for the removed-list alarm.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\GoneLists;
use Wynko\Log;
use PHPUnit\Framework\TestCase;

/** Covers reporting a vanished list exactly once, and re-arming when it returns. */
final class GoneListsTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'list_names' ), array( 'gone-1' => 'Newsletter' ) );
	}

	/**
	 * The stored entries as "level: message", newest first.
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

	/**
	 * Creates a published signup form bound to a list.
	 *
	 * @param string $list_id Bound list id.
	 * @return void
	 */
	private function form_for( string $list_id ): void {
		$id = wynko_test_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, Config::form_meta_key( 'list_id' ), $list_id );
	}

	public function test_a_referenced_list_missing_from_the_index_is_an_error(): void {
		$this->form_for( 'gone-1' );

		GoneLists::check( array( 'other' => 'Other' ), array( 'gone-1' => 1 ) );

		$this->assertCount( 1, $this->messages() );
		$this->assertStringStartsWith( 'error: ', $this->messages()[0] );
		$this->assertStringContainsString( 'Newsletter', $this->messages()[0] );
	}

	public function test_the_entry_counts_the_forms_still_using_it(): void {
		$this->form_for( 'gone-1' );
		$this->form_for( 'gone-1' );

		GoneLists::check( array(), array( 'gone-1' => 2 ) );

		$this->assertStringContainsString( '2 signup forms', $this->messages()[0] );
	}

	public function test_it_is_reported_once_however_often_the_sync_runs(): void {
		$this->form_for( 'gone-1' );

		GoneLists::check( array(), array( 'gone-1' => 1 ) );
		GoneLists::check( array(), array( 'gone-1' => 1 ) );
		GoneLists::check( array(), array( 'gone-1' => 1 ) );

		$this->assertCount( 1, $this->messages() );
	}

	public function test_a_list_that_comes_back_can_be_reported_again(): void {
		$this->form_for( 'gone-1' );
		GoneLists::check( array(), array( 'gone-1' => 1 ) );
		GoneLists::check( array( 'gone-1' => 'Newsletter' ), array( 'gone-1' => 1 ) );
		Log::clear();

		GoneLists::check( array(), array( 'gone-1' => 1 ) );

		$this->assertCount( 1, $this->messages() );
	}

	public function test_a_list_no_form_uses_any_more_is_not_reported(): void {
		GoneLists::check( array(), array() );

		$this->assertSame( array(), $this->messages() );
	}

	public function test_dropping_the_last_form_clears_the_ledger(): void {
		$this->form_for( 'gone-1' );
		GoneLists::check( array(), array( 'gone-1' => 1 ) );
		GoneLists::check( array(), array() );
		Log::clear();

		GoneLists::check( array(), array( 'gone-1' => 1 ) );

		$this->assertCount( 1, $this->messages() );
	}

	public function test_a_list_with_no_remembered_name_is_reported_by_id(): void {
		delete_option( Config::option_key( 'list_names' ) );
		$this->form_for( 'unknown-id' );

		GoneLists::check( array(), array( 'unknown-id' => 1 ) );

		$this->assertStringContainsString( 'unknown-id', $this->messages()[0] );
	}

	public function test_a_present_list_is_never_reported(): void {
		$this->form_for( 'gone-1' );

		GoneLists::check( array( 'gone-1' => 'Newsletter' ), array( 'gone-1' => 1 ) );

		$this->assertSame( array(), $this->messages() );
		$this->assertSame( array(), GoneLists::reported() );
	}

	public function test_wynko_list_gone_fires_once_for_a_newly_missing_list(): void {
		$this->form_for( 'gone-1' );
		$fired = array();
		add_action(
			'wynko_list_gone',
			static function ( $list_id, $name, $forms ) use ( &$fired ) {
				$fired[] = array( $list_id, $name, $forms );
			}
		);

		GoneLists::check( array(), array( 'gone-1' => 1 ) );
		GoneLists::check( array(), array( 'gone-1' => 1 ) );

		$this->assertSame( array( array( 'gone-1', 'Newsletter', 1 ) ), $fired );
	}
}
