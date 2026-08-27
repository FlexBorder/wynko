<?php
/**
 * Tests for the Security tab's settings.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\SecurityTab;
use Wynko\Admin\SettingsPage;
use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Log;
use Wynko\Throttle;
use PHPUnit\Framework\TestCase;

/** Covers the tab's place in the screen, its group, and its clamps. */
final class SecurityTabTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_the_tab_is_listed_on_the_settings_screen(): void {
		$this->assertArrayHasKey( SettingsPage::TAB_SECURITY, SettingsPage::tabs() );
		$this->assertSame( SettingsPage::TAB_SECURITY, SettingsPage::current_tab( 'security' ) );
	}

	public function test_the_tab_owns_its_settings_group(): void {
		// Sharing the API tab's group would make saving either tab reset the
		// other's options: wp-admin/options.php writes every option registered
		// to the submitted group, passing null for anything the form did not post.
		$this->assertNotSame( SettingsPage::GROUP, SettingsPage::GROUP_SECURITY );
		$this->assertNotSame( SettingsPage::PAGE, SettingsPage::PAGE_SECURITY );
		$this->assertNotSame( SettingsPage::GROUP_NOTIFICATIONS, SettingsPage::GROUP_SECURITY );
	}

	public function test_it_owns_the_three_rate_limit_settings(): void {
		$this->assertSame(
			array( 'throttle_window', 'throttle_ip_max', 'throttle_form_max' ),
			array_keys( SecurityTab::settings() )
		);
		foreach ( SecurityTab::settings() as $sanitizer ) {
			$this->assertIsCallable( array( SecurityTab::class, $sanitizer ) );
		}
	}

	public function test_each_cap_is_clamped_to_its_configured_bounds(): void {
		$bounds = Config::bounds( 'throttle_ip_max' );

		$this->assertSame( (int) $bounds['max'], SecurityTab::sanitize_ip_max( 999999 ) );
		$this->assertSame( (int) $bounds['min'], SecurityTab::sanitize_ip_max( 0 ) );
		$this->assertSame( 20, SecurityTab::sanitize_ip_max( '20' ) );
	}

	public function test_the_window_and_form_cap_are_clamped_too(): void {
		$this->assertSame( (int) Config::bounds( 'throttle_window' )['max'], SecurityTab::sanitize_window( 99999 ) );
		$this->assertSame( (int) Config::bounds( 'throttle_form_max' )['min'], SecurityTab::sanitize_form_max( -5 ) );
	}

	public function test_a_reset_returns_to_the_security_tab(): void {
		$url = SecurityTab::reset_redirect_url();

		$this->assertStringContainsString( 'tab=' . SettingsPage::TAB_SECURITY, $url );
		$this->assertStringContainsString( 'wynko_throttle=reset', $url );
	}

	/**
	 * A form bound to a list, so Throttle has something to name.
	 *
	 * @param string $title Form name.
	 * @return int
	 */
	private function a_form( string $title = 'Newsletter signup' ): int {
		return wynko_test_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Fills one form's window to the given number of signups.
	 *
	 * @param int $form_id Form post id.
	 * @param int $hits    How many submissions to record.
	 * @return void
	 */
	private function fill_window( int $form_id, int $hits ): void {
		// A cap low enough that the threshold is reachable without recording
		// hundreds of submissions; the per-visitor cap is raised out of the way
		// so the per-form counter is what is being exercised.
		update_option( Config::option_key( 'throttle_form_max' ), 10 );
		update_option( Config::option_key( 'throttle_ip_max' ), 1000 );

		for ( $i = 0; $i < $hits; $i++ ) {
			Throttle::allows( $form_id, '203.0.113.9' );
		}
	}

	public function test_the_count_cell_reports_signups_against_the_cap(): void {
		$id = $this->a_form();
		$this->fill_window( $id, 3 );

		$cell = SecurityTab::signup_count_cell( $id );

		$this->assertStringContainsString( '3 of 10', $cell );
		$this->assertStringNotContainsString( 'wynko-count-tight', $cell );
	}

	public function test_a_form_near_its_cap_is_marked(): void {
		$id = $this->a_form();
		$this->fill_window( $id, 8 );

		$this->assertStringContainsString( 'wynko-count-tight', SecurityTab::signup_count_cell( $id ) );
	}

	public function test_reaching_the_threshold_records_one_error_a_day(): void {
		$id = $this->a_form( 'Footer signup' );
		$this->fill_window( $id, 10 );

		$errors = array_filter(
			Log::all(),
			static function ( array $entry ): bool {
				return 'error' === $entry['level'] && false !== strpos( $entry['message'], 'Footer signup' );
			}
		);

		// Ten submissions, eight of them at or past the threshold, one entry.
		$this->assertCount( 1, $errors );
	}

	public function test_the_notice_names_the_form_and_points_at_the_caps(): void {
		$id = $this->a_form( 'Footer signup' );
		$this->fill_window( $id, 8 );
		wynko_test_set_can_manage( true );

		ob_start();
		SecurityTab::render_admin_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Footer signup', $html );
		$this->assertStringContainsString( 'tab=' . SettingsPage::TAB_SECURITY, $html );
	}

	/** The Security tab it points at is a per-site screen, absent from a network menu. */
	public function test_the_notice_stays_out_of_network_admin(): void {
		$id = $this->a_form( 'Footer signup' );
		$this->fill_window( $id, 8 );
		wynko_test_set_can_manage( true );
		$GLOBALS['wynko_test_is_network_admin'] = true;

		ob_start();
		SecurityTab::render_admin_notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_a_quiet_site_gets_no_notice(): void {
		$this->a_form();
		wynko_test_set_can_manage( true );

		ob_start();
		SecurityTab::render_admin_notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_clearing_the_counters_clears_the_warning(): void {
		$id = $this->a_form();
		$this->fill_window( $id, 8 );
		Throttle::reset();

		$this->assertSame( array(), Throttle::pressured() );
		$this->assertSame( 0, Throttle::form_hits( $id ) );
	}

	public function test_the_window_is_explained_in_minutes(): void {
		update_option( Config::option_key( 'throttle_window' ), 10 );

		$this->assertStringContainsString( '10 minutes', SecurityTab::window_sentence() );
	}
}
