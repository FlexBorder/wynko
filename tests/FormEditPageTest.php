<?php
/**
 * Tests for the form editor's rendering.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\Forms\FormEditPage;
use Wynko\Admin\Forms\Screen;
use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Forms\Messages;
use Wynko\Support\LapostaErrors;
use PHPUnit\Framework\TestCase;

/** Covers each tab's controls and the locking of required fields. */
final class FormEditPageTest extends TestCase {

	/**
	 * The form under test.
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

	private function bind_list(): void {
		FormData::load( $this->form_id )->save_list_id( 'list_a' );
	}

	private function queue_lists(): void {
		wynko_test_queue_response( 200, '{"data":[{"list":{"list_id":"list_a","name":"Newsletter"}}]}' );
	}

	/**
	 * An account with a genuine choice of list, so nothing is preselected.
	 *
	 * @return void
	 */
	private function queue_two_lists(): void {
		wynko_test_queue_response( 200, '{"data":[{"list":{"list_id":"list_a","name":"Newsletter"}},{"list":{"list_id":"list_b","name":"Customers"}}]}' );
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

	private function render( string $tab = Screen::TAB_EDITOR ): string {
		$_GET['tab'] = $tab;
		ob_start();
		FormEditPage::render( $this->form_id );
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );
		return $html;
	}

	public function test_the_screen_has_a_heading_of_its_own(): void {
		// The form's name is an input, not a heading, so it cannot be the h1 a
		// screen reader announces.
		$html = $this->render( Screen::TAB_MESSAGES );

		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Edit signup form</h1>', $html );
		$this->assertLessThan(
			strpos( $html, 'wynko-form-header' ),
			strpos( $html, '<h1' ),
			'The heading must come before the title field.'
		);
	}

	public function test_the_title_and_shortcode_appear_once_above_the_tabs(): void {
		$html = $this->render( Screen::TAB_MESSAGES );

		$this->assertSame( 1, substr_count( $html, 'name="wynko_form_name"' ) );
		$this->assertStringContainsString( 'wynko-form-header', $html );
		$this->assertStringContainsString( '[wynko_form id="' . $this->form_id . '"]', $html );

		$header = strpos( $html, 'wynko-form-header' );
		$tabs   = strpos( $html, 'nav-tab-wrapper' );
		$this->assertNotFalse( $header );
		$this->assertNotFalse( $tabs );
		$this->assertLessThan( $tabs, $header, 'The header must render before the tab nav.' );
	}

	public function test_it_renders_nothing_without_the_capability(): void {
		wynko_test_set_can_manage( false );

		$this->assertSame( '', $this->render() );
	}

	public function test_it_posts_to_the_save_action_with_a_nonce_and_the_form_id(): void {
		$this->queue_lists();

		$html = $this->render();

		$this->assertStringContainsString( 'name="action" value="' . FormEditPage::ACTION_SAVE . '"', $html );
		$this->assertStringContainsString( 'value="' . wp_create_nonce( FormEditPage::ACTION_SAVE ) . '"', $html );
		$this->assertStringContainsString( 'name="form_id" value="' . $this->form_id . '"', $html );
	}

	public function test_a_save_is_confirmed_the_way_wordpress_confirms_one(): void {
		$_GET[ FormEditPage::SAVED_ARG ] = '1';
		$this->queue_lists();

		$html = $this->render( Screen::TAB_MESSAGES );

		unset( $_GET[ FormEditPage::SAVED_ARG ] );

		$this->assertStringContainsString( 'notice notice-success is-dismissible', $html );
		$this->assertStringContainsString( 'Form saved.', $html );
	}

	public function test_nothing_is_confirmed_without_the_flag(): void {
		$this->queue_lists();

		$this->assertStringNotContainsString( 'Form saved.', $this->render( Screen::TAB_MESSAGES ) );
	}

	public function test_every_tab_link_names_itself_for_the_save_on_the_way(): void {
		$this->queue_lists();

		$html = $this->render();

		$this->assertStringContainsString( 'name="' . FormEditPage::GOTO_ARG . '" value=""', $html );
		foreach ( array_keys( Screen::tabs() ) as $tab ) {
			$this->assertStringContainsString( 'data-tab="' . $tab . '"', $html );
		}
	}

	public function test_it_renders_a_tab_link_per_tab(): void {
		$this->queue_lists();

		$html = $this->render();

		foreach ( array_keys( Screen::tabs() ) as $tab ) {
			$this->assertStringContainsString( Screen::edit_url( $this->form_id, $tab ), $html );
		}
	}

	public function test_the_editor_tab_offers_the_accounts_lists(): void {
		$this->queue_lists();

		$html = $this->render();

		$this->assertStringContainsString( 'name="wynko_list_id"', $html );
		$this->assertStringContainsString( 'value="list_a"', $html );
		$this->assertStringContainsString( 'Newsletter', $html );
	}

