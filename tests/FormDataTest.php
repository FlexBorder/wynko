<?php
/**
 * Tests for the form value object.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Support\Fields as FieldData;
use Wynko\Support\LapostaErrors;
use PHPUnit\Framework\TestCase;

/** Covers loading, the meta round trip, and the defaults an unsaved form gets. */
final class FormDataTest extends TestCase {

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

	public function test_a_new_form_has_signed_nobody_up(): void {
		$this->assertSame( 0, FormData::load( $this->form_id )->signup_total() );
	}

	public function test_recording_a_signup_raises_the_total_by_one(): void {
		FormData::load( $this->form_id )->record_signup();
		FormData::load( $this->form_id )->record_signup();

		$this->assertSame( 2, FormData::load( $this->form_id )->signup_total() );
	}

	/**
	 * The meta is writable by anything with the post id. A total is a count of
	 * things that happened, so whatever is found there reads as at least none.
	 */
	public function test_a_nonsense_stored_total_reads_as_none(): void {
		update_post_meta( $this->form_id, Config::form_meta_key( 'signups' ), -5 );
		$this->assertSame( 0, FormData::load( $this->form_id )->signup_total() );

		update_post_meta( $this->form_id, Config::form_meta_key( 'signups' ), 'plenty' );
		$this->assertSame( 0, FormData::load( $this->form_id )->signup_total() );
	}

	private function defs(): array {
		return FieldData::normalize(
			array(
				'data' => array(
					array(
						'field' => array(
							'field_id'    => 'f_1',
							'name'        => 'First name',
							'custom_name' => 'first_name',
							'datatype'    => 'text',
							'required'    => true,
						),
					),
					array(
						'field' => array(
							'field_id'    => 'f_2',
							'name'        => 'Company',
							'custom_name' => 'company',
							'datatype'    => 'text',
						),
					),
				),
			)
		);
	}

	public function test_load_returns_the_form(): void {
		$form = FormData::load( $this->form_id );

		$this->assertNotNull( $form );
		$this->assertSame( $this->form_id, $form->id() );
		$this->assertSame( 'Newsletter signup', $form->name() );
		$this->assertTrue( $form->is_published() );
	}

	public function test_load_refuses_a_missing_post(): void {
		$this->assertNull( FormData::load( $this->form_id + 999 ) );
	}

	public function test_load_refuses_a_post_of_another_type(): void {
		$other = wynko_test_insert_post( array( 'post_type' => 'page' ) );

		$this->assertNull( FormData::load( $other ) );
	}

