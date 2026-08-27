<?php
/**
 * Field resource.
 *
 * @package Wynko
 */

namespace Wynko\Api;

use Wynko\Cache;
use Wynko\Config;
use Wynko\Support\Fields as FieldData;
use Wynko\Support\LapostaErrors;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The Laposta /field endpoint, cached per list. */
final class Fields {

	/**
	 * Fetches one list's fields, normalized.
	 *
	 * @param string $list_id Laposta list id.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function all( string $list_id ) {
		$decoded = Client::request( 'GET', 'field?list_id=' . rawurlencode( $list_id ) );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		return FieldData::normalize( $decoded );
	}

	/**
	 * Cached field definitions for one list, shared by the editor and the
	 * front-end renderer. A failure yields no fields and error => true, cached
	 * briefly so an outage does not turn every page view into an API call.
	 *
	 * All lists live in one transient keyed by list id, with a per-entry expiry,
	 * so one list's refetch does not extend another's life.
	 *
	 * @param string $list_id Laposta list id.
	 * @param bool   $force   Refetch even when a fresh entry is held.
	 * @return array{fields:array<int,array<string,mixed>>,error:bool,reason:string}
	 */
	public static function for_list( string $list_id, bool $force = false ): array {
		if ( '' === $list_id ) {
			return self::failure( FieldData::FETCH_GONE );
		}

		$cache = get_transient( Config::fields_transient_key() );
		$cache = is_array( $cache ) ? $cache : array();

		$entry = $force ? null : ( $cache[ $list_id ] ?? null );
		if ( is_array( $entry ) && isset( $entry['fields'], $entry['error'], $entry['expires'] ) && $entry['expires'] > time() ) {
			return array(
				'fields' => $entry['fields'],
				'error'  => (bool) $entry['error'],
				// An entry written before the reason existed reports none.
				'reason' => (string) ( $entry['reason'] ?? FieldData::FETCH_OK ),
			);
		}

		$fields = self::all( $list_id );
		if ( is_wp_error( $fields ) ) {
			$data   = $fields->get_error_data();
			$result = self::failure(
				FieldData::classify_fetch_error(
					(string) $fields->get_error_code(),
					LapostaErrors::status_of( is_array( $data ) ? $data : array() )
				)
			);
		} else {
			$result = array(
				'fields' => $fields,
				'error'  => false,
				'reason' => FieldData::FETCH_OK,
			);
		}

		$ttl               = $result['error'] ? Cache::negative_ttl() : Cache::ttl_seconds();
		$cache[ $list_id ] = array_merge( $result, array( 'expires' => time() + $ttl ) );

		// The transient's own lifetime is the longest any entry can need, so a
		// fresh entry is never dropped early by a neighbour's short negative TTL.
		set_transient( Config::fields_transient_key(), $cache, Cache::ttl_seconds() );

		// Only a forced refetch is stamped. The unforced path runs inside any
		// front-end page view that renders a form, and an option write on an
		// anonymous request is exactly the cost sync_fields() declines to pay;
		// the forced one is an operator pressing Refresh fields or a signup
		// resyncing after field drift, which is what "last sync" should move
		// for.
		if ( $force ) {
			Cache::stamp( ! $result['error'] );
		}

		return $result;
	}

	/**
	 * A fetch that produced nothing, carrying why.
	 *
	 * @param string $reason One of Support\Fields::FETCH_* .
	 * @return array{fields:array<int,array<string,mixed>>,error:bool,reason:string}
	 */
	private static function failure( string $reason ): array {
		return array(
			'fields' => array(),
			'error'  => true,
			'reason' => $reason,
		);
	}
}
