<?php
/**
 * Submission-rate limiting for the public signup endpoint.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Forms\FormData;
use Wynko\Support\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The counter store behind the signup rate limit: Support\RateLimiter does the
 * arithmetic, this class holds the per-IP and per-form counters.
 *
 * The IP itself is never stored — it is keyed through wp_hash(), so a counter
 * name is a one-way site-scoped digest rather than a visitor's address.
 */
final class Throttle {

	/**
	 * Whether this submission is within both caps, recording it when it is.
	 *
	 * Nothing is recorded once either counter has refused, so a blocked client
	 * cannot keep extending its own lockout past the window.
	 *
	 * @param int    $form_id Form post id.
	 * @param string $ip      Submitting IP, '' when unreadable.
	 * @return bool
	 */
	public static function allows( int $form_id, string $ip ): bool {
		$window = Config::throttle_window();
		$now    = time();

		$ip_key   = self::key( 'ip', '' === $ip ? 'unknown' : wp_hash( $ip ) );
		$form_key = self::key( 'form', (string) $form_id );

		$ip_hits   = RateLimiter::prune( self::read( $ip_key ), $now, $window );
		$form_hits = RateLimiter::prune( self::read( $form_key ), $now, $window );

		$allowed = ! ( RateLimiter::exceeded( $ip_hits, Config::throttle_max( 'ip' ) )
			|| RateLimiter::exceeded( $form_hits, Config::throttle_max( 'form' ) ) );

		/**
		 * Filters whether a submission is allowed through the throttle.
		 *
		 * @since 1.1.0
		 * @param bool   $allowed The default allow/deny decision.
		 * @param int    $form_id Form post id.
		 * @param string $ip      Submitting IP, '' when unreadable.
		 */
		$allowed = (bool) apply_filters( 'wynko_throttle_allowed', $allowed, $form_id, $ip );

		if ( ! $allowed ) {
			return false;
		}

		$form_hits = RateLimiter::record( $form_hits, $now );
		self::write( $ip_key, RateLimiter::record( $ip_hits, $now ), $window );
		self::write( $form_key, $form_hits, $window );
		self::warn_if_nearly_full( $form_id, count( $form_hits ) );

		return true;
	}

	/**
	 * Whether this IP is within its cap, recording it when it is. The per-IP
	 * variant of allows(), for a caller with no Wynko form id to meter a
	 * per-form counter against — the Contact Form 7 bridge, so far the only
	 * one. Shares the same counter allows() writes, since the per-visitor cap
	 * is already documented as counted "across all your forms."
	 *
	 * @param string $ip Submitting IP, '' when unreadable.
	 * @return bool
	 */
	public static function allows_ip( string $ip ): bool {
		$window = Config::throttle_window();
		$now    = time();

		$ip_key  = self::key( 'ip', '' === $ip ? 'unknown' : wp_hash( $ip ) );
		$ip_hits = RateLimiter::prune( self::read( $ip_key ), $now, $window );

		if ( RateLimiter::exceeded( $ip_hits, Config::throttle_max( 'ip' ) ) ) {
			return false;
		}

		self::write( $ip_key, RateLimiter::record( $ip_hits, $now ), $window );

		return true;
	}

	/**
	 * Records an error when a form has taken nearly as many signups as its cap
	 * allows, at most once a day per form.
	 *
	 * An error rather than a warning because that is the level the alert email
	 * sends on. The daily gap keeps a busy form from repeating the same news
	 * every time its window refills.
	 *
	 * @param int $form_id Form post id.
	 * @param int $hits    Signups counted in the open window, this one included.
	 * @return void
	 */
	private static function warn_if_nearly_full( int $form_id, int $hits ): void {
		if ( $hits < Config::throttle_pressure_threshold() ) {
			return;
		}

		$key = Config::throttle_pressure_key( $form_id, self::epoch() );
		if ( false !== get_transient( $key ) ) {
			return;
		}
		set_transient( $key, time(), Config::throttle_pressure_interval() );

		$form = FormData::load( $form_id );
		self::remember_pressure( null === $form ? (string) $form_id : $form->display_name() );

		Log::error(
			sprintf(
				/* translators: 1: form name, 2: signups counted, 3: the per-form cap, 4: window length in minutes. */
				__( 'Signup form "%1$s" has taken %2$d of the %3$d signups it allows per %4$d minutes. Once the cap is reached the form turns every signup away until the window passes — raise the per-form limit on the Security tab if this is real traffic.', 'wynko-for-laposta' ),
				null === $form ? (string) $form_id : $form->display_name(),
				$hits,
				Config::throttle_max( 'form' ),
				(int) round( Config::throttle_window() / MINUTE_IN_SECONDS )
			)
		);
	}

