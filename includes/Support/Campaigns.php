<?php
/**
 * Campaign list logic.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pure, WordPress-free campaign logic: normalize, sort, diff. */
final class Campaigns {

	/**
	 * The campaign's send date, preferring each field's RFC3339 twin, or ''
	 * when it has none. Deliberately excludes modified/created: only sent
	 * campaigns reach the cache, and a modification date rendered as "date
	 * sent" would be wrong rather than merely imprecise.
	 *
	 * @param array<string,mixed> $c Raw campaign.
	 * @return string
	 */
	private static function sent_at( array $c ): string {
		foreach ( array( 'delivery_started', 'delivery_ended', 'delivery_requested' ) as $field ) {
			foreach ( array( $field . '_iso', $field ) as $key ) {
				if ( ! empty( $c[ $key ] ) && is_scalar( $c[ $key ] ) ) {
					return (string) $c[ $key ];
				}
			}
		}
		return '';
	}

	/**
	 * Recipient list identifiers, from either shape the API may produce: a map
	 * of list id => segment ids, or a plain list of ids. Anything else yields
	 * an empty array — a campaign whose recipients we cannot parse must match
	 * no filter rather than every filter.
	 *
	 * @param mixed $value Raw list_ids value.
	 * @return array<int,string>
	 */
	private static function list_ids( $value ): array {
		if ( ! is_array( $value ) || array() === $value ) {
			return array();
		}
		// Not array_is_list(): that is PHP 8.1 and this plugin supports 8.0.
		$ids = array_values( $value ) === $value ? $value : array_keys( $value );
		$out = array();
		foreach ( $ids as $id ) {
			if ( is_string( $id ) || is_int( $id ) ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}

	/**
	 * The Unix timestamp of a campaign's sent date, 0 when it has none or the
	 * value will not parse. strtotime() rather than a string comparison: the
	 * API mixes '2026-01-01 10:00:00' with its RFC3339 twin, where 'T' would
	 * sort above ' '.
	 *
	 * @param array<string,mixed> $c Normalized campaign.
	 * @return int
	 */
	private static function timestamp( array $c ): int {
		$value = isset( $c['sent_at'] ) ? (string) $c['sent_at'] : '';
		if ( '' === $value ) {
			return 0;
		}
		$ts = strtotime( $value );
		return is_int( $ts ) ? $ts : 0;
	}

	/**
	 * The text a sort key compares, '' when the campaign has none.
	 *
	 * @param array<string,mixed> $c        Normalized campaign.
	 * @param string              $order_by Sort key.
	 * @return string
	 */
	private static function text( array $c, string $order_by ): string {
		$field = ( 'name' === $order_by ) ? 'name' : 'subject';
		return trim( isset( $c[ $field ] ) ? (string) $c[ $field ] : '' );
	}

	/**
	 * Whether a campaign has no value for the given sort key.
	 *
	 * @param array<string,mixed> $c        Normalized campaign.
	 * @param string              $order_by Sort key.
	 * @return bool
	 */
	private static function is_blank( array $c, string $order_by ): bool {
		return ( 'date' === $order_by ) ? 0 === self::timestamp( $c ) : '' === self::text( $c, $order_by );
	}

	/**
	 * Orders campaigns by date sent, subject, or internal name. Campaigns with no
	 * value for the key sort last in both directions, and ties keep their
	 * incoming order.
	 *
	 * @param array<int,array<string,mixed>> $campaigns Normalized campaigns.
	 * @param string                         $order_by  One of date|subject|name.
	 * @param string                         $order     One of asc|desc.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_by( array $campaigns, string $order_by, string $order ): array {
		$direction = ( 'asc' === $order ) ? 1 : -1;
		$out       = array_values( $campaigns );
		usort(
			$out,
			static function ( $a, $b ) use ( $order_by, $direction ) {
				$a_blank = self::is_blank( $a, $order_by );
				$b_blank = self::is_blank( $b, $order_by );
				if ( $a_blank !== $b_blank ) {
					return $a_blank ? 1 : -1;
				}
				if ( $a_blank ) {
					return 0;
				}
				$cmp = ( 'date' === $order_by )
					? self::timestamp( $a ) <=> self::timestamp( $b )
					: strcasecmp( self::text( $a, $order_by ), self::text( $b, $order_by ) );
				return $direction * $cmp;
			}
		);
		return $out;
	}

	/**
	 * Sent campaigns only ({ subject, name, web, sent_at, list_ids }), newest
	 * first with undated campaigns last.
	 *
	 * @param array<string,mixed> $decoded Decoded API response.
	 * @return array<int,array{subject:string,name:string,web:string,sent_at:string,list_ids:array<int,string>}>
	 */
	public static function normalize( array $decoded ): array {
		$rows = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		$out  = array();
		foreach ( $rows as $row ) {
			// Laposta wraps each item as { campaign: {...} }; tolerate a flat shape too.
			$c = ( isset( $row['campaign'] ) && is_array( $row['campaign'] ) ) ? $row['campaign'] : $row;
			if ( ! is_array( $c ) || empty( $c['web'] ) ) {
				continue;
			}
			$out[] = array(
				'subject'  => isset( $c['subject'] ) ? (string) $c['subject'] : '',
				'name'     => isset( $c['name'] ) ? (string) $c['name'] : '',
				'web'      => (string) $c['web'],
				'sent_at'  => self::sent_at( $c ),
				'list_ids' => self::list_ids( $c['list_ids'] ?? null ),
			);
		}
		return self::sort_by( $out, 'date', 'desc' );
	}

	/**
	 * Campaigns in $incoming whose web URL was not already in $old.
	 *
	 * @param array<int,array<string,mixed>> $old      Previously cached campaigns.
	 * @param array<int,array<string,mixed>> $incoming Freshly fetched campaigns.
	 * @return array<int,array<string,mixed>>
	 */
	public static function diff_new( array $old, array $incoming ): array {
		$seen = array();
		foreach ( $old as $c ) {
			if ( isset( $c['web'] ) ) {
				$seen[ $c['web'] ] = true;
			}
		}
		$diff = array();
		foreach ( $incoming as $c ) {
			if ( isset( $c['web'] ) && empty( $seen[ $c['web'] ] ) ) {
				$diff[] = $c;
			}
		}
		return $diff;
	}
}
