<?php
/**
 * Tests for the pure field-definition logic.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\Fields;
use PHPUnit\Framework\TestCase;

/** Covers normalize() and merge_overrides(). */
final class FieldsTest extends TestCase {

	private function decoded(): array {
		return array(
			'data' => array(
				array(
					'field' => array(
						'field_id'         => 'f_1',
						'name'             => 'First name',
						'custom_name'      => 'first_name',
						'datatype'         => 'text',
						'datatype_display' => '',
						'required'         => true,
					),
				),
				array(
					'field' => array(
						'field_id'    => 'f_2',
						'name'        => 'Age',
						'custom_name' => 'age',
						'datatype'    => 'numeric',
						'required'    => false,
					),
				),
				array(
					'field' => array(
						'field_id'    => 'f_3',
						'name'        => 'Birthday',
						'custom_name' => 'birthday',
						'datatype'    => 'date',
						'required'    => false,
					),
				),
				array(
					'field' => array(
						'field_id'         => 'f_4',
						'name'             => 'Interest',
						'custom_name'      => 'interest',
						'datatype'         => 'select_single',
						'datatype_display' => 'radio',
						'required'         => false,
						'options'          => array( 'News', 'Offers' ),
					),
				),
				array(
					'field' => array(
						'field_id'    => 'f_5',
						'name'        => 'Topics',
						'custom_name' => 'topics',
						'datatype'    => 'select_multiple',
						'required'    => false,
						'options'     => array( 'A', 'B' ),
					),
				),
			),
		);
	}

	public function test_normalize_maps_each_datatype_to_a_plugin_type(): void {
		$out = Fields::normalize( $this->decoded() );

		$this->assertCount( 5, $out );
		$this->assertSame( Fields::TYPE_TEXT, $out[0]['type'] );
		$this->assertSame( Fields::TYPE_NUMBER, $out[1]['type'] );
		$this->assertSame( Fields::TYPE_DATE, $out[2]['type'] );
		$this->assertSame( Fields::TYPE_CHOICE, $out[3]['type'] );
		$this->assertSame( Fields::TYPE_CHOICE, $out[4]['type'] );
	}

	public function test_normalize_flags_only_select_multiple_as_multiple(): void {
		$out = Fields::normalize( $this->decoded() );

		$this->assertFalse( $out[3]['multiple'] );
		$this->assertTrue( $out[4]['multiple'] );
	}

	public function test_normalize_carries_options_and_required(): void {
		$out = Fields::normalize( $this->decoded() );

		$this->assertTrue( $out[0]['required'] );
		$this->assertSame( array(), $out[0]['options'] );
		$this->assertSame( array( 'News', 'Offers' ), $out[3]['options'] );
	}

	public function test_normalize_tolerates_a_flat_row_shape(): void {
		$out = Fields::normalize(
			array(
				'data' => array(
					array(
						'field_id'    => 'f_1',
						'name'        => 'Name',
						'custom_name' => 'name',
						'datatype'    => 'text',
					),
				),
			)
		);

		$this->assertSame( 'f_1', $out[0]['field_id'] );
		$this->assertFalse( $out[0]['required'] );
	}

	public function test_normalize_skips_rows_without_an_id_or_custom_name(): void {
		$out = Fields::normalize(
			array(
				'data' => array(
					array( 'field' => array( 'name' => 'No id' ) ),
					array( 'field' => array( 'field_id' => 'f_9' ) ),
					array( 'field' => 'not an array' ),
				),
			)
		);

		$this->assertSame( array(), $out );
	}

	public function test_normalize_falls_back_to_text_for_an_unknown_datatype(): void {
		$out = Fields::normalize(
			array(
				'data' => array(
					array(
						'field' => array(
							'field_id'    => 'f_1',
							'name'        => 'Mystery',
							'custom_name' => 'mystery',
							'datatype'    => 'quantum',
						),
					),
				),
			)
		);

		$this->assertSame( Fields::TYPE_TEXT, $out[0]['type'] );
	}

