<?php
/**
 * Tests for deleting forms from the list screen.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\Forms\FormsListPage;
use Wynko\Admin\Forms\FormsTable;
use Wynko\Config;
use Wynko\Forms\FormData;
use PHPUnit\Framework\TestCase;

/** Bulk delete must touch forms and nothing else, and only when asked. */
final class FormsTableTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		wynko_test_set_can_manage( true );
		unset( $_REQUEST['action'], $_REQUEST['action2'], $_REQUEST['filter_action'] );
	}

	protected function tearDown(): void {
		unset( $_REQUEST['action'], $_REQUEST['action2'], $_REQUEST['filter_action'] );
	}

	public function test_apply_with_no_action_chosen_asks_for_nothing(): void {
		$_REQUEST['action']  = '-1';
		$_REQUEST['action2'] = '-1';

		$this->assertFalse( ( new FormsTable() )->current_action() );
	}

	public function test_the_upper_bulk_select_is_read(): void {
		$_REQUEST['action']  = 'delete';
		$_REQUEST['action2'] = '-1';

		$this->assertSame( 'delete', ( new FormsTable() )->current_action() );
	}

	public function test_the_bulk_handler_runs_before_output(): void {
		// The redirect it ends with must happen before admin-header.php sends
		// anything, so it hangs off load-{$hook} rather than the render
		// callback. Menu is the only place that wiring lives.
		$forms = null;
		foreach ( \Wynko\Admin\Menu::screens() as $screen ) {
			if ( \Wynko\Admin\Menu::FORMS === $screen['slug'] ) {
				$forms = $screen;
			}
		}

		$this->assertNotNull( $forms );
		$this->assertContains(
			array( FormsListPage::class, 'handle_bulk_action' ),
			$forms['load'] ?? array()
		);
	}

	public function test_the_lower_bulk_select_is_read_too(): void {
		// WP_List_Table renders it but its own current_action() ignores it, so
		// without the override choosing Delete below would silently do nothing.
		$_REQUEST['action']  = '-1';
		$_REQUEST['action2'] = 'delete';

		$this->assertSame( 'delete', ( new FormsTable() )->current_action() );
	}

	/**
	 * Creates a signup form.
	 *
	 * @param string $title Form name.
	 * @return int
	 */
	private function make_form( string $title ): int {
		return wynko_test_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
	}

	public function test_bulk_delete_removes_every_form_it_is_given(): void {
		$one = $this->make_form( 'One' );
		$two = $this->make_form( 'Two' );

		$this->assertSame( 2, FormsListPage::bulk_delete( array( $one, $two ) ) );
		$this->assertNull( FormData::load( $one ) );
		$this->assertNull( FormData::load( $two ) );
	}

	public function test_bulk_delete_ignores_ids_that_are_not_forms(): void {
		$form = $this->make_form( 'One' );
		$page = wynko_test_insert_post(
			array(
				'post_title'  => 'About',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 1, FormsListPage::bulk_delete( array( $form, $page, 9999 ) ) );
		$this->assertNotNull( get_post( $page ) );
	}

	public function test_bulk_delete_refuses_without_the_capability(): void {
		$form = $this->make_form( 'One' );
		wynko_test_set_can_manage( false );

		$this->assertSame( 0, FormsListPage::bulk_delete( array( $form ) ) );
		$this->assertNotNull( FormData::load( $form ) );
	}
}
