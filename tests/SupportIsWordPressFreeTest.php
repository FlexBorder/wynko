<?php
/**
 * Architecture test for the Support\ namespace.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use PHPUnit\Framework\TestCase;

/** Fails the build when WordPress leaks into the pure layer. */
final class SupportIsWordPressFreeTest extends TestCase {

	private const WORDPRESS_CALL = '/\b(?:__|_e|_x|_n|esc_[a-z_]+|wp_[a-z_]+|(?:get|update|delete|add)_(?:option|site_option|transient)|get_transient|set_transient|add_(?:action|filter)|apply_filters|do_action|current_time|sanitize_[a-z_]+|is_multisite|get_current_blog_id)\s*\(/';

	/**
	 * Supplies every PHP file in includes/Support/.
	 *
	 * @return array<string,array{string}>
	 */
	public function support_files(): array {
		$files = glob( dirname( __DIR__ ) . '/includes/Support/*.php' );
		$cases = array();
		foreach ( $files ? $files : array() as $file ) {
			$cases[ basename( $file ) ] = array( $file );
		}
		return $cases;
	}

	/**
	 * @dataProvider support_files
	 */
	public function test_file_calls_no_wordpress_functions( string $file ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file under test; there is no HTTP request here.
		$source = (string) file_get_contents( $file );

		// The direct-access guard is the one sanctioned WordPress touchpoint.
		$source = str_replace( "if ( ! defined( 'ABSPATH' ) ) {", '', $source );

		$this->assertSame(
			0,
			preg_match( self::WORDPRESS_CALL, $source, $match ),
			sprintf( '%s calls %s; move the WordPress-facing part to Admin\, Api\, or Blocks\.', basename( $file ), $match[0] ?? '' )
		);
	}

	public function test_the_provider_actually_found_files(): void {
		$this->assertNotEmpty( $this->support_files() );
	}
}
