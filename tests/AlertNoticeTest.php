<?php
/**
 * Tests for the site-wide failed-alert notice.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\AlertNotice;
use Wynko\Config;
use PHPUnit\Framework\TestCase;

/** Covers what raises the notice, what silences it, and what raises it again. */
final class AlertNoticeTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		wynko_test_set_can_manage( true );
	}

	/**
	 * The rendered notice.
	 *
	 * @return string
	 */
	private function rendered(): string {
		ob_start();
		AlertNotice::render();
		return (string) ob_get_clean();
	}

	public function test_nothing_is_shown_before_a_failure(): void {
		$this->assertFalse( AlertNotice::should_show() );
	}

	public function test_a_recorded_failure_shows(): void {
		AlertNotice::record( 'mail refused' );

		$this->assertTrue( AlertNotice::should_show() );
	}

	public function test_dismissing_silences_it(): void {
		AlertNotice::record( 'mail refused' );

		AlertNotice::dismiss();

		$this->assertFalse( AlertNotice::should_show() );
	}

	/**
	 * Dismissing silences that failure, not the feature — including when both
	 * happen inside the same second, which a timestamp could not order.
	 */
	public function test_a_later_failure_raises_it_again(): void {
		AlertNotice::record( 'mail refused' );
		AlertNotice::dismiss();

		AlertNotice::record( 'mail refused again' );

		$this->assertTrue( AlertNotice::should_show() );
	}

	public function test_dismissing_nothing_records_nothing(): void {
		AlertNotice::dismiss();

		$this->assertSame( array(), get_option( Config::option_key( 'alert_failure' ), array() ) );
	}

	public function test_the_notice_uses_the_native_element_and_names_the_log(): void {
		AlertNotice::record( 'mail refused' );

		$html = $this->rendered();

		$this->assertStringContainsString( 'notice notice-warning', $html );
		$this->assertStringContainsString( 'mail refused', $html );
		$this->assertStringContainsString( 'wynko-log', $html );
	}

	/**
	 * The message carries whatever the mail system said, which is remote text
	 * reaching an admin screen.
	 */
	public function test_a_hostile_message_is_escaped(): void {
		AlertNotice::record( '<script>alert(1)</script>' );

		$html = $this->rendered();

		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * The dismissal is a link rather than the is-dismissible button, because
	 * the plugin's script is not loaded on the screens this notice appears on.
	 */
	public function test_the_dismiss_link_is_nonced_and_goes_to_admin_post(): void {
		AlertNotice::record( 'mail refused' );

		$html = $this->rendered();

		$this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringContainsString( AlertNotice::ACTION_DISMISS, $html );
		$this->assertStringContainsString( '_wpnonce', $html );
	}

	public function test_render_prints_nothing_when_there_is_nothing_to_say(): void {
		$this->assertSame( '', $this->rendered() );
	}
	/** The log link it points at needs manage_options, so the notice does too. */
	public function test_a_reader_without_the_capability_sees_nothing(): void {
		AlertNotice::record( 'mail refused' );
		wynko_test_set_can_manage( false );

		$this->assertSame( '', $this->rendered() );
	}

	/** Both destinations it offers are per-site screens, absent from a network menu. */
	public function test_the_notice_stays_out_of_network_admin(): void {
		AlertNotice::record( 'mail refused' );
		$GLOBALS['wynko_test_is_network_admin'] = true;

		$this->assertSame( '', $this->rendered() );
	}
}
