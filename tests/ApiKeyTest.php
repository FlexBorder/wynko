<?php
/**
 * Tests for the API key resolver.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\ApiKey;
use Wynko\Config;
use PHPUnit\Framework\TestCase;

/** Covers the constant-over-option precedence, including the per-blog constant. */
final class ApiKeyTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_no_key_configured(): void {
		$this->assertSame( '', ApiKey::resolve() );
		$this->assertSame( 'none', ApiKey::source() );
		$this->assertSame( '', ApiKey::constant_name() );
	}

	protected function tearDown(): void {
		putenv( 'WYNKO_API_KEY' );
		putenv( 'WYNKO_API_KEY_1' );
		putenv( 'WYNKO_API_KEY_7' );
		unset( $GLOBALS['wynko_test_blog_id'] );
	}

	public function test_environment_variable_beats_the_option(): void {
		putenv( 'WYNKO_API_KEY=env-key' );
		update_option( Config::option_key( 'api_key' ), 'option-key' );

		$this->assertSame( 'env-key', ApiKey::resolve() );
		$this->assertSame( 'env', ApiKey::source() );
		$this->assertSame( 'WYNKO_API_KEY', ApiKey::source_name() );
		$this->assertSame( '', ApiKey::constant_name() );
	}

	public function test_per_blog_environment_variable_beats_the_network_one(): void {
		$GLOBALS['wynko_test_blog_id'] = 7;
		putenv( 'WYNKO_API_KEY=network-key' );
		putenv( 'WYNKO_API_KEY_7=blog-key' );

		$this->assertSame( 'blog-key', ApiKey::resolve() );
		$this->assertSame( 'WYNKO_API_KEY_7', ApiKey::source_name() );
	}

	public function test_a_blank_environment_variable_is_treated_as_absent(): void {
		putenv( 'WYNKO_API_KEY=   ' );
		update_option( Config::option_key( 'api_key' ), 'option-key' );

		$this->assertSame( 'option-key', ApiKey::resolve() );
		$this->assertSame( 'option', ApiKey::source() );
		$this->assertSame( '', ApiKey::source_name() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_environment_beats_a_wp_config_constant(): void {
		define( 'WYNKO_API_KEY', 'constant-key' );
		putenv( 'WYNKO_API_KEY=env-key' );

		$this->assertSame( 'env-key', ApiKey::resolve() );
		$this->assertSame( 'env', ApiKey::source() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_stored_key_round_trips_through_the_envelope(): void {
		define( 'SECURE_AUTH_KEY', 'auth-key' );
		define( 'SECURE_AUTH_SALT', 'auth-salt' );

		$sealed = ApiKey::store( 'my-api-key' );
		update_option( Config::option_key( 'api_key' ), $sealed );

		$this->assertStringStartsWith( 'wynko:v1:', $sealed );
		$this->assertStringNotContainsString( 'my-api-key', $sealed );
		$this->assertSame( 'my-api-key', ApiKey::stored() );
		$this->assertSame( 'my-api-key', ApiKey::resolve() );
		$this->assertSame( 'ok', ApiKey::stored_state() );
		$this->assertSame( 'option', ApiKey::source() );
	}

	public function test_a_plaintext_stored_key_is_still_readable(): void {
		update_option( Config::option_key( 'api_key' ), 'legacy-plaintext' );

		$this->assertSame( 'legacy-plaintext', ApiKey::stored() );
		$this->assertSame( 'ok', ApiKey::stored_state() );
	}

	/**
	 * Rotating SECURE_AUTH_KEY is routine incident response, and it makes every
	 * sealed value unopenable. That must read as "no key", never as a key.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_undecryptable_key_reads_as_unreadable_not_as_a_key(): void {
		define( 'SECURE_AUTH_KEY', 'rotated-key' );
		define( 'SECURE_AUTH_SALT', 'rotated-salt' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fabricates an envelope sealed with material this site no longer has.
		update_option( Config::option_key( 'api_key' ), 'wynko:v1:' . base64_encode( random_bytes( 64 ) ) );

		$this->assertSame( '', ApiKey::stored() );
		$this->assertSame( '', ApiKey::resolve() );
		$this->assertSame( 'unreadable', ApiKey::stored_state() );
		$this->assertSame( 'none', ApiKey::source() );
	}

	public function test_no_stored_key_reads_as_empty(): void {
		$this->assertSame( 'empty', ApiKey::stored_state() );
	}

	public function test_without_salts_the_key_is_stored_as_plaintext(): void {
		$this->assertSame( 'my-api-key', ApiKey::store( 'my-api-key' ) );
		$this->assertSame( '', ApiKey::key_material() );
	}

	/**
	 * A fresh wp-config.php that was never run through the salt generator
	 * carries WordPress's placeholder text. Sealing with it would be theatre.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_placeholder_salts_do_not_count_as_material(): void {
		define( 'SECURE_AUTH_KEY', 'put your unique phrase here' );
		define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );

		$this->assertSame( '', ApiKey::key_material() );
		$this->assertSame( 'my-api-key', ApiKey::store( 'my-api-key' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_auth_key_is_the_fallback_when_secure_auth_is_absent(): void {
		define( 'AUTH_KEY', 'plain-auth-key' );
		define( 'AUTH_SALT', 'plain-auth-salt' );

		$this->assertSame( 'plain-auth-keyplain-auth-salt', ApiKey::key_material() );
		$this->assertStringStartsWith( 'wynko:v1:', ApiKey::store( 'my-api-key' ) );
	}

	public function test_falls_back_to_the_stored_option(): void {
		update_option( Config::option_key( 'api_key' ), '  option-key  ' );

		$this->assertSame( 'option-key', ApiKey::resolve() );
		$this->assertSame( 'option', ApiKey::source() );
		$this->assertSame( '', ApiKey::constant_name() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_network_constant_beats_the_option(): void {
		define( 'WYNKO_API_KEY', 'network-key' );
		update_option( Config::option_key( 'api_key' ), 'option-key' );

		$this->assertSame( 'network-key', ApiKey::resolve() );
		$this->assertSame( 'constant', ApiKey::source() );
		$this->assertSame( 'WYNKO_API_KEY', ApiKey::constant_name() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_per_site_constant_beats_the_network_constant(): void {
		$GLOBALS['wynko_test_blog_id'] = 3;
		define( 'WYNKO_API_KEY', 'network-key' );
		define( 'WYNKO_API_KEY_3', 'site-three-key' );

		$this->assertSame( 'site-three-key', ApiKey::resolve() );
		$this->assertSame( 'WYNKO_API_KEY_3', ApiKey::constant_name() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_another_sites_constant_is_ignored(): void {
		$GLOBALS['wynko_test_blog_id'] = 2;
		define( 'WYNKO_API_KEY_3', 'site-three-key' );
		update_option( Config::option_key( 'api_key' ), 'option-key' );

		$this->assertSame( 'option-key', ApiKey::resolve() );
		$this->assertSame( 'option', ApiKey::source() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_blank_constant_falls_through(): void {
		define( 'WYNKO_API_KEY', '   ' );
		update_option( Config::option_key( 'api_key' ), 'option-key' );

		$this->assertSame( 'option-key', ApiKey::resolve() );
		$this->assertSame( 'option', ApiKey::source() );
		$this->assertSame( '', ApiKey::constant_name() );
	}
}