	public function test_the_editor_tab_asks_for_a_list_before_showing_fields(): void {
		$this->queue_two_lists();

		$this->assertStringContainsString( 'Choose a list', $this->render() );
	}

	public function test_an_account_with_one_list_has_it_chosen_already(): void {
		// Nothing to choose between, so the editor opens on the only answer
		// rather than on an empty picker and an empty field table.
		$this->assertSame(
			'list_a',
			FormEditPage::preselected_list(
				'',
				array(
					array(
						'value' => 'list_a',
						'label' => 'Newsletter',
					),
				)
			)
		);
	}

	public function test_a_second_list_makes_it_a_choice_again(): void {
		$options = array(
			array(
				'value' => 'list_a',
				'label' => 'Newsletter',
			),
			array(
				'value' => 'list_b',
				'label' => 'Customers',
			),
		);

		$this->assertSame( '', FormEditPage::preselected_list( '', $options ) );
	}

	public function test_a_bound_list_is_never_replaced_by_the_only_one(): void {
		$this->assertSame(
			'list_b',
			FormEditPage::preselected_list(
				'list_b',
				array(
					array(
						'value' => 'list_a',
						'label' => 'Newsletter',
					),
				)
			)
		);
	}

	public function test_a_form_with_no_list_renders_an_empty_slot_and_no_table(): void {
		$this->queue_two_lists();

		$html = $this->render();

		// The container choosing a list fills. It is always present so the
		// picker has somewhere to write, and empty so a form with no list is
		// not offered a layout to arrange before there is anything to arrange.
		$this->assertStringContainsString( '<div class="wynko-fields__slot"></div>', $html );
		$this->assertStringNotContainsString( 'wynko-fields__body', $html );
	}

	public function test_the_edit_form_is_identifiable_apart_from_the_delete_form(): void {
		$this->queue_lists();

		$html = $this->render();

		// What the unsaved-changes guard watches. Without the id it could not
		// tell a save from the delete form's submit, and would warn on both.
		$this->assertStringContainsString( '<form id="wynko-form-edit"', $html );
		$this->assertStringContainsString( 'wynko-form-actions__delete', $html );
	}

	public function test_a_bound_list_links_to_its_page_in_laposta(): void {
		$this->bind_list();
		$this->queue_lists();
		$this->queue_fields();

		$html = $this->render();

		$this->assertStringContainsString( 'listconfig=list_a', $html );
		$this->assertStringContainsString( 'Open this list in Laposta', $html );
	}

	public function test_a_form_with_no_list_offers_no_link_and_no_refresh(): void {
		$this->queue_two_lists();

		$html = $this->render();

		$this->assertStringNotContainsString( 'Open this list in Laposta', $html );
		$this->assertStringContainsString( 'wynko-refresh-fields" disabled="disabled"', $html );
	}

	public function test_placeholder_mode_with_nothing_typed_warns(): void {
		$this->bind_list();
		$this->queue_lists();
		$this->queue_fields();

		$form = FormData::load( $this->form_id );
		$form->save_settings( array( 'label_mode' => 'placeholder' ) );

		$this->assertStringContainsString( 'notice-warning', $this->render() );
	}

	public function test_placeholder_mode_with_one_typed_does_not_warn(): void {
		$this->bind_list();
		$this->queue_lists();
		$this->queue_fields();

		$form = FormData::load( $this->form_id );
		$form->save_settings( array( 'label_mode' => 'placeholder' ) );
		$form->save_field_overrides(
			array(
				array(
					'field_id'    => 'f_1',
					'visible'     => true,
					'placeholder' => 'Your first name',
				),
			)
		);

		$this->assertStringNotContainsString( 'notice-warning', $this->render() );
	}

	public function test_label_mode_never_warns(): void {
		$this->bind_list();
		$this->queue_lists();
		$this->queue_fields();

		$this->assertStringNotContainsString( 'notice-warning', $this->render() );
	}

	public function test_a_bound_list_renders_a_row_per_field(): void {
		$this->bind_list();
		$this->queue_lists();
		$this->queue_fields();

		$html = $this->render();

		$this->assertStringContainsString( 'name="wynko_fields[0][field_id]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[1][field_id]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][order]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][label]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][css_class]"', $html );
	}

	public function test_a_required_field_is_locked_visible_and_explained(): void {
		$this->bind_list();
		$this->queue_lists();
		$this->queue_fields();

		$html = $this->render();

		// The required field submits its visibility as a hidden 1; only the
		// optional one gets a checkbox the admin can clear.
		$this->assertStringContainsString( '<input type="hidden" name="wynko_fields[0][visible]" value="1"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[1][visible]" value="1"', $html );
		$this->assertStringContainsString( 'wynko-badge--required', $html );
	}