	public function test_normalize_returns_empty_without_a_data_key(): void {
		$this->assertSame( array(), Fields::normalize( array() ) );
	}

	public function test_normalize_drops_lapostas_labels_pseudo_field(): void {
		$decoded           = $this->decoded();
		$decoded['data'][] = array(
			'field' => array(
				'field_id'    => 'f_labels',
				'name'        => 'Labels',
				'custom_name' => 'labels',
				'datatype'    => 'labels',
				'in_form'     => false,
				'required'    => false,
				'options'     => array( 'gale' ),
			),
		);

		$out = Fields::normalize( $decoded );

		$this->assertCount( 5, $out );
		$this->assertNotContains( 'f_labels', array_column( $out, 'field_id' ) );
	}

	public function test_normalize_keeps_a_custom_field_merely_named_labels(): void {
		$out = Fields::normalize(
			array(
				'data' => array(
					array(
						'field' => array(
							'field_id'    => 'f_1',
							'name'        => 'Labels',
							'custom_name' => 'labels',
							'datatype'    => 'select_single',
							'options'     => array( 'A' ),
						),
					),
				),
			)
		);

		$this->assertCount( 1, $out );
		$this->assertSame( Fields::TYPE_CHOICE, $out[0]['type'] );
	}

	public function test_normalize_carries_the_laposta_default_value(): void {
		$out = Fields::normalize(
			array(
				'data' => array(
					array(
						'field' => array(
							'field_id'     => 'f_1',
							'name'         => 'Country',
							'custom_name'  => 'country',
							'datatype'     => 'text',
							'defaultvalue' => 'Nederland',
						),
					),
					array(
						'field' => array(
							'field_id'    => 'f_2',
							'name'        => 'City',
							'custom_name' => 'city',
							'datatype'    => 'text',
						),
					),
				),
			)
		);

		$this->assertSame( 'Nederland', $out[0]['default'] );
		$this->assertSame( '', $out[1]['default'] );
	}

	public function test_merge_falls_the_value_back_to_lapostas_default(): void {
		$defs = Fields::normalize(
			array(
				'data' => array(
					array(
						'field' => array(
							'field_id'     => 'f_1',
							'name'         => 'Country',
							'custom_name'  => 'country',
							'datatype'     => 'text',
							'defaultvalue' => 'Nederland',
						),
					),
				),
			)
		);

		$this->assertSame( 'Nederland', Fields::merge_overrides( $defs, array() )[0]['value'] );

		$typed = Fields::merge_overrides(
			$defs,
			array(
				array(
					'field_id' => 'f_1',
					'value'    => 'België',
				),
			)
		);

		$this->assertSame( 'België', $typed[0]['value'] );
	}

	public function test_merge_gives_a_date_no_default_value_from_either_side(): void {
		$defs = Fields::normalize(
			array(
				'data' => array(
					array(
						'field' => array(
							'field_id'     => 'f_1',
							'name'         => 'Birthday',
							'custom_name'  => 'birthday',
							'datatype'     => 'date',
							'defaultvalue' => '2026-01-01',
						),
					),
				),
			)
		);

		$merged = Fields::merge_overrides(
			$defs,
			array(
				array(
					'field_id' => 'f_1',
					'value'    => '2020-05-05',
				),
			)
		);

		$this->assertSame( '', $merged[0]['value'] );
	}

	public function test_accepts_default_value_is_false_only_for_a_date(): void {
		$this->assertTrue( Fields::accepts_default_value( Fields::TYPE_TEXT ) );
		$this->assertTrue( Fields::accepts_default_value( Fields::TYPE_NUMBER ) );
		$this->assertTrue( Fields::accepts_default_value( Fields::TYPE_CHOICE ) );
		$this->assertFalse( Fields::accepts_default_value( Fields::TYPE_DATE ) );
	}

