<?php
/**
 * Tests for submission validation.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\Fields;
use Wynko\Support\FormValidator;
use Wynko\Support\LapostaErrors;
use PHPUnit\Framework\TestCase;

/** Covers every ERR_* path and the fully valid submission. */
final class FormValidatorTest extends TestCase {

	private function def( string $custom_name, string $type, bool $required = false, bool $multiple = false, array $options = array() ): array {
		return array(
			'field_id'    => 'f_' . $custom_name,
			'name'        => $custom_name,
			'custom_name' => $custom_name,
			'type'        => $type,
			'required'    => $required,
			'multiple'    => $multiple,
			'options'     => $options,
			'visible'     => true,
			'label'       => $custom_name,
			'css_class'   => '',
		);
	}

	private function defs(): array {
		return array(
			$this->def( 'first_name', Fields::TYPE_TEXT, true ),
			$this->def( 'age', Fields::TYPE_NUMBER ),
			$this->def( 'birthday', Fields::TYPE_DATE ),
			$this->def( 'interest', Fields::TYPE_CHOICE, false, false, array( 'News', 'Offers' ) ),
			$this->def( 'topics', Fields::TYPE_CHOICE, false, true, array( 'A', 'B' ) ),
		);
	}

	private function valid(): array {
		return array(
			FormValidator::KEY_EMAIL => 'visitor@example.org',
			FormValidator::KEY_TERMS => '1',
			'first_name'             => 'Ada',
			'age'                    => '36',
			'birthday'               => '1990-05-04',
			'interest'               => 'News',
			'topics'                 => array( 'A', 'B' ),
		);
	}

	public function test_a_valid_submission_has_no_errors(): void {
		$this->assertSame( array(), FormValidator::validate( $this->defs(), $this->valid(), true ) );
	}

	public function test_a_missing_email_is_required(): void {
		$submitted                             = $this->valid();
		$submitted[ FormValidator::KEY_EMAIL ] = '  ';

		$errors = FormValidator::validate( $this->defs(), $submitted, false );

		$this->assertSame( FormValidator::ERR_REQUIRED, $errors[ FormValidator::KEY_EMAIL ] );
	}

	public function test_a_malformed_email_is_reported(): void {
		$submitted                             = $this->valid();
		$submitted[ FormValidator::KEY_EMAIL ] = 'not-an-email';

		$errors = FormValidator::validate( $this->defs(), $submitted, false );

		$this->assertSame( FormValidator::ERR_INVALID_EMAIL, $errors[ FormValidator::KEY_EMAIL ] );
	}

	public function test_a_missing_required_text_field_is_reported(): void {
		$submitted               = $this->valid();
		$submitted['first_name'] = '';

		$errors = FormValidator::validate( $this->defs(), $submitted, false );

		$this->assertSame( FormValidator::ERR_REQUIRED, $errors['first_name'] );
	}

	public function test_an_absent_optional_field_is_not_an_error(): void {
		$submitted = $this->valid();
		unset( $submitted['age'], $submitted['birthday'], $submitted['interest'], $submitted['topics'] );

		$this->assertSame( array(), FormValidator::validate( $this->defs(), $submitted, false ) );
	}

	public function test_a_non_numeric_number_is_reported(): void {
		$submitted        = $this->valid();
		$submitted['age'] = 'thirty-six';

		$errors = FormValidator::validate( $this->defs(), $submitted, false );

		$this->assertSame( FormValidator::ERR_INVALID_VALUE, $errors['age'] );
	}

	public function test_a_date_outside_the_expected_format_is_reported(): void {
		$submitted             = $this->valid();
		$submitted['birthday'] = '04/05/1990';

		$errors = FormValidator::validate( $this->defs(), $submitted, false );

		$this->assertSame( FormValidator::ERR_INVALID_VALUE, $errors['birthday'] );
	}

	public function test_an_impossible_date_in_the_right_shape_is_reported(): void {
		$submitted             = $this->valid();
		$submitted['birthday'] = '1990-02-31';

		$errors = FormValidator::validate( $this->defs(), $submitted, false );

		$this->assertSame( FormValidator::ERR_INVALID_VALUE, $errors['birthday'] );
	}

	public function test_a_choice_outside_the_options_is_reported(): void {
		$submitted             = $this->valid();
		$submitted['interest'] = 'Something else';

		$errors = FormValidator::validate( $this->defs(), $submitted, false );

		$this->assertSame( FormValidator::ERR_INVALID_VALUE, $errors['interest'] );
	}

