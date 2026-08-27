<?php
/**
 * Activity log.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded activity log persisted in a single (non-autoloaded) option. */
final class Log {

	/**
	 * Prepends an entry and trims the log to its configured maximum. Entries
	 * below the configured threshold are dropped rather than stored, so lowering
	 * the threshold later cannot recover them.
	 *
	 * @param string $level   One of Sanitizer::LOG_LEVELS; anything else is stored as 'info'.
	 * @param string $message Human-readable, already translated.
	 * @return void
	 */
	public static function add( string $level, string $message ): void {
		$level = Sanitizer::log_level( $level, Sanitizer::LEVEL_INFO );
		if ( ! Sanitizer::level_meets( $level, self::threshold() ) ) {
			return;
		}

		$entries = self::all();
		array_unshift(
			$entries,
			array(
				'time'    => current_time( 'mysql' ),
				'level'   => $level,
				'message' => $message,
			)
		);
		update_option( Config::option_key( 'log' ), Sanitizer::trim_log( $entries, Config::log_max() ), false );
		Notifier::maybe_notify( $level, $message );
	}

	/**
	 * The lowest severity currently being recorded.
	 *
	 * @return string One of Sanitizer::LOG_LEVELS.
	 */
	public static function threshold(): string {
		return Sanitizer::log_level( Config::get( 'log_level' ), (string) Config::default_for( 'log_level' ) );
	}

	/**
	 * Logs an informational entry.
	 *
	 * @param string $message Human-readable, already translated.
	 * @return void
	 */
	public static function info( string $message ): void {
		self::add( Sanitizer::LEVEL_INFO, $message );
	}

	/**
	 * Logs a warning: something a reader should notice, but not a failure.
	 *
	 * @param string $message Human-readable, already translated.
	 * @return void
	 */
	public static function warning( string $message ): void {
		self::add( Sanitizer::LEVEL_WARNING, $message );
	}

	/**
	 * Logs an error entry.
	 *
	 * @param string $message Human-readable, already translated.
	 * @return void
	 */
	public static function error( string $message ): void {
		self::add( Sanitizer::LEVEL_ERROR, $message );
	}

	/**
	 * Returns the stored entries, newest first.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function all(): array {
		$entries = get_option( Config::option_key( 'log' ), array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Deletes every stored entry.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( Config::option_key( 'log' ) );
	}
}
