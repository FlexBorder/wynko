<?php
/**
 * Architecture test for external URLs in the PHP sources.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Fails the build when an external URL is written into the shipped PHP, so that
 * changing a URL or a link target stays a one-file edit. Only string literals
 * count; prose in a docblock may still name a URL.
 *
 * Scope is every PHP file the archive ships: includes/ and config/ at any
 * depth, plus the root entry points. Test fixtures are excluded because a
 * fixture's whole job is to stand in for API data, URLs included.
 */
final class NoHardcodedUrlsTest extends TestCase {

	/**
	 * Files that hold a URL literal by design.
	 *
	 * @var array<int,string>
	 */
	private const EXEMPT = array(
		// The registry itself: the one place a URL is supposed to be written.
		'config/urls.php',
		// A fake plugin path, defined so static analysis can resolve WYNKO_URL. Never ships.
		'phpstan-bootstrap.php',
	);

	/**
	 * Supplies every shipped PHP file: includes/ and config/ at any depth,
	 * plus the root scripts.
	 *
	 * @return array<string,array{string}>
	 */
	public function source_files(): array {
		$root  = dirname( __DIR__ );
		$files = glob( $root . '/*.php' );
		$files = is_array( $files ) ? $files : array();

		foreach ( array( '/includes', '/config' ) as $dir ) {
			/** @var iterable<\SplFileInfo> $found */
			$found = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $found as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		$cases = array();
		foreach ( $files as $file ) {
			$relative = str_replace( $root . '/', '', $file );
			if ( in_array( $relative, self::EXEMPT, true ) ) {
				continue;
			}
			$cases[ $relative ] = array( $file );
		}
		ksort( $cases );
		return $cases;
	}

	/**
	 * @dataProvider source_files
	 */
	public function test_file_holds_no_url_literal( string $file ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file under test; there is no HTTP request here.
		$source = (string) file_get_contents( $file );

		$found = array();
		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			$is_string = T_CONSTANT_ENCAPSED_STRING === $token[0] || T_ENCAPSED_AND_WHITESPACE === $token[0];
			if ( $is_string && preg_match( '#https?://#', $token[1] ) ) {
				$found[] = trim( $token[1] );
			}
		}

		$this->assertSame(
			array(),
			$found,
			sprintf( '%s hard-codes %s; register it in config/urls.php and read it through Wynko\Urls.', basename( $file ), implode( ', ', $found ) )
		);
	}

	public function test_the_provider_actually_found_files(): void {
		$this->assertNotEmpty( $this->source_files() );
	}
}
