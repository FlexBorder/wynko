<?php
/**
 * Tests for the CycloneDX component reader.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\Sbom;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shapes a CycloneDX document actually arrives in, including the
 * empty one this plugin ships today.
 */
final class SbomTest extends TestCase {

	/**
	 * The documents in sbom/ carry no components — that is the current, correct
	 * answer, and the About tab renders from it rather than from prose.
	 *
	 * @return void
	 */
	public function test_a_document_without_components_yields_nothing(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local fixture file under test; there is no HTTP request here.
		$json = (string) file_get_contents( WYNKO_PATH . 'sbom/composer.cdx.json' );

		$this->assertSame( array(), Sbom::components( $json ) );
	}

	/**
	 * Name, version, and the first licence id are what the About tab shows.
	 *
	 * @return void
	 */
	public function test_components_are_read_with_name_version_and_licence(): void {
		$json = '{"components":[{"name":"acme/widget","version":"1.2.3","licenses":[{"license":{"id":"MIT"}}]}]}';

		$this->assertSame(
			array(
				array(
					'name'    => 'acme/widget',
					'version' => '1.2.3',
					'license' => 'MIT',
				),
			),
			Sbom::components( $json )
		);
	}

	/**
	 * A licence can arrive as an SPDX expression rather than an id.
	 *
	 * @return void
	 */
	public function test_a_licence_expression_is_read_too(): void {
		$json = '{"components":[{"name":"acme/widget","version":"1.0.0","licenses":[{"expression":"MIT OR GPL-2.0-or-later"}]}]}';

		$this->assertSame( 'MIT OR GPL-2.0-or-later', Sbom::components( $json )[0]['license'] );
	}

	/**
	 * Missing fields degrade to empty strings; the caller decides what to print.
	 *
	 * @return void
	 */
	public function test_missing_fields_become_empty_strings(): void {
		$json = '{"components":[{"name":"acme/widget"}]}';

		$this->assertSame(
			array(
				array(
					'name'    => 'acme/widget',
					'version' => '',
					'license' => '',
				),
			),
			Sbom::components( $json )
		);
	}

	/**
	 * Garbage in yields an empty list, never an error — a broken SBOM must not
	 * take the About tab down with it.
	 *
	 * @return void
	 */
	public function test_unparseable_input_yields_nothing(): void {
		$this->assertSame( array(), Sbom::components( 'not json at all' ) );
		$this->assertSame( array(), Sbom::components( '' ) );
		$this->assertSame( array(), Sbom::components( '{"components":"a string"}' ) );
		$this->assertSame( array(), Sbom::components( '[1,2,3]' ) );
	}

	/**
	 * A nameless entry is not a component anyone can act on, so it is dropped.
	 *
	 * @return void
	 */
	public function test_entries_without_a_name_are_dropped(): void {
		$json = '{"components":[{"version":"1.0.0"},{"name":"acme/widget","version":"2.0.0"}]}';

		$components = Sbom::components( $json );

		$this->assertCount( 1, $components );
		$this->assertSame( 'acme/widget', $components[0]['name'] );
	}
}