	public function test_an_unreachable_field_list_says_so_instead_of_rendering_empty(): void {
		$this->bind_list();
		$this->queue_lists();
		wynko_test_queue_response( 500, '' );

		$this->assertStringContainsString( 'Could not load the fields', $this->render() );
	}

	public function test_the_messages_tab_renders_a_field_per_slug_with_the_default_as_placeholder(): void {
		$this->queue_lists();

		$html = $this->render( Screen::TAB_MESSAGES );

		foreach ( LapostaErrors::slugs() as $slug ) {
			$this->assertStringContainsString( 'name="wynko_messages[' . $slug . ']"', $html );
		}
		$this->assertStringContainsString( Messages::defaults()[ LapostaErrors::SLUG_SUCCESS ], $html );
	}

	public function test_the_messages_tab_shows_a_saved_override_as_the_value(): void {
		FormData::load( $this->form_id )->save_messages(
			array(
				LapostaErrors::SLUG_SUCCESS  => 'Check your inbox.',
				LapostaErrors::SLUG_REQUIRED => 'Fill this in.',
			)
		);
		$this->queue_lists();

		$html = $this->render( Screen::TAB_MESSAGES );

		$this->assertStringContainsString( '>Check your inbox.</textarea>', $html );
		$this->assertStringContainsString( 'value="Fill this in."', $html );
	}

	public function test_the_messages_tab_separates_what_shows_above_the_form_from_what_shows_beside_a_field(): void {
		$this->queue_lists();

		$html = $this->render( Screen::TAB_MESSAGES );

		$this->assertStringContainsString( 'Shown above the form', $html );
		$this->assertStringContainsString( 'Shown beside a field', $html );

		// A message that may carry markup gets a box that can hold a sentence
		// with a link in it; one that lands beside an input does not.
		foreach ( LapostaErrors::slugs() as $slug ) {
			$expected = Messages::allows_html( $slug ) ? '<textarea' : '<input type="text"';
			$this->assertStringContainsString(
				$expected . ' id="wynko-message-' . $slug . '"',
				$html
			);
		}
	}

	public function test_the_settings_tab_renders_every_setting(): void {
		$this->queue_lists();

		$html = $this->render( Screen::TAB_SETTINGS );

		// label_mode belongs to the Editor tab, tested below. skip_doi is
		// disabled, so it submits only when something is stored — its own
		// tests cover both states.
		$exceptions = array( 'label_mode', 'skip_doi' );

		foreach ( array_keys( Config::form_settings_defaults() ) as $key ) {
			if ( in_array( $key, $exceptions, true ) ) {
				continue;
			}
			$this->assertStringContainsString( 'name="wynko_settings[' . $key . ']"', $html );
		}
		$this->assertStringNotContainsString( 'name="wynko_settings[label_mode]"', $html );
	}

	public function test_double_opt_in_is_shown_but_cannot_be_used(): void {
		$this->queue_lists();

		$html = $this->render( Screen::TAB_SETTINGS );

		$this->assertStringContainsString( 'Skip double opt-in for this form', $html );
		$this->assertStringContainsString( 'disabled="disabled"', $html );
		$this->assertStringContainsString( 'paid Laposta plan', $html );
	}

	public function test_a_stored_skip_doi_survives_a_settings_save(): void {
		FormData::load( $this->form_id )->save_settings( array( 'skip_doi' => true ) );
		$this->queue_lists();

		// A disabled checkbox submits nothing, so what is stored has to travel
		// some other way or a save of this tab would silently clear it.
		$html = $this->render( Screen::TAB_SETTINGS );
		$this->assertStringContainsString( '<input type="hidden" name="wynko_settings[skip_doi]" value="1" />', $html );

		FormEditPage::save(
			$this->form_id,
			array(
				'tab'             => Screen::TAB_SETTINGS,
				'wynko_form_name' => 'Newsletter signup',
				'wynko_settings'  => array( 'skip_doi' => '1' ),
			)
		);

		$this->assertTrue( FormData::load( $this->form_id )->settings()['skip_doi'] );
	}

	public function test_the_duplicate_message_is_off_by_default_and_says_what_it_costs(): void {
		$this->queue_lists();

		$html = $this->render( Screen::TAB_SETTINGS );

		$this->assertFalse( FormData::load( $this->form_id )->settings()['reveal_duplicate'] );
		$this->assertStringContainsString( 'name="wynko_settings[reveal_duplicate]"', $html );
		$this->assertStringContainsString( 'check whether a particular address is on your list', $html );
	}

	public function test_the_success_message_is_read_only_while_the_form_redirects(): void {
		FormData::load( $this->form_id )->save_settings(
			array(
				'redirect_type' => 'url',
				'redirect_url'  => 'https://example.com/thanks',
			)
		);

		$html = $this->render( Screen::TAB_MESSAGES );

		$this->assertStringContainsString( 'readonly="readonly"', $html );
		$this->assertStringContainsString( 'Change what happens after a successful signup', $html );
	}