	public function test_default_value_error_refuses_a_number_outside_its_bounds(): void {
		$row = array(
			'value' => '200',
			'attrs' => array(
				'min' => '10',
				'max' => '100',
			),
		);

		$this->assertSame( Fields::DEFAULT_ABOVE_MAX, Fields::default_value_error( $row, Fields::TYPE_NUMBER ) );

		$row['value'] = '5';
		$this->assertSame( Fields::DEFAULT_BELOW_MIN, Fields::default_value_error( $row, Fields::TYPE_NUMBER ) );

		$row['value'] = '50';
		$this->assertNull( Fields::default_value_error( $row, Fields::TYPE_NUMBER ) );

		$row['value'] = 'lots';
		$this->assertSame( Fields::DEFAULT_NOT_A_NUMBER, Fields::default_value_error( $row, Fields::TYPE_NUMBER ) );
	}

	public function test_default_value_error_ignores_types_without_numeric_bounds(): void {
		$row = array(
			'value' => 'anything',
			'attrs' => array( 'max' => '3' ),
		);

		$this->assertNull( Fields::default_value_error( $row, Fields::TYPE_TEXT ) );
		$this->assertNull( Fields::default_value_error( array( 'value' => '' ), Fields::TYPE_NUMBER ) );
	}

	public function test_merge_orders_by_the_stored_overrides(): void {
		$defs   = Fields::normalize( $this->decoded() );
		$merged = Fields::merge_overrides(
			$defs,
			array(
				array(
					'field_id'  => 'f_3',
					'visible'   => true,
					'label'     => 'Date of birth',
					'css_class' => 'dob',
				),
				array(
					'field_id'  => 'f_2',
					'visible'   => false,
					'label'     => '',
					'css_class' => '',
				),
			)
		);

		$this->assertSame( array( 'f_3', 'f_2', 'f_1', 'f_4', 'f_5' ), array_column( $merged, 'field_id' ) );
		$this->assertSame( 'Date of birth', $merged[0]['label'] );
		$this->assertSame( 'dob', $merged[0]['css_class'] );
		$this->assertFalse( $merged[1]['visible'] );
	}

	public function test_merge_forces_required_fields_visible(): void {
		$defs   = Fields::normalize( $this->decoded() );
		$merged = Fields::merge_overrides(
			$defs,
			array(
				array(
					'field_id'  => 'f_1',
					'visible'   => false,
					'label'     => '',
					'css_class' => '',
				),
			)
		);

		$this->assertTrue( $merged[0]['visible'] );
	}

	public function test_merge_defaults_a_field_with_no_override_to_visible_and_its_api_name(): void {
		$defs   = Fields::normalize( $this->decoded() );
		$merged = Fields::merge_overrides( $defs, array() );

		$this->assertTrue( $merged[1]['visible'] );
		$this->assertSame( 'Age', $merged[1]['label'] );
		$this->assertSame( '', $merged[1]['css_class'] );
	}

	public function test_merge_drops_an_override_for_a_field_laposta_no_longer_returns(): void {
		$defs   = Fields::normalize( $this->decoded() );
		$merged = Fields::merge_overrides(
			$defs,
			array(
				array(
					'field_id'  => 'f_gone',
					'visible'   => true,
					'label'     => 'Ghost',
					'css_class' => '',
				),
			)
		);

		$this->assertNotContains( 'f_gone', array_column( $merged, 'field_id' ) );
		$this->assertCount( 5, $merged );
	}

	public function test_content_keys_survive_whatever_the_type_is(): void {
		$row = Fields::normalize_override(
			array(
				'field_id'    => 'f_1',
				'label'       => 'First name',
				'css_class'   => 'big',
				'placeholder' => 'Your name',
				'help'        => 'As it appears on your card.',
				'value'       => 'Ada',
			)
		);

		$this->assertSame( 'big', $row['css_class'] );
		$this->assertSame( 'Your name', $row['placeholder'] );
		$this->assertSame( 'As it appears on your card.', $row['help'] );
		$this->assertSame( 'Ada', $row['value'] );
		$this->assertArrayNotHasKey( 'label_mode', $row );
	}

