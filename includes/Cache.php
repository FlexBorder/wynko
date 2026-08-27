<?php
/**
 * Cached campaign list.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Api\Campaigns;
use Wynko\Api\Fields as ApiFields;
use Wynko\Api\Lists as ApiLists;
use Wynko\Forms\FormData;
use Wynko\Support\Lists as ListData;
use Wynko\Support\Sanitizer;
use Wynko\Support\SyncDiff;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Transient-backed cache of the normalized campaign list, with a negative cache on failure. */
final class Cache {

	/**
	 * Returns the success TTL, derived from the clamped cache-duration setting.
	 *
	 * @return int
	 */
	public static function ttl_seconds(): int {
		$bounds  = Config::bounds( 'cache_minutes' );
		$minutes = Sanitizer::clamp_int( Config::get( 'cache_minutes' ), $bounds['min'], $bounds['max'], (int) Config::default_for( 'cache_minutes' ) );
		return $minutes * MINUTE_IN_SECONDS;
	}

	/**
	 * Returns the failure TTL: short, and never longer than the success TTL —
	 * stops front-end refills hammering the API during an outage.
	 *
	 * @return int
	 */
	public static function negative_ttl(): int {
		return (int) min( self::ttl_seconds(), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Returns the cached campaigns, filling the cache on a miss. An entry
	 * written before the current shape existed counts as a miss.
	 *
	 * @return array<int,array{subject:string,name:string,web:string,sent_at:string,list_ids:array<int,string>}>
	 */
	public static function get(): array {
		$cached = get_transient( Config::transient_key() );
		if ( is_array( $cached ) && ( array() === $cached || self::is_current_shape( $cached ) ) ) {
			return $cached;
		}
		$result = self::fill( false );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Whether a cached list carries every key the block reads. array_key_exists
	 * rather than isset for sent_at and name, because a campaign legitimately
	 * stores '' for either.
	 *
	 * @param array<int,mixed> $cached Cached campaign list, known non-empty.
	 * @return bool
	 */
	private static function is_current_shape( array $cached ): bool {
		return isset( $cached[0]['list_ids'] )
			&& array_key_exists( 'sent_at', $cached[0] )
			&& array_key_exists( 'name', $cached[0] );
	}

	/**
	 * Refetches the campaigns and writes activity-log entries.
	 *
	 * @return array<int,array{subject:string,name:string,web:string,sent_at:string,list_ids:array<int,string>}>|WP_Error
	 */
	public static function refresh() {
		return self::fill( true );
	}

	/**
	 * Runs one sync: campaigns, then the list index, then the field definitions
	 * of every list a published signup form is bound to.
	 *
	 * Only a campaign failure aborts it; a list or field failure is reported and
	 * the sync carries on. One activity-log entry is written at the end, naming
	 * only what was new.
	 *
	 * @param bool $manual Whether an operator asked for this sync.
	 * @return array<int,array{subject:string,name:string,web:string,sent_at:string,list_ids:array<int,string>}>|WP_Error
	 */
	private static function fill( bool $manual ) {
		$result = Campaigns::all();
		if ( is_wp_error( $result ) ) {
			Log::error(
				$manual
					/* translators: %s: error message. */
					? sprintf( __( 'Sync failed: %s', 'wynko-for-laposta' ), $result->get_error_message() )
					/* translators: %s: error message. */
					: sprintf( __( 'Automatic sync failed: %s', 'wynko-for-laposta' ), $result->get_error_message() )
			);
			set_transient( Config::transient_key(), array(), self::negative_ttl() );
			self::record_sync( false );
			return $result;
		}

		set_transient( Config::transient_key(), $result, self::ttl_seconds() );
		self::record_sync( true );

		$seen   = self::seen();
		$counts = FormData::list_reference_counts();

		$new_campaigns = self::count_unseen( $seen, 'campaigns', SyncDiff::ids( $result, 'web' ) );
		$new_lists     = self::sync_lists( $seen, $counts );
		$new_fields    = self::sync_fields( $seen, array_keys( $counts ), $manual );

		update_option( Config::option_key( 'seen' ), $seen, false );

		self::log_sync( $manual, $new_campaigns, $new_lists, $new_fields );
		return $result;
	}

	/**
	 * The identifiers the previous sync recorded.
	 *
	 * @return array<string,mixed>
	 */
	private static function seen(): array {
		$stored = get_option( Config::option_key( 'seen' ), Config::default_for( 'seen' ) );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * How many of $current the record had not seen, updating the record.
	 *
	 * A resource missing from the record entirely has never been synced, so a
	 * first sync records everything and counts nothing.
	 *
	 * @param array<string,mixed> $seen    The record, updated in place.
	 * @param string              $key     Which resource.
	 * @param array<int,string>   $current Identifiers fetched now.
	 * @return int
	 */
	private static function count_unseen( array &$seen, string $key, array $current ): int {
		$first        = ! array_key_exists( $key, $seen ) || ! is_array( $seen[ $key ] );
		$previous     = $first ? array() : array_map( 'strval', $seen[ $key ] );
		$seen[ $key ] = $current;

		return $first ? 0 : count( SyncDiff::unseen( $previous, $current ) );
	}

	/**
	 * Refreshes the list index, remembers what it held, and checks it for
	 * lists that signup forms still depend on.
	 *
	 * A failure is logged rather than swallowed into the negative cache, where
	 * nothing would say the account had stopped answering.
	 *
	 * @param array<string,mixed> $seen   The seen record, updated in place.
	 * @param array<string,int>   $counts list_id => how many published forms use it.
	 * @return int Lists the previous sync had not seen.
	 */
	private static function sync_lists( array &$seen, array $counts ): int {
		$lists = ApiLists::all();
		if ( is_wp_error( $lists ) ) {
			Log::error(
				sprintf(
					/* translators: %s: error message. */
					__( 'The list index could not be fetched: %s', 'wynko-for-laposta' ),
					$lists->get_error_message()
				)
			);
			return 0;
		}

		set_transient(
			Config::lists_transient_key(),
			array(
				'options' => array_map(
					static function ( array $row ): array {
						return array(
							'value' => $row['list_id'],
							'label' => $row['name'],
						);
					},
					$lists
				),
				'error'   => false,
			),
			self::ttl_seconds()
		);

		$names = ListData::names( $lists );
		self::remember_names( $names, array_keys( $counts ) );
		GoneLists::check( $names, $counts );

		return self::count_unseen( $seen, 'lists', SyncDiff::ids( $lists, 'list_id' ) );
	}

	/**
	 * Stores the current name of every referenced list, so one that later
	 * disappears can still be named in the entry reporting it.
	 *
	 * @param array<string,string> $names      list_id => name, freshly fetched.
	 * @param array<int,string>    $referenced List ids published forms use.
	 * @return void
	 */
	private static function remember_names( array $names, array $referenced ): void {
		$known = get_option( Config::option_key( 'list_names' ), Config::default_for( 'list_names' ) );
		$known = is_array( $known ) ? $known : array();

		foreach ( $names as $list_id => $name ) {
			if ( in_array( $list_id, $referenced, true ) ) {
				$known[ $list_id ] = $name;
			}
		}

		update_option( Config::option_key( 'list_names' ), $known, false );
	}

	/**
	 * Reads the field definitions of every referenced list and counts what is
	 * new. Only an operator pressing Sync now forces a refetch, because an
	 * automatic refill would turn one front-end render into a blocking API call
	 * per list.
	 *
	 * @param array<string,mixed> $seen       The seen record, updated in place.
	 * @param array<int,string>   $referenced List ids published forms are bound to.
	 * @param bool                $force      Whether to refetch rather than read the cache.
	 * @return int Fields the previous sync had not seen.
	 */
	private static function sync_fields( array &$seen, array $referenced, bool $force ): int {
		$known = ( isset( $seen['fields'] ) && is_array( $seen['fields'] ) ) ? $seen['fields'] : array();
		$next  = array();
		$new   = 0;

		foreach ( $referenced as $list_id ) {
			$fetched = ApiFields::for_list( $list_id, $force );
			if ( $fetched['error'] ) {
				// Keep what was recorded for this list: a failed fetch is not
				// evidence its fields went away, and dropping the record would
				// announce all of them as new once it answers again.
				if ( isset( $known[ $list_id ] ) ) {
					$next[ $list_id ] = $known[ $list_id ];
				}
				continue;
			}

			$ids              = SyncDiff::ids( $fetched['fields'], 'field_id' );
			$next[ $list_id ] = $ids;
			$previous         = isset( $known[ $list_id ] ) && is_array( $known[ $list_id ] ) ? array_map( 'strval', $known[ $list_id ] ) : null;
			$new             += null === $previous ? 0 : count( SyncDiff::unseen( $previous, $ids ) );
		}

		$seen['fields'] = $next;
		return $new;
	}

	/**
	 * Writes the sync's single entry, naming only what was new.
	 *
	 * One entry rather than a start/finish pair, which filled the log with a day
	 * of "nothing happened".
	 *
	 * @param bool $manual        Whether an operator asked for this sync.
	 * @param int  $new_campaigns Campaigns not seen before.
	 * @param int  $new_lists     Lists not seen before.
	 * @param int  $new_fields    Fields not seen before.
	 * @return void
	 */
	private static function log_sync( bool $manual, int $new_campaigns, int $new_lists, int $new_fields ): void {
		$clauses = array();
		if ( $new_campaigns > 0 ) {
			/* translators: %d: number of campaigns. */
			$clauses[] = sprintf( _n( '%d new campaign', '%d new campaigns', $new_campaigns, 'wynko-for-laposta' ), $new_campaigns );
		}
		if ( $new_lists > 0 ) {
			/* translators: %d: number of lists. */
			$clauses[] = sprintf( _n( '%d new list', '%d new lists', $new_lists, 'wynko-for-laposta' ), $new_lists );
		}
		if ( $new_fields > 0 ) {
			/* translators: %d: number of fields. */
			$clauses[] = sprintf( _n( '%d new field', '%d new fields', $new_fields, 'wynko-for-laposta' ), $new_fields );
		}

		if ( array() === $clauses ) {
			Log::info( $manual ? __( 'Sync succeeded.', 'wynko-for-laposta' ) : __( 'Automatic sync succeeded.', 'wynko-for-laposta' ) );
			return;
		}

		$found = wp_sprintf_l( '%l', $clauses );
		Log::info(
			$manual
				/* translators: %s: what the sync found, e.g. "2 new campaigns, 1 new list". */
				? sprintf( __( 'Sync succeeded: %s.', 'wynko-for-laposta' ), $found )
				/* translators: %s: what the sync found, e.g. "2 new campaigns, 1 new list". */
				: sprintf( __( 'Automatic sync succeeded: %s.', 'wynko-for-laposta' ), $found )
		);
	}

	/**
	 * Stamps the outcome of a live fetch from Laposta.
	 *
	 * Called from fill() and from the resources that fill their own caches, so
	 * "last sync" means the last time this site got an answer from Laposta,
	 * whoever asked.
	 *
	 * @param bool $ok Whether the fetch succeeded.
	 * @return void
	 */
	public static function stamp( bool $ok ): void {
		self::record_sync( $ok );
	}

	/**
	 * Writes the stamp.
	 *
	 * @param bool $ok Whether the fetch succeeded.
	 * @return void
	 */
	private static function record_sync( bool $ok ): void {
		update_option(
			Config::option_key( 'last_sync' ),
			array(
				'at' => time(),
				'ok' => $ok,
			),
			false
		);
	}

	/**
	 * Returns when campaigns were last fetched, and whether that attempt
	 * worked.
	 *
	 * @return array{at:int,ok:bool}|null Null when no sync has ever run.
	 */
	public static function last_sync(): ?array {
		$stored = get_option( Config::option_key( 'last_sync' ), Config::default_for( 'last_sync' ) );
		if ( ! is_array( $stored ) || ! isset( $stored['at'] ) ) {
			return null;
		}

		return array(
			'at' => (int) $stored['at'],
			'ok' => (bool) ( $stored['ok'] ?? false ),
		);
	}

	/**
	 * When the cached data was last filled, as one sentence for a settings
	 * screen. Printed beside the cache duration, which says how stale the data
	 * is allowed to get: this says how stale it is.
	 *
	 * @return string
	 */
	public static function last_refresh_sentence(): string {
		$last = self::last_sync();
		if ( null === $last ) {
			return __( 'Nothing has been fetched from Laposta yet.', 'wynko-for-laposta' );
		}

		$ago      = human_time_diff( $last['at'], time() );
		$absolute = (string) wp_date( 'Y-m-d H:i', $last['at'] );

		return $last['ok']
			? sprintf(
				/* translators: 1: relative time, e.g. "12 mins"; 2: absolute date and time. */
				__( 'Last refreshed %1$s ago (%2$s).', 'wynko-for-laposta' ),
				$ago,
				$absolute
			)
			: sprintf(
				/* translators: 1: relative time, e.g. "12 mins"; 2: absolute date and time. */
				__( 'The last refresh failed, %1$s ago (%2$s) — see the activity log.', 'wynko-for-laposta' ),
				$ago,
				$absolute
			);
	}

	/**
	 * Drops the cached campaign list, the list index, and the field
	 * definitions, so Sync now refreshes all three. Deliberately unlogged:
	 * invalidation is not a fetch, and the refill it provokes reports itself.
	 *
	 * @return void
	 */
	public static function bust(): void {
		delete_transient( Config::transient_key() );
		delete_transient( Config::lists_transient_key() );
		delete_transient( Config::fields_transient_key() );
	}
}
