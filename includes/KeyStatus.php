<?php
/**
 * Cached API-key connection verdict.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Api\Campaigns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cached verdict of the last API-key check, fingerprinted against the key so
 * that rotating it invalidates the verdict without an explicit bust. Only the
 * SHA-256 is stored, never the key itself.
 */
final class KeyStatus {

	const TRANSIENT = 'wynko_key_status';

	/**
	 * Returns the SHA-256 of a key, or '' for an empty key.
	 *
	 * @param string $key Raw API key.
	 * @return string
	 */
	public static function fingerprint( string $key ): string {
		return '' === $key ? '' : hash( 'sha256', $key );
	}

	/**
	 * Stores the verdict for a key, fingerprinted rather than in the clear.
	 *
	 * @param string $key     Raw API key.
	 * @param bool   $ok      Whether the key authenticated.
	 * @param string $message Failure message, empty on success.
	 * @param string $code    WP_Error code behind the failure, empty on success.
	 * @return void
	 */
	public static function record( string $key, bool $ok, string $message = '', string $code = '' ): void {
		set_transient(
			self::TRANSIENT,
			array(
				'fingerprint' => self::fingerprint( $key ),
				'ok'          => $ok,
				'message'     => $message,
				'code'        => $code,
				'checked_at'  => time(),
			),
			$ok ? Cache::ttl_seconds() : Cache::negative_ttl()
		);
	}

	/**
	 * Returns the stored verdict for a key. A record written before 'code'
	 * existed reports '', which callers read as "no idea".
	 *
	 * @param string $key Raw API key.
	 * @return array{ok:bool,message:string,code:string}|null Null when nothing is cached for this key.
	 */
	public static function cached( string $key ): ?array {
		$stored = get_transient( self::TRANSIENT );
		if ( ! is_array( $stored ) || '' === self::fingerprint( $key ) ) {
			return null;
		}
		if ( ( $stored['fingerprint'] ?? '' ) !== self::fingerprint( $key ) ) {
			return null;
		}
		return array(
			'ok'      => (bool) ( $stored['ok'] ?? false ),
			'message' => (string) ( $stored['message'] ?? '' ),
			'code'    => (string) ( $stored['code'] ?? '' ),
		);
	}

	/**
	 * Returns the verdict for a key, probing the API only when nothing cached
	 * matches it. The single entry point for "does this key work?".
	 *
	 * @param string $key Raw API key.
	 * @return array{ok:bool,message:string,code:string}
	 */
	public static function verify( string $key ): array {
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => '',
				'code'    => '',
			);
		}

		$cached = self::cached( $key );
		if ( null !== $cached ) {
			return $cached;
		}

		$result  = Campaigns::all( $key );
		$ok      = ! is_wp_error( $result );
		$message = $ok ? '' : $result->get_error_message();
		$code    = $ok ? '' : (string) $result->get_error_code();
		self::record( $key, $ok, $message, $code );

		/**
		 * Fires after a live API-key verification.
		 *
		 * @since 1.1.0
		 * @param string $fingerprint SHA-256 of the verified key, never the key itself.
		 * @param bool   $ok          Whether the key authenticated.
		 */
		do_action( 'wynko_api_key_verified', self::fingerprint( $key ), $ok );

		self::log_probe( $ok, $message );

		return array(
			'ok'      => $ok,
			'message' => $message,
			'code'    => $code,
		);
	}

	/**
	 * Reports one live probe. Only the miss path calls this, so an admin page
	 * load answered from the cached verdict adds nothing to the log.
	 *
	 * @param bool   $ok      Whether the key authenticated.
	 * @param string $message Failure message, empty on success.
	 * @return void
	 */
	private static function log_probe( bool $ok, string $message ): void {
		if ( $ok ) {
			Log::info( __( 'Connected to the Laposta API.', 'wynko-for-laposta' ) );
			return;
		}

		Log::error(
			/* translators: %s: error message. */
			sprintf( __( 'Connection to the Laposta API failed: %s', 'wynko-for-laposta' ), $message )
		);
	}

	/**
	 * Returns the verdict for the currently resolved key, which is the only path
	 * a key from the environment or wp-config.php takes.
	 *
	 * @return array{ok:bool,message:string,code:string}
	 */
	public static function current(): array {
		return self::verify( ApiKey::resolve() );
	}
}
