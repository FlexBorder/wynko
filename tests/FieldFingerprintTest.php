<?php
/**
 * Tests for the field-set identity fingerprint.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\FieldFingerprint;
use PHPUnit\Framework\TestCase;

/** Covers of(). */
final class FieldFingerprintTest extends TestCase {

	private function fields(): array {
		return array(
			array(
				'field_id' => 'f_1',
				'required' => true,
			),
			array(
				'field_id' => 'f_2',
				'required' => false,
			),
		);
	}

	public function test_the_same_set_hashes_the_same_regardless_of_order(): void {
		$forward = FieldFingerprint::of( $this->fields() );
		$reverse = FieldFingerprint::of( array_reverse( $this->fields() ) );

		$this->assertSame( $forward, $reverse );
	}

	public function test_adding_an_optional_field_changes_the_hash(): void {
		$before = FieldFingerprint::of( $this->fields() );
		$after  = FieldFingerprint::of(
			array_merge(
				$this->fields(),
				array(
					array(
						'field_id' => 'f_3',
						'required' => false,
					),
				)
			)
		);

		$this->assertNotSame( $before, $after );
	}

	public function test_adding_a_required_field_changes_the_hash(): void {
		$before = FieldFingerprint::of( $this->fields() );
		$after  = FieldFingerprint::of(
			array_merge(
				$this->fields(),
				array(
					array(
						'field_id' => 'f_3',
						'required' => true,
					),
				)
			)
		);

		$this->assertNotSame( $before, $after );
	}

	public function test_toggling_required_on_an_existing_field_changes_the_hash(): void {
		$before = FieldFingerprint::of( $this->fields() );
		$after  = FieldFingerprint::of(
			array(
				array(
					'field_id' => 'f_1',
					'required' => false,
				),
				array(
					'field_id' => 'f_2',
					'required' => false,
				),
			)
		);

		$this->assertNotSame( $before, $after );
	}

	public function test_an_empty_set_still_hashes_to_something_stable(): void {
		$this->assertSame( FieldFingerprint::of( array() ), FieldFingerprint::of( array() ) );
		$this->assertNotSame( '', FieldFingerprint::of( array() ) );
	}

	public function test_a_row_missing_field_id_is_skipped_without_perturbing_the_rest(): void {
		$with_junk = array_merge( $this->fields(), array( array( 'required' => true ) ) );

		$this->assertSame( FieldFingerprint::of( $this->fields() ), FieldFingerprint::of( $with_junk ) );
	}
}
