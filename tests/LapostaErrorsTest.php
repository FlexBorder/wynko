<?php
/**
 * Tests for Laposta error classification.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\LapostaErrors;
use PHPUnit\Framework\TestCase;

/** Covers every documented Laposta error code and the fallbacks around them. */
final class LapostaErrorsTest extends TestCase {

	public function codes(): array {
		return array(
			'201 empty'        => array( 201, LapostaErrors::SLUG_REQUIRED ),
			'202 syntax'       => array( 202, LapostaErrors::SLUG_INVALID_VALUE ),
			'203 unknown'      => array( 203, LapostaErrors::SLUG_GENERIC ),
			'204 duplicate'    => array( 204, LapostaErrors::SLUG_DUPLICATE ),
			'205 not a number' => array( 205, LapostaErrors::SLUG_INVALID_VALUE ),
			'206 not boolean'  => array( 206, LapostaErrors::SLUG_INVALID_VALUE ),
			'207 not a date'   => array( 207, LapostaErrors::SLUG_INVALID_VALUE ),
			'208 not an email' => array( 208, LapostaErrors::SLUG_INVALID_EMAIL ),
			'209 not a URL'    => array( 209, LapostaErrors::SLUG_INVALID_VALUE ),
			'210 not JSON'     => array( 210, LapostaErrors::SLUG_GENERIC ),
			'999 other'        => array( 999, LapostaErrors::SLUG_GENERIC ),
		);
	}

	/**
	 * @dataProvider codes
	 */
	public function test_each_documented_code_maps_to_a_slug( int $code, string $expected ): void {
		$this->assertSame( $expected, LapostaErrors::slug_for_code( $code ) );
	}

	public function test_an_unknown_code_falls_back_to_generic(): void {
		$this->assertSame( LapostaErrors::SLUG_GENERIC, LapostaErrors::slug_for_code( 12345 ) );
	}

	public function test_slug_for_error_reads_the_code_out_of_the_payload(): void {
		$this->assertSame(
			LapostaErrors::SLUG_DUPLICATE,
			LapostaErrors::slug_for_error(
				array(
					'type'      => 'invalid_input',
					'message'   => 'Email address already exists',
					'code'      => 204,
					'parameter' => 'email',
				)
			)
		);
	}

	public function test_slug_for_error_accepts_a_numeric_string_code(): void {
		$this->assertSame( LapostaErrors::SLUG_INVALID_EMAIL, LapostaErrors::slug_for_error( array( 'code' => '208' ) ) );
	}

	public function test_slug_for_error_falls_back_when_there_is_no_code(): void {
		$this->assertSame( LapostaErrors::SLUG_GENERIC, LapostaErrors::slug_for_error( array() ) );
		$this->assertSame( LapostaErrors::SLUG_GENERIC, LapostaErrors::slug_for_error( array( 'code' => 'nonsense' ) ) );
	}

	public function test_slugs_lists_every_constant_once(): void {
		$slugs = LapostaErrors::slugs();

		$this->assertCount( 8, $slugs );
		$this->assertSame( $slugs, array_unique( $slugs ) );
		$this->assertContains( LapostaErrors::SLUG_SUCCESS, $slugs );
		$this->assertContains( LapostaErrors::SLUG_TERMS, $slugs );
	}
	public function test_status_of_reads_the_carried_status(): void {
		$this->assertSame( 400, LapostaErrors::status_of( array( 'http_status' => 400 ) ) );
	}

	public function test_status_of_is_zero_when_nothing_was_carried(): void {
		$this->assertSame( 0, LapostaErrors::status_of( array( 'code' => 204 ) ) );
	}

	public function test_a_carried_status_does_not_disturb_classification(): void {
		$this->assertSame(
			LapostaErrors::SLUG_DUPLICATE,
			LapostaErrors::slug_for_error(
				array(
					'http_status' => 400,
					'code'        => 204,
				)
			)
		);
	}
	public function test_an_unknown_parameter_is_field_drift(): void {
		$this->assertTrue(
			LapostaErrors::is_field_drift(
				array(
					'http_status' => 400,
					'code'        => 203,
				)
			)
		);
	}

	public function test_a_400_with_no_code_is_field_drift(): void {
		$this->assertTrue( LapostaErrors::is_field_drift( array( 'http_status' => 400 ) ) );
	}

	public function test_a_400_with_an_unrecognised_code_is_field_drift(): void {
		$this->assertTrue(
			LapostaErrors::is_field_drift(
				array(
					'http_status' => 400,
					'code'        => 777,
				)
			)
		);
	}

	/**
	 * The commonest drift: a required field added in Laposta that the form does
	 * not show. FormValidator has already passed on everything the form does
	 * show, so this cannot be the visitor leaving a box empty.
	 */
	public function test_a_required_field_the_form_does_not_show_is_drift(): void {
		$this->assertTrue(
			LapostaErrors::is_field_drift(
				array(
					'http_status' => 400,
					'code'        => 201,
					'parameter'   => 'birthday',
				),
				array( 'email', 'first_name' )
			)
		);
	}

	public function test_a_required_field_the_form_does_show_is_not_drift(): void {
		$this->assertFalse(
			LapostaErrors::is_field_drift(
				array(
					'http_status' => 400,
					'code'        => 201,
					'parameter'   => 'first_name',
				),
				array( 'email', 'first_name' )
			)
		);
	}

	/** Nothing named means nothing to match against the form, so treat it as drift. */
	public function test_a_required_error_naming_nothing_is_drift(): void {
		$this->assertTrue(
			LapostaErrors::is_field_drift(
				array(
					'http_status' => 400,
					'code'        => 201,
				),
				array( 'email' )
			)
		);
	}

	public function test_an_invalid_email_is_not_drift(): void {
		$this->assertFalse(
			LapostaErrors::is_field_drift(
				array(
					'http_status' => 400,
					'code'        => 208,
				)
			)
		);
	}

	public function test_a_404_is_not_drift(): void {
		$this->assertFalse(
			LapostaErrors::is_field_drift(
				array(
					'http_status' => 404,
					'code'        => 203,
				)
			)
		);
	}
}
