<?php
/**
 * Tests for the form save handler.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\Forms\FormEditPage;
use Wynko\Admin\Forms\Screen;
use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Support\LapostaErrors;
use PHPUnit\Framework\TestCase;

/** Covers sanitization, ordering, the required-field lock, and the guards. */
final class FormSaveTest extends TestCase {

	/**
	 * The form under test, created fresh in setUp().
	 *
	 * @var int
	 */
	private int $form_id;

	protected function setUp(): void {
		wynko_test_reset_store();
		wynko_test_set_can_manage( true );
		update_option( Config::option_key( 'api_key' ), 'test-key' );

		$this->form_id = wynko_test_insert_post(
			array(
				'post_title'  => 'Newsletter signup',
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
	}

	private function queue_fields(): void {
		wynko_test_queue_response(
			200,
			'{"data":[' .
			'{"field":{"field_id":"f_1","name":"First name","custom_name":"first_name","datatype":"text","required":true}},' .
			'{"field":{"field_id":"f_2","name":"Company","custom_name":"company","datatype":"text","required":false}}' .
			']}'
		);
	}

	private function raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'wynko_form_name' => 'Newsletter signup',
				'wynko_list_id'   => 'list_a',
				'tab'             => Screen::TAB_EDITOR,
			),
			$overrides
		);
	}

	public function test_save_stores_the_bound_list_and_the_name(): void {
		$this->queue_fields();

		FormEditPage::save( $this->form_id, $this->raw( array( 'wynko_form_name' => '  Renamed  ' ) ) );

		$form = FormData::load( $this->form_id );
		$this->assertSame( 'list_a', $form->list_id() );
		$this->assertSame( 'Renamed', $form->name() );
	}

	public function test_save_refuses_an_id_that_is_not_a_form(): void {
		$other = wynko_test_insert_post( array( 'post_type' => 'page' ) );

		$this->assertSame( FormEditPage::SAVE_NOT_FOUND, FormEditPage::save( $other, $this->raw() ) );
	}

	public function test_wynko_form_config_saved_fires_after_a_successful_save(): void {
		$this->queue_fields();
		$fired = array();
		add_action(
			'wynko_form_config_saved',
			static function ( $form_id, $tab ) use ( &$fired ) {
				$fired[] = array( $form_id, $tab );
			}
		);

		FormEditPage::save( $this->form_id, $this->raw() );

		$this->assertSame( array( array( $this->form_id, Screen::TAB_EDITOR ) ), $fired );
	}

	public function test_wynko_form_config_saved_does_not_fire_on_a_refused_save(): void {
		$other = wynko_test_insert_post( array( 'post_type' => 'page' ) );
		$fired = false;
		add_action(
			'wynko_form_config_saved',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		FormEditPage::save( $other, $this->raw() );

		$this->assertFalse( $fired );
	}

	public function test_save_orders_the_fields_by_the_submitted_order(): void {
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id'  => 'f_1',
							'order'     => '5',
							'visible'   => '1',
							'label'     => 'Name',
							'css_class' => '',
						),
						array(
							'field_id'  => 'f_2',
							'order'     => '1',
							'visible'   => '1',
							'label'     => 'Company',
							'css_class' => '',
						),
					),
				)
			)
		);

		$this->assertSame( array( 'f_2', 'f_1' ), array_column( FormData::load( $this->form_id )->field_overrides(), 'field_id' ) );
	}

	public function test_save_forces_a_required_field_visible_even_when_the_post_says_otherwise(): void {
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id'  => 'f_1',
							'order'     => '0',
							'label'     => '',
							'css_class' => '',
						),
						array(
							'field_id'  => 'f_2',
							'order'     => '1',
							'label'     => '',
							'css_class' => '',
						),
					),
				)
			)
		);

		$overrides = FormData::load( $this->form_id )->field_overrides();
		$this->assertTrue( $overrides[0]['visible'], 'f_1 is required and must stay visible' );
		$this->assertFalse( $overrides[1]['visible'] );
	}

	public function test_save_strips_markup_from_a_label_and_cleans_the_css_class(): void {
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id'  => 'f_2',
							'order'     => '0',
							'visible'   => '1',
							'label'     => '<script>alert(1)</script>Company',
							'css_class' => 'wide "evil onclick=x',
						),
					),
				)
			)
		);

		$row = FormData::load( $this->form_id )->field_overrides()[0];
		$this->assertStringNotContainsString( '<', $row['label'] );
		$this->assertStringNotContainsString( '"', $row['css_class'] );
	}

	public function test_save_drops_a_field_id_that_is_not_on_the_list(): void {
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id'  => 'f_smuggled',
							'order'     => '0',
							'visible'   => '1',
							'label'     => '',
							'css_class' => '',
						),
					),
				)
			)
		);

		$this->assertSame( array(), FormData::load( $this->form_id )->field_overrides() );
	}

	public function test_save_stores_messages_and_drops_unknown_slugs(): void {
		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'tab'            => Screen::TAB_MESSAGES,
					'wynko_messages' => array(
						LapostaErrors::SLUG_SUCCESS => 'Check your inbox.',
						'not_a_slug'                => 'nope',
					),
				)
			)
		);

		$messages = FormData::load( $this->form_id )->messages();
		$this->assertSame( 'Check your inbox.', $messages[ LapostaErrors::SLUG_SUCCESS ] );
		$this->assertArrayNotHasKey( 'not_a_slug', $messages );
	}

	public function test_save_keeps_markup_only_in_the_messages_shown_above_the_form(): void {
		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'tab'            => Screen::TAB_MESSAGES,
					'wynko_messages' => array(
						LapostaErrors::SLUG_SUCCESS  => 'Thanks — <a href="https://example.com/next">what happens now</a>.',
						LapostaErrors::SLUG_REQUIRED => 'Fill in <strong>this</strong> field.',
					),
				)
			)
		);

		$messages = FormData::load( $this->form_id )->messages();
		$this->assertStringContainsString( '<a href="https://example.com/next">', $messages[ LapostaErrors::SLUG_SUCCESS ] );
		$this->assertSame( 'Fill in this field.', $messages[ LapostaErrors::SLUG_REQUIRED ] );
	}

	public function test_save_stores_settings_with_checkboxes_as_booleans(): void {
		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'tab'            => Screen::TAB_SETTINGS,
					'wynko_settings' => array(
						'redirect_url'   => 'https://example.org/thanks/',
						'skip_doi'       => '1',
						'terms_required' => '1',
						'terms_text'     => 'I agree',
						'terms_url'      => 'https://example.org/terms/',
					),
				)
			)
		);

		$settings = FormData::load( $this->form_id )->settings();
		$this->assertTrue( $settings['skip_doi'] );
		$this->assertTrue( $settings['terms_required'] );
		$this->assertFalse( $settings['hide_after_submit'] );
		$this->assertSame( 'https://example.org/thanks/', $settings['redirect_url'] );
	}

	public function test_saving_messages_leaves_the_list_and_fields_alone(): void {
		$this->queue_fields();
		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'order'    => 0,
							'field_id' => 'f_2',
							'visible'  => '1',
							'label'    => 'Your company',
						),
					),
				)
			)
		);

		FormEditPage::save(
			$this->form_id,
			array(
				'tab'             => Screen::TAB_MESSAGES,
				'wynko_form_name' => 'Newsletter signup',
				'wynko_messages'  => array( LapostaErrors::SLUG_SUCCESS => 'Check your inbox.' ),
			)
		);

		$form = FormData::load( $this->form_id );
		$this->assertSame( 'list_a', $form->list_id() );
		$this->assertSame( 'Your company', $form->field_overrides()[0]['label'] );
		$this->assertSame( 'Check your inbox.', $form->message( LapostaErrors::SLUG_SUCCESS ) );
	}

	public function test_saving_settings_leaves_the_list_alone(): void {
		$this->queue_fields();
		FormEditPage::save( $this->form_id, $this->raw() );

		FormEditPage::save(
			$this->form_id,
			array(
				'tab'             => Screen::TAB_SETTINGS,
				'wynko_form_name' => 'Newsletter signup',
				'wynko_settings'  => array( 'skip_doi' => '1' ),
			)
		);

		$form = FormData::load( $this->form_id );
		$this->assertSame( 'list_a', $form->list_id() );
		$this->assertTrue( $form->settings()['skip_doi'] );
	}

	public function test_saving_the_editor_leaves_messages_and_settings_alone(): void {
		FormEditPage::save(
			$this->form_id,
			array(
				'tab'            => Screen::TAB_MESSAGES,
				'wynko_messages' => array( LapostaErrors::SLUG_SUCCESS => 'Check your inbox.' ),
			)
		);
		FormEditPage::save(
			$this->form_id,
			array(
				'tab'            => Screen::TAB_SETTINGS,
				'wynko_settings' => array( 'skip_doi' => '1' ),
			)
		);

		$this->queue_fields();
		FormEditPage::save( $this->form_id, $this->raw() );

		$form = FormData::load( $this->form_id );
		$this->assertSame( 'Check your inbox.', $form->message( LapostaErrors::SLUG_SUCCESS ) );
		$this->assertTrue( $form->settings()['skip_doi'] );
	}

	public function test_an_unknown_tab_is_treated_as_the_editor(): void {
		$this->queue_fields();
		FormEditPage::save( $this->form_id, $this->raw( array( 'tab' => 'not_a_tab' ) ) );

		$this->assertSame( 'list_a', FormData::load( $this->form_id )->list_id() );
	}

	public function test_an_unknown_redirect_type_is_rejected(): void {
		FormEditPage::save(
			$this->form_id,
			array(
				'tab'            => Screen::TAB_SETTINGS,
				'wynko_settings' => array(
					'redirect_type'    => 'javascript',
					'redirect_page_id' => '12abc',
				),
			)
		);

		$settings = FormData::load( $this->form_id )->settings();
		$this->assertSame( '', $settings['redirect_type'] );
		$this->assertSame( '12', $settings['redirect_page_id'] );
	}

	public function test_the_saved_redirect_returns_to_the_tab_that_was_saved(): void {
		$url = FormEditPage::saved_redirect_url( $this->form_id, Screen::TAB_SETTINGS );

		$this->assertStringContainsString( 'tab=' . Screen::TAB_SETTINGS, $url );
		$this->assertStringContainsString( 'wynko_form_saved=1', $url );
	}

	public function test_handle_save_refuses_without_the_capability(): void {
		wynko_test_set_can_manage( false );

		$this->expectException( WpDieException::class );
		$this->expectExceptionCode( 403 );

		FormEditPage::handle_save();
	}

	public function test_handle_new_refuses_without_the_capability(): void {
		wynko_test_set_can_manage( false );

		$this->expectException( WpDieException::class );
		$this->expectExceptionCode( 403 );

		FormEditPage::handle_new();
	}

	public function test_a_save_lands_on_the_tab_it_was_heading_for(): void {
		// A tab click saves the tab it is leaving and names its destination, so
		// the redirect must follow the destination rather than the saved tab.
		$this->assertSame(
			Screen::TAB_MESSAGES,
			FormEditPage::destination_tab(
				array( FormEditPage::GOTO_ARG => Screen::TAB_MESSAGES ),
				Screen::TAB_EDITOR
			)
		);
	}

	public function test_an_ordinary_save_stays_on_the_tab_it_saved(): void {
		// current_tab() answers "editor" for anything unknown, so an absent or
		// empty destination must not be resolved through it.
		$this->assertSame(
			Screen::TAB_SETTINGS,
			FormEditPage::destination_tab( array(), Screen::TAB_SETTINGS )
		);
		$this->assertSame(
			Screen::TAB_SETTINGS,
			FormEditPage::destination_tab( array( FormEditPage::GOTO_ARG => '' ), Screen::TAB_SETTINGS )
		);
	}

	public function test_handle_delete_refuses_without_the_capability(): void {
		wynko_test_set_can_manage( false );

		$this->expectException( WpDieException::class );
		$this->expectExceptionCode( 403 );

		FormEditPage::handle_delete();
	}

	public function test_the_new_form_lands_on_the_editor_and_a_failure_on_the_list(): void {
		$this->assertStringContainsString( 'form=' . $this->form_id, FormEditPage::new_redirect_url( $this->form_id ) );
		$this->assertSame( Screen::list_url(), FormEditPage::new_redirect_url( 0 ) );
	}

	public function test_the_new_content_keys_are_stored(): void {
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id'  => 'f_1',
							'order'     => '0',
							'visible'   => '1',
							'label'     => 'First name',
							'help'      => 'As on your card.',
							'value'     => 'Ada',
							'css_class' => 'big',
						),
					),
				)
			)
		);

		$row = FormData::load( $this->form_id )->field_overrides()[0];

		$this->assertSame( 'As on your card.', $row['help'] );
		$this->assertSame( 'Ada', $row['value'] );
		$this->assertSame( 'big', $row['css_class'] );
	}

	public function test_a_default_that_is_still_laposta_s_own_is_not_stored(): void {
		// The editor shows Laposta's value in the box, so an untouched save
		// posts it straight back. Storing it would freeze it; the field must
		// keep following the list.
		wynko_test_queue_response(
			200,
			'{"data":[{"field":{"field_id":"f_1","name":"Country","custom_name":"country","datatype":"text","required":true,"defaultvalue":"Nederland"}}]}'
		);

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id' => 'f_1',
							'order'    => '0',
							'visible'  => '1',
							'value'    => 'Nederland',
						),
					),
				)
			)
		);

		$this->assertSame( '', FormData::load( $this->form_id )->field_overrides()[0]['value'] );
	}

	public function test_a_default_the_admin_typed_over_laposta_s_own_is_stored(): void {
		wynko_test_queue_response(
			200,
			'{"data":[{"field":{"field_id":"f_1","name":"Country","custom_name":"country","datatype":"text","required":true,"defaultvalue":"Nederland"}}]}'
		);

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id' => 'f_1',
							'order'    => '0',
							'visible'  => '1',
							'value'    => 'België',
						),
					),
				)
			)
		);

		$this->assertSame( 'België', FormData::load( $this->form_id )->field_overrides()[0]['value'] );
	}

	public function test_a_pattern_that_does_not_compile_refuses_the_save(): void {
		$this->queue_fields();

		$outcome = FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id' => 'f_1',
							'order'    => '0',
							'visible'  => '1',
							'attrs'    => array( 'pattern' => '[a-z' ),
						),
					),
				)
			)
		);

		$this->assertSame( FormEditPage::SAVE_BAD_PATTERN, $outcome );
		$this->assertSame( array(), FormData::load( $this->form_id )->field_overrides() );
	}

	private function queue_number_field(): void {
		wynko_test_queue_response(
			200,
			'{"data":[{"field":{"field_id":"f_n","name":"Age","custom_name":"age","datatype":"numeric","required":false}}]}'
		);
	}

	private function save_number_default( string $value ): string {
		$this->queue_number_field();

		return FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id' => 'f_n',
							'order'    => '0',
							'visible'  => '1',
							'value'    => $value,
							'attrs'    => array(
								'min' => '10',
								'max' => '100',
							),
						),
					),
				)
			)
		);
	}

	public function test_a_default_value_above_the_max_refuses_the_save(): void {
		$this->assertSame( FormEditPage::SAVE_BAD_DEFAULT, $this->save_number_default( '200' ) );
		$this->assertSame( array(), FormData::load( $this->form_id )->field_overrides() );
	}

	public function test_a_default_value_below_the_min_refuses_the_save(): void {
		$this->assertSame( FormEditPage::SAVE_BAD_DEFAULT, $this->save_number_default( '2' ) );
	}

	public function test_a_default_value_inside_the_bounds_saves(): void {
		$this->assertSame( FormEditPage::SAVE_OK, $this->save_number_default( '50' ) );
		$this->assertSame( '50', FormData::load( $this->form_id )->field_overrides()[0]['value'] );
	}

	public function test_a_pattern_that_compiles_saves(): void {
		$this->queue_fields();

		$outcome = FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id' => 'f_1',
							'order'    => '0',
							'visible'  => '1',
							'attrs'    => array( 'pattern' => '[a-z]+' ),
						),
					),
				)
			)
		);

		$this->assertSame( FormEditPage::SAVE_OK, $outcome );
		$this->assertSame( '[a-z]+', FormData::load( $this->form_id )->field_overrides()[0]['attrs']['pattern'] );
	}

	public function test_a_legacy_mode_survives_an_editor_save(): void {
		// Saving the Editor tab rewrites the rows through normalize_override(),
		// which no longer carries label_mode — so the evidence the derivation
		// reads is destroyed by the first save an admin makes.
		update_post_meta(
			$this->form_id,
			Config::form_meta_key( 'fields' ),
			array(
				array(
					'field_id'   => 'f_1',
					'label_mode' => 'placeholder',
				),
			)
		);
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_fields' => array(
						array(
							'field_id' => 'f_1',
							'order'    => '0',
							'visible'  => '1',
						),
					),
				)
			)
		);

		$this->assertSame( 'placeholder', FormData::load( $this->form_id )->settings()['label_mode'] );
	}

	public function test_the_label_mode_setting_is_stored(): void {
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw( array( 'wynko_settings' => array( 'label_mode' => 'both' ) ) )
		);

		$this->assertSame( 'both', FormData::load( $this->form_id )->settings()['label_mode'] );
	}

	public function test_an_unknown_label_mode_falls_back_to_label(): void {
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw( array( 'wynko_settings' => array( 'label_mode' => 'interpretive-dance' ) ) )
		);

		$this->assertSame( 'label', FormData::load( $this->form_id )->settings()['label_mode'] );
	}

	public function test_a_settings_save_leaves_the_label_mode_alone(): void {
		// The mode is chosen on the Editor tab, so the Settings tab submits no
		// value for it — and must not read that silence as "back to default".
		$this->queue_fields();
		FormEditPage::save(
			$this->form_id,
			$this->raw( array( 'wynko_settings' => array( 'label_mode' => 'placeholder' ) ) )
		);

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'tab'            => Screen::TAB_SETTINGS,
					'wynko_settings' => array( 'redirect_type' => 'url' ),
				)
			)
		);

		$form = FormData::load( $this->form_id );
		$this->assertSame( 'placeholder', $form->settings()['label_mode'] );
		$this->assertSame( 'url', $form->settings()['redirect_type'] );
	}

	public function test_an_editor_save_leaves_the_other_settings_alone(): void {
		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'tab'            => Screen::TAB_SETTINGS,
					'wynko_settings' => array(
						'redirect_type'     => 'url',
						'redirect_url'      => 'https://example.com/thanks',
						'hide_after_submit' => '1',
					),
				)
			)
		);

		$this->queue_fields();
		FormEditPage::save(
			$this->form_id,
			$this->raw( array( 'wynko_settings' => array( 'label_mode' => 'both' ) ) )
		);

		$settings = FormData::load( $this->form_id )->settings();
		$this->assertSame( 'both', $settings['label_mode'] );
		$this->assertSame( 'https://example.com/thanks', $settings['redirect_url'] );
		$this->assertTrue( $settings['hide_after_submit'] );
	}

	public function test_the_button_label_and_class_are_stored_from_the_editor_tab(): void {
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'wynko_button' => array(
						'label'     => '  Join the list  ',
						'css_class' => 'btn btn-primary',
					),
				)
			)
		);

		$this->assertSame(
			array(
				'label'     => 'Join the list',
				'css_class' => 'btn btn-primary',
			),
			FormData::load( $this->form_id )->button()
		);
	}

	public function test_markup_in_the_button_label_is_stripped_on_save(): void {
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw( array( 'wynko_button' => array( 'label' => 'Join <script>alert(1)</script>' ) ) )
		);

		$this->assertSame( 'Join alert(1)', FormData::load( $this->form_id )->button()['label'] );
	}

	public function test_a_button_class_is_reduced_to_what_a_class_attribute_may_hold(): void {
		$this->queue_fields();

		FormEditPage::save(
			$this->form_id,
			$this->raw( array( 'wynko_button' => array( 'css_class' => 'btn "onclick=alert(1)' ) ) )
		);

		$this->assertSame( 'btn onclickalert1', FormData::load( $this->form_id )->button()['css_class'] );
	}

	public function test_a_messages_save_leaves_the_button_alone(): void {
		$this->queue_fields();
		FormEditPage::save( $this->form_id, $this->raw( array( 'wynko_button' => array( 'label' => 'Join' ) ) ) );

		FormEditPage::save(
			$this->form_id,
			$this->raw(
				array(
					'tab'            => Screen::TAB_MESSAGES,
					'wynko_messages' => array( LapostaErrors::SLUG_SUCCESS => 'Welcome' ),
				)
			)
		);

		$this->assertSame( 'Join', FormData::load( $this->form_id )->button()['label'] );
	}
}