	public function test_a_multi_choice_rejects_the_whole_field_when_one_value_is_unknown(): void {
		$submitted           = $this->valid();
		$submitted['topics'] = array( 'A', 'Z' );

		$errors = FormValidator::validate( $this->defs(), $submitted, false );

		$this->assertSame( FormValidator::ERR_INVALID_VALUE, $errors['topics'] );
	}

	public function test_a_multi_choice_rejects_a_scalar_where_an_array_belongs(): void {
		$submitted           = $this->valid();
		$submitted['topics'] = 'A';

		$errors = FormValidator::validate( $this->defs(), $submitted, false );

		$this->assertSame( FormValidator::ERR_INVALID_VALUE, $errors['topics'] );
	}

	public function test_a_required_multi_choice_with_an_empty_array_is_required(): void {
		$defs      = array( $this->def( 'topics', Fields::TYPE_CHOICE, true, true, array( 'A', 'B' ) ) );
		$submitted = array(
			FormValidator::KEY_EMAIL => 'visitor@example.org',
			'topics'                 => array(),
		);

		$errors = FormValidator::validate( $defs, $submitted, false );

		$this->assertSame( FormValidator::ERR_REQUIRED, $errors['topics'] );
	}

	public function test_unchecked_terms_are_reported_when_required(): void {
		$submitted = $this->valid();
		unset( $submitted[ FormValidator::KEY_TERMS ] );

		$errors = FormValidator::validate( $this->defs(), $submitted, true );

		$this->assertSame( FormValidator::ERR_TERMS_UNCHECKED, $errors[ FormValidator::KEY_TERMS ] );
	}

	public function test_omitted_terms_are_fine_when_not_required(): void {
		$submitted = $this->valid();
		unset( $submitted[ FormValidator::KEY_TERMS ] );

		$this->assertSame( array(), FormValidator::validate( $this->defs(), $submitted, false ) );
	}

	public function test_a_hidden_field_is_not_validated(): void {
		// Callers filter to visible fields; an empty def list must validate the
		// email alone rather than inventing requirements.
		$errors = FormValidator::validate( array(), array( FormValidator::KEY_EMAIL => 'visitor@example.org' ), false );

		$this->assertSame( array(), $errors );
	}

	public function test_every_error_code_maps_to_a_message_slug(): void {
		$this->assertSame( LapostaErrors::SLUG_REQUIRED, FormValidator::slug_for( FormValidator::ERR_REQUIRED ) );
		$this->assertSame( LapostaErrors::SLUG_INVALID_EMAIL, FormValidator::slug_for( FormValidator::ERR_INVALID_EMAIL ) );
		$this->assertSame( LapostaErrors::SLUG_INVALID_VALUE, FormValidator::slug_for( FormValidator::ERR_INVALID_VALUE ) );
		$this->assertSame( LapostaErrors::SLUG_TERMS, FormValidator::slug_for( FormValidator::ERR_TERMS_UNCHECKED ) );
		$this->assertSame( LapostaErrors::SLUG_GENERIC, FormValidator::slug_for( 'something else' ) );
	}

	public function test_a_number_outside_the_configured_bounds_is_refused(): void {
		$defs = array(
			array(
				'custom_name' => 'guests',
				'type'        => Fields::TYPE_NUMBER,
				'required'    => false,
				'multiple'    => false,
				'options'     => array(),
				'attrs'       => array(
					'min' => '1',
					'max' => '10',
				),
			),
		);

		// A range slider makes these hard to submit from a browser, which is
		// exactly why the server may not assume they were not.
		$this->assertSame(
			array(),
			FormValidator::validate(
				$defs,
				array(
					'email'  => 'a@b.co',
					'guests' => '5',
				),
				false
			)
		);
		$this->assertSame(
			FormValidator::ERR_INVALID_VALUE,
			FormValidator::validate(
				$defs,
				array(
					'email'  => 'a@b.co',
					'guests' => '11',
				),
				false
			)['guests']
		);
		$this->assertSame(
			FormValidator::ERR_INVALID_VALUE,
			FormValidator::validate(
				$defs,
				array(
					'email'  => 'a@b.co',
					'guests' => '0',
				),
				false
			)['guests']
		);
	}

