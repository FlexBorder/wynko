<?php
/**
 * Tests for the front-end form markup.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Forms\Button;
use Wynko\Forms\FormData;
use Wynko\Forms\Messages;
use Wynko\Frontend\FormRenderer;
use Wynko\Frontend\FormSubmitHandler;
use Wynko\Support\Fields as FieldData;
use Wynko\Support\LapostaErrors;
use PHPUnit\Framework\TestCase;

/** Covers the markup, the per-type inputs, and the result notice. */
final class FormRendererTest extends TestCase {

	/**
	 * The published form under test.
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
			'{"data":[' .
			'{"field":{"field_id":"f_1","name":"First name","custom_name":"first_name","datatype":"text","required":true}},' .
			'{"field":{"field_id":"f_2","name":"Age","custom_name":"age","datatype":"numeric"}},' .
			'{"field":{"field_id":"f_3","name":"Birthday","custom_name":"birthday","datatype":"date"}},' .
			'{"field":{"field_id":"f_4","name":"Interest","custom_name":"interest","datatype":"select_single","options":["News","Offers"]}},' .
			'{"field":{"field_id":"f_5","name":"Topics","custom_name":"topics","datatype":"select_multiple","options":["A","B"]}}' .
			']}'
		);
	}

	public function test_an_unknown_form_renders_nothing(): void {
		$this->assertSame( '', FormRenderer::render( $this->form_id + 999 ) );
	}

	public function test_an_unpublished_form_renders_nothing(): void {
		$draft = wynko_test_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'draft',
			)
		);

		$this->assertSame( '', FormRenderer::render( $draft ) );
	}

	public function test_it_posts_to_the_submit_action_with_a_form_scoped_nonce(): void {
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );

		$this->assertStringContainsString( 'name="action" value="' . FormSubmitHandler::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="wynko_form_id" value="' . $this->form_id . '"', $html );
		$this->assertStringContainsString( wp_create_nonce( FormSubmitHandler::nonce_action( $this->form_id ) ), $html );
	}

	public function test_it_always_renders_a_required_email_input(): void {
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );

		$this->assertStringContainsString( 'type="email"', $html );
		$this->assertStringContainsString( 'name="wynko_email"', $html );
	}

	public function test_each_type_gets_its_own_input(): void {
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );

		$this->assertStringContainsString( 'name="wynko_field[first_name]" ', $html );
		$this->assertStringContainsString( 'type="number"', $html );
		$this->assertStringContainsString( 'type="date"', $html );
		$this->assertStringContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( 'name="wynko_field[topics][]"', $html );
		$this->assertStringContainsString( 'type="checkbox"', $html );
	}

	public function test_a_required_field_carries_the_required_attribute(): void {
		$this->queue_fields();

		$this->assertStringContainsString( 'required', FormRenderer::render( $this->form_id ) );
	}

	public function test_a_hidden_field_is_not_rendered(): void {
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id'  => 'f_2',
					'visible'   => false,
					'label'     => '',
					'css_class' => '',
				),
			)
		);
		$this->queue_fields();

		$this->assertStringNotContainsString( 'wynko_field[age]', FormRenderer::render( $this->form_id ) );
	}

	public function test_a_custom_label_and_css_class_are_used(): void {
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id'  => 'f_2',
					'visible'   => true,
					'label'     => 'How old are you?',
					'css_class' => 'wynko-age',
				),
			)
		);
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );

		$this->assertStringContainsString( 'How old are you?', $html );
		$this->assertStringContainsString( 'wynko-age', $html );
	}

	public function test_the_terms_checkbox_appears_only_when_required(): void {
		$this->queue_fields();
		$this->assertStringNotContainsString( 'name="wynko_terms"', FormRenderer::render( $this->form_id ) );

		FormData::load( $this->form_id )->save_settings(
			array(
				'terms_required'  => true,
				'terms_text'      => 'I agree to the terms',
				'terms_link_type' => 'url',
				'terms_url'       => 'https://example.org/terms/',
			)
		);
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );
		$this->assertStringContainsString( 'name="wynko_terms"', $html );
		$this->assertStringContainsString( 'I agree to the terms', $html );
		$this->assertStringContainsString( 'https://example.org/terms/', $html );
	}

	public function test_a_standalone_label_carries_the_class_that_puts_it_above_its_field(): void {
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );

		$this->assertStringContainsString( '<label for="wynko-email-1" class="wynko-form__label">', $html );
		// The choice options wrap their own input, so they must not take it.
		$this->assertStringNotContainsString( 'wynko-1-interest-0" class="wynko-form__label"', $html );
	}

	public function test_the_terms_text_can_link_to_a_page_instead_of_a_url(): void {
		$page_id = wynko_test_insert_post(
			array(
				'post_title'  => 'Terms',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		FormData::load( $this->form_id )->save_settings(
			array(
				'terms_required'  => true,
				'terms_text'      => 'I agree to the terms',
				'terms_link_type' => 'page',
				'terms_page_id'   => (string) $page_id,
				'terms_url'       => 'https://example.org/terms/',
			)
		);
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );

		$this->assertStringContainsString( get_permalink( $page_id ), $html );
		$this->assertStringNotContainsString( 'https://example.org/terms/', $html );
	}

	public function test_a_form_with_no_list_tells_an_admin_and_nobody_else(): void {
		FormData::load( $this->form_id )->save_list_id( '' );
		wynko_test_set_can_manage( true );
		$this->assertStringContainsString( 'Wynko', FormRenderer::render( $this->form_id ) );

		wynko_test_set_can_manage( false );
		$this->assertSame( '', FormRenderer::render( $this->form_id ) );
	}

	public function test_a_success_result_shows_the_success_message(): void {
		$token                                 = FormSubmitHandler::store_result(
			array(
				'status'  => FormSubmitHandler::STATUS_SUCCESS,
				'form_id' => $this->form_id,
				'errors'  => array(),
				'values'  => array(),
				'slug'    => LapostaErrors::SLUG_SUCCESS,
			)
		);
		$_GET[ FormSubmitHandler::RESULT_ARG ] = $token;
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );
		unset( $_GET[ FormSubmitHandler::RESULT_ARG ] );

		$this->assertStringContainsString( Messages::defaults()[ LapostaErrors::SLUG_SUCCESS ], $html );
	}

	public function test_hide_after_submit_drops_the_form_on_success(): void {
		FormData::load( $this->form_id )->save_settings( array( 'hide_after_submit' => true ) );
		$_GET[ FormSubmitHandler::RESULT_ARG ] = FormSubmitHandler::store_result(
			array(
				'status'  => FormSubmitHandler::STATUS_SUCCESS,
				'form_id' => $this->form_id,
				'errors'  => array(),
				'values'  => array(),
				'slug'    => LapostaErrors::SLUG_SUCCESS,
			)
		);

		$html = FormRenderer::render( $this->form_id );
		unset( $_GET[ FormSubmitHandler::RESULT_ARG ] );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertSame( 0, wynko_test_http_calls() );
	}

	public function test_a_validation_failure_shows_per_field_errors_and_keeps_the_values(): void {
		$_GET[ FormSubmitHandler::RESULT_ARG ] = FormSubmitHandler::store_result(
			array(
				'status'  => FormSubmitHandler::STATUS_INVALID,
				'form_id' => $this->form_id,
				'errors'  => array( 'email' => LapostaErrors::SLUG_INVALID_EMAIL ),
				'values'  => array(
					'email'  => 'nope',
					'fields' => array( 'first_name' => 'Ada' ),
				),
				'slug'    => LapostaErrors::SLUG_GENERIC,
			)
		);
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );
		unset( $_GET[ FormSubmitHandler::RESULT_ARG ] );

		$this->assertStringContainsString( Messages::defaults()[ LapostaErrors::SLUG_INVALID_EMAIL ], $html );
		$this->assertStringContainsString( 'value="nope"', $html );
		$this->assertStringContainsString( 'value="Ada"', $html );
	}

	public function test_a_result_for_another_form_is_ignored(): void {
		$other                                 = wynko_test_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
		$_GET[ FormSubmitHandler::RESULT_ARG ] = FormSubmitHandler::store_result(
			array(
				'status'  => FormSubmitHandler::STATUS_SUCCESS,
				'form_id' => $other,
				'errors'  => array(),
				'values'  => array(),
				'slug'    => LapostaErrors::SLUG_SUCCESS,
			)
		);
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );
		unset( $_GET[ FormSubmitHandler::RESULT_ARG ] );

		$this->assertStringNotContainsString( Messages::defaults()[ LapostaErrors::SLUG_SUCCESS ], $html );
	}

	public function test_rendering_with_an_explicit_result_needs_no_url_token(): void {
		$this->queue_fields();

		// The REST path has its outcome in hand, so it renders without minting
		// or consuming a one-shot transient at all.
		$html = FormRenderer::render_with_result(
			$this->form_id,
			array(
				'status'  => FormSubmitHandler::STATUS_SUCCESS,
				'form_id' => $this->form_id,
				'errors'  => array(),
				'values'  => array(),
				'slug'    => LapostaErrors::SLUG_SUCCESS,
			)
		);

		$this->assertStringContainsString( 'wynko-form__notice--success', $html );
		$this->assertStringContainsString( Messages::defaults()[ LapostaErrors::SLUG_SUCCESS ], $html );
	}

	public function test_rendering_without_a_result_shows_a_clean_form(): void {
		$this->queue_fields();

		$html = FormRenderer::render_with_result( $this->form_id, null );

		$this->assertStringNotContainsString( 'wynko-form__notice', $html );
		$this->assertStringContainsString( '<form', $html );
	}

	public function test_the_form_defers_validation_wording_to_the_server(): void {
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );

		// novalidate stops the browser showing its own wording before our
		// request is ever made; the semantic attributes stay for assistive
		// technology and are still mirrored by FormValidator server-side.
		$this->assertStringContainsString( 'novalidate="novalidate"', $html );
		$this->assertStringContainsString( 'required="required"', $html );
		$this->assertStringContainsString( 'type="email"', $html );
	}

	public function test_plain_label_mode_emits_no_placeholder(): void {
		update_post_meta( $this->form_id, '_wynko_settings', array( 'label_mode' => FieldData::LABEL_MODE_LABEL ) );
		$this->queue_fields();

		$this->assertStringNotContainsString( 'placeholder=', FormRenderer::render( $this->form_id ) );
	}

	/**
	 * Renders the form with one override applied to the field it names, and
	 * optionally a form setting changed.
	 *
	 * @param array<string,mixed> $override Override row, needing a field_id.
	 * @param array<string,mixed> $settings Form settings to store first.
	 * @return string
	 */
	private function render_with_override( array $override, array $settings = array() ): string {
		if ( array() !== $settings ) {
			FormData::load( $this->form_id )->save_settings( $settings );
		}
		FormData::load( $this->form_id )->save_field_overrides(
			array( array_merge( array( 'visible' => true ), $override ) )
		);
		$this->queue_fields();

		return FormRenderer::render( $this->form_id );
	}

