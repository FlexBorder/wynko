<?php
/**
 * Tests for the forms list screen.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\Forms\FormsListPage;
use Wynko\Admin\Forms\FormsTable;
use Wynko\Admin\Forms\Screen;
use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Throttle;
use PHPUnit\Framework\TestCase;

/**
 * Covers what the list screen puts in each cell and which forms it lists.
 *
 * The assertions go through FormsTable's own column methods rather than
 * FormsListPage::render(), because rendering runs WP_List_Table::display() —
 * WordPress's code, not the plugin's, and not something the shim harness
 * should pretend to have.
 */
final class FormsListPageTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		wynko_test_set_can_manage( true );
		update_option( Config::option_key( 'api_key' ), 'test-key' );
	}

	private function a_form( string $title = 'Newsletter signup', string $list_id = 'list_a' ): int {
		$id = wynko_test_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
		FormData::load( $id )->save_list_id( $list_id );
		return $id;
	}

	private function queue_lists(): void {
		wynko_test_queue_response( 200, '{"data":[{"list":{"list_id":"list_a","name":"Newsletter"}}]}' );
	}

	/**
	 * A prepared table, with the queued list response consumed.
	 *
	 * @return FormsTable
	 */
	private function table(): FormsTable {
		$table = new FormsTable();
		$table->prepare_items();
		return $table;
	}

	public function test_the_shortcode_names_the_form_id(): void {
		$this->assertSame( '[wynko_form id="7"]', FormsListPage::shortcode_for( 7 ) );
	}

	public function test_forms_returns_only_the_form_post_type(): void {
		$this->a_form();
		wynko_test_insert_post( array( 'post_type' => 'page' ) );

		$this->assertCount( 1, FormsListPage::forms() );
	}

	public function test_it_lists_a_row_per_form_and_names_its_list(): void {
		// Title and list name deliberately share no substring: this must fail
		// if the list-name join is ever dropped and the cell happens to still
		// match by falling back to the title.
		$id = $this->a_form( 'Signup form', 'list_a' );
		wynko_test_queue_response( 200, '{"data":[{"list":{"list_id":"list_a","name":"VIP Newsletter"}}]}' );

		$table = $this->table();

		$this->assertCount( 1, $table->items );
		$cell = $table->column_default( FormData::load( $id ), 'list' );

		$this->assertStringContainsString( '>VIP Newsletter</a>', $cell );
		$this->assertStringContainsString( 'listconfig=list_a', $cell );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $cell );
	}

	public function test_an_unreachable_api_falls_back_to_the_raw_list_id(): void {
		$id = $this->a_form( 'Signup form', 'list_a' );
		wynko_test_queue_response( 500, '{"error":{"message":"boom"}}' );

		// The name is unknown, so the id stands in — but the link still points
		// at the right list, which is the part that does not need the API.
		$this->assertStringContainsString( '>list_a</a>', $this->table()->column_default( FormData::load( $id ), 'list' ) );
	}

	public function test_it_renders_the_shortcode_in_a_readonly_field(): void {
		$id = $this->a_form();
		$this->queue_lists();

		$cell = $this->table()->column_default( FormData::load( $id ), 'shortcode' );

		$this->assertStringContainsString( 'readonly', $cell );
		$this->assertStringContainsString( FormsListPage::shortcode_for( $id ), $cell );
	}

	public function test_it_links_each_row_to_its_editor_and_offers_row_actions(): void {
		$id = $this->a_form();
		$this->queue_lists();

		$cell = $this->table()->column_name( FormData::load( $id ) );

		$this->assertStringContainsString( Screen::edit_url( $id ), $cell );
		$this->assertStringContainsString( 'row-actions', $cell );
		$this->assertStringContainsString( 'Delete', $cell );
	}

	public function test_each_row_offers_a_selection_checkbox(): void {
		$id = $this->a_form();
		$this->queue_lists();

		$this->assertStringContainsString(
			'name="form_ids[]" value="' . $id . '"',
			$this->table()->column_cb( FormData::load( $id ) )
		);
	}

	public function test_the_signups_column_shows_the_forms_lifetime_total(): void {
		$id = $this->a_form();
		$this->queue_lists();
		$form = FormData::load( $id );
		$form->record_signup();
		$form->record_signup();

		$this->assertSame( '2', $this->table()->column_default( FormData::load( $id ), 'signups' ) );
	}

	/** A form nobody has signed up through has a real answer: none yet. */
	public function test_the_signups_column_shows_zero_before_any_signup(): void {
		$id = $this->a_form();
		$this->queue_lists();

		$this->assertSame( '0', $this->table()->column_default( FormData::load( $id ), 'signups' ) );
	}

	/**
	 * The rate-limit window is the Security tab's number. Metering counts every
	 * submission a form accepts, including ones Laposta never took, so it must
	 * not be what a column headed "Signups" reports.
	 */
	public function test_the_signups_column_is_not_the_rate_limit_window(): void {
		$id = $this->a_form();
		$this->queue_lists();
		Throttle::allows( $id, '203.0.113.9' );
		Throttle::allows( $id, '203.0.113.9' );

		$this->assertSame( '0', $this->table()->column_default( FormData::load( $id ), 'signups' ) );
	}

	public function test_the_successful_and_failed_columns_are_gone(): void {
		$columns = array_keys( $this->table()->get_columns() );

		$this->assertContains( 'signups', $columns );
		$this->assertNotContains( 'successful', $columns );
		$this->assertNotContains( 'failed', $columns );
	}

	public function test_a_row_offers_a_rename_box_bound_to_its_own_form(): void {
		$id = $this->a_form( 'Newsletter signup' );
		$this->queue_lists();

		$cell = $this->table()->column_name( FormData::load( $id ) );

		$this->assertStringContainsString( 'Quick edit', $cell );
		$this->assertStringContainsString( 'name="wynko_form_name"', $cell );
		$this->assertStringContainsString( 'form="' . FormsListPage::rename_form_id( $id ) . '"', $cell );
	}

	public function test_an_unnamed_form_is_listed_under_the_name_it_will_be_saved_with(): void {
		$id = $this->a_form( '' );
		$this->queue_lists();

		$this->assertStringContainsString(
			FormData::default_name(),
			$this->table()->column_name( FormData::load( $id ) )
		);
	}

	public function test_a_delete_returns_with_a_notice(): void {
		$this->assertStringContainsString(
			FormsListPage::NOTICE_ARG . '=' . FormsListPage::NOTICE_DELETED,
			FormsListPage::notice_url( FormsListPage::NOTICE_DELETED )
		);
	}

	public function test_a_form_with_no_bound_list_says_so_rather_than_rendering_blank(): void {
		$id = $this->a_form( 'Unbound', '' );
		$this->queue_lists();

		$this->assertSame( 'No list selected', $this->table()->column_default( FormData::load( $id ), 'list' ) );
	}

	public function test_an_empty_screen_invites_the_first_form(): void {
		$this->queue_lists();

		ob_start();
		$this->table()->no_items();
		$this->assertStringContainsString( 'No signup forms yet', (string) ob_get_clean() );
	}

	public function test_it_renders_nothing_without_the_capability(): void {
		$this->a_form();
		wynko_test_set_can_manage( false );

		ob_start();
		FormsListPage::render();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
