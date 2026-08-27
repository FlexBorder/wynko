<?php
/**
 * Architecture test cross-checking config/urls.php against readme.txt.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Fails the build when a host registered in config/urls.php — the single
 * source of truth for every external URL the plugin emits — isn't named in
 * readme.txt's "External services" section. WordPress.org
 * review checks that every outside host a plugin reaches is disclosed
 * there; this keeps the two in sync without either being hand-audited
 * against the other.
 */
final class ExternalServicesDisclosedTest extends TestCase {

	/**
	 * Supplies every distinct host referenced by config/urls.php.
	 *
	 * @return array<string,array{string}>
	 */
	public function registered_hosts(): array {
		$urls = require dirname( __DIR__ ) . '/config/urls.php';

		$candidates   = array( $urls['api_base'] ?? '' );
		$candidates[] = array_column( $urls['links'] ?? array(), 'url' );
		$candidates   = array_merge( array( $candidates[0] ), $candidates[1] );

		$hosts = array();
		foreach ( $candidates as $url ) {
			if ( '' === $url ) {
				continue;
			}
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[ $host ] = true;
			}
		}

		$cases = array();
		foreach ( array_keys( $hosts ) as $host ) {
			$cases[ $host ] = array( $host );
		}
		ksort( $cases );
		return $cases;
	}

	/**
	 * @dataProvider registered_hosts
	 */
	public function test_host_is_named_in_the_readme_disclosure_section( string $host ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file under test; there is no HTTP request here.
		$readme  = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		$section = $this->disclosure_section( $readme );

		$this->assertStringContainsString(
			$host,
			$section,
			sprintf(
				'config/urls.php reaches %s, but readme.txt\'s "External services" section does not name it.',
				$host
			)
		);
	}

	public function test_the_provider_actually_found_hosts(): void {
		$this->assertNotEmpty( $this->registered_hosts() );
	}

	/**
	 * Extracts the text between the "External services" heading
	 * and the next "== ... ==" heading.
	 */
	private function disclosure_section( string $readme ): string {
		$found = preg_match(
			'/== External services ==(.*?)\n== /s',
			$readme,
			$matches
		);
		$this->assertSame( 1, $found, 'readme.txt has no "External services" section.' );
		return $matches[1];
	}
}
