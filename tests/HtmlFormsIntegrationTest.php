<?php
/**
 * Tests for the HTML Forms bridge, run without HTML Forms' classes present.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Integrations\HtmlForms\HtmlFormsIntegration;
use PHPUnit\Framework\TestCase;

/** Tests that don't need HTML_Forms\Forms to exist. */
final class HtmlFormsIntegrationTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
	}

	public function test_it_reports_the_bundled_identity(): void {
		$integration = new HtmlFormsIntegration();

		$this->assertSame( 'html-forms', $integration->slug() );
		$this->assertSame( '', $integration->author() );
	}

	/**
	 * Isolated from the rest of the suite: HtmlFormsIntegrationAvailableTest
	 * defines HTML_Forms\Forms globally for its own run, and that definition
	 * outlives it for the rest of the process once loaded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_it_is_unavailable_without_the_html_forms_classes(): void {
		$this->assertFalse( class_exists( '\HTML_Forms\Forms', false ) );

		$integration = new HtmlFormsIntegration();
		$this->assertFalse( $integration->is_available() );
	}
}
