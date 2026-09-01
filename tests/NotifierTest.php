<?php
/**
 * Tests for the critical-email alert.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Log;
use Wynko\Notifier;
use PHPUnit\Framework\TestCase;

/** Covers every gate between an error being logged and a mail going out. */
final class NotifierTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		Notifier::reset_guard();
		update_option( 'wynko_notify_enabled', true );
		update_option( 'wynko_notify_emails', 'ops@example.org' );
	}

	protected function tearDown(): void {
		Notifier::reset_guard();
	}

	/**
	 * The mails the wp_mail() shim recorded.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function sent(): array {
		return $GLOBALS['wynko_test_mail'];
	}

	public function test_an_error_sends_one_mail(): void {
		Log::error( 'Sync failed: timeout' );

		$this->assertCount( 1, $this->sent() );
		$this->assertSame( array( 'ops@example.org' ), $this->sent()[0]['to'] );
		$this->assertStringContainsString( 'Sync failed: timeout', $this->sent()[0]['body'] );
	}

	public function test_warnings_and_info_send_nothing(): void {
		Log::warning( 'slow' );
		Log::info( 'fine' );

		$this->assertSame( array(), $this->sent() );
	}

	public function test_deployed_recipients_switch_the_alerts_on(): void {
		update_option( 'wynko_notify_enabled', false );
		putenv( 'WYNKO_NOTIFY_EMAILS=deploy@example.org' );

		try {
			$this->assertTrue( Notifier::enabled() );
			$this->assertTrue( Notifier::forced() );

			Log::error( 'boom' );

			$this->assertCount( 1, $this->sent() );
			$this->assertSame( array( 'deploy@example.org' ), $this->sent()[0]['to'] );
		} finally {
			putenv( 'WYNKO_NOTIFY_EMAILS' );
		}
	}

	public function test_a_deployed_switch_outranks_deployed_recipients(): void {
		putenv( 'WYNKO_NOTIFY_EMAILS=deploy@example.org' );
		putenv( 'WYNKO_NOTIFY_ENABLED=off' );

		try {
			$this->assertFalse( Notifier::enabled() );
			$this->assertFalse( Notifier::forced() );

			Log::error( 'boom' );

			$this->assertSame( array(), $this->sent() );
		} finally {
			putenv( 'WYNKO_NOTIFY_EMAILS' );
			putenv( 'WYNKO_NOTIFY_ENABLED' );
		}
	}

	public function test_deployed_recipients_with_no_deliverable_address_switch_nothing_on(): void {
		update_option( 'wynko_notify_enabled', false );
		putenv( 'WYNKO_NOTIFY_EMAILS=not-an-address' );

		try {
			$this->assertFalse( Notifier::enabled() );
			$this->assertFalse( Notifier::forced() );
		} finally {
			putenv( 'WYNKO_NOTIFY_EMAILS' );
		}
	}

	public function test_it_sends_nothing_while_disabled(): void {
		update_option( 'wynko_notify_enabled', false );

		Log::error( 'boom' );

		$this->assertSame( array(), $this->sent() );
	}

	public function test_it_sends_nothing_without_a_valid_recipient(): void {
		update_option( 'wynko_notify_emails', 'not-an-address' );

		Log::error( 'boom' );

		$this->assertSame( array(), $this->sent() );
	}

	public function test_invalid_addresses_are_dropped_from_a_mixed_list(): void {
		update_option( 'wynko_notify_emails', 'nope, ops@example.org' );

		Log::error( 'boom' );

		$this->assertSame( array( 'ops@example.org' ), $this->sent()[0]['to'] );
	}

	public function test_a_second_error_inside_the_hour_sends_nothing(): void {
		Log::error( 'first' );
		Log::error( 'second' );

		$this->assertCount( 1, $this->sent() );
	}

	public function test_the_throttle_is_written_before_the_send(): void {
		$GLOBALS['wynko_test_mail_result'] = false;

		Log::error( 'boom' );

		$this->assertNotFalse( get_transient( Config::notify_transient_key() ) );
	}

	public function test_a_failed_send_is_recorded_without_recursing(): void {
		$GLOBALS['wynko_test_mail_result'] = false;

		Log::error( 'boom' );

		// Two entries, one mail attempt: the failure is recorded at error level
		// so it stays visible to an operator whose threshold is 'error', and the
		// in-flight guard is what stops that entry triggering another send.
		$this->assertCount( 2, Log::all() );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_the_guard_alone_stops_a_send(): void {
		// Asserted directly rather than through a nested log call: with the
		// throttle written before the send, a re-entrant call is already blocked
		// by the throttle, so a nested-call test would pass with the guard
		// deleted and claim coverage it does not have.
		Notifier::set_guard_for_test( true );

		Notifier::maybe_notify( 'error', 'boom' );

		$this->assertSame( array(), $this->sent() );
		$this->assertFalse( get_transient( Config::notify_transient_key() ) );
	}

	public function test_the_next_hour_sends_again(): void {
		Log::error( 'first' );
		delete_transient( Config::notify_transient_key() );
		Log::error( 'second' );

		$this->assertCount( 2, $this->sent() );
	}

	public function test_the_subject_names_the_site(): void {
		$this->assertSame( '[Example] Laposta error', Notifier::subject() );
	}

	public function test_the_body_carries_the_message_and_points_at_the_log(): void {
		$body = Notifier::body( 'Sync failed: timeout' );

		$this->assertStringContainsString( 'Sync failed: timeout', $body );
		$this->assertStringContainsString( 'wynko-log', $body );
	}

	public function test_a_test_send_ignores_and_does_not_write_the_throttle(): void {
		$this->assertSame( Notifier::TEST_OK, Notifier::send_test() );
		$this->assertSame( Notifier::TEST_OK, Notifier::send_test() );

		$this->assertCount( 2, $this->sent() );
		$this->assertFalse( get_transient( Config::notify_transient_key() ) );
	}

	public function test_a_test_send_without_recipients_says_so(): void {
		update_option( 'wynko_notify_emails', '' );

		$this->assertSame( Notifier::TEST_NO_RECIPIENTS, Notifier::send_test() );
		$this->assertSame( array(), $this->sent() );
	}

	/**
	 * The bug this distinction exists for: a configured, valid address plus a
	 * mailer that refuses the message is not "no address configured", and
	 * reporting it as such sends the operator hunting the wrong thing.
	 *
	 * @return void
	 */
	public function test_a_mailer_refusal_is_not_reported_as_a_missing_address(): void {
		$GLOBALS['wynko_test_mail_result'] = false;

		$verdict = Notifier::send_test();

		$this->assertSame( Notifier::TEST_FAILED, $verdict );
		$this->assertNotSame( Notifier::TEST_NO_RECIPIENTS, $verdict );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_a_failed_test_send_records_the_mailer_s_reason(): void {
		$GLOBALS['wynko_test_mail_result'] = false;
		$GLOBALS['wynko_test_mail_reason'] = 'Invalid address: (From): wordpress@localhost';

		Notifier::send_test();

		$entries = Log::all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'error', $entries[0]['level'] );
		$this->assertStringContainsString( 'Invalid address: (From): wordpress@localhost', $entries[0]['message'] );
	}

	public function test_a_failed_test_send_neither_writes_nor_needs_the_throttle(): void {
		$GLOBALS['wynko_test_mail_result'] = false;

		Notifier::send_test();
		Notifier::send_test();

		// Twice in a row, because a test send must stay usable while the
		// operator fixes the mail configuration.
		$this->assertCount( 2, $this->sent() );
		$this->assertFalse( get_transient( Config::notify_transient_key() ) );
	}

	public function test_a_failed_test_send_does_not_cascade_into_a_real_alert(): void {
		$GLOBALS['wynko_test_mail_result'] = false;

		Notifier::send_test();

		// One attempt: the error it logs must not re-enter the notifier.
		$this->assertCount( 1, $this->sent() );
	}

	public function test_a_test_send_without_a_stated_reason_still_records_the_failure(): void {
		$GLOBALS['wynko_test_mail_result'] = false;
		$GLOBALS['wynko_test_mail_reason'] = '';

		Notifier::send_test();

		$this->assertCount( 1, Log::all() );
		$this->assertSame( 'error', Log::all()[0]['level'] );
	}
	public function test_a_successful_test_send_is_logged(): void {
		update_option( Config::option_key( 'notify_enabled' ), true );
		update_option( Config::option_key( 'notify_emails' ), 'a@example.com,b@example.com' );

		$this->assertSame( Notifier::TEST_OK, Notifier::send_test() );

		$entries = Log::all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'info', $entries[0]['level'] );
		$this->assertSame( 'Test email sent to 2 recipients.', $entries[0]['message'] );
	}

	public function test_wynko_notification_recipients_filter_can_modify_who_is_mailed(): void {
		add_filter(
			'wynko_notification_recipients',
			static function ( $recipients ) {
				return array( 'override@example.org' );
			}
		);

		Log::error( 'boom' );

		$this->assertSame( array( 'override@example.org' ), $this->sent()[0]['to'] );
	}

	public function test_wynko_notification_sent_fires_after_a_successful_alert(): void {
		$fired = array();
		add_action(
			'wynko_notification_sent',
			static function ( $to, $message ) use ( &$fired ) {
				$fired[] = array( $to, $message );
			}
		);

		Log::error( 'boom' );

		$this->assertSame( array( array( array( 'ops@example.org' ), 'boom' ) ), $fired );
	}

	public function test_wynko_notification_sent_does_not_fire_on_a_failed_send(): void {
		$GLOBALS['wynko_test_mail_result'] = false;
		$fired                             = false;
		add_action(
			'wynko_notification_sent',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		Log::error( 'boom' );

		$this->assertFalse( $fired );
	}
}
