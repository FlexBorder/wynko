<?php
/**
 * Tests for the activity-log screen.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\LogPage;
use Wynko\Config;
use Wynko\KeyStatus;
use Wynko\Log;
use Wynko\Support\LogText;
use Wynko\Support\Sanitizer;
use PHPUnit\Framework\TestCase;

/** Covers the view filter, the two actions' guards, and the export body. */
final class LogPageTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		$GLOBALS['wynko_test_can_manage'] = true;
		unset( $_GET[ LogPage::ARG_LEVEL ], $_GET[ LogPage::ARG_SEARCH ], $_GET['wynko_cleared'], $_POST );
		$_POST = array();
	}

	protected function tearDown(): void {
		unset( $_GET[ LogPage::ARG_LEVEL ], $_GET[ LogPage::ARG_SEARCH ], $_GET['wynko_cleared'] );
		$_POST = array();
	}

	/** Seeds one entry of each level. */
	private function seed(): void {
		Log::info( 'Automatic sync succeeded: 3 campaigns loaded.' );
		Log::warning( 'Sync succeeded, but the account returned no campaigns.' );
		Log::error( 'Sync failed: timeout' );
	}

	/**
	 * Renders the screen and returns its markup.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		LogPage::render_page();
		return (string) ob_get_clean();
	}

	public function test_the_screen_is_blank_without_the_capability(): void {
		$GLOBALS['wynko_test_can_manage'] = false;
		$this->seed();

		$this->assertSame( '', $this->render() );
	}

	public function test_every_entry_shows_when_nothing_is_filtered(): void {
		$this->seed();
		$html = $this->render();

		$this->assertStringContainsString( 'Sync failed: timeout', $html );
		$this->assertStringContainsString( 'returned no campaigns', $html );
		$this->assertStringContainsString( '3 campaigns loaded', $html );
		$this->assertStringContainsString( 'Showing 3 of 3 entries.', $html );
	}

	public function test_the_level_filter_narrows_the_table(): void {
		$this->seed();
		$_GET[ LogPage::ARG_LEVEL ] = 'warning';
		$html                       = $this->render();

		$this->assertStringContainsString( 'Sync failed: timeout', $html );
		$this->assertStringContainsString( 'returned no campaigns', $html );
		$this->assertStringNotContainsString( '3 campaigns loaded', $html );
		$this->assertStringContainsString( 'Showing 2 of 3 entries.', $html );
	}

	public function test_the_search_filter_narrows_the_table(): void {
		$this->seed();
		$_GET[ LogPage::ARG_SEARCH ] = 'timeout';

		$this->assertStringContainsString( 'Showing 1 of 3 entries.', $this->render() );
	}

	public function test_a_filter_matching_nothing_shows_the_empty_state(): void {
		$this->seed();
		$_GET[ LogPage::ARG_SEARCH ] = 'nothing here';

		$this->assertStringContainsString( 'No activity to show.', $this->render() );
	}

	public function test_an_unknown_level_argument_falls_back_to_all(): void {
		$_GET[ LogPage::ARG_LEVEL ] = 'urgent';

		$this->assertSame( Sanitizer::LEVEL_ALL, LogPage::requested_level() );
	}

	/** Both filters arrive as request data and may be arrays. */
	public function test_an_array_filter_argument_is_discarded(): void {
		$_GET[ LogPage::ARG_SEARCH ] = array( 'a', 'b' );
		$_GET[ LogPage::ARG_LEVEL ]  = array( 'error' );

		$this->assertSame( '', LogPage::requested_search() );
		$this->assertSame( Sanitizer::LEVEL_ALL, LogPage::requested_level() );
	}

	public function test_clean_level_rejects_a_value_outside_the_enum(): void {
		$this->assertSame( 'error', LogPage::clean_level( 'error' ) );
		$this->assertSame( Sanitizer::LEVEL_ALL, LogPage::clean_level( 'debug' ) );
		$this->assertSame( Sanitizer::LEVEL_ALL, LogPage::clean_level( array() ) );
	}

	public function test_the_screen_names_the_current_threshold_and_links_to_settings(): void {
		update_option( Config::option_key( 'log_level' ), 'error' );
		$html = $this->render();

		$this->assertStringContainsString( 'Recording: Errors only.', $html );
		$this->assertStringContainsString( 'Change in Settings', $html );
		$this->assertStringContainsString( 'tab=api', $html );
	}

	public function test_the_action_buttons_carry_their_own_nonces(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'value="wynko_export_log"', $html );
		$this->assertStringContainsString( 'value="wynko_clear_log"', $html );
		$this->assertSame( 2, substr_count( $html, 'name="_wpnonce"' ) );
	}

	public function test_the_export_form_carries_the_active_filter(): void {
		$_GET[ LogPage::ARG_LEVEL ]  = 'error';
		$_GET[ LogPage::ARG_SEARCH ] = 'timeout';
		$html                        = $this->render();

		$this->assertStringContainsString( 'name="' . LogPage::ARG_LEVEL . '" value="error"', $html );
		$this->assertStringContainsString( 'name="' . LogPage::ARG_SEARCH . '" value="timeout"', $html );
	}

	public function test_the_export_refuses_a_user_without_the_capability(): void {
		$GLOBALS['wynko_test_can_manage'] = false;

		$this->expectException( WpDieException::class );
		LogPage::handle_export();
	}

	public function test_clearing_refuses_a_user_without_the_capability(): void {
		$GLOBALS['wynko_test_can_manage'] = false;

		$this->expectException( WpDieException::class );
		LogPage::handle_clear();
	}

	public function test_the_filename_is_safe_and_named_for_the_log(): void {
		$filename = LogPage::filename();

		$this->assertStringStartsWith( 'wynko-activity-log-', $filename );
		$this->assertStringEndsWith( '.txt', $filename );
		$this->assertSame( $filename, sanitize_file_name( $filename ) );
	}

	public function test_the_export_header_records_the_filter_and_no_key(): void {
		$header = LogPage::export_header( 'error', 'timeout' );
		$text   = implode( ' ', $header );

		$this->assertStringContainsString( 'level=error', $text );
		$this->assertStringContainsString( 'search=timeout', $text );
		$this->assertStringNotContainsString( 'wynko_api_key', $text );
	}

	/**
	 * The downloadable file is written to be attached to a support thread, so no
	 * part of the key may reach it, through a header row or an error message.
	 */
	public function test_the_export_body_never_carries_the_api_key(): void {
		update_option( Config::option_key( 'api_key' ), 'sk-secret-key-value' );
		wynko_test_queue_response( 401, '' );
		KeyStatus::verify( 'sk-secret-key-value' );

		$body = LogText::format( Log::all(), LogPage::export_header( 'all', '' ) );

		$this->assertStringContainsString( 'Connection to the Laposta API failed', $body );
		$this->assertStringNotContainsString( 'sk-secret-key-value', $body );
		$this->assertStringNotContainsString( KeyStatus::fingerprint( 'sk-secret-key-value' ), $body );
	}

	/**
	 * The header names the threshold the way the screens do. It used to print
	 * the stored value, so a download read "info" for a level no picker offers.
	 */
	public function test_the_export_header_words_the_threshold(): void {
		update_option( Config::option_key( 'log_level' ), 'info' );

		$text = implode( ' ', LogPage::export_header( 'all', '' ) );

		$this->assertStringContainsString( 'Info, warnings and errors', $text );
	}

	public function test_the_export_header_marks_an_empty_search(): void {
		$this->assertStringContainsString( 'search=-', implode( ' ', LogPage::export_header( 'all', '' ) ) );
	}

	public function test_the_cleared_notice_appears_only_after_a_redirect(): void {
		$this->assertStringNotContainsString( 'notice-success', $this->render() );

		$_GET['wynko_cleared'] = '1';
		$this->assertStringContainsString( 'has been cleared', $this->render() );
	}

	public function test_the_clear_redirect_returns_to_the_log(): void {
		$url = LogPage::clear_redirect_url();

		$this->assertStringContainsString( 'page=wynko-log', $url );
		$this->assertStringContainsString( 'wynko_cleared=1', $url );
	}
}
