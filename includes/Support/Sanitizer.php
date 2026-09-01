<?php
/**
 * Value clamps and HTTP status classification.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pure, WordPress-free clamps and classification; callers pass explicit bounds and own all wording. */
final class Sanitizer {

	const STATUS_INVALID_KEY  = 'invalid_key';
	const STATUS_RATE_LIMITED = 'rate_limited';
	const STATUS_UNEXPECTED   = 'unexpected';

	const LEVEL_ERROR   = 'error';
	const LEVEL_WARNING = 'warning';
	const LEVEL_INFO    = 'info';

	/**
	 * Activity-log severities, most severe first. The ranking lives here rather
	 * than in config/settings.php because comparing two levels is logic, not
	 * configuration; SanitizerTest asserts the two lists stay in step.
	 */
	const LOG_LEVELS = array( self::LEVEL_ERROR, self::LEVEL_WARNING, self::LEVEL_INFO );

	/** Matches every level, for a view that filters on text alone. */
	const LEVEL_ALL = 'all';

	/**
	 * Clamps a value into [$min, $max], falling back when it is not numeric.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @param int   $fallback Used when $value is not numeric.
	 * @return int
	 */
	public static function clamp_int( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return (int) max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Reads a boolean written as text, the way a deployment writes one. The
	 * spellings are the ones an .env file or a wp-config.php line actually
	 * carries; anything else is false, including the empty string.
	 *
	 * @param string $value Raw text.
	 * @return bool
	 */
	public static function truthy( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Returns $value when it is one of $allowed, else $fallback. Callers own
	 * the allowed list, so this stays as bounds-free as clamp_int().
	 *
	 * @param mixed             $value    Raw value.
	 * @param array<int,string> $allowed  Permitted values.
	 * @param string            $fallback Used when $value is not permitted.
	 * @return string
	 */
	public static function enum( $value, array $allowed, string $fallback ): string {
		return ( is_string( $value ) && in_array( $value, $allowed, true ) ) ? $value : $fallback;
	}

	/**
	 * Caps the activity log at $max entries.
	 *
	 * @param array<int,mixed> $entries Newest-first.
	 * @param int              $max     Maximum retained entries.
	 * @return array<int,mixed>
	 */
	public static function trim_log( array $entries, int $max ): array {
		return array_slice( $entries, 0, max( 0, $max ) );
	}

	/**
	 * Coerces a raw value to one of self::LOG_LEVELS.
	 *
	 * @param mixed  $value    Raw level.
	 * @param string $fallback Used when $value is not a known level.
	 * @return string
	 */
	public static function log_level( $value, string $fallback ): string {
		return self::enum( $value, self::LOG_LEVELS, $fallback );
	}

	/**
	 * Whether $level is at least as severe as $threshold. The one comparison
	 * behind both the write-time gate and the on-screen filter, so the two
	 * cannot disagree about what "warnings and up" means.
	 *
	 * @param string $level     Entry level.
	 * @param string $threshold Lowest severity of interest, or self::LEVEL_ALL.
	 * @return bool
	 */
	public static function level_meets( string $level, string $threshold ): bool {
		if ( self::LEVEL_ALL === $threshold || '' === $threshold ) {
			return true;
		}
		$rank  = array_search( $level, self::LOG_LEVELS, true );
		$floor = array_search( $threshold, self::LOG_LEVELS, true );

		// An unknown level is never filtered out: a stored entry the code no
		// longer recognises should still be readable.
		return false === $rank || false === $floor || $rank <= $floor;
	}

	/**
	 * Narrows the log to the entries a viewer asked for.
	 *
	 * @param array<int,array<string,string>> $entries   Newest-first.
	 * @param string                          $threshold Lowest severity, or self::LEVEL_ALL.
	 * @param string                          $search    Case-insensitive message substring; '' matches all.
	 * @return array<int,array<string,string>>
	 */
	public static function filter_log( array $entries, string $threshold, string $search ): array {
		$matches = static function ( $entry ) use ( $threshold, $search ): bool {
			if ( ! is_array( $entry ) ) {
				return false;
			}
			if ( ! self::level_meets( (string) ( $entry['level'] ?? '' ), $threshold ) ) {
				return false;
			}
			return '' === $search || false !== stripos( (string) ( $entry['message'] ?? '' ), $search );
		};

		return array_values( array_filter( $entries, $matches ) );
	}

	/**
	 * Classifies a non-2xx HTTP status. Callers own the wording, so the
	 * classification stays WordPress-free and testable on its own.
	 *
	 * @param int $status HTTP status code.
	 * @return string One of self::STATUS_* .
	 */
	public static function classify_status( int $status ): string {
		if ( 401 === $status || 403 === $status ) {
			return self::STATUS_INVALID_KEY;
		}
		if ( 429 === $status ) {
			return self::STATUS_RATE_LIMITED;
		}
		return self::STATUS_UNEXPECTED;
	}

	/**
	 * Strips control characters (newlines included) from a string that must
	 * stay on one line wherever it lands, plain-text exports included — an
	 * HTML rendering path gets this for free from WordPress's own output
	 * escaping, but a plain-text one has no equivalent, so a value that can
	 * originate from outside this plugin (e.g. a third-party integration's
	 * declared name) needs it stripped explicitly before it can forge extra
	 * lines there.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	public static function single_line( string $value ): string {
		return (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', trim( $value ) );
	}
}