	public function test_a_date_field_gets_no_placeholder(): void {
		// f_3 is the date field. "Label + placeholder" would give every other
		// field one; a date input takes none, per the HTML standard.
		$html = $this->render_with_override(
			array(
				'field_id'    => 'f_3',
				'placeholder' => 'YYYY-MM-DD',
			),
			array( 'label_mode' => FieldData::LABEL_MODE_BOTH )
		);

		$this->assertStringNotContainsString( 'placeholder="YYYY-MM-DD"', $html );
	}

	public function test_a_range_field_is_never_required_in_markup(): void {
		// f_1 is the required text field; f_2 is the number one. A range takes
		// no required attribute — it always has a value.
		$html = $this->render_with_override(
			array(
				'field_id' => 'f_2',
				'attrs'    => array( 'style' => 'range' ),
			)
		);

		$this->assertStringContainsString( 'type="range"', $html );
		$this->assertStringNotContainsString( 'name="wynko_field[age]" value="" required', $html );
	}

	public function test_a_range_field_shows_the_number_it_is_on(): void {
		// A thumb's position is not a number, so the slider says which one it
		// posts. The server writes the starting value, which with no bounds and
		// no default is the midpoint of the standard's own 0 to 100.
		$html = $this->render_with_override(
			array(
				'field_id' => 'f_2',
				'attrs'    => array( 'style' => 'range' ),
			)
		);

		$this->assertStringContainsString( 'value="50"', $html );
		$this->assertStringContainsString( '<output class="wynko-form__range-value" for="wynko-1-age">50</output>', $html );
	}

