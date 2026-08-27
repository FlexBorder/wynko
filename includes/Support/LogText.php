<?php
/**
 * Plain-text rendering of the activity log.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats log entries as the .txt an operator downloads. Pure so the download
 * handler stays a thin capability/nonce/header wrapper that tests can skip.
 */
final class LogText {

	/**
	 * Renders a header block and one line per entry.
	 *
	 * @param array<int,array<string,string>> $entries Newest-first.
	 * @param array<string,string>            $header  Label => value, rendered above the entries.
	 * @return string
	 */
	public static function format( array $entries, array $header ): string {
		$lines = array();

		$width = 0;
		foreach ( array_keys( $header ) as $label ) {
			$width = max( $width, strlen( $label ) );
		}
		foreach ( $header as $label => $value ) {
			$lines[] = str_pad( $label, $width ) . ' : ' . $value;
		}
		$lines[] = '';
		$lines[] = str_repeat( '-', 72 );
		$lines[] = '';

		if ( array() === $entries ) {
			$lines[] = '(no entries)';
		}
		foreach ( $entries as $entry ) {
			$lines[] = self::line( $entry );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Renders one entry as "time  LEVEL    message".
	 *
	 * @param array<string,string> $entry Stored entry.
	 * @return string
	 */
	private static function line( array $entry ): string {
		return sprintf(
			'%s  %s  %s',
			str_pad( (string) ( $entry['time'] ?? '' ), 19 ),
			str_pad( strtoupper( (string) ( $entry['level'] ?? '' ) ), 7 ),
			(string) ( $entry['message'] ?? '' )
		);
	}
}
