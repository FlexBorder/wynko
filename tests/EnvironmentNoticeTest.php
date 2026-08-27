<?php
/**
 * Tests for the site-wide untested-environment notice.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\EnvironmentNotice;
use Wynko\Config;
use Wynko\Support\Requirements;
use Wynko\SystemInfo;
use PHPUnit\Framework\TestCase;

/**
 * Covers what raises the notice, what silences it, and what raises it again.
 *
 * The two halves are tested apart: what should_show() makes of a reading is
 * driven through hand-built readings, and what the gather actually finds is
 * driven through the faults the bootstrap can stage (WordPress version,
 * database banner, HTTPS, PHP version).
 */
final class EnvironmentNoticeTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		wynko_test_set_can_manage( true );
	}

	/**
	 * A hand-built reading, in the shape SystemInfo::environment() returns.
	 *
	 * @param array<int,array{name:string,value:string,note:string,status:string}> $items       Shortfalls.
	 * @param string                                                               $fingerprint Digest of the reading.
	 * @return array{status:string,items:array<int,array{name:string,value:string,note:string,status:string}>,fingerprint:string}
	 */
	private function reading( array $items, string $fingerprint = 'abc' ): array {
		return array(
			'status'      => array() === $items ? Requirements::STATUS_OK : Requirements::STATUS_BELOW_ADVISED,
			'items'       => $items,
			'fingerprint' => $fingerprint,
		);
	}

	/**
	 * One shortfall.
	 *
	 * @param string $name Row name.
	 * @return array{name:string,value:string,note:string,status:string}
	 */
	private function shortfall( string $name = 'PHP — Version' ): array {
		return array(
			'name'   => $name,
			'value'  => '8.1.0',
			'note'   => '8.4 or newer is advised',
			'status' => Requirements::STATUS_BELOW_ADVISED,
		);
	}

	/**
	 * The rendered notice.
	 *
	 * @return string
	 */
	private function rendered(): string {
		ob_start();
		EnvironmentNotice::render();
		return (string) ob_get_clean();
	}

	public function test_a_reading_with_no_shortfall_says_nothing(): void {
		$this->assertFalse( EnvironmentNotice::should_show( $this->reading( array() ) ) );
	}

	public function test_a_reading_with_a_shortfall_speaks_up(): void {
		$this->assertTrue( EnvironmentNotice::should_show( $this->reading( array( $this->shortfall() ) ) ) );
	}

	public function test_a_dismissed_reading_is_silenced(): void {
		update_option( Config::option_key( 'env_dismissed' ), 'abc' );

		$this->assertFalse( EnvironmentNotice::should_show( $this->reading( array( $this->shortfall() ), 'abc' ) ) );
	}

	public function test_a_changed_reading_speaks_up_again(): void {
		update_option( Config::option_key( 'env_dismissed' ), 'abc' );

		$this->assertTrue( EnvironmentNotice::should_show( $this->reading( array( $this->shortfall() ), 'def' ) ) );
	}

	public function test_dismissing_stores_the_fingerprint_of_the_environment_in_hand(): void {
		EnvironmentNotice::dismiss();

		$this->assertSame( SystemInfo::environment()['fingerprint'], get_option( Config::option_key( 'env_dismissed' ) ) );
	}

	public function test_dismissal_stores_a_digest_and_never_the_readings(): void {
		$GLOBALS['wynko_test_wp_version'] = '6.5';

		EnvironmentNotice::dismiss();
		$stored = (string) get_option( Config::option_key( 'env_dismissed' ) );

		$this->assertSame( 64, strlen( $stored ) );
		$this->assertStringNotContainsString( '6.5', $stored );
	}

	public function test_a_version_below_what_is_advised_warns_rather_than_errs(): void {
		$GLOBALS['wynko_test_wp_version'] = '6.5';

		$html = $this->rendered();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '6.5', $html );
	}

	public function test_a_version_below_the_floor_wordpress_enforces_is_an_error(): void {
		$GLOBALS['wynko_test_wp_version'] = '6.0';

		$html = $this->rendered();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringNotContainsString( 'notice-warning', $html );
	}

	public function test_an_upgrade_that_fixes_one_fault_of_two_raises_the_notice_again(): void {
		$GLOBALS['wynko_test_wp_version']  = '6.5';
		$GLOBALS['wynko_test_using_https'] = false;
		EnvironmentNotice::dismiss();

		$GLOBALS['wynko_test_using_https'] = true;

		$this->assertTrue( EnvironmentNotice::should_show( SystemInfo::environment() ) );
	}

	public function test_more_faults_than_it_lists_are_summarised(): void {
		$GLOBALS['wynko_test_wp_version']  = '6.0';
		$GLOBALS['wpdb']->server_info      = '5.6.0';
		$GLOBALS['wynko_test_using_https'] = false;
		$GLOBALS['wynko_test_php_version'] = '8.1.0';

		// Four independently staged faults (WordPress, database, HTTPS, PHP) —
		// staged rather than relied upon from the real environment, since the
		// suite now runs across a PHP matrix that reaches the advised version
		// itself.
		$this->assertGreaterThan( EnvironmentNotice::MAX_ITEMS, count( SystemInfo::environment()['items'] ) );

		$html = $this->rendered();

		$this->assertSame( EnvironmentNotice::MAX_ITEMS + 1, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( 'more', $html );
	}

	public function test_the_notice_points_at_the_about_tab(): void {
		$GLOBALS['wynko_test_wp_version'] = '6.5';

		$this->assertStringContainsString( 'tab=about', $this->rendered() );
	}

	/** Repeating the summary above the report it links to reads as a fault. */
	public function test_the_notice_stays_off_the_report_it_links_to(): void {
		$GLOBALS['wynko_test_wp_version'] = '6.5';
		$_GET['page']                     = 'wynko-settings';
		$_GET['tab']                      = 'about';

		$html = $this->rendered();
		unset( $_GET['page'], $_GET['tab'] );

		$this->assertSame( '', $html );
	}

	/**
	 * The plugin registers on admin_menu only, so there is no network screen for
	 * the notice to point at — and its dismissal is per-site, which would
	 * silence one site's copy of a server-wide fact.
	 */
	public function test_nothing_is_printed_in_network_admin(): void {
		$GLOBALS['wynko_test_wp_version']       = '6.0';
		$GLOBALS['wynko_test_is_network_admin'] = true;

		$this->assertSame( '', $this->rendered() );
	}

	public function test_a_user_without_the_capability_is_shown_nothing(): void {
		$GLOBALS['wynko_test_wp_version'] = '6.0';
		wynko_test_set_can_manage( false );

		$this->assertSame( '', $this->rendered() );
	}

	public function test_the_dismiss_handler_refuses_a_user_without_the_capability(): void {
		wynko_test_set_can_manage( false );

		$this->expectException( WpDieException::class );
		EnvironmentNotice::handle_dismiss();
	}

	public function test_the_dismiss_link_carries_a_nonce(): void {
		$this->assertStringContainsString( '_wpnonce', EnvironmentNotice::dismiss_url() );
	}

	/**
	 * The connection verdict is BELOW_REQUIRED whenever the key is wrong, which
	 * is an API problem with its own surface. Rolling it in would nag about
	 * connectivity on every admin screen of every site with a bad key.
	 */
	public function test_a_failing_api_connection_is_not_an_environment_fault(): void {
		set_transient(
			\Wynko\KeyStatus::TRANSIENT,
			array(
				'ok'          => false,
				'message'     => 'The API key was refused.',
				'code'        => 'wynko_status',
				'fingerprint' => 'whatever',
			)
		);

		foreach ( SystemInfo::environment()['items'] as $item ) {
			$this->assertStringNotContainsString( 'Connection', $item['name'] );
		}
	}
}
