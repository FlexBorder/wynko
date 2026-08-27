<?php
/**
 * List index logic.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pure, WordPress-free list logic: normalize the /list response. */
final class Lists {

	/**
	 * Identified, named lists only.
	 *
	 * @param array<string,mixed> $decoded Decoded API response.
	 * @return array<int,array{list_id:string,name:string}>
	 */
	public static function normalize( array $decoded ): array {
		$rows = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		$out  = array();
		foreach ( $rows as $row ) {
			// Laposta wraps each item as { list: {...} }; tolerate a flat shape too.
			$l = ( isset( $row['list'] ) && is_array( $row['list'] ) ) ? $row['list'] : $row;
			if ( ! is_array( $l ) || empty( $l['list_id'] ) || empty( $l['name'] ) ) {
				continue;
			}
			$out[] = array(
				'list_id' => (string) $l['list_id'],
				'name'    => (string) $l['name'],
			);
		}
		return $out;
	}

	/**
	 * The index as list_id => name, for a caller that needs to name a list it
	 * can no longer fetch.
	 *
	 * @param array<int,array{list_id:string,name:string}> $lists Normalized index.
	 * @return array<string,string>
	 */
	public static function names( array $lists ): array {
		$out = array();
		foreach ( $lists as $row ) {
			if ( '' !== $row['list_id'] ) {
				$out[ $row['list_id'] ] = $row['name'];
			}
		}
		return $out;
	}
}
