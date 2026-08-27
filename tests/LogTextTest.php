<?php
/**
 * Tests for the log's plain-text rendering.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\LogText;
use PHPUnit\Framework\TestCase;

/** Covers the downloadable file's shape. */
final class LogTextTest extends TestCase {

	/**
	 * Two entries standing in for the stored log.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function fixture(): array {
		return array(
			array(
				'time'    => '2026-08-16 09:30:00',
				'level'   => 'error',
				'message' => 'Sync failed: timeout',
			),
			array(
				'time'    => '2026-08-16 09:00:00',
				'level'   => 'info',
				'message' => 'Connected to the Laposta API.',
			),
		);
	}

	public function test_the_header_precedes_the_entries(): void {
		$text = LogText::format( $this->fixture(), array( 'Site' => 'https://example.test' ) );

		$this->assertStringContainsString( 'Site : https://example.test', $text );
		$this->assertLessThan( strpos( $text, 'Sync failed' ), strpos( $text, 'example.test' ) );
	}

	public function test_every_entry_gets_a_line(): void {
		$text  = LogText::format( $this->fixture(), array() );
		$lines = array_filter( explode( "\n", $text ) );

		$this->assertStringContainsString( '2026-08-16 09:30:00', $text );
		$this->assertStringContainsString( 'ERROR', $text );
		$this->assertStringContainsString( 'Connected to the Laposta API.', $text );
		$this->assertSame( 3, count( $lines ) );
	}

	public function test_the_newest_entry_comes_first(): void {
		$text = LogText::format( $this->fixture(), array() );

		$this->assertLessThan( strpos( $text, '09:00:00' ), strpos( $text, '09:30:00' ) );
	}

	public function test_an_empty_log_says_so(): void {
		$this->assertStringContainsString( '(no entries)', LogText::format( array(), array() ) );
	}

	public function test_header_labels_are_padded_to_one_width(): void {
		$text = LogText::format(
			array(),
			array(
				'PHP'  => '8.3.0',
				'Site' => 'https://example.test',
			)
		);

		$this->assertStringContainsString( 'PHP  : 8.3.0', $text );
		$this->assertStringContainsString( 'Site : https://example.test', $text );
	}

	public function test_the_file_ends_with_a_newline(): void {
		$this->assertStringEndsWith( "\n", LogText::format( $this->fixture(), array() ) );
	}
}
