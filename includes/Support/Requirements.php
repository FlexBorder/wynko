<?php
/**
 * Version, module, and byte comparisons for the system report.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns readings into verdicts. Every value is passed in and the wording belongs
 * to the caller; this class only decides which verdict applies.
 */
final class Requirements {

	const STATUS_OK             = 'ok';
	const STATUS_BELOW_ADVISED  = 'below_advised';
	const STATUS_BELOW_REQUIRED = 'below_required';
	const STATUS_UNKNOWN        = 'unknown';
	/** A row that reports a fact rather than a threshold — no verdict to draw. */
	const STATUS_INFO = 'info';

	/**
	 * The verdict for one version reading. An empty threshold is not a failure
	 * — it means nothing was declared to compare against.
	 *
	 * @param string $current  Version in hand.
	 * @param string $required Minimum that works at all, or ''.
	 * @param string $advised  Version we recommend, or ''.
	 * @return string One of self::STATUS_* .
	 */
	public static function classify( string $current, string $required, string $advised ): string {
		if ( '' === trim( $current ) ) {
			return self::STATUS_UNKNOWN;
		}
		if ( '' !== $required && version_compare( $current, $required, '<' ) ) {
			return self::STATUS_BELOW_REQUIRED;
		}
		if ( '' !== $advised && version_compare( $current, $advised, '<' ) ) {
			return self::STATUS_BELOW_ADVISED;
		}
		return self::STATUS_OK;
	}

	/**
	 * The wanted entries absent from the loaded set, in the order they were
	 * wanted.
	 *
	 * @param array<int,string> $wanted Names we would like present.
	 * @param array<int,string> $loaded Names actually present.
	 * @return array<int,string>
	 */
	public static function missing( array $wanted, array $loaded ): array {
		return array_values( array_diff( $wanted, $loaded ) );
	}

	/**
	 * Reads a php.ini shorthand byte value. Returns -1 for unlimited and 0 for
	 * anything unparseable, so a caller can tell "no limit" from "no idea".
	 *
	 * @param string $value Raw ini value, e.g. '256M'.
	 * @return int
	 */
	public static function bytes_from_ini( string $value ): int {
		$value = trim( $value );
		if ( '-1' === $value ) {
			return -1;
		}
		if ( 1 !== preg_match( '/^(\d+)\s*([KMG])?$/i', $value, $match ) ) {
			return 0;
		}

		$bytes  = (int) $match[1];
		$suffix = strtoupper( $match[2] ?? '' );
		if ( 'K' === $suffix ) {
			return $bytes * 1024;
		}
		if ( 'M' === $suffix ) {
			return $bytes * 1024 * 1024;
		}
		if ( 'G' === $suffix ) {
			return $bytes * 1024 * 1024 * 1024;
		}
		return $bytes;
	}

	/**
	 * Human-readable size. Returns '' for the unlimited sentinel so the caller
	 * words it, the same division of labour as classify().
	 *
	 * @param int $bytes Byte count, or -1 for unlimited.
	 * @return string
	 */
	public static function format_bytes( int $bytes ): string {
		if ( $bytes < 0 ) {
			return '';
		}

		$units = array( 'B', 'KB', 'MB', 'GB' );
		$last  = count( $units ) - 1;
		$size  = (float) $bytes;
		$unit  = 0;
		while ( $size >= 1024 && $unit < $last ) {
			$size /= 1024;
			++$unit;
		}

		$rounded = round( $size, 1 );
		$printed = ( floor( $rounded ) === $rounded ) ? (string) (int) $rounded : (string) $rounded;
		return $printed . ' ' . $units[ $unit ];
	}

	/**
	 * Reads a comparable version out of an OpenSSL banner.
	 *
	 * Two shapes reach this: 'OpenSSL 3.0.13 30 Jan 2024' and 'OpenSSL/3.0.13'.
	 * LibreSSL and BoringSSL number their releases on their own scale, so an
	 * unrecognised vendor returns '' rather than a manufactured verdict.
	 *
	 * @param string $banner Raw banner.
	 * @return string Dotted version, or '' when unreadable or not OpenSSL.
	 */
	public static function openssl_version( string $banner ): string {
		if ( false === stripos( $banner, 'openssl' ) ) {
			return '';
		}
		if ( 1 !== preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $banner, $match ) ) {
			return '';
		}

		return $match[1];
	}

	/**
	 * Splits a database server banner into a vendor and a comparable version.
	 * Which vendor it is decides which thresholds apply, so this is a
	 * classification rather than formatting.
	 *
	 * MariaDB reports itself two ways, and the leading 5.5.5 in
	 * '5.5.5-10.4.28-MariaDB' is a compatibility prefix rather than a version.
	 * Strip it, or every MariaDB looks a decade out of date.
	 *
	 * @param string $server_info Raw banner, e.g. from $wpdb->db_server_info().
	 * @return array{name:string,version:string} Empty strings when unreadable.
	 */
	public static function database_server( string $server_info ): array {
		$is_mariadb = ( false !== stripos( $server_info, 'mariadb' ) );
		$readable   = preg_replace( '/^5\.5\.5-/', '', trim( $server_info ) );
		$readable   = is_string( $readable ) ? $readable : '';

		if ( 1 !== preg_match( '/\d+\.\d+(?:\.\d+)?/', $readable, $match ) ) {
			return array(
				'name'    => '',
				'version' => '',
			);
		}

		return array(
			'name'    => $is_mariadb ? 'MariaDB' : 'MySQL',
			'version' => $match[0],
		);
	}
}
