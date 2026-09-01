<?php
/**
 * Tests for the signup form block.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Blocks\Form;
use Wynko\Admin\Forms\Screen;
use Wynko\Config;
use Wynko\Forms\FormData;
use PHPUnit\Framework\TestCase;

/** Covers the render callback and the data handed to the editor. */
final class FormBlockTest extends TestCase {

	/**
	 * The published form created in setUp().
	 *
	 * @var int
	 */
	private int $form_id;

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );

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
			'{"data":[{"field":{"field_id":"f_1","name":"First name","custom_name":"first_name","datatype":"text","required":true}}]}'
		);
	}

	public function test_render_delegates_to_the_shared_renderer(): void {
		$this->queue_fields();

		$html = Form::render( array( 'formId' => (string) $this->form_id ) );

		$this->assertStringContainsString( 'wynko-form-' . $this->form_id, $html );
	}

	public function test_render_without_a_chosen_form_prompts_an_editor(): void {
		wynko_test_set_can_manage( true );

		$this->assertStringContainsString( 'Wynko', Form::render( array() ) );
	}

	public function test_render_without_a_chosen_form_is_silent_for_a_visitor(): void {
		wynko_test_set_can_manage( false );

		$this->assertSame( '', Form::render( array() ) );
	}

	public function test_editor_options_carry_only_published_forms(): void {
		wynko_test_insert_post(
			array(
				'post_title'  => 'Draft form',
				'post_type'   => Config::form_post_type(),
				'post_status' => 'draft',
			)
		);

		$options = Form::editor_options();

		$this->assertSame(
			array(
				array(
					'value' => (string) $this->form_id,
					'label' => 'Newsletter signup',
				),
			),
			$options
		);
	}

	public function test_the_editor_is_told_where_each_form_is_edited(): void {
		$data = Form::editor_data();

		$this->assertSame( Form::editor_options(), $data['forms'] );
		$this->assertSame(
			Screen::edit_url( $this->form_id ),
			$data['editUrls'][ (string) $this->form_id ]
		);
		$this->assertSame( Screen::list_url(), $data['listUrl'] );
	}

	public function test_wynko_form_block_content_filter_can_modify_the_output(): void {
		$this->queue_fields();
		add_filter(
			'wynko_form_block_content',
			static function ( $content ) {
				return $content . '<!--filtered-->';
			}
		);

		$html = Form::render( array( 'formId' => (string) $this->form_id ) );

		$this->assertStringContainsString( '<!--filtered-->', $html );
	}
}