	public function test_only_a_range_gets_that_number(): void {
		$html = $this->render_with_override( array( 'field_id' => 'f_2' ) );

		$this->assertStringNotContainsString( 'wynko-form__range-value', $html );
	}

	public function test_a_field_that_cannot_take_a_placeholder_keeps_a_visible_label(): void {
		$html = $this->render_with_override(
			array( 'field_id' => 'f_3' ),
			array( 'label_mode' => FieldData::LABEL_MODE_PLACEHOLDER )
		);

		$this->assertStringContainsString( '<label for="wynko-1-birthday" class="wynko-form__label">Birthday</label>', $html );
	}

	public function test_a_field_that_can_take_a_placeholder_hides_its_label(): void {
		$html = $this->render_with_override(
			array(
				'field_id'    => 'f_1',
				'label'       => 'First name',
				'placeholder' => 'Ada',
			),
			array( 'label_mode' => FieldData::LABEL_MODE_PLACEHOLDER )
		);

		$this->assertStringContainsString( 'class="wynko-form__label screen-reader-text">First name</label>', $html );
		$this->assertStringContainsString( 'placeholder="Ada"', $html );
	}

	public function test_label_and_placeholder_mode_shows_both(): void {
		$html = $this->render_with_override(
			array(
				'field_id'    => 'f_1',
				'label'       => 'First name',
				'placeholder' => 'Ada',
			),
			array( 'label_mode' => FieldData::LABEL_MODE_BOTH )
		);

		$this->assertStringContainsString( '<label for="wynko-1-first_name" class="wynko-form__label">First name</label>', $html );
		$this->assertStringContainsString( 'placeholder="Ada"', $html );
	}

