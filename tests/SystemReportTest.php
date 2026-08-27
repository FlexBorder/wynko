<?php
/**
 * Tests for the system report's rendering.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\SystemReport;
use Wynko\Log;
use Wynko\Support\Requirements;
use PHPUnit\Framework\TestCase;

/** Covers the text export's shape, the filename, and the on-screen table. */
final class SystemReportTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	/**
	 * A two-row fixture standing in for SystemInfo::sections().
	 *
	 * @return array<int,array{title:string,rows:array<int,array{label:string,value:string,note:string,status:string,action:string}>}>
	 */
	private function fixture(): array {
		return array(
			array(
				'title' => 'PHP',
				'rows'  => array(
					array(
						'label'  => 'Version',
						'value'  => '8.1.2',
						'note'   => '8.2 or newer is advised',
						'status' => Requirements::STATUS_BELOW_ADVISED,
						'action' => '',
					),
					array(
						'label'  => 'Server interface',
						'value'  => 'fpm-fcgi',
						'note'   => '',
						'status' => Requirements::STATUS_INFO,
						'action' => '',
					),
				),
			),
		);
	}

	public function test_the_text_export_carries_a_header_and_every_row(): void {
		$text = SystemReport::text( $this->fixture() );

		$this->assertStringContainsString( 'Wynko — system report', $text );
		$this->assertStringContainsString( '== PHP ==', $text );
		$this->assertStringContainsString( 'Version: 8.1.2', $text );
		$this->assertStringContainsString( '8.2 or newer is advised', $text );
		$this->assertStringContainsString( 'Server interface: fpm-fcgi', $text );
	}

	public function test_only_a_row_that_is_not_ok_carries_a_marker(): void {
		$text = SystemReport::text( $this->fixture() );

		$this->assertStringContainsString( '[warn]', $text );
		$this->assertStringContainsString( "Server interface: fpm-fcgi\n", $text );
	}

	public function test_a_failed_row_is_marked_as_a_failure(): void {
		$sections = array(
			array(
				'title' => 'PHP modules',
				'rows'  => array(
					array(
						'label'  => 'json',
						'value'  => 'Missing',
						'note'   => 'required by this plugin',
						'status' => Requirements::STATUS_BELOW_REQUIRED,
						'action' => '',
					),
				),
			),
		);

		$this->assertStringContainsString( '[fail]', SystemReport::text( $sections ) );
	}

	public function test_the_filename_is_safe_and_dated(): void {
		$filename = SystemReport::filename();

		$this->assertStringStartsWith( 'wynko-system-report-', $filename );
		$this->assertStringEndsWith( '.txt', $filename );
		$this->assertSame( $filename, sanitize_file_name( $filename ) );
	}

	public function test_render_prints_every_section(): void {
		ob_start();
		SystemReport::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( 'PHP modules', $html );
		$this->assertStringContainsString( 'Connection status', $html );
	}

	public function test_the_screen_carries_the_same_header_the_file_does(): void {
		ob_start();
		SystemReport::render();
		$html = (string) ob_get_clean();

		foreach ( SystemReport::header_lines() as $line ) {
			$this->assertStringContainsString( esc_html( $line ), $html );
			$this->assertStringContainsString( $line, SystemReport::text( $this->fixture() ) );
		}
	}

	public function test_the_connection_row_carries_the_test_button(): void {
		ob_start();
		SystemReport::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'wynko-report__action', $html );
		$this->assertStringContainsString( 'value="wynko_api_ping"', $html );
	}

	public function test_the_export_refuses_a_user_without_the_capability(): void {
		$GLOBALS['wynko_test_can_manage'] = false;

		$this->expectException( WpDieException::class );
		SystemReport::handle_export();
	}

	public function test_the_ping_refuses_a_user_without_the_capability(): void {
		$GLOBALS['wynko_test_can_manage'] = false;

		$this->expectException( WpDieException::class );
		SystemReport::handle_ping();
	}

	/** The probe never runs for an empty key, so the button must speak for it. */
	public function test_a_ping_without_a_key_is_logged_as_a_warning(): void {
		SystemReport::log_ping(
			array(
				'ok'      => false,
				'message' => '',
				'code'    => '',
			)
		);

		$this->assertSame( 'warning', Log::all()[0]['level'] );
		$this->assertStringContainsString( 'no API key is configured', Log::all()[0]['message'] );
	}

	public function test_a_ping_that_actually_probed_is_left_to_the_probe(): void {
		SystemReport::log_ping(
			array(
				'ok'      => false,
				'message' => 'Invalid API key',
				'code'    => 'wynko_status',
			)
		);
		SystemReport::log_ping(
			array(
				'ok'      => true,
				'message' => '',
				'code'    => '',
			)
		);

		$this->assertSame( array(), Log::all() );
	}

	public function test_the_action_buttons_carry_their_own_nonces(): void {
		$GLOBALS['wynko_test_can_manage'] = true;

		ob_start();
		SystemReport::render_actions();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="wynko_system_report"', $html );
		$this->assertStringNotContainsString( 'value="wynko_api_ping"', $html );
		$this->assertSame( 1, substr_count( $html, '<form' ) );
		$this->assertSame( 1, substr_count( $html, 'name="_wpnonce"' ) );
	}

	public function test_the_ping_notice_appears_only_after_a_redirect(): void {
		ob_start();
		SystemReport::render_ping_notice();
		$this->assertSame( '', (string) ob_get_clean() );

		$_GET['wynko_ping'] = 'error';
		ob_start();
		SystemReport::render_ping_notice();
		$html = (string) ob_get_clean();
		unset( $_GET['wynko_ping'] );

		$this->assertStringContainsString( 'notice-error', $html );
	}

	public function test_the_ping_returns_to_the_about_tab(): void {
		$this->assertStringContainsString( 'tab=about', SystemReport::ping_redirect_url( 'ok' ) );
		$this->assertStringContainsString( 'wynko_ping=ok', SystemReport::ping_redirect_url( 'ok' ) );
	}
}
