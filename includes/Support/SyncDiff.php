<?php
/**
 * What one sync found that the last one did not.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-free set arithmetic over the identifiers a sync fetches.
 *
 * The comparison must be against something durable: Cache::fill() runs precisely
 * when the transient has expired, so diffing against it would report the whole
 * account as new every cache window. The caller stores the last full snapshot in
 * an option instead, replaced rather than accumulated.
 */
final class SyncDiff {

	/**
	 * Identifiers present now that were absent from the last snapshot.
	 *
	 * @param array<int,string> $seen    Identifiers the last sync recorded.
	 * @param array<int,string> $current Identifiers this sync fetched.
	 * @return array<int,string>
	 */
	public static function unseen( array $seen, array $current ): array {
		return array_values( array_diff( $current, $seen ) );
	}

	/**
	 * One key lifted out of each row, skipping rows that do not carry it.
	 *
	 * @param array<int,array<string,mixed>> $rows Normalized API rows.
	 * @param string                         $key  Identifying key.
	 * @return array<int,string>
	 */
	public static function ids( array $rows, string $key ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$id = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
			if ( '' !== $id ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
