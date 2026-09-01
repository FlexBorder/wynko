<?php
/**
 * Tests for the site-wide auto-disabled-integration notice.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\IntegrationAutoDisabledNotice;
use Wynko\Config;
use PHPUnit\Framework\TestCase;

/** Covers what raises the notice, its wording, and dismissal. */
final class IntegrationAutoDisabledNoticeTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		wynko_test_set_can_manage( true );
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'contact-form-7', 'Contact Form 7' );
				$integrations[] = new FakeIntegration( 'html-forms', 'HTML Forms' );
				return $integrations;
			}
		);
	}

	/**
	 * @return string
	 */
	private function rendered(): string {
		ob_start();
		IntegrationAutoDisabledNotice::render();
		return (string) ob_get_clean();
	}

	public function test_an_empty_queue_says_nothing(): void {
		$this->assertSame( '', $this->rendered() );
	}

	public function test_a_queued_integration_is_named(): void {
		update_option( Config::option_key( 'integrations_auto_disabled' ), array( 'contact-form-7' ) );

		$html = $this->rendered();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Contact Form 7', $html );
	}

	public function test_more_than_one_queued_integration_is_named(): void {
		update_option( Config::option_key( 'integrations_auto_disabled' ), array( 'contact-form-7', 'html-forms' ) );

		$html = $this->rendered();

		$this->assertStringContainsString( 'Contact Form 7', $html );
		$this->assertStringContainsString( 'HTML Forms', $html );
	}

	public function test_a_slug_no_longer_registered_falls_back_to_itself(): void {
		update_option( Config::option_key( 'integrations_auto_disabled' ), array( 'long-gone' ) );

		$this->assertStringContainsString( 'long-gone', $this->rendered() );
	}

	public function test_a_user_without_the_capability_is_shown_nothing(): void {
		update_option( Config::option_key( 'integrations_auto_disabled' ), array( 'contact-form-7' ) );
		wynko_test_set_can_manage( false );

		$this->assertSame( '', $this->rendered() );
	}

	public function test_the_notice_links_to_the_integrations_screen(): void {
		update_option( Config::option_key( 'integrations_auto_disabled' ), array( 'contact-form-7' ) );

		$this->assertStringContainsString( 'page=wynko-integrations', $this->rendered() );
	}

	public function test_the_notice_dismisses_with_the_native_x_not_a_labelled_button(): void {
		update_option( Config::option_key( 'integrations_auto_disabled' ), array( 'contact-form-7' ) );

		$html = $this->rendered();

		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'class="notice-dismiss"', $html );
		$this->assertStringNotContainsString( '>Dismiss<', $html );
	}

	public function test_the_dismiss_link_carries_a_nonce(): void {
		$this->assertStringContainsString( '_wpnonce', IntegrationAutoDisabledNotice::dismiss_url() );
	}

	public function test_the_dismiss_handler_refuses_a_user_without_the_capability(): void {
		wynko_test_set_can_manage( false );

		$this->expectException( WpDieException::class );
		IntegrationAutoDisabledNotice::handle_dismiss();
	}

	public function test_dismissing_drains_the_queue(): void {
		update_option( Config::option_key( 'integrations_auto_disabled' ), array( 'contact-form-7' ) );

		IntegrationAutoDisabledNotice::dismiss();

		$this->assertSame( array(), IntegrationAutoDisabledNotice::queued() );
	}
}