	public function test_help_text_is_tied_to_its_input(): void {
		$html = $this->render_with_override(
			array(
				'field_id' => 'f_1',
				'help'     => 'As on your card.',
			)
		);

		$this->assertMatchesRegularExpression( '/aria-describedby="[^"]*wynko-1-first_name-help"/', $html );
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
		$this->assertStringContainsString( 'As on your card.', $html );
	}

	public function test_help_text_hangs_off_its_own_button(): void {
		// The wrapper is what the tooltip is positioned against, so the bubble
		// lands beneath the (?) that opened it rather than under the label.
		$html = $this->render_with_override(
			array(
				'field_id' => 'f_1',
				'help'     => 'As on your card.',
			)
		);

		$this->assertMatchesRegularExpression(
			'/<span class="wynko-form__help-wrap"><button[^>]*class="wynko-form__help-toggle"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<span class="wynko-form__help" id="wynko-1-first_name-help" role="tooltip" hidden[^>]*>As on your card\.<\/span><\/span>/',
			$html
		);
	}

	public function test_a_default_value_prefills_the_input(): void {
		$html = $this->render_with_override(
			array(
				'field_id' => 'f_1',
				'value'    => 'Ada',
			)
		);

		$this->assertStringContainsString( 'name="wynko_field[first_name]" value="Ada"', $html );
	}

	/**
	 * Renders the form as an invalid submission just came back.
	 *
	 * @param array<string,string> $errors Field key => message slug.
	 * @return string
	 */
	private function render_with_errors( array $errors ): string {
		$_GET[ FormSubmitHandler::RESULT_ARG ] = FormSubmitHandler::store_result(
			array(
				'status'  => FormSubmitHandler::STATUS_INVALID,
				'form_id' => $this->form_id,
				'errors'  => $errors,
				'values'  => array(
					'email'  => 'nope',
					'fields' => array(),
				),
				'slug'    => LapostaErrors::SLUG_GENERIC,
			)
		);
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );
		unset( $_GET[ FormSubmitHandler::RESULT_ARG ] );

