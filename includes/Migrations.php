<?php
/**
 * Stored-data upgrades.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Support\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time upgrade of stored data. Options are per-site, so on multisite each
 * blog migrates itself on its own first load — no network-wide loop.
 */
final class Migrations {

	const SCHEMA_OPTION  = 'wynko_schema';
	const SCHEMA_VERSION = 4;

	/**
	 * Runs the pending migrations for the current site, once.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		// Entries cached before list_ids existed would vanish from every
		// filtered block until they expired. It is a cache: drop, don't migrate.
		delete_transient( Config::transient_key() );
		self::seal_stored_key();

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );

		/**
		 * Fires after this site's per-site migrations complete.
		 *
		 * @since 1.1.0
		 * @param int $schema_version The schema version just migrated to.
		 */
		do_action( 'wynko_migrations_completed', self::SCHEMA_VERSION );
	}

	/**
	 * Re-stores a plaintext API key sealed. No-op when it is already sealed or
	 * the site has no usable salts.
	 *
	 * @return void
	 */
	private static function seal_stored_key(): void {
		$raw = (string) get_option( Config::option_key( 'api_key' ), '' );
		if ( '' === $raw || Crypto::is_envelope( $raw ) ) {
			return;
		}
		update_option( Config::option_key( 'api_key' ), ApiKey::store( $raw ), true );
	}
}
