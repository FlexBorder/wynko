<?php
/**
 * Tests for the list index logic.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\Lists;
use PHPUnit\Framework\TestCase;

/** Covers normalization of the /list response. */
final class ListsTest extends TestCase {

	public function test_normalize_reads_the_wrapped_shape(): void {
		$out = Lists::normalize(
			array(
				'data' => array(
					array(
						'list' => array(
							'list_id' => 'list_a',
							'name'    => 'Newsletter',
						),
					),
					array(
						'list' => array(
							'list_id' => 'list_b',
							'name'    => 'Customers',
						),
					),
				),
			)
		);
		$this->assertCount( 2, $out );
		$this->assertSame( 'list_a', $out[0]['list_id'] );
		$this->assertSame( 'Newsletter', $out[0]['name'] );
		$this->assertSame( 'Customers', $out[1]['name'] );
	}

	public function test_normalize_reads_the_flat_shape(): void {
		$out = Lists::normalize(
			array(
				'data' => array(
					array(
						'list_id' => 'list_a',
						'name'    => 'Newsletter',
					),
				),
			)
		);
		$this->assertSame( 'list_a', $out[0]['list_id'] );
	}

	public function test_normalize_handles_an_empty_or_missing_data_key(): void {
		$this->assertSame( array(), Lists::normalize( array( 'data' => array() ) ) );
		$this->assertSame( array(), Lists::normalize( array() ) );
	}

	/**
	 * A row without both fields cannot be rendered as a usable option, so it is
	 * dropped rather than shown with an empty label.
	 */
	public function test_normalize_drops_rows_missing_an_id_or_a_name(): void {
		$out = Lists::normalize(
			array(
				'data' => array(
					array( 'list_id' => 'list_a' ),
					array( 'name' => 'Nameless id' ),
					array(
						'list_id' => 'list_c',
						'name'    => 'Keeper',
					),
				),
			)
		);
		$this->assertCount( 1, $out );
		$this->assertSame( 'list_c', $out[0]['list_id'] );
	}
	public function test_names_maps_ids_to_names(): void {
		$this->assertSame(
			array( 'a' => 'A' ),
			Lists::names(
				array(
					array(
						'list_id' => 'a',
						'name'    => 'A',
					),
				)
			)
		);
	}

	public function test_names_skips_a_list_whose_id_is_empty(): void {
		$this->assertSame(
			array(),
			Lists::names(
				array(
					array(
						'list_id' => '',
						'name'    => 'A',
					),
				)
			)
		);
	}
}
