<?php
/**
 * Tests for the settings screen's key sanitizer.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\SettingsPage;
use Wynko\Config;
use Wynko\Log;
use PHPUnit\Framework\TestCase;

/**
 * Covers the exactly-once verification contract. WordPress sanitizes twice on
 * a first save — update_option() sanitizes, then delegates to add_option(),
 * which sanitizes again — so every assertion here is about the second call
 * being inert.
 */
final class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		SettingsPage::reset_memo();
	}

	public function test_a_second_sanitize_in_one_request_does_not_reverify(): void {
		wynko_test_queue_response( 200, '{"data":[]}' );

		$first  = SettingsPage::sanitize_key( 'a-good-key' );
		$second = SettingsPage::sanitize_key( $first );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, wynko_test_http_calls() );
		$this->assertCount( 1, Log::all() );
		$this->assertCount( 1, wynko_test_settings_errors() );
	}

	public function test_a_rejected_key_is_reported_once_and_keeps_the_previous_value(): void {
		update_option( Config::option_key( 'api_key' ), 'previous-key' );
		wynko_test_queue_response( 401, '' );

		$first  = SettingsPage::sanitize_key( 'a-bad-key' );
		$second = SettingsPage::sanitize_key( $first );

		$this->assertSame( 'previous-key', $first );
		$this->assertSame( 'previous-key', $second );
		$this->assertSame( 1, wynko_test_http_calls() );
		$this->assertCount( 1, Log::all() );
		$this->assertCount( 1, wynko_test_settings_errors() );
	}

	/**
	 * A rejected save must fingerprint the key that resolve() will return
	 * afterwards — the previous one. Fingerprinting the rejected key leaves the
	 * cache unable to answer for the resolved key, and the next page render
	 * probes the API again.
	 */
	public function test_a_rejected_key_leaves_a_usable_verdict_for_the_resolved_key(): void {
		update_option( Config::option_key( 'api_key' ), 'previous-key' );
		wynko_test_queue_response( 401, '' );

		SettingsPage::sanitize_key( 'a-bad-key' );

		$this->assertNotNull( \Wynko\KeyStatus::cached( 'previous-key' ) );
	}

	/**
	 * update_option() hands add_option() the value the first sanitize returned, so
	 * a second sanitize that did not recognise an envelope would send the blob to
	 * Laposta and overwrite what it had just produced. The memo is reset here so
	 * the guard is tested on its own.
	 */
	public function test_feeding_the_sanitizer_its_own_output_leaves_the_stored_value_alone(): void {
		update_option( Config::option_key( 'api_key' ), 'previous-key' );
		SettingsPage::reset_memo();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Stands in for a sealed value the sanitizer produced earlier in the request.
		$envelope = 'wynko:v1:' . base64_encode( random_bytes( 64 ) );

		$this->assertSame( $envelope, SettingsPage::sanitize_key( $envelope ) );
		$this->assertSame( 0, wynko_test_http_calls() );
		$this->assertCount( 0, Log::all() );
		$this->assertCount( 0, wynko_test_settings_errors() );
	}

	public function test_a_blank_submission_keeps_the_stored_value_without_probing(): void {
		update_option( Config::option_key( 'api_key' ), 'previous-key' );

		$this->assertSame( 'previous-key', SettingsPage::sanitize_key( '' ) );
		$this->assertSame( 0, wynko_test_http_calls() );
		$this->assertCount( 0, Log::all() );
	}

	/**
	 * The field explains itself and links to Laposta's own instructions. The
	 * URL is a PHP literal, never part of a translated string, so a translator
	 * cannot break the href.
	 *
	 * @return void
	 */
	public function test_the_key_description_links_to_the_laposta_docs(): void {
		$html = SettingsPage::key_description();

		$this->assertStringContainsString( 'The API key to connect with your Laposta account.', $html );
		$this->assertStringContainsString( 'https://docs.laposta.org/article/947-how-do-i-get-an-api-key', $html );
		$this->assertStringContainsString( 'Learn how to create an API key', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
	}

	/**
	 * The anchor allowlist is exactly what the description needs — nothing that
	 * would let a translated string introduce markup of its own.
	 *
	 * @return void
	 */
	public function test_the_link_allowlist_permits_only_anchors(): void {
		$allowed = SettingsPage::allowed_link_html();

		$this->assertSame( array( 'a' ), array_keys( $allowed ) );
		$this->assertSame( array( 'href', 'target', 'rel' ), array_keys( $allowed['a'] ) );
	}

	/**
	 * With no usable salts the key is stored as plain text, so the warning must
	 * say so, link the term it uses, and carry the remedy. The routes that skip
	 * the database live in storage_options() and are asserted there.
	 *
	 * Its own process: a fresh one has no salts defined, which is exactly the
	 * state under test. Guarding on `defined()` instead would let the test skip
	 * itself the day another test leaks a constant, and a skip is not a
	 * failure — the coverage would vanish silently.
	 *
	 * @runInSeparateProcess
	 * @return void
	 */
	public function test_a_site_that_cannot_encrypt_gets_a_warning_with_the_salts_remedy(): void {
		$this->assertFalse( SettingsPage::can_encrypt() );

		$html = SettingsPage::plaintext_warning();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'https://developer.wordpress.org/reference/functions/wp_salt/', $html );
		$this->assertStringContainsString( 'wp-config.php', $html );
	}

	/**
	 * A site that can seal its key gets one sentence, not a paragraph — and it
	 * links the term it uses rather than explaining salts inline. The caveat
	 * about what encryption does not cover lives in storage_options().
	 *
	 * @return void
	 */
	public function test_the_save_note_is_one_sentence_and_links_the_salts_reference(): void {
		$html = SettingsPage::storage_note();

		$this->assertStringContainsString( 'https://developer.wordpress.org/reference/functions/wp_salt/', $html );
		$this->assertStringContainsString( 'security salts', $html );
		$this->assertStringNotContainsString( 'database dump', $html );
	}

	/**
	 * The routes that keep the key out of the database are named in one place
	 * and rendered in both states, so an operator on a correctly salted site
	 * learns about them too. Collapsed by default; open where they are the
	 * live remedy.
	 *
	 * @return void
	 */
	public function test_the_storage_options_name_both_routes_out_of_the_database(): void {
		$html = SettingsPage::storage_options();

		$this->assertStringContainsString( '<details>', $html );
		$this->assertStringContainsString( '<summary>', $html );
		$this->assertStringContainsString( 'WYNKO_API_KEY', $html );
		$this->assertStringContainsString( '.env', $html );
		$this->assertStringContainsString( 'wp-config.php', $html );
	}

	/**
	 * The wp-config.php route is shown as the line to paste, not described in
	 * prose — and it survives the allowlist the caller runs it through.
	 *
	 * @return void
	 */
	public function test_the_storage_options_carry_a_copyable_wp_config_line(): void {
		$html = wp_kses( SettingsPage::storage_options(), SettingsPage::allowed_storage_html() );

		$this->assertStringContainsString( '<pre class="wynko-code"><code>', $html );
		$this->assertStringContainsString( 'define(', $html );
		$this->assertStringContainsString( 'WYNKO_API_KEY', $html );
	}

	/**
	 * On multisite the per-site names are spelled out with this site's own id:
	 * {blog_id} is the part an operator gets wrong, and the screen knows it.
	 *
	 * @return void
	 */
	public function test_the_storage_options_fill_in_this_sites_id_on_multisite(): void {
		$GLOBALS['wynko_test_multisite'] = true;

		$html = SettingsPage::storage_options();

		$this->assertStringContainsString( 'WYNKO_API_KEY_' . get_current_blog_id(), $html );
		$this->assertStringNotContainsString( '{blog_id}', $html );
	}

	/**
	 * @return void
	 */
	public function test_a_single_site_is_not_told_about_per_site_keys(): void {
		$html = SettingsPage::storage_options();

		$this->assertStringNotContainsString( 'WYNKO_API_KEY_', $html );
	}

	/**
	 * @return void
	 */
	public function test_the_storage_options_can_render_expanded(): void {
		$this->assertStringContainsString( '<details open>', SettingsPage::storage_options( true ) );
	}

	/**
	 * The allowlist covers exactly the markup this screen emits — the notice,
	 * its list, and the disclosure. Nothing broader: a translated string must
	 * not be able to introduce markup of its own.
	 *
	 * @return void
	 */
	public function test_the_storage_allowlist_covers_the_disclosure_markup(): void {
		$allowed = SettingsPage::allowed_storage_html();

		$this->assertArrayHasKey( 'a', $allowed );
		$this->assertArrayHasKey( 'details', $allowed );
		$this->assertArrayHasKey( 'summary', $allowed );
		$this->assertSame( array( 'open' ), array_keys( $allowed['details'] ) );
		$this->assertArrayNotHasKey( 'script', $allowed );
		$this->assertArrayNotHasKey( 'style', $allowed );
	}

	/**
	 * A site that can seal its key gets no warning at all.
	 *
	 * @runInSeparateProcess
	 * @return void
	 */
	public function test_a_site_that_can_encrypt_gets_no_warning(): void {
		define( 'SECURE_AUTH_KEY', 'a-real-key' );
		define( 'SECURE_AUTH_SALT', 'a-real-salt' );

		$this->assertTrue( SettingsPage::can_encrypt() );
		$this->assertSame( '', SettingsPage::plaintext_warning() );
	}

	/**
	 * Only the two known tabs are reachable. Anything else — a typo, a probe —
	 * lands on the API tab rather than rendering an unknown screen.
	 *
	 * @return void
	 */
	public function test_an_unknown_tab_falls_back_to_the_api_tab(): void {
		$this->assertSame( SettingsPage::TAB_API, SettingsPage::current_tab( '' ) );
		$this->assertSame( SettingsPage::TAB_API, SettingsPage::current_tab( 'nonsense' ) );
		$this->assertSame( SettingsPage::TAB_API, SettingsPage::current_tab( '<script>' ) );
		$this->assertSame( SettingsPage::TAB_API, SettingsPage::current_tab( SettingsPage::TAB_API ) );
		$this->assertSame( SettingsPage::TAB_ABOUT, SettingsPage::current_tab( SettingsPage::TAB_ABOUT ) );
	}

	/**
	 * Every tab is declared with a label, API first and About last.
	 *
	 * @return void
	 */
	public function test_every_tab_is_declared_in_order(): void {
		$tabs = SettingsPage::tabs();

		$this->assertSame(
			array( SettingsPage::TAB_API, SettingsPage::TAB_SECURITY, SettingsPage::TAB_NOTIFICATIONS, SettingsPage::TAB_ABOUT ),
			array_keys( $tabs )
		);
		foreach ( $tabs as $label ) {
			$this->assertNotSame( '', $label );
		}
	}

	/**
	 * "Sync now" must come back to the tab that has the sync button on it. A
	 * redirect that drops the tab argument lands the operator on About with a
	 * notice about something they cannot see.
	 *
	 * @return void
	 */
	public function test_the_sync_redirect_returns_to_the_api_tab(): void {
		$url = SettingsPage::sync_redirect_url( 'ok' );

		$this->assertStringContainsString( 'page=' . SettingsPage::PAGE, $url );
		$this->assertStringContainsString( 'tab=' . SettingsPage::TAB_API, $url );
		$this->assertStringContainsString( 'wynko_sync=ok', $url );
		$this->assertStringContainsString( 'wynko_sync=error', SettingsPage::sync_redirect_url( 'error' ) );
	}
}