	/**
	 * How many signups one form has taken in the window that is still open.
	 *
	 * A sliding window, so this falls as attempts age out; the lifetime total is
	 * FormData::signup_total(). Every accepted submission counts here, whether or
	 * not Laposta went on to take it.
	 *
	 * @param int $form_id Form post id.
	 * @return int
	 */
	public static function form_hits( int $form_id ): int {
		return count(
			RateLimiter::prune(
				self::read( self::key( 'form', (string) $form_id ) ),
				time(),
				Config::throttle_window()
			)
		);
	}

	/**
	 * Whether this refusal is the one that gets written down, claiming the
	 * window when it is.
	 *
	 * Log::add() rewrites the whole log on every call, so one entry per form per
	 * window keeps a flood from amplifying into repeated writes. The flag carries
	 * the counter epoch, so a reset re-arms it.
	 *
	 * @param int $form_id Form post id.
	 * @return bool
	 */
	public static function should_log( int $form_id ): bool {
		$key = Config::throttle_logged_key( $form_id, self::epoch() );
		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, time(), Config::throttle_window() );
		return true;
	}

	/**
	 * Adds one form to the list the admin notice reads.
	 *
	 * A single transient of names, rather than the notice recomputing counters on
	 * every admin page load. It expires with the warning that raised it.
	 *
	 * @param string $name The form's name.
	 * @return void
	 */
	private static function remember_pressure( string $name ): void {
		$names = self::pressured();
		if ( in_array( $name, $names, true ) ) {
			return;
		}

		$names[] = $name;
		set_transient( Config::throttle_pressure_notice_key(), $names, Config::throttle_pressure_interval() );
	}

	/**
	 * The forms warned about within the last interval, newest last.
	 *
	 * @return array<int,string>
	 */
	public static function pressured(): array {
		$names = get_transient( Config::throttle_pressure_notice_key() );

		return is_array( $names ) ? array_values( array_map( 'strval', $names ) ) : array();
	}

	/**
	 * Clears every counter on this site by moving the epoch, which every key
	 * carries: the old ones become unreachable at once and expire on their own.
	 *
	 * Deliberately not a LIKE sweep of the options table: behind a persistent
	 * object cache a transient never reaches it.
	 *
	 * @return void
	 */
	public static function reset(): void {
		update_option( Config::throttle_epoch_option(), self::epoch() + 1 );
		// The notice describes counters that no longer exist once the epoch moves.
		delete_transient( Config::throttle_pressure_notice_key() );
	}

	/**
	 * The current epoch.
	 *
	 * @return int
	 */
	public static function epoch(): int {
		return (int) get_option( Config::throttle_epoch_option(), 0 );
	}

	/**
	 * Builds one counter's transient key.
	 *
	 * @param string $which      'ip' or 'form'.
	 * @param string $identifier Hashed IP, form id, or 'unknown'.
	 * @return string
	 */
	private static function key( string $which, string $identifier ): string {
		return Config::throttle_transient_prefix( $which ) . self::epoch() . '_' . $identifier;
	}

	/**
	 * Reads one counter, unvalidated — RateLimiter::prune() is what turns
	 * whatever was in storage into a list of timestamps.
	 *
	 * @param string $key Transient key.
	 * @return array<int,mixed>
	 */
	private static function read( string $key ): array {
		$hits = get_transient( $key );

		return is_array( $hits ) ? $hits : array();
	}

	/**
	 * Writes one counter, expiring a full window after its last attempt.
	 *
	 * @param string         $key    Transient key.
	 * @param array<int,int> $hits   Attempt timestamps.
	 * @param int            $window Window length in seconds.
	 * @return void
	 */
	private static function write( string $key, array $hits, int $window ): void {
		set_transient( $key, $hits, $window );
	}
}
