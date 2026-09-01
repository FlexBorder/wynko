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

	public function test_it_owns_the_rate_limit_and_opt_out_settings(): void {
		$this->assertSame(
			array( 'disable_form_throttle', 'throttle_window', 'throttle_ip_max', 'throttle_form_max', 'disable_form_nonce' ),
			array_keys( SecurityTab::settings() )
		);
		foreach ( SecurityTab::settings() as $sanitizer ) {
			$this->assertIsCallable( array( SecurityTab::class, $sanitizer ) );
		}
	}

	/**
	 * Inverted the same way sanitize_disable_throttle() is: the box reads
	 * "Enable nonce verification" and is checked by default, so a checked box
	 * must save disable_form_nonce as false and an absent (unchecked) one as
	 * true.
	 */
	public function test_the_nonce_checkbox_normalises_to_an_inverted_boolean(): void {
		$this->assertFalse( SecurityTab::sanitize_disable_nonce( '1' ) );
		$this->assertTrue( SecurityTab::sanitize_disable_nonce( null ) );
	}

	/**
	 * Inverted relative to every other checkbox here: this one reads "Enable
	 * rate limiting" and is checked by default, so a checked box must save
	 * disable_form_throttle as false and an absent (unchecked) one as true.
	 */
	public function test_the_throttle_checkbox_normalises_to_an_inverted_boolean(): void {
		$this->assertFalse( SecurityTab::sanitize_disable_throttle( '1' ) );
		$this->assertTrue( SecurityTab::sanitize_disable_throttle( null ) );
	}

	/** On by default: a fresh install must show the box checked, nothing hidden. */
	public function test_the_throttle_checkbox_is_checked_by_default(): void {
		ob_start();
		SecurityTab::field_throttle_enabled();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="wynko-throttle-enabled"', $html );
		$this->assertStringContainsString( 'checked', $html );
	}

	public function test_disabling_throttle_via_the_option_unchecks_the_box(): void {
		update_option( Config::option_key( 'disable_form_throttle' ), true );

		ob_start();
		SecurityTab::field_throttle_enabled();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'checked', $html );
	}

	/** On by default, same as the throttle checkbox. */
	public function test_the_nonce_checkbox_is_checked_by_default(): void {
		ob_start();
		SecurityTab::field_nonce_enabled();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="wynko-nonce-enabled"', $html );
		$this->assertStringContainsString( 'checked', $html );
	}

	public function test_disabling_nonce_via_the_option_unchecks_the_box(): void {
		update_option( Config::option_key( 'disable_form_nonce' ), true );

		ob_start();
		SecurityTab::field_nonce_enabled();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'checked', $html );
	}

	/** Nothing changes for a site that never touches either setting. */
	public function test_a_quiet_site_shows_no_warning(): void {
		ob_start();
		SecurityTab::field_throttle_enabled();
		SecurityTab::field_nonce_enabled();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'notice-warning', $html );
	}

	/** The warning reuses core's own notice styling, right after the field it is about. */
	public function test_disabling_the_nonce_shows_a_warning_right_after_its_own_checkbox(): void {
		update_option( Config::option_key( 'disable_form_nonce' ), true );

		ob_start();
		SecurityTab::field_nonce_enabled();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="notice notice-warning inline">', $html );
		$this->assertStringContainsString( 'Nonce verification is off', $html );
		$this->assertGreaterThan( strpos( $html, 'wynko-nonce-enabled' ), strpos( $html, 'Nonce verification is off' ) );
	}

	public function test_disabling_the_throttle_shows_a_warning_right_after_its_own_checkbox(): void {
		update_option( Config::option_key( 'disable_form_throttle' ), true );

		ob_start();
		SecurityTab::field_throttle_enabled();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="notice notice-warning inline">', $html );
		$this->assertStringContainsString( 'Rate limiting is off', $html );
		$this->assertGreaterThan( strpos( $html, 'wynko-throttle-enabled' ), strpos( $html, 'Rate limiting is off' ) );
	}

	/** The warning is scoped to this one field, never a visitor-facing or cross-screen notice. */
	public function test_the_warning_is_not_part_of_the_cross_screen_admin_notice(): void {
		update_option( Config::option_key( 'disable_form_nonce' ), true );
		wynko_test_set_can_manage( true );

		ob_start();
		SecurityTab::render_admin_notice();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Nonce verification is off', $html );
	}

	/**
	 * The three caps are nested inside one wrapper, the same pattern
	 * NotificationsTab uses for the recipients field under its own switch —
	 * form.js hides it as one unit by toggling this one class, and
	 * wynko-nested-fields is the shared indent styling both tabs' nested
	 * blocks carry, so the visual treatment lives in one place rather than
	 * being duplicated per feature.
	 */
	public function test_the_capped_fields_are_nested_under_one_wrapper(): void {
		ob_start();
		SecurityTab::field_throttle_fields();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wynko-throttle-fields wynko-nested-fields">', $html );
		$this->assertStringContainsString( 'id="wynko-throttle-window"', $html );
		$this->assertStringContainsString( 'id="wynko-throttle-ip-max"', $html );
		$this->assertStringContainsString( 'id="wynko-throttle-form-max"', $html );
	}

	public function test_the_nested_wrapper_carries_the_hide_marker_when_disabled(): void {
		update_option( Config::option_key( 'disable_form_throttle' ), true );

		ob_start();
		SecurityTab::field_throttle_fields();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wynko-throttle-fields wynko-nested-fields wynko-hidden">', $html );
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
	 * The reset button belongs to the per-form counts table, not to the
	 * screen's own Save changes row: it lives in the table's own footer,
	 * reaching a form declared elsewhere on the page by its form="" attribute
	 * rather than by being physically inside it (forms cannot nest in HTML).
	 */
	public function test_the_reset_button_is_part_of_the_counts_table(): void {
		$this->a_form();

		ob_start();
		SecurityTab::field_throttle_fields();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'form="wynko-reset-throttle"', $html );
		$this->assertGreaterThan( strpos( $html, '<tfoot>' ), strpos( $html, 'form="wynko-reset-throttle"' ) );
		$this->assertLessThan( strpos( $html, '</table>' ), strpos( $html, 'form="wynko-reset-throttle"' ) );
	}

	/** With no forms there is nothing to reset, so the table — and its button — do not print at all. */
	public function test_no_forms_means_no_counts_table_and_no_reset_button(): void {
		ob_start();
		SecurityTab::field_throttle_fields();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'wynko-form-counts', $html );
		$this->assertStringNotContainsString( 'Reset signup limits', $html );
	}

	/** render() itself must never carry its own copy of the button any more. */
	public function test_the_top_level_render_carries_no_reset_button_of_its_own(): void {
		ob_start();
		SecurityTab::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Reset signup limits', $html );
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
