<?php
/**
 * Tests for the signup button element.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\Forms\FieldRows;
use Wynko\Config;
use Wynko\Forms\Button;
use Wynko\Forms\FormData;
use PHPUnit\Framework\TestCase;

/**
 * The button is the one element in the editor that is not a Laposta field:
 * its wording and its class are the admin's, and both survive the round trip.
 */
final class FormButtonTest extends TestCase {

	/**
	 * The post id of the form under test.
	 *
	 * @var int
	 */
	private int $form_id;

	protected function setUp(): void {
		wynko_test_reset_store();
		$this->form_id = wynko_test_insert_post(
			array(
				'post_title'  => 'Newsletter signup',
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * The form under test.
	 *
	 * @return FormData
	 */
	private function form(): FormData {
		return FormData::load( $this->form_id );
	}

	public function test_an_unsaved_form_stores_nothing_and_renders_the_built_in_wording(): void {
		$this->assertSame(
			array(
				'label'     => '',
				'css_class' => '',
			),
			$this->form()->button()
		);

		$this->assertNotSame( '', Button::default_label() );
		$this->assertSame(
			array(
				'label'     => Button::default_label(),
				'css_class' => '',
			),
			Button::resolve( $this->form() )
		);
	}

	public function test_a_stored_label_and_class_come_back(): void {
		$this->form()->save_button(
			array(
				'label'     => 'Join the list',
				'css_class' => 'btn btn-primary',
			)
		);

		$this->assertSame(
			array(
				'label'     => 'Join the list',
				'css_class' => 'btn btn-primary',
			),
			Button::resolve( $this->form() )
		);
	}

	public function test_a_blank_label_falls_back_rather_than_rendering_an_empty_button(): void {
		$this->form()->save_button( array( 'label' => '   ' ) );

		$this->assertSame( Button::default_label(), Button::resolve( $this->form() )['label'] );
	}

	public function test_a_key_that_is_not_ours_never_reaches_the_database(): void {
		$this->form()->save_button(
			array(
				'label'   => 'Join',
				'onclick' => 'alert(1)',
			)
		);

		$stored = get_post_meta( $this->form_id, Config::form_meta_key( 'button' ), true );

		$this->assertSame( array( 'label', 'css_class' ), array_keys( $stored ) );
	}

	public function test_the_editor_shows_the_button_as_its_own_row(): void {
		$html = FieldRows::table(
			array(),
			array(
				'label'     => 'Join the list',
				'css_class' => 'btn',
			)
		);

		$this->assertStringContainsString( 'wynko-row--button', $html );
		$this->assertStringContainsString( 'name="wynko_button[label]"', $html );
		$this->assertStringContainsString( 'value="Join the list"', $html );
		$this->assertStringContainsString( 'name="wynko_button[css_class]"', $html );
		$this->assertStringContainsString( 'value="btn"', $html );
	}

	public function test_an_unset_label_reads_as_the_built_in_wording_in_grey(): void {
		$html = FieldRows::table( array(), $this->form()->button() );

		$this->assertStringContainsString( 'placeholder="' . Button::default_label() . '"', $html );
	}

	public function test_the_button_row_sits_outside_the_body_the_list_swap_replaces(): void {
		$this->assertStringNotContainsString( 'wynko-row--button', FieldRows::tbody( array() ) );
		$this->assertStringContainsString( 'wynko-row--button', FieldRows::table( array(), $this->form()->button() ) );
	}

	public function test_the_button_row_cannot_be_dragged_or_moved(): void {
		$html = FieldRows::table( array(), $this->form()->button() );
		$row  = substr( $html, (int) strpos( $html, 'wynko-row--button' ) );

		$this->assertStringNotContainsString( 'wynko-handle', $row );
		$this->assertStringNotContainsString( 'wynko-move', $row );
	}
}