	public function test_a_constraint_is_dropped_when_the_type_does_not_declare_it(): void {
		$defs = array(
			array(
				'field_id'    => 'f_1',
				'name'        => 'Age',
				'custom_name' => 'age',
				'type'        => Fields::TYPE_NUMBER,
				'required'    => false,
				'multiple'    => false,
				'options'     => array(),
			),
		);

		$merged = Fields::merge_overrides(
			$defs,
			array(
				array(
					'field_id' => 'f_1',
					'attrs'    => array(
						'min'       => '1',
						'maxlength' => '9',
					),
				),
			)
		);

		$this->assertSame( array( 'min' => '1' ), $merged[0]['attrs'] );
	}

	public function test_a_pattern_compiles_anchored(): void {
		$this->assertSame( '/\A(?:[a-z]+)\z/u', Fields::compile_pattern( '[a-z]+' ) );
	}

	public function test_a_pattern_that_does_not_compile_is_refused(): void {
		$this->assertNull( Fields::compile_pattern( '[a-z' ) );
		$this->assertNull( Fields::compile_pattern( '*' ) );
		$this->assertNull( Fields::compile_pattern( '' ) );
	}

	public function test_a_pattern_matches_the_whole_value_or_nothing(): void {
		$this->assertTrue( Fields::pattern_matches( '[a-z]+', 'abc' ) );
		$this->assertFalse( Fields::pattern_matches( '[a-z]+', 'abc1' ) );
		$this->assertFalse( Fields::pattern_matches( '[a-z', 'abc' ) );
	}

	public function test_a_pattern_containing_a_slash_still_compiles(): void {
		$this->assertTrue( Fields::pattern_matches( '[0-9]{2}/[0-9]{2}', '01/02' ) );
	}

	public function test_only_a_type_that_takes_a_placeholder_reports_one(): void {
		$this->assertTrue( Fields::accepts_placeholder( Fields::TYPE_TEXT, array() ) );
		$this->assertTrue( Fields::accepts_placeholder( Fields::TYPE_NUMBER, array() ) );
		$this->assertFalse( Fields::accepts_placeholder( Fields::TYPE_DATE, array() ) );
		$this->assertFalse( Fields::accepts_placeholder( Fields::TYPE_CHOICE, array() ) );
		$this->assertFalse(
			Fields::accepts_placeholder( Fields::TYPE_NUMBER, array( 'style' => 'range' ) )
		);
	}

	public function test_a_range_starts_at_the_midpoint_of_its_bounds(): void {
		$this->assertSame( '50', Fields::range_default( array() ) );
		$this->assertSame(
			'15',
			Fields::range_default(
				array(
					'min' => '10',
					'max' => '20',
				)
			)
		);
		$this->assertSame(
			'12.5',
			Fields::range_default(
				array(
					'min' => '10',
					'max' => '15',
				)
			)
		);
	}

	public function test_a_range_whose_maximum_is_below_its_minimum_starts_at_the_minimum(): void {
		$this->assertSame(
			'10',
			Fields::range_default(
				array(
					'min' => '10',
					'max' => '5',
				)
			)
		);
	}
	public function test_a_404_means_the_list_is_gone(): void {
		$this->assertSame( Fields::FETCH_GONE, Fields::classify_fetch_error( 'wynko_status', 404 ) );
	}

	public function test_a_missing_key_is_its_own_reason(): void {
		$this->assertSame( Fields::FETCH_NO_KEY, Fields::classify_fetch_error( 'wynko_no_key', 0 ) );
	}

	public function test_a_transport_failure_is_unreachable(): void {
		$this->assertSame( Fields::FETCH_UNREACHABLE, Fields::classify_fetch_error( 'wynko_http', 0 ) );
	}

	public function test_a_500_is_unreachable_rather_than_gone(): void {
		$this->assertSame( Fields::FETCH_UNREACHABLE, Fields::classify_fetch_error( 'wynko_status', 500 ) );
	}
}