	public function test_the_success_message_is_editable_when_the_form_stays_on_the_page(): void {
		$html = $this->render( Screen::TAB_MESSAGES );

		$this->assertStringNotContainsString( 'readonly="readonly"', $html );
	}

	public function test_a_redirect_to_a_deleted_page_leaves_the_success_message_editable(): void {
		FormData::load( $this->form_id )->save_settings(
			array(
				'redirect_type'    => 'page',
				'redirect_page_id' => '999999',
			)
		);

		// The form falls back to showing the message in place, so greying its
		// box would be untrue.
		$this->assertStringNotContainsString( 'readonly="readonly"', $this->render( Screen::TAB_MESSAGES ) );
	}

	public function test_the_editor_tab_offers_the_label_mode(): void {
		$this->queue_lists();

		$this->assertStringContainsString( 'name="wynko_settings[label_mode]"', $this->render() );
	}

	public function test_the_label_mode_is_offered_before_a_list_is_bound(): void {
		// It renders above the point where an unbound form stops, or the first
		// Editor save of such a form would post no mode at all.
		FormData::load( $this->form_id )->save_list_id( '' );
		$this->queue_lists();

		$this->assertStringContainsString( 'name="wynko_settings[label_mode]"', $this->render() );
	}

	public function test_the_skip_doi_control_explains_what_it_overrides(): void {
		$this->queue_lists();

		// An admin has to know this is a per-form override of a list-level
		// Laposta setting, not a plugin feature.
		$this->assertStringContainsString( 'double opt-in', $this->render( Screen::TAB_SETTINGS ) );
	}

	public function test_the_terms_settings_arrive_hidden_until_the_box_is_ticked(): void {
		$this->queue_lists();

		$this->assertStringContainsString( 'id="wynko-terms-detail" hidden', $this->render( Screen::TAB_SETTINGS ) );
	}

	public function test_the_terms_settings_arrive_visible_once_it_is(): void {
		FormData::load( $this->form_id )->save_settings( array( 'terms_required' => true ) );
		$this->queue_lists();

		$html = $this->render( Screen::TAB_SETTINGS );

		$this->assertStringContainsString( 'id="wynko-terms-detail"', $html );
		$this->assertStringNotContainsString( 'id="wynko-terms-detail" hidden', $html );
	}

	public function test_the_terms_settings_are_nested_inside_the_checkbox_row(): void {
		$this->queue_lists();

		$html = $this->render( Screen::TAB_SETTINGS );

		// One row, not three: the wording and the link are the checkbox's own
		// options rather than settings standing beside it.
		$this->assertSame( 1, substr_count( $html, 'Terms checkbox' ) );
		$this->assertStringContainsString( 'aria-controls="wynko-terms-detail"', $html );
		$this->assertMatchesRegularExpression(
			'/wynko-terms-required.*wynko-subfields.*wynko-terms-text/s',
			$html
		);
	}

	public function test_the_terms_link_offers_a_page_as_well_as_a_url(): void {
		$this->queue_lists();

		$html = $this->render( Screen::TAB_SETTINGS );

		$this->assertStringContainsString( 'name="wynko_settings[terms_link_type]" value="page"', $html );
		$this->assertStringContainsString( 'name="wynko_settings[terms_page_id]"', $html );
	}

	public function test_a_rename_stores_the_new_name(): void {
		$this->assertTrue( FormEditPage::rename( $this->form_id, 'Footer signup' ) );
		$this->assertSame( 'Footer signup', FormData::load( $this->form_id )->name() );
	}

	public function test_a_rename_to_the_same_name_changes_nothing(): void {
		$name = FormData::load( $this->form_id )->name();

		$this->assertFalse( FormEditPage::rename( $this->form_id, $name ) );
	}

	public function test_a_blank_rename_falls_back_to_the_placeholder(): void {
		FormEditPage::rename( $this->form_id, '   ' );

		$this->assertSame( FormData::default_name(), FormData::load( $this->form_id )->name() );
	}

	public function test_renaming_a_form_that_does_not_exist_does_nothing(): void {
		$this->assertFalse( FormEditPage::rename( 99999, 'Anything' ) );
	}

	public function test_a_form_saved_without_a_name_is_named(): void {
		wp_update_post(
			array(
				'ID'         => $this->form_id,
				'post_title' => '',
			)
		);
		$this->queue_lists();
		$this->queue_fields();

		FormEditPage::save( $this->form_id, array( 'tab' => 'editor' ) );

		$this->assertSame( FormData::default_name(), FormData::load( $this->form_id )->name() );
	}
}
