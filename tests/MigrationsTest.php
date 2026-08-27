<?php
/**
 * Tests for the stored-data upgrades.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\ApiKey;
use Wynko\Config;
use Wynko\Migrations;
use PHPUnit\Framework\TestCase;

/** Covers the stored-data upgrade run. */
final class MigrationsTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	/**
	 * Entries cached before list_ids existed would match no list filter, so
	 * the upgrade drops the cache rather than migrating its shape.
	 */
	public function test_drops_the_campaign_cache(): void {
		set_transient( Config::transient_key(), array( array( 'subject' => 'Pre-list_ids' ) ) );

		Migrations::maybe_run();

		$this->assertFalse( get_transient( Config::transient_key() ) );
	}

	public function test_nothing_to_migrate_still_records_the_schema_version(): void {
		Migrations::maybe_run();
		$this->assertSame( Migrations::SCHEMA_VERSION, get_option( Migrations::SCHEMA_OPTION ) );
		$this->assertFalse( get_option( Config::option_key( 'api_key' ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_migration_seals_an_existing_plaintext_key(): void {
		define( 'SECURE_AUTH_KEY', 'auth-key' );
		define( 'SECURE_AUTH_SALT', 'auth-salt' );
		update_option( Config::option_key( 'api_key' ), 'legacy-plaintext' );

		Migrations::maybe_run();

		$this->assertStringStartsWith( 'wynko:v1:', (string) get_option( Config::option_key( 'api_key' ) ) );
		$this->assertSame( 'legacy-plaintext', ApiKey::stored() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_migration_leaves_an_already_sealed_key_alone(): void {
		define( 'SECURE_AUTH_KEY', 'auth-key' );
		define( 'SECURE_AUTH_SALT', 'auth-salt' );
		$sealed = ApiKey::store( 'already-sealed' );
		update_option( Config::option_key( 'api_key' ), $sealed );

		Migrations::maybe_run();

		$this->assertSame( $sealed, get_option( Config::option_key( 'api_key' ) ) );
	}
}
