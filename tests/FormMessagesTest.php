<?php
/**
 * Tests for the built-in form messages.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Forms\Messages;
use Wynko\Support\LapostaErrors;
use PHPUnit\Framework\TestCase;

/** Every slug must have wording, and a form's override must win. */
final class FormMessagesTest extends TestCase {

	/**
	 * The test form's post id.
	 *
	 * @var int
	 */
	private int $form_id;

	protected function setUp(): void {
		wynko_test_reset_store();
		$this->form_id = wynko_test_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
	}

	public function test_every_slug_has_a_default_and_a_label(): void {
		$defaults = Messages::defaults();

		foreach ( LapostaErrors::slugs() as $slug ) {
			$this->assertArrayHasKey( $slug, $defaults, $slug . ' has no default' );
			$this->assertNotSame( '', $defaults[ $slug ] );
			$this->assertNotSame( '', Messages::label( $slug ) );
		}
	}

	public function test_defaults_carry_no_extra_slugs(): void {
		$this->assertSame( LapostaErrors::slugs(), array_keys( Messages::defaults() ) );
	}

	public function test_resolve_uses_the_default_when_the_form_has_no_override(): void {
		$form = FormData::load( $this->form_id );

		$this->assertSame(
			Messages::defaults()[ LapostaErrors::SLUG_SUCCESS ],
			Messages::resolve( $form, LapostaErrors::SLUG_SUCCESS )
		);
	}

	public function test_resolve_prefers_the_forms_own_wording(): void {
		FormData::load( $this->form_id )->save_messages( array( LapostaErrors::SLUG_SUCCESS => 'Check your inbox.' ) );

		$this->assertSame( 'Check your inbox.', Messages::resolve( FormData::load( $this->form_id ), LapostaErrors::SLUG_SUCCESS ) );
	}

	public function test_resolve_falls_back_to_the_generic_error_for_an_unknown_slug(): void {
		$this->assertSame(
			Messages::defaults()[ LapostaErrors::SLUG_GENERIC ],
			Messages::resolve( FormData::load( $this->form_id ), 'not_a_slug' )
		);
	}
}
