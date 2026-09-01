<?php
/**
 * Tests for the Notifications tab's settings.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\NotificationsTab;
use Wynko\Admin\SettingsPage;
use Wynko\Config;
use Wynko\Notifier;
use PHPUnit\Framework\TestCase;

/** Covers the tab's sanitisers, its warning state, and its place in the screen. */
final class NotificationsTabTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_the_tab_is_listed_on_the_settings_screen(): void {
		$this->assertArrayHasKey( 'notifications', SettingsPage::tabs() );
		$this->assertSame( 'notifications', SettingsPage::current_tab( 'notifications' ) );
	}

	public function test_the_tab_owns_its_settings_group(): void {
		// Sharing the API tab's group would make saving either tab reset the
		// other's options: wp-admin/options.php writes every option registered
		// to the submitted group, passing null for anything the form did not post.
		$this->assertNotSame( SettingsPage::GROUP, SettingsPage::GROUP_NOTIFICATIONS );
		$this->assertNotSame( SettingsPage::PAGE, SettingsPage::PAGE_NOTIFICATIONS );
	}

	public function test_deployed_recipients_lock_the_switch_on_screen(): void {
		// Stored on, so the hidden field has something to prove it carries: a
		// disabled control posts nothing, and options.php would otherwise write
		// this option away on the next save of the tab.
		update_option( 'wynko_notify_enabled', true );
		putenv( 'WYNKO_NOTIFY_EMAILS=deploy@example.org' );

		try {
			ob_start();
			NotificationsTab::field_enabled();
			$html = (string) ob_get_clean();
		} finally {
			putenv( 'WYNKO_NOTIFY_EMAILS' );
		}

		$this->assertStringContainsString( 'checked', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'WYNKO_NOTIFY_EMAILS', $html );
		$this->assertStringContainsString(
			'<input type="hidden" name="wynko_notify_enabled" value="1"',
			$html
		);
	}

	public function test_the_enabled_sanitiser_yields_a_bool(): void {
		$this->assertTrue( NotificationsTab::sanitize_enabled( '1' ) );
		$this->assertFalse( NotificationsTab::sanitize_enabled( null ) );
		$this->assertFalse( NotificationsTab::sanitize_enabled( '0' ) );
	}

	public function test_the_address_sanitiser_normalises_the_list(): void {
		$this->assertSame(
			'a@example.org, b@example.org',
			NotificationsTab::sanitize_emails( ' a@example.org ;b@example.org ' )
		);
	}

	public function test_the_address_sanitiser_keeps_a_multi_line_paste(): void {
		// sanitize_text_field() collapses newlines, so parsing has to happen
		// before sanitising or a pasted list fuses into one unparseable blob.
		$this->assertSame(
			'a@example.org, b@example.org',
			NotificationsTab::sanitize_emails( "a@example.org\nb@example.org" )
		);
	}

	public function test_the_address_sanitiser_drops_invalid_addresses_and_says_so(): void {
		$stored = NotificationsTab::sanitize_emails( 'nope, ops@example.org' );

		$this->assertSame( 'ops@example.org', $stored );
		$this->assertNotSame( array(), $GLOBALS['wynko_test_settings_errors'] );
	}

	public function test_the_address_sanitiser_caps_the_list(): void {
		$typed = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$typed[] = 'a' . $i . '@example.org';
		}

		$stored = NotificationsTab::sanitize_emails( implode( ',', $typed ) );

		$this->assertCount( 10, explode( ', ', $stored ) );
	}

	public function test_the_address_sanitiser_names_addresses_dropped_for_exceeding_the_cap(): void {
		$typed = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$typed[] = 'a' . $i . '@example.org';
		}

		NotificationsTab::sanitize_emails( implode( ',', $typed ) );

		$errors = wynko_test_settings_errors();
		$codes  = array_column( $errors, 'code' );
		$this->assertContains( 'wynko_notify_overflow', $codes );
	}

	public function test_it_warns_when_switched_on_with_nobody_to_mail(): void {
		update_option( 'wynko_notify_enabled', true );
		update_option( 'wynko_notify_emails', '' );

		$this->assertStringContainsString( 'notice-warning', NotificationsTab::warning() );
	}

	public function test_it_does_not_warn_when_configured(): void {
		update_option( 'wynko_notify_enabled', true );
		update_option( 'wynko_notify_emails', 'ops@example.org' );

		$this->assertSame( '', NotificationsTab::warning() );
	}

	public function test_it_does_not_warn_while_switched_off(): void {
		update_option( 'wynko_notify_enabled', false );
		update_option( 'wynko_notify_emails', '' );

		$this->assertSame( '', NotificationsTab::warning() );
	}

	public function test_the_test_send_redirect_targets_the_tab(): void {
		$url = NotificationsTab::test_redirect_url( 'ok' );

		$this->assertStringContainsString( 'tab=notifications', $url );
		$this->assertStringContainsString( 'wynko_notify_test=ok', $url );
	}

	public function test_the_test_handler_refuses_a_user_without_the_capability(): void {
		$GLOBALS['wynko_test_can_manage'] = false;

		$this->expectException( WpDieException::class );
		NotificationsTab::handle_test();
	}

	public function test_the_test_handler_refuses_a_bad_nonce(): void {
		$GLOBALS['wynko_test_can_manage'] = true;
		$_POST['_wpnonce']                = 'nonce:wrong';

		try {
			$this->expectException( WpDieException::class );
			NotificationsTab::handle_test();
		} finally {
			unset( $_POST['_wpnonce'] );
		}
	}

	/**
	 * Renders the tab's notice for one redirect flag.
	 *
	 * @param string $flag Flag to place in the query string.
	 * @return string
	 */
	private function notice_for( string $flag ): string {
		$_GET['wynko_notify_test'] = $flag;

		ob_start();
		NotificationsTab::render_notice();
		$html = (string) ob_get_clean();
		unset( $_GET['wynko_notify_test'] );

		return $html;
	}

	public function test_the_notice_reports_a_successful_test(): void {
		$this->assertStringContainsString( 'notice-success', $this->notice_for( Notifier::TEST_OK ) );
	}

	public function test_the_missing_address_notice_names_the_missing_address(): void {
		$html = $this->notice_for( Notifier::TEST_NO_RECIPIENTS );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'no valid address', $html );
	}

	/**
	 * The bug: a mailer refusal used to render the missing-address wording,
	 * which is a claim about the operator's configuration that is not true.
	 *
	 * @return void
	 */
	public function test_the_mailer_failure_notice_does_not_blame_the_address(): void {
		$html = $this->notice_for( Notifier::TEST_FAILED );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringNotContainsString( 'no valid address', $html );
		$this->assertStringContainsString( 'activity log', $html );
	}

	public function test_an_unknown_flag_renders_nothing(): void {
		$this->assertSame( '', $this->notice_for( 'bogus' ) );
	}

	public function test_the_notice_stays_quiet_without_a_flag(): void {
		ob_start();
		NotificationsTab::render_notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_test_button_carries_its_own_nonce(): void {
		$GLOBALS['wynko_test_can_manage'] = true;

		ob_start();
		NotificationsTab::render();
		$html = (string) ob_get_clean();

		// Its own nonce, distinct from the settings form's: the test send posts
		// to admin-post.php, not to options.php.
		$this->assertStringContainsString( 'value="wynko_notify_test"', $html );
		$this->assertStringContainsString( 'value="' . wp_create_nonce( NotificationsTab::ACTION_TEST ) . '"', $html );
	}

	public function test_the_field_renders_the_stored_list(): void {
		update_option( 'wynko_notify_emails', 'ops@example.org' );

		ob_start();
		NotificationsTab::field_emails();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="ops@example.org"', $html );
		$this->assertStringContainsString( 'wynko_notify_emails', $html );
	}
	public function test_the_recipients_row_is_hidden_while_alerts_are_off(): void {
		update_option( Config::option_key( 'notify_enabled' ), false );

		ob_start();
		NotificationsTab::field_emails();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'wynko-hidden', $html );
	}

	public function test_the_recipients_row_is_visible_while_alerts_are_on(): void {
		update_option( Config::option_key( 'notify_enabled' ), true );

		ob_start();
		NotificationsTab::field_emails();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'wynko-hidden', $html );
	}

	/**
	 * A disabled input does not post, and options.php writes every option in
	 * the submitted group — so disabling it would blank the stored addresses.
	 */
	public function test_the_recipients_input_is_never_disabled(): void {
		update_option( Config::option_key( 'notify_enabled' ), false );

		ob_start();
		NotificationsTab::field_emails();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'name="wynko_notify_emails"', $html );
	}

	public function test_the_recipients_are_nested_under_the_switch_that_governs_them(): void {
		update_option( Config::option_key( 'notify_enabled' ), true );

		ob_start();
		NotificationsTab::field_enabled();
		$html = (string) ob_get_clean();

		// One row: the checkbox, and beneath it the label, the field, and the
		// test send. A "Send to" row of its own left the label standing over a
		// field that was not rendered.
		$this->assertStringContainsString( 'wynko-notify-enabled', $html );
		$this->assertStringContainsString( 'wynko-notify-emails', $html );
		$this->assertStringContainsString( 'Send to', $html );
		$this->assertStringContainsString( 'Send test email', $html );
	}

	public function test_the_whole_nested_block_hides_while_alerts_are_off(): void {
		update_option( Config::option_key( 'notify_enabled' ), false );

		ob_start();
		NotificationsTab::field_enabled();
		$html = (string) ob_get_clean();

		// The label, the field, and the button are inside the hidden container,
		// so the label cannot be left showing on its own.
		$this->assertStringContainsString( 'wynko-notify-emails wynko-nested-fields wynko-hidden', $html );
	}

	public function test_the_recipients_field_shows_the_shape_it_wants(): void {
		update_option( Config::option_key( 'notify_enabled' ), true );

		ob_start();
		NotificationsTab::field_emails();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'placeholder="name@domain1.com,name@domain2.com"', $html );
		$this->assertStringContainsString( 'Maximum 10 addresses', $html );
	}

	public function test_a_deployed_switch_replaces_the_checkbox_with_its_source(): void {
		putenv( 'WYNKO_NOTIFY_ENABLED=true' );

		ob_start();
		NotificationsTab::field_enabled();
		$html = (string) ob_get_clean();
		putenv( 'WYNKO_NOTIFY_ENABLED' );

		$this->assertStringContainsString( 'WYNKO_NOTIFY_ENABLED', $html );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		// Still posts what is stored, or saving the tab would clear it.
		$this->assertStringContainsString( 'type="hidden"', $html );
	}
}