	public function test_a_draft_form_is_not_published(): void {
		$draft = wynko_test_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'draft',
			)
		);

		$this->assertFalse( FormData::load( $draft )->is_published() );
	}

	public function test_an_untouched_form_has_no_list_and_empty_messages(): void {
		$form = FormData::load( $this->form_id );

		$this->assertSame( '', $form->list_id() );
		$this->assertSame( '', $form->message( LapostaErrors::SLUG_SUCCESS ) );
		$this->assertSame( array(), $form->field_overrides() );
	}

	public function test_an_untouched_form_gets_the_configured_settings_defaults(): void {
		$this->assertSame( Config::form_settings_defaults(), FormData::load( $this->form_id )->settings() );
	}

	public function test_the_list_id_round_trips(): void {
		FormData::load( $this->form_id )->save_list_id( 'list_a' );

		$this->assertSame( 'list_a', FormData::load( $this->form_id )->list_id() );
	}

	public function test_every_presentation_key_survives_the_round_trip(): void {
		// The row shape is rebuilt on read and on write. A key added to one but
		// not the other reads as "I saved it and it vanished", so this asserts
		// the whole widened row, not a sample of it.
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id'    => 'f_1',
					'visible'     => true,
					'label'       => 'Your name',
					'css_class'   => 'wide',
					'placeholder' => 'Ada Lovelace',
					'help'        => 'As on your card.',
					'value'       => 'Ada',
					'attrs'       => array(
						'min'   => '1',
						'max'   => '10',
						'step'  => '1',
						'style' => 'range',
					),
				),
			)
		);

		$row = FormData::load( $this->form_id )->field_overrides()[0];

		$this->assertSame( 'Ada Lovelace', $row['placeholder'] );
		$this->assertSame( 'As on your card.', $row['help'] );
		$this->assertSame( 'Ada', $row['value'] );
		$this->assertSame(
			array(
				'min'   => '1',
				'max'   => '10',
				'step'  => '1',
				'style' => 'range',
			),
			$row['attrs']
		);
	}

	public function test_a_form_with_nothing_stored_takes_the_configured_default(): void {
		$this->assertSame(
			FieldData::LABEL_MODE_BOTH,
			FormData::load( $this->form_id )->settings()['label_mode']
		);
	}

	public function test_a_legacy_form_whose_rows_name_no_mode_stays_on_labels(): void {
		update_post_meta(
			$this->form_id,
			'_wynko_fields',
			array(
				array(
					'field_id' => 'f_1',
					'visible'  => true,
				),
			)
		);

		$this->assertSame(
			FieldData::LABEL_MODE_LABEL,
			FormData::load( $this->form_id )->settings()['label_mode']
		);
	}

	public function test_a_legacy_form_keeps_the_mode_most_of_its_rows_had(): void {
		update_post_meta(
			$this->form_id,
			Config::form_meta_key( 'fields' ),
			array(
				array(
					'field_id'   => 'f_1',
					'label_mode' => FieldData::LABEL_MODE_PLACEHOLDER,
				),
				array(
					'field_id'   => 'f_2',
					'label_mode' => FieldData::LABEL_MODE_PLACEHOLDER,
				),
				array(
					'field_id'   => 'f_3',
					'label_mode' => FieldData::LABEL_MODE_LABEL,
				),
			)
		);

		$this->assertSame(
			FieldData::LABEL_MODE_PLACEHOLDER,
			FormData::load( $this->form_id )->settings()['label_mode']
		);
	}

	public function test_a_form_saved_before_the_link_type_existed_still_links_its_terms(): void {
		update_post_meta(
			$this->form_id,
			Config::form_meta_key( 'settings' ),
			array(
				'terms_required' => true,
				'terms_url'      => 'https://example.org/terms/',
			)
		);

		$this->assertSame( 'url', FormData::load( $this->form_id )->settings()['terms_link_type'] );
	}

	public function test_a_form_saved_before_the_link_type_existed_with_no_url_does_not_link(): void {
		update_post_meta(
			$this->form_id,
			Config::form_meta_key( 'settings' ),
			array( 'terms_required' => true )
		);

		$this->assertSame( '', FormData::load( $this->form_id )->settings()['terms_link_type'] );
	}

	public function test_a_stored_mode_beats_whatever_the_rows_say(): void {
		update_post_meta(
			$this->form_id,
			Config::form_meta_key( 'settings' ),
			array( 'label_mode' => FieldData::LABEL_MODE_BOTH )
		);
		update_post_meta(
			$this->form_id,
			Config::form_meta_key( 'fields' ),
			array(
				array(
					'field_id'   => 'f_1',
					'label_mode' => FieldData::LABEL_MODE_PLACEHOLDER,
				),
			)
		);

		$this->assertSame(
			FieldData::LABEL_MODE_BOTH,
			FormData::load( $this->form_id )->settings()['label_mode']
		);
	}

	public function test_an_unknown_attribute_is_dropped(): void {
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id' => 'f_1',
					'attrs'    => array(
						'min'      => '2',
						'onclick'  => 'alert(1)',
						'nonsense' => 'x',
					),
				),
			)
		);

		$row = FormData::load( $this->form_id )->field_overrides()[0];

		$this->assertSame( array( 'min' => '2' ), $row['attrs'] );
	}

	public function test_field_overrides_round_trip_and_drive_the_merge(): void {
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id'  => 'f_2',
					'visible'   => false,
					'label'     => 'Employer',
					'css_class' => 'wide',
				),
			)
		);

		$fields = FormData::load( $this->form_id )->fields( $this->defs() );

		// Email is injected first when it has no override placing it elsewhere.
		$this->assertSame( array( 'email', 'f_2', 'f_1' ), array_column( $fields, 'field_id' ) );
		$this->assertSame( 'Employer', $fields[1]['label'] );
		$this->assertFalse( $fields[1]['visible'] );
	}

	public function test_visible_fields_drops_the_hidden_ones_but_keeps_required(): void {
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id'  => 'f_1',
					'visible'   => false,
					'label'     => '',
					'css_class' => '',
				),
				array(
					'field_id'  => 'f_2',
					'visible'   => false,
					'label'     => '',
					'css_class' => '',
				),
			)
		);

		$form = FormData::load( $this->form_id );

		// f_1 is required by Laposta, so it stays whatever the override says;
		// email is always required and always present.
		$this->assertSame( array( 'email', 'f_1' ), array_column( $form->visible_fields( $this->defs() ), 'field_id' ) );

		// The custom-field view drops email: Laposta takes the address as a
		// top-level parameter, and FormValidator checks it separately.
		$this->assertSame( array( 'f_1' ), array_column( $form->visible_custom_fields( $this->defs() ), 'field_id' ) );
	}

	public function test_a_form_saved_before_email_had_a_row_keeps_it_first(): void {
		// Its overrides mention every Laposta field and not email, which would
		// otherwise append the address to the bottom of a form nobody edited.
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id' => 'f_1',
					'visible'  => true,
				),
				array(
					'field_id' => 'f_2',
					'visible'  => true,
				),
			)
		);

		$fields = FormData::load( $this->form_id )->fields( $this->defs() );

		$this->assertSame( array( 'email', 'f_1', 'f_2' ), array_column( $fields, 'field_id' ) );
	}

	public function test_the_email_row_takes_the_position_its_override_gives_it(): void {
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id' => 'f_1',
					'visible'  => true,
				),
				array(
					'field_id' => 'email',
					'visible'  => true,
					'label'    => 'Your email',
				),
			)
		);

		$fields = FormData::load( $this->form_id )->fields( $this->defs() );

		$this->assertSame( 'f_1', $fields[0]['field_id'] );
		$this->assertSame( 'email', $fields[1]['field_id'] );
		$this->assertSame( 'Your email', $fields[1]['label'] );
	}

	public function test_messages_round_trip_and_ignore_unknown_slugs(): void {
		FormData::load( $this->form_id )->save_messages(
			array(
				LapostaErrors::SLUG_SUCCESS => 'Almost there — check your inbox.',
				'not_a_slug'                => 'nope',
			)
		);

		$form = FormData::load( $this->form_id );

		$this->assertSame( 'Almost there — check your inbox.', $form->message( LapostaErrors::SLUG_SUCCESS ) );
		$this->assertArrayNotHasKey( 'not_a_slug', $form->messages() );
	}

	public function test_settings_round_trip_and_fill_missing_keys_from_the_defaults(): void {
		FormData::load( $this->form_id )->save_settings(
			array(
				'skip_doi'     => true,
				'redirect_url' => 'https://example.org/thanks/',
			)
		);

		$settings = FormData::load( $this->form_id )->settings();

		$this->assertTrue( $settings['skip_doi'] );
		$this->assertSame( 'https://example.org/thanks/', $settings['redirect_url'] );
		$this->assertFalse( $settings['terms_required'] );
	}

	public function test_a_non_scalar_submitted_value_degrades_to_an_empty_string(): void {
		FormData::load( $this->form_id )->save_settings(
			array(
				'terms_text' => array( 'nope' ),
			)
		);

		$this->assertSame( '', FormData::load( $this->form_id )->settings()['terms_text'] );
	}

	public function test_the_redirect_resolves_a_chosen_page(): void {
		$page = wynko_test_insert_post(
			array(
				'post_title'  => 'Thanks',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		FormData::load( $this->form_id )->save_settings(
			array(
				'redirect_type'    => 'page',
				'redirect_page_id' => (string) $page,
			)
		);

		$this->assertSame( get_permalink( $page ), FormData::load( $this->form_id )->redirect_url() );
	}

	public function test_a_deleted_redirect_page_falls_back_to_no_redirect(): void {
		FormData::load( $this->form_id )->save_settings(
			array(
				'redirect_type'    => 'page',
				'redirect_page_id' => '999999',
			)
		);

		$this->assertSame( '', FormData::load( $this->form_id )->redirect_url() );
	}

	public function test_the_redirect_uses_the_typed_url_when_that_is_the_mode(): void {
		FormData::load( $this->form_id )->save_settings(
			array(
				'redirect_type' => 'url',
				'redirect_url'  => 'https://example.org/thanks/',
			)
		);

		$this->assertSame( 'https://example.org/thanks/', FormData::load( $this->form_id )->redirect_url() );
	}

	public function test_no_redirect_mode_means_no_redirect(): void {
		// A URL left over from an earlier choice must not fire once the mode
		// has been switched back to staying on the page.
		FormData::load( $this->form_id )->save_settings( array( 'redirect_url' => 'https://example.org/thanks/' ) );

		$this->assertSame( '', FormData::load( $this->form_id )->redirect_url() );
	}

	public function test_corrupt_meta_degrades_to_the_defaults(): void {
		update_post_meta( $this->form_id, Config::form_meta_key( 'settings' ), 'not an array' );
		update_post_meta( $this->form_id, Config::form_meta_key( 'fields' ), 'not an array' );

		$form = FormData::load( $this->form_id );

		$this->assertSame( Config::form_settings_defaults(), $form->settings() );
		$this->assertSame( array(), $form->field_overrides() );
	}
	public function test_referenced_list_ids_are_distinct_and_published_only(): void {
		$this->make_form( 'One', 'list-a', 'publish' );
		$this->make_form( 'Two', 'list-a', 'publish' );
		$this->make_form( 'Three', 'list-b', 'publish' );
		$this->make_form( 'Draft', 'list-c', 'draft' );
		$this->make_form( 'Unbound', '', 'publish' );

		$ids = FormData::referenced_list_ids();

		sort( $ids );
		$this->assertSame( array( 'list-a', 'list-b' ), $ids );
	}

	public function test_referenced_list_ids_are_empty_without_forms(): void {
		$this->assertSame( array(), FormData::referenced_list_ids() );
	}

	/**
	 * Creates a signup form bound to a list.
	 *
	 * @param string $name    Post title.
	 * @param string $list_id Bound list id, '' for none.
	 * @param string $status  Post status.
	 * @return int
	 */
	private function make_form( string $name, string $list_id, string $status ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_title'  => $name,
				'post_status' => $status,
			)
		);
		update_post_meta( $id, Config::form_meta_key( 'list_id' ), $list_id );
		return (int) $id;
	}
}
