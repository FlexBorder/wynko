<?php
/**
 * Tests for the signup-form shortcode.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Frontend\Shortcode;
use PHPUnit\Framework\TestCase;

/** The shortcode is a thin wrapper: it resolves an id and delegates. */
final class ShortcodeTest extends TestCase {

	/**
	 * The published test form's post id.
	 *
	 * @var int
	 */
	private int $form_id;

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );

		$this->form_id = wynko_test_insert_post(
			array(
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

	public function test_it_renders_the_form_named_by_id(): void {
		$this->queue_fields();

		$html = Shortcode::render( array( 'id' => (string) $this->form_id ) );

		$this->assertStringContainsString( 'wynko-form-' . $this->form_id, $html );
	}

	public function test_a_missing_id_renders_nothing(): void {
		$this->assertSame( '', Shortcode::render( array() ) );
		$this->assertSame( '', Shortcode::render( '' ) );
	}

	public function test_a_non_numeric_id_renders_nothing(): void {
		$this->assertSame( '', Shortcode::render( array( 'id' => 'drop table' ) ) );
	}

	public function test_registering_uses_the_configured_tag(): void {
		Shortcode::register();

		$this->assertArrayHasKey( Config::form_shortcode(), $GLOBALS['wynko_test_shortcodes'] );
	}
}
