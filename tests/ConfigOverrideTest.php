<?php
/**
 * Tests for settings supplied by the environment or wp-config.php.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use PHPUnit\Framework\TestCase;

/**
 * Covers the precedence Config::get() applies: an environment variable for this
 * site, then one for the network, then the matching constants, then the stored
 * option. Also covers what is refused — a value the setting cannot hold, and a
 * setting that was never marked overridable at all.
 */
final class ConfigOverrideTest extends TestCase {

	/**
	 * Variables this test set, cleared again in tearDown.
	 *
	 * @var array<int,string>
	 */
	private array $exported = array();

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	protected function tearDown(): void {
		foreach ( $this->exported as $name ) {
			putenv( $name );
		}
		$this->exported = array();
	}

	/**
	 * Exports one variable for the duration of the test.
	 *
	 * @param string $name  Variable name.
	 * @param string $value Value.
	 * @return void
	 */
	private function export( string $name, string $value ): void {
		putenv( $name . '=' . $value );
		$this->exported[] = $name;
	}

	public function test_an_environment_variable_outranks_the_stored_option(): void {
		update_option( Config::option_key( 'cache_minutes' ), 15 );
		$this->export( 'WYNKO_CACHE_MINUTES', '120' );

		$this->assertSame( 120, Config::get( 'cache_minutes' ) );
		$this->assertTrue( Config::is_overridden( 'cache_minutes' ) );
	}

	public function test_the_stored_option_stands_when_nothing_overrides_it(): void {
		update_option( Config::option_key( 'cache_minutes' ), 15 );

		$this->assertSame( 15, Config::get( 'cache_minutes' ) );
		$this->assertFalse( Config::is_overridden( 'cache_minutes' ) );
	}

	public function test_the_per_site_variable_outranks_the_network_one(): void {
		$this->export( 'WYNKO_CACHE_MINUTES', '120' );
		$this->export( 'WYNKO_CACHE_MINUTES_' . get_current_blog_id(), '30' );

		$this->assertSame( 30, Config::get( 'cache_minutes' ) );
		$this->assertSame( 'WYNKO_CACHE_MINUTES_' . get_current_blog_id(), Config::override( 'cache_minutes' )['name'] );
	}

	public function test_an_out_of_range_number_is_held_to_its_bounds(): void {
		$this->export( 'WYNKO_CACHE_MINUTES', '999999' );

		$this->assertSame( 1440, Config::get( 'cache_minutes' ) );
	}

	public function test_a_value_that_is_not_a_number_is_ignored(): void {
		update_option( Config::option_key( 'cache_minutes' ), 15 );
		$this->export( 'WYNKO_CACHE_MINUTES', 'soon' );

		$this->assertSame( 15, Config::get( 'cache_minutes' ) );
		$this->assertFalse( Config::is_overridden( 'cache_minutes' ) );
	}

	public function test_an_exported_but_empty_variable_counts_as_absent(): void {
		update_option( Config::option_key( 'notify_emails' ), 'ops@example.com' );
		$this->export( 'WYNKO_NOTIFY_EMAILS', '' );

		$this->assertSame( 'ops@example.com', Config::get( 'notify_emails' ) );
	}

	public function test_a_boolean_reads_the_spellings_a_deployment_writes(): void {
		$this->export( 'WYNKO_NOTIFY_ENABLED', 'true' );
		$this->assertTrue( Config::get( 'notify_enabled' ) );

		$this->export( 'WYNKO_NOTIFY_ENABLED', 'off' );
		$this->assertFalse( Config::get( 'notify_enabled' ) );
	}

	public function test_a_value_outside_the_allowed_list_is_ignored(): void {
		update_option( Config::option_key( 'log_level' ), 'warning' );
		$this->export( 'WYNKO_LOG_LEVEL', 'chatty' );

		$this->assertSame( 'warning', Config::get( 'log_level' ) );
	}

	public function test_an_allowed_value_is_taken(): void {
		$this->export( 'WYNKO_LOG_LEVEL', 'error' );

		$this->assertSame( 'error', Config::get( 'log_level' ) );
	}

	public function test_stored_state_is_not_overridable(): void {
		$this->export( 'WYNKO_SEEN', 'anything' );

		$this->assertSame( array(), Config::env_names( 'seen' ) );
		$this->assertFalse( Config::is_overridden( 'seen' ) );
	}

	public function test_the_api_key_resolves_through_api_key_not_here(): void {
		$this->assertSame( array(), Config::env_names( 'api_key' ) );
	}

	public function test_every_throttle_cap_can_be_deployed(): void {
		$this->export( 'WYNKO_THROTTLE_WINDOW', '5' );
		$this->export( 'WYNKO_THROTTLE_IP_MAX', '3' );
		$this->export( 'WYNKO_THROTTLE_FORM_MAX', '99' );

		$this->assertSame( 300, Config::throttle_window() );
		$this->assertSame( 3, Config::throttle_max( 'ip' ) );
		$this->assertSame( 99, Config::throttle_max( 'form' ) );
	}

	/**
	 * The constant tier, in its own process: a define() cannot be undone, and
	 * every other test in this suite reads the same settings.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_supplies_a_setting_when_no_variable_does(): void {
		define( 'WYNKO_CACHE_MINUTES', '45' );

		$this->assertSame( 45, Config::get( 'cache_minutes' ) );
		$this->assertSame( 'constant', Config::override( 'cache_minutes' )['source'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_variable_outranks_a_constant(): void {
		define( 'WYNKO_CACHE_MINUTES', '45' );
		putenv( 'WYNKO_CACHE_MINUTES=90' );

		$this->assertSame( 90, Config::get( 'cache_minutes' ) );
		$this->assertSame( 'env', Config::override( 'cache_minutes' )['source'] );

		putenv( 'WYNKO_CACHE_MINUTES' );
	}
}
