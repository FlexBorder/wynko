<?php
/**
 * Tests for the About tab.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\AboutTab;
use PHPUnit\Framework\TestCase;

/** Covers what the tab prints: prose, the help links, and the system report. */
final class AboutTabTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		$GLOBALS['wynko_test_can_manage'] = true;
	}

	/**
	 * The rendered tab.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		AboutTab::render();
		return (string) ob_get_clean();
	}

	public function test_it_carries_the_report_and_the_support_links(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'System report', $html );
		$this->assertStringContainsString( 'Getting help', $html );
		$this->assertStringContainsString( 'Download report', $html );
		$this->assertStringContainsString( 'Test connection now', $html );
	}

	public function test_an_unregistered_support_url_renders_as_text_not_an_empty_link(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'href=""', $html );
		$this->assertStringContainsString( 'not available yet', $html );
	}

	public function test_it_keeps_the_independence_notice(): void {
		$this->assertStringContainsString( 'not affiliated with', $this->render() );
	}

	public function test_the_inspiration_paragraph_names_mc4wp_and_links_it(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Wynko was inspired by', $html );
		$this->assertStringContainsString( 'MC4WP — Mailchimp for WordPress', $html );
		$this->assertStringContainsString( 'https://wordpress.org/plugins/mailchimp-for-wp/', $html );
	}

	public function test_independence_says_where_a_laposta_problem_goes(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Laposta support', $html );
		$this->assertStringContainsString( 'https://www.laposta.nl/contact', $html );
	}

	/**
	 * HTTP/2, the accepted TLS versions, and whether an object cache is
	 * installed are all things PHP cannot see past a proxy to check. They are
	 * recommendations, and the tab has to read as recommending rather than as
	 * reporting a fault.
	 */
	public function test_the_host_recommendations_are_stated_as_guidance(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Recommended server configuration', $html );
		$this->assertStringContainsString( 'TLS 1.2 or 1.3', $html );
		$this->assertStringContainsString( 'HTTP/2', $html );
		$this->assertStringContainsString( 'persistent object cache', $html );
		$this->assertStringContainsString( 'None of these are checked', $html );
	}
}
