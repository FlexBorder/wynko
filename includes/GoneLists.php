<?php
/**
 * The removed-list alarm.
 *
 * @package Wynko
 */

namespace Wynko;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports a list that signup forms still point at but Laposta no longer has.
 *
 * A removed list stays removed, so a ledger of what has already been reported
 * keeps every later sync from logging and mailing the same error again. An
 * entry clears when the list reappears or when no published form uses it, which
 * makes a future removal news again.
 */
final class GoneLists {

	/**
	 * Reports every referenced list absent from the index, then prunes the
	 * ledger to the lists that are still both referenced and missing.
	 *
	 * Callers must pass an index that was actually fetched: a failed fetch is not
	 * an empty account, and would otherwise alarm on every list at once.
	 *
	 * @param array<string,string> $index  list_id => name, freshly fetched.
	 * @param array<string,int>    $counts list_id => how many published forms use it.
	 * @return void
	 */
	public static function check( array $index, array $counts ): void {
		$names    = get_option( Config::option_key( 'list_names' ), Config::default_for( 'list_names' ) );
		$names    = is_array( $names ) ? $names : array();
		$reported = self::reported();
		$still    = array();

		foreach ( $counts as $list_id => $forms ) {
			$list_id = (string) $list_id;
			if ( isset( $index[ $list_id ] ) ) {
				continue;
			}

			$still[] = $list_id;
			if ( in_array( $list_id, $reported, true ) ) {
				continue;
			}

			$name = (string) ( $names[ $list_id ] ?? '' );
			Log::error(
				sprintf(
					/* translators: 1: list name or id, 2: number of signup forms. */
					_n(
						'The list "%1$s" no longer exists in Laposta, but %2$d signup form still uses it.',
						'The list "%1$s" no longer exists in Laposta, but %2$d signup forms still use it.',
						$forms,
						'wynko-for-laposta'
					),
					'' !== $name ? $name : $list_id,
					$forms
				)
			);
		}

		update_option( Config::option_key( 'gone_lists' ), $still, false );
	}

	/**
	 * The list ids already reported as removed.
	 *
	 * @return array<int,string>
	 */
	public static function reported(): array {
		$stored = get_option( Config::option_key( 'gone_lists' ), Config::default_for( 'gone_lists' ) );
		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}
}