	public function test_a_date_outside_the_configured_range_is_refused(): void {
		$defs = array(
			array(
				'custom_name' => 'visit',
				'type'        => Fields::TYPE_DATE,
				'required'    => false,
				'multiple'    => false,
				'options'     => array(),
				'attrs'       => array(
					'min' => '2026-01-01',
					'max' => '2026-12-31',
				),
			),
		);

		$this->assertSame(
			array(),
			FormValidator::validate(
				$defs,
				array(
					'email' => 'a@b.co',
					'visit' => '2026-06-15',
				),
				false
			)
		);
		$this->assertSame(
			FormValidator::ERR_INVALID_VALUE,
			FormValidator::validate(
				$defs,
				array(
					'email' => 'a@b.co',
					'visit' => '2025-12-31',
				),
				false
			)['visit']
		);
	}

	/**
	 * A text field carrying the given constraints.
	 *
	 * @param array<string,string> $attrs Configured attributes.
	 * @return array<string,mixed>
	 */
	private function text_field( array $attrs ): array {
		return array(
			'custom_name' => 'nickname',
			'type'        => Fields::TYPE_TEXT,
			'required'    => false,
			'multiple'    => false,
			'options'     => array(),
			'attrs'       => $attrs,
		);
	}

	public function test_a_value_shorter_than_the_minimum_is_refused(): void {
		$errors = FormValidator::validate(
			array( $this->text_field( array( 'minlength' => '3' ) ) ),
			array(
				'email'    => 'a@b.co',
				'nickname' => 'ab',
			),
			false
		);

		$this->assertSame( FormValidator::ERR_INVALID_VALUE, $errors['nickname'] );
	}

	public function test_a_value_meeting_the_minimum_is_accepted(): void {
		$errors = FormValidator::validate(
			array( $this->text_field( array( 'minlength' => '3' ) ) ),
			array(
				'email'    => 'a@b.co',
				'nickname' => 'abc',
			),
			false
		);

		$this->assertSame( array(), $errors );
	}

	public function test_a_value_matching_the_pattern_is_accepted(): void {
		$errors = FormValidator::validate(
			array( $this->text_field( array( 'pattern' => '[A-Z]{2}[0-9]{4}' ) ) ),
			array(
				'email'    => 'a@b.co',
				'nickname' => 'AB1234',
			),
			false
		);

		$this->assertSame( array(), $errors );
	}

	public function test_a_value_failing_the_pattern_is_refused(): void {
		$errors = FormValidator::validate(
			array( $this->text_field( array( 'pattern' => '[A-Z]{2}[0-9]{4}' ) ) ),
			array(
				'email'    => 'a@b.co',
				'nickname' => 'ab1234',
			),
			false
		);

		$this->assertSame( FormValidator::ERR_PATTERN, $errors['nickname'] );
	}

	public function test_a_pattern_that_does_not_compile_refuses_everything(): void {
		$errors = FormValidator::validate(
			array( $this->text_field( array( 'pattern' => '[A-Z' ) ) ),
			array(
				'email'    => 'a@b.co',
				'nickname' => 'anything',
			),
			false
		);

		$this->assertSame( FormValidator::ERR_PATTERN, $errors['nickname'] );
	}

	public function test_text_longer_than_the_configured_maxlength_is_refused(): void {
		$defs = array(
			array(
				'custom_name' => 'note',
				'type'        => Fields::TYPE_TEXT,
				'required'    => false,
				'multiple'    => false,
				'options'     => array(),
				'attrs'       => array( 'maxlength' => '5' ),
			),
		);

		$this->assertSame(
			array(),
			FormValidator::validate(
				$defs,
				array(
					'email' => 'a@b.co',
					'note'  => 'short',
				),
				false
			)
		);
		$this->assertSame(
			FormValidator::ERR_INVALID_VALUE,
			FormValidator::validate(
				$defs,
				array(
					'email' => 'a@b.co',
					'note'  => 'far too long',
				),
				false
			)['note']
		);
	}

	public function test_a_field_with_no_configured_attributes_is_unbounded(): void {
		$defs = array(
			array(
				'custom_name' => 'guests',
				'type'        => Fields::TYPE_NUMBER,
				'required'    => false,
				'multiple'    => false,
				'options'     => array(),
			),
		);

		$this->assertSame(
			array(),
			FormValidator::validate(
				$defs,
				array(
					'email'  => 'a@b.co',
					'guests' => '99999',
				),
				false
			)
		);
	}
}