		return $html;
	}

	public function test_an_error_reads_before_the_input_it_belongs_to(): void {
		// In the flow and above the control, per the W3C design system: a
		// message that overlays what follows it hides the next field, and one
		// below the input pushes the control out from under the visitor.
		$html = $this->render_with_errors( array( 'email' => LapostaErrors::SLUG_INVALID_EMAIL ) );

		$this->assertMatchesRegularExpression(
			'/<\/label><span class="wynko-form__error" id="wynko-email-1-error"[^>]*>.*?<\/span><input type="email"/s',
			$html
		);
	}

	public function test_an_error_says_it_is_one_to_a_screen_reader(): void {
		$html = $this->render_with_errors( array( 'email' => LapostaErrors::SLUG_INVALID_EMAIL ) );

		$this->assertStringContainsString( '<span class="screen-reader-text">Error:</span>', $html );
	}

	public function test_an_errored_field_marks_its_wrapper(): void {
		$html = $this->render_with_errors( array( 'email' => LapostaErrors::SLUG_INVALID_EMAIL ) );

		$this->assertMatchesRegularExpression( '/<p class="[^"]*wynko-form__field--error"/', $html );
	}

	public function test_a_pattern_failure_reads_the_description_the_admin_wrote(): void {
		// f_1 is the text field. The description is what tells a visitor what
		// would be accepted; "Please check this value" cannot.
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id' => 'f_1',
					'visible'  => true,
					'attrs'    => array(
						'pattern' => '[0-9]{4}',
						'title'   => 'Four digits, like 1234.',
					),
				),
			)
		);

		$html = $this->render_with_errors( array( 'first_name' => LapostaErrors::SLUG_PATTERN ) );

		$this->assertStringContainsString( 'Four digits, like 1234.', $html );
	}

	public function test_a_pattern_failure_with_no_description_falls_back_to_the_wording(): void {
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id' => 'f_1',
					'visible'  => true,
					'attrs'    => array( 'pattern' => '[0-9]{4}' ),
				),
			)
		);

		$html = $this->render_with_errors( array( 'first_name' => LapostaErrors::SLUG_PATTERN ) );

		$this->assertStringContainsString(
			Messages::defaults()[ LapostaErrors::SLUG_PATTERN ],
			$html
		);
	}

	public function test_a_choice_group_reads_its_error_before_its_options(): void {
		$html = $this->render_with_errors( array( 'interest' => LapostaErrors::SLUG_INVALID_VALUE ) );

		$this->assertMatchesRegularExpression( '/<\/legend><span class="wynko-form__error"/', $html );
	}

	public function test_the_terms_checkbox_reads_its_error_before_the_box(): void {
		FormData::load( $this->form_id )->save_settings(
			array(
				'terms_required' => true,
				'terms_text'     => 'I agree to the terms',
			)
		);

		$html = $this->render_with_errors( array( 'terms' => LapostaErrors::SLUG_INVALID_VALUE ) );

		$this->assertMatchesRegularExpression(
			'/<p class="[^"]*--terms[^"]*"><span class="wynko-form__error"/',
			$html
		);
	}

	public function test_an_errored_input_says_so_and_points_at_the_message(): void {
		$html = $this->render_with_errors( array( 'email' => LapostaErrors::SLUG_INVALID_EMAIL ) );

		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertMatchesRegularExpression( '/aria-describedby="[^"]*wynko-email-1-error"/', $html );
	}

	public function test_an_errored_choice_field_is_marked_and_focusable(): void {
		// f_4 is the single-choice field. Its error must be reachable the same
		// way a text field's is: form.js focuses [aria-invalid="true"] when a
		// submission comes back invalid, and a group that carries neither
		// attribute leaves the visitor looking at nothing.
		$_GET[ FormSubmitHandler::RESULT_ARG ] = FormSubmitHandler::store_result(
			array(
				'status'  => FormSubmitHandler::STATUS_INVALID,
				'form_id' => $this->form_id,
				'errors'  => array( 'interest' => LapostaErrors::SLUG_INVALID_VALUE ),
				'values'  => array(
					'email'  => 'a@b.co',
					'fields' => array(),
				),
				'slug'    => LapostaErrors::SLUG_GENERIC,
			)
		);
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );
		unset( $_GET[ FormSubmitHandler::RESULT_ARG ] );

		$this->assertMatchesRegularExpression( '/<fieldset[^>]*aria-invalid="true"/', $html );
		$this->assertMatchesRegularExpression( '/<fieldset[^>]*aria-describedby="[^"]*wynko-1-interest-error"/', $html );
	}

	public function test_a_choice_field_ties_its_help_text_to_the_group(): void {
		$html = $this->render_with_override(
			array(
				'field_id' => 'f_4',
				'help'     => 'Pick one.',
			)
		);

		$this->assertMatchesRegularExpression( '/<fieldset[^>]*aria-describedby="[^"]*wynko-1-interest-help"/', $html );
	}

	public function test_the_localized_config_carries_a_rest_nonce(): void {
		$data = FormRenderer::script_data();

		$this->assertArrayHasKey( 'restRoot', $data );
		$this->assertArrayHasKey( 'restNonce', $data );
		$this->assertNotSame( '', $data['restNonce'] );
	}

	public function test_the_submit_button_carries_the_built_in_wording_by_default(): void {
		$this->queue_fields();

		$html = FormRenderer::render( $this->form_id );

		$this->assertStringContainsString(
			'<button type="submit" class="wynko-form__submit">' . Button::default_label() . '</button>',
			$html
		);
	}

	public function test_the_configured_wording_and_class_reach_the_button(): void {
		$this->queue_fields();
		FormData::load( $this->form_id )->save_button(
			array(
				'label'     => 'Join the list',
				'css_class' => 'btn btn-primary',
			)
		);

		$html = FormRenderer::render( $this->form_id );

		$this->assertStringContainsString(
			'<button type="submit" class="wynko-form__submit btn btn-primary">Join the list</button>',
			$html
		);
	}
}
