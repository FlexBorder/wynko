<?php
/**
 * Tests for the Contact Form 7 bridge, run without CF7's classes present.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Integrations\ContactForm7\ContactForm7Integration;
use PHPUnit\Framework\TestCase;

/** Tests that don't need WPCF7_ContactForm/WPCF7_Submission to exist. */
final class ContactForm7IntegrationTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
	}

	public function test_it_reports_the_bundled_identity(): void {
		$integration = new ContactForm7Integration();

		$this->assertSame( 'contact-form-7', $integration->slug() );
		$this->assertSame( '', $integration->author() );
	}

	/**
	 * Isolated from the rest of the suite: ContactForm7IntegrationAvailableTest
	 * defines WPCF7_ContactForm globally for its own run, and that definition
	 * outlives it for the rest of the process once loaded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_it_is_unavailable_without_the_cf7_classes(): void {
		$this->assertFalse( class_exists( 'WPCF7_ContactForm', false ) );

		$integration = new ContactForm7Integration();
		$this->assertFalse( $integration->is_available() );
	}
}
