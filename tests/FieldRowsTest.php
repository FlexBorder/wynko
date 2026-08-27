<?php
/**
 * Tests for the editor's field rows.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\Forms\FieldRows;
use Wynko\Support\Fields;
use PHPUnit\Framework\TestCase;

/** The editor must tell the truth about what each field accepts. */
final class FieldRowsTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	/**
	 * A merged field definition, as FormData::fields() returns them.
	 *
	 * @param array<string,mixed> $overrides Values to change.
	 * @return array<string,mixed>
	 */
	private function field( array $overrides = array() ): array {
		return array_merge(
			array(
				'field_id'    => 'f_1',
				'name'        => 'First name',
				'custom_name' => 'first_name',
				'type'        => Fields::TYPE_TEXT,
				'required'    => false,
				'multiple'    => false,
				'options'     => array(),
				'visible'     => true,
				'label'       => 'First name',
				'css_class'   => '',
			),
			$overrides
		);
	}

	/**
	 * The stored signup button, as FormData::button() returns it.
	 *
	 * @return array{label:string,css_class:string}
	 */
	private function button(): array {
		return array(
			'label'     => '',
			'css_class' => '',
		);
	}

	public function test_each_type_reads_as_words(): void {
		$this->assertSame( 'Text', FieldRows::type_label( $this->field() ) );
		$this->assertSame( 'Number', FieldRows::type_label( $this->field( array( 'type' => Fields::TYPE_NUMBER ) ) ) );
		$this->assertSame( 'Date (YYYY-MM-DD)', FieldRows::type_label( $this->field( array( 'type' => Fields::TYPE_DATE ) ) ) );
		$this->assertSame(
			'Single choice',
			FieldRows::type_label( $this->field( array( 'type' => Fields::TYPE_CHOICE ) ) )
		);
		$this->assertSame(
			'Multiple choice',
			FieldRows::type_label(
				$this->field(
					array(
						'type'     => Fields::TYPE_CHOICE,
						'multiple' => true,
					)
				)
			)
		);
	}

	/**
	 * The synthetic email definition, as FormData::fields() injects it.
	 *
	 * @param array<string,mixed> $overrides Values to change.
	 * @return array<string,mixed>
	 */
	private function email_field( array $overrides = array() ): array {
		return array_merge(
			Fields::email_definition( 'Email address' ),
			array(
				'visible'   => true,
				'label'     => 'Email address',
				'css_class' => '',
			),
			$overrides
		);
	}

	public function test_the_email_row_can_be_labelled_classed_and_moved(): void {
		// It is a row like any other now: only its required flag is fixed.
		$html = FieldRows::tbody(
			array(
				$this->email_field(
					array(
						'label'     => 'Your email',
						'css_class' => 'wide',
					)
				),
				$this->field(),
			)
		);

		// The plain wynko-row class is what puts it inside jQuery UI sortable's
		// `items` selector; without it the row renders but cannot be dragged.
		$this->assertStringContainsString( 'class="wynko-row wynko-row--email"', $html );
		$this->assertStringContainsString( 'wynko-handle', $html );
		$this->assertStringContainsString( 'value="Your email"', $html );
		$this->assertStringContainsString( 'value="wide"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][field_id]" value="email"', $html );
		$this->assertStringContainsString( 'Move Email address down', $html );
	}

	public function test_the_email_row_offers_the_constraints_its_type_declares(): void {
		// It is typed as text, and a length or pattern on top of the address
		// check FormValidator already runs is meaningful. What it must not get
		// is a numeric bound, which would save and then do nothing.
		$html = FieldRows::tbody( array( $this->email_field() ) );

		$this->assertStringContainsString( '[attrs][maxlength]', $html );
		$this->assertStringContainsString( '[attrs][autocomplete]', $html );
		$this->assertStringNotContainsString( '[attrs][step]', $html );
		$this->assertStringNotContainsString( '[attrs][style]', $html );
	}

	public function test_the_email_row_stays_required(): void {
		$html = FieldRows::tbody( array( $this->email_field() ) );

		$this->assertStringContainsString( 'wynko-badge--required', $html );
		$this->assertStringContainsString( '[visible]" value="1"', $html );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
	}

	public function test_the_email_row_can_sit_anywhere_in_the_order(): void {
		$html = FieldRows::tbody( array( $this->field(), $this->email_field() ) );

		$this->assertGreaterThan(
			strpos( $html, 'first_name' ),
			strpos( $html, 'wynko-row--email' ),
			'Email must render where the admin put it.'
		);
	}

	public function test_a_choice_field_shows_its_options(): void {
		$html = FieldRows::tbody(
			array(
				$this->field(
					array(
						'type'    => Fields::TYPE_CHOICE,
						'options' => array( 'Weekly', 'Monthly' ),
					)
				),
			)
		);

		$this->assertStringContainsString( 'Single choice', $html );
		$this->assertStringContainsString( 'Weekly', $html );
		$this->assertStringContainsString( 'Monthly', $html );
	}

	public function test_a_long_option_list_is_summarized_rather_than_dumped(): void {
		$html = FieldRows::tbody(
			array(
				$this->field(
					array(
						'type'    => Fields::TYPE_CHOICE,
						'options' => array( 'A', 'B', 'C', 'D', 'E', 'F' ),
					)
				),
			)
		);

		$this->assertStringContainsString( 'A, B, C, D, +2 more', $html );
	}

	public function test_a_required_field_gets_a_badge_and_submits_its_visibility(): void {
		$html = FieldRows::tbody( array( $this->field( array( 'required' => true ) ) ) );

		$this->assertStringContainsString( 'wynko-badge--required', $html );
		$this->assertStringContainsString( '[visible]" value="1"', $html );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
	}

	public function test_an_optional_field_offers_a_visibility_checkbox(): void {
		$html = FieldRows::tbody( array( $this->field() ) );

		$this->assertStringContainsString( 'type="checkbox" name="wynko_fields[0][visible]"', $html );
	}

	public function test_label_and_class_inputs_do_not_carry_a_fixed_width_class(): void {
		// regular-text is a hard 25em, which is what pushed these two columns
		// out of the table; the stylesheet sizes them to their cell instead.
		$this->assertStringNotContainsString( 'regular-text', FieldRows::tbody( array( $this->field() ) ) );
	}

	public function test_every_row_offers_a_keyboard_reorder(): void {
		$html = FieldRows::tbody(
			array(
				$this->field(),
				$this->field(
					array(
						'field_id'    => 'f_2',
						'custom_name' => 'company',
						'name'        => 'Company',
					)
				),
			)
		);

		$this->assertSame( 2, substr_count( $html, 'data-direction="up"' ) );
		$this->assertSame( 2, substr_count( $html, 'data-direction="down"' ) );
		$this->assertStringContainsString( 'Move First name up', $html );
		$this->assertStringContainsString( 'Move Company down', $html );
	}

	public function test_the_table_carries_a_type_column(): void {
		$html = FieldRows::table( array( $this->field() ), $this->button() );

		$this->assertStringContainsString( '<th scope="col">Type</th>', $html );
		$this->assertStringNotContainsString( 'widefat fixed', $html );
	}

	public function test_the_row_no_longer_offers_a_label_mode(): void {
		// It is one setting for the whole form now, above the table.
		$this->assertStringNotContainsString( '[label_mode]', FieldRows::tbody( array( $this->field() ) ) );
	}

	public function test_the_panel_names_the_group_each_control_belongs_to(): void {
		$html = FieldRows::tbody( array( $this->field() ) );

		$this->assertStringContainsString( 'What the visitor sees', $html );
		$this->assertStringContainsString( 'What the form accepts', $html );
		$this->assertSame( 2, substr_count( $html, '<legend class="wynko-panel__legend">' ) );
	}

	public function test_every_control_explains_itself_and_is_tied_to_its_hint(): void {
		$html = FieldRows::tbody( array( $this->field( array( 'type' => Fields::TYPE_NUMBER ) ) ) );

		$this->assertStringContainsString( 'id="wynko-field-0-help"', $html );
		$this->assertStringContainsString( 'aria-controls="wynko-field-0-help-hint"', $html );
		$this->assertStringContainsString( 'aria-describedby="wynko-field-0-help-hint"', $html );
		$this->assertStringContainsString( 'id="wynko-field-0-help-hint"', $html );
		$this->assertStringContainsString( 'aria-describedby="wynko-field-0-attr-step-hint"', $html );

		// A hint is a tooltip, not a second copy of the panel on the screen.
		$this->assertSame(
			substr_count( $html, 'class="wynko-hint__toggle"' ),
			substr_count( $html, 'class="wynko-hint__text"' )
		);
		$this->assertStringNotContainsString( 'class="wynko-hint__text" id="wynko-field-0-help-hint" role="tooltip">', $html );
	}

	public function test_a_hint_id_is_the_row_it_belongs_to(): void {
		$html = FieldRows::tbody( array( $this->field(), $this->field( array( 'field_id' => 'f_2' ) ) ) );

		$this->assertStringContainsString( 'aria-controls="wynko-field-1-help-hint"', $html );
		$this->assertSame( 1, substr_count( $html, 'id="wynko-field-0-help-hint"' ) );
	}

	public function test_the_table_no_longer_has_a_css_class_column(): void {
		$this->assertStringNotContainsString( 'wynko-col-class', FieldRows::table( array( $this->field() ), $this->button() ) );
	}

	public function test_every_row_has_a_panel_that_starts_closed(): void {
		$html = FieldRows::tbody( array( $this->field() ) );

		$this->assertStringContainsString( 'aria-expanded="false"', $html );
		$this->assertStringContainsString( 'aria-controls="wynko-panel-0"', $html );
		$this->assertMatchesRegularExpression( '/<tr class="wynko-row__panel" id="wynko-panel-0" hidden/', $html );
	}

	public function test_the_panel_holds_the_content_controls_for_every_type(): void {
		foreach ( array( Fields::TYPE_TEXT, Fields::TYPE_DATE, Fields::TYPE_CHOICE ) as $type ) {
			$html = FieldRows::tbody( array( $this->field( array( 'type' => $type ) ) ) );

			$this->assertStringContainsString( '[css_class]', $html, $type );
			$this->assertStringContainsString( '[help]', $html, $type );
		}
	}

	public function test_only_a_type_that_takes_a_default_value_is_offered_one(): void {
		$this->assertStringContainsString( '[value]', FieldRows::tbody( array( $this->field() ) ) );
		$this->assertStringNotContainsString(
			'[value]',
			FieldRows::tbody( array( $this->field( array( 'type' => Fields::TYPE_DATE ) ) ) )
		);
	}

	public function test_a_numbers_default_value_carries_its_own_bounds(): void {
		$html = FieldRows::tbody(
			array(
				$this->field(
					array(
						'type'  => Fields::TYPE_NUMBER,
						'attrs' => array(
							'min' => '10',
							'max' => '100',
						),
					)
				),
			)
		);

		$this->assertMatchesRegularExpression(
			'/<input type="number" id="wynko-field-0-value"[^>]*min="10"[^>]*max="100"/',
			$html
		);
	}

	public function test_a_laposta_side_default_is_shown_in_the_box(): void {
		$html = FieldRows::tbody(
			array(
				$this->field(
					array(
						'default' => 'Nederland',
						'value'   => 'Nederland',
					)
				),
			)
		);

		// In the box rather than only behind it, so an admin can see what the form
		// fills in. FormEditPage drops it again on save, and the placeholder
		// stays for the emptied box.
		$this->assertMatchesRegularExpression(
			'/<input type="text" id="wynko-field-0-value"[^>]*value="Nederland"[^>]*placeholder="Nederland"/',
			$html
		);
	}

	public function test_the_placeholder_has_a_column_of_its_own(): void {
		$html = FieldRows::table( array( $this->field() ), $this->button() );

		$this->assertStringContainsString( '>Placeholder<', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][placeholder]"', $html );
	}

	public function test_only_a_type_that_takes_a_placeholder_is_offered_one(): void {
		$this->assertStringContainsString( '[placeholder]', FieldRows::tbody( array( $this->field() ) ) );
		$this->assertStringNotContainsString(
			'[placeholder]',
			FieldRows::tbody( array( $this->field( array( 'type' => Fields::TYPE_DATE ) ) ) )
		);
	}

	public function test_a_placeholder_is_read_only_when_the_form_shows_none(): void {
		// Read-only, not absent and not disabled: a disabled input posts
		// nothing, so a save from this state would blank every placeholder.
		$html = FieldRows::tbody( array( $this->field() ), Fields::LABEL_MODE_LABEL );

		$this->assertStringContainsString( 'name="wynko_fields[0][placeholder]"', $html );
		$this->assertStringContainsString( 'readonly="readonly"', $html );
	}

	public function test_a_placeholder_is_editable_on_a_form_that_shows_one(): void {
		foreach ( array( Fields::LABEL_MODE_BOTH, Fields::LABEL_MODE_PLACEHOLDER ) as $mode ) {
			$this->assertDoesNotMatchRegularExpression(
				'/name="wynko_fields\[0\]\[placeholder\]"[^>]*readonly/',
				FieldRows::tbody( array( $this->field() ), $mode )
			);
		}
	}

	public function test_a_text_label_is_read_only_in_placeholder_mode(): void {
		$html = FieldRows::tbody( array( $this->field() ), Fields::LABEL_MODE_PLACEHOLDER );

		$this->assertMatchesRegularExpression( '/name="wynko_fields\[0\]\[label\]"[^>]*readonly/', $html );
	}

	/**
	 * A date takes no placeholder, so its label is the only naming the visitor
	 * gets whatever the mode says.
	 */
	public function test_a_date_label_stays_editable_in_placeholder_mode(): void {
		$html = FieldRows::tbody(
			array( $this->field( array( 'type' => Fields::TYPE_DATE ) ) ),
			Fields::LABEL_MODE_PLACEHOLDER
		);

		$this->assertDoesNotMatchRegularExpression( '/name="wynko_fields\[0\]\[label\]"[^>]*readonly/', $html );
	}

	public function test_a_choice_label_stays_editable_in_placeholder_mode(): void {
		$html = FieldRows::tbody(
			array( $this->field( array( 'type' => Fields::TYPE_CHOICE ) ) ),
			Fields::LABEL_MODE_PLACEHOLDER
		);

		$this->assertDoesNotMatchRegularExpression( '/name="wynko_fields\[0\]\[label\]"[^>]*readonly/', $html );
	}

	public function test_a_label_is_editable_in_the_other_modes(): void {
		foreach ( array( Fields::LABEL_MODE_BOTH, Fields::LABEL_MODE_LABEL ) as $mode ) {
			$this->assertDoesNotMatchRegularExpression(
				'/name="wynko_fields\[0\]\[label\]"[^>]*readonly/',
				FieldRows::tbody( array( $this->field() ), $mode )
			);
		}
	}

	/** Read-only, never disabled: a disabled input would post no label at all. */
	public function test_a_read_only_label_still_posts(): void {
		$html = FieldRows::tbody( array( $this->field() ), Fields::LABEL_MODE_PLACEHOLDER );

		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][label]"', $html );
	}

	public function test_a_row_says_whether_its_type_takes_a_placeholder(): void {
		$this->assertStringContainsString(
			'data-wynko-placeholderable="1"',
			FieldRows::tbody( array( $this->field() ) )
		);
		$this->assertStringContainsString(
			'data-wynko-placeholderable="0"',
			FieldRows::tbody( array( $this->field( array( 'type' => Fields::TYPE_DATE ) ) ) )
		);
	}

	public function test_a_text_field_offers_lengths_a_pattern_and_autofill(): void {
		$html = FieldRows::tbody( array( $this->field() ) );

		$this->assertStringContainsString( 'name="wynko_fields[0][attrs][minlength]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][attrs][maxlength]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][attrs][pattern]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][attrs][title]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][attrs][autocomplete]"', $html );
	}

	public function test_a_number_field_offers_bounds_and_a_slider(): void {
		$html = FieldRows::tbody( array( $this->field( array( 'type' => Fields::TYPE_NUMBER ) ) ) );

		$this->assertStringContainsString( 'name="wynko_fields[0][attrs][min]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][attrs][max]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][attrs][step]"', $html );
		$this->assertStringContainsString( 'name="wynko_fields[0][attrs][style]"', $html );
		$this->assertStringNotContainsString( '[attrs][pattern]', $html );
	}

	public function test_a_date_field_offers_a_range_but_no_step_or_slider(): void {
		$html = FieldRows::tbody( array( $this->field( array( 'type' => Fields::TYPE_DATE ) ) ) );

		$this->assertStringContainsString( 'name="wynko_fields[0][attrs][min]"', $html );
		$this->assertStringNotContainsString( '[attrs][step]', $html );
		$this->assertStringNotContainsString( '[attrs][style]', $html );
	}

	public function test_a_choice_field_offers_no_attributes(): void {
		$html = FieldRows::tbody( array( $this->field( array( 'type' => Fields::TYPE_CHOICE ) ) ) );

		$this->assertStringNotContainsString( '[attrs]', $html );
	}
}
