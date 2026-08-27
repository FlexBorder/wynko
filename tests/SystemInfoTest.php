<?php
/**
 * Tests for the system report's probe.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\SettingsPage;
use Wynko\Cache;
use Wynko\KeyStatus;
use Wynko\Support\Requirements;
use Wynko\SystemInfo;
use PHPUnit\Framework\TestCase;

/** Covers the section shape, the verdicts, reachability, and secret leakage. */
final class SystemInfoTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	/**
	 * Flattens every row of every section into one string, for leak assertions.
	 *
	 * @return string
	 */
	private function flattened(): string {
		$text = '';
		foreach ( SystemInfo::sections() as $section ) {
			$text .= $section['title'];
			foreach ( $section['rows'] as $row ) {
				$text .= $row['label'] . $row['value'] . $row['note'] . $row['status'];
			}
		}
		return $text;
	}

	/**
	 * One row, by section title and label.
	 *
	 * @param string $section Section title.
	 * @param string $label   Row label.
	 * @return array{label:string,value:string,note:string,status:string,action:string}
	 */
	private function row( string $section, string $label ): array {
		foreach ( SystemInfo::sections() as $candidate ) {
			if ( $candidate['title'] !== $section ) {
				continue;
			}
			foreach ( $candidate['rows'] as $row ) {
				if ( $row['label'] === $label ) {
					return $row;
				}
			}
		}
		$this->fail( sprintf( 'No row "%s" in section "%s".', $label, $section ) );
	}

	public function test_every_section_is_present_and_shaped(): void {
		$titles = array();
		foreach ( SystemInfo::sections() as $section ) {
			$titles[] = $section['title'];
			$this->assertNotEmpty( $section['rows'] );
			foreach ( $section['rows'] as $row ) {
				$this->assertArrayHasKey( 'label', $row );
				$this->assertArrayHasKey( 'value', $row );
				$this->assertArrayHasKey( 'note', $row );
				$this->assertArrayHasKey( 'status', $row );
				$this->assertArrayHasKey( 'action', $row );
			}
		}

		$this->assertSame(
			array( 'WordPress', 'PHP', 'Database', 'PHP modules', 'Server', 'Plugin' ),
			$titles
		);
	}

	public function test_a_request_that_raises_its_memory_limit_reports_the_configured_one(): void {
		$before   = $this->row( 'PHP', 'Memory limit' );
		$original = (string) ini_get( 'memory_limit' );

		// What wp-admin/admin.php does on its way in, and admin-post.php does
		// not: the divergence that made the screen and the downloaded file
		// disagree about this row.
		ini_set( 'memory_limit', '777M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Reproducing wp_raise_memory_limit() to prove the report ignores it; restored below.
		$after = $this->row( 'PHP', 'Memory limit' );
		ini_set( 'memory_limit', $original ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Restores what the test found.

		$this->assertSame( $before, $after );
	}

	public function test_two_readings_of_the_same_site_agree(): void {
		$first = SystemInfo::sections();

		$ballast = str_repeat( 'x', 2 * 1024 * 1024 );
		$second  = SystemInfo::sections();
		unset( $ballast );

		// No row may report anything only the request it ran in can know: the
		// screen and the file it produces are two requests.
		$this->assertSame( $first, $second );
	}

	public function test_the_php_row_reports_the_running_version(): void {
		$row = $this->row( 'PHP', 'Version' );

		$this->assertSame( PHP_VERSION, $row['value'] );
	}

	public function test_a_reading_that_clears_its_thresholds_states_none_of_them(): void {
		// Driven through the WordPress row rather than the PHP one: the PHP the
		// suite runs on is whatever the container ships, and the advised version
		// is deliberately newer than most hosts run, so the PHP row's verdict is
		// not the test's to control.
		$GLOBALS['wynko_test_wp_version'] = '99.0';

		$row = $this->row( 'WordPress', 'Version' );

		$this->assertSame( Requirements::STATUS_OK, $row['status'] );
		$this->assertSame( '', $row['note'] );
	}

	public function test_a_reading_below_its_floor_names_that_floor_alone(): void {
		$GLOBALS['wynko_test_wp_version'] = '6.0';

		$row = $this->row( 'WordPress', 'Version' );

		$this->assertSame( Requirements::STATUS_BELOW_REQUIRED, $row['status'] );
		$this->assertStringContainsString( '6.4', $row['note'] );
		$this->assertStringNotContainsString( 'advised', $row['note'] );
	}

	public function test_the_database_row_names_the_server(): void {
		$row = $this->row( 'Database', 'Server' );

		$this->assertStringContainsString( 'MySQL', $row['value'] );
		$this->assertStringContainsString( '8.0.36', $row['value'] );
	}

	public function test_an_unreadable_database_banner_is_unknown(): void {
		$GLOBALS['wpdb']->server_info = 'nonsense';

		$row = $this->row( 'Database', 'Server' );

		$this->assertSame( Requirements::STATUS_UNKNOWN, $row['status'] );
	}

	public function test_a_required_module_that_is_loaded_is_ok(): void {
		$row = $this->row( 'PHP modules', 'json' );

		$this->assertSame( Requirements::STATUS_OK, $row['status'] );
		$this->assertSame( '', $row['note'] );
	}

	public function test_no_key_reports_its_source_and_no_verdict(): void {
		$this->assertStringContainsString( 'No API key', $this->row( 'Plugin', 'API key source' )['value'] );
		$this->assertSame( Requirements::STATUS_UNKNOWN, $this->row( 'Plugin', 'Connection status' )['status'] );
	}

	public function test_never_synced_reads_as_never(): void {
		$this->assertSame( 'Never', $this->row( 'Plugin', 'Last sync' )['value'] );
	}

	public function test_the_signup_rate_limit_is_reported_as_configured(): void {
		// What the Security tab stored, not what the plugin ships with: a
		// report showing the defaults would be silent about the one setting an
		// operator changed.
		$GLOBALS['wynko_test_options']['wynko_throttle_ip_max']   = 7;
		$GLOBALS['wynko_test_options']['wynko_throttle_form_max'] = 99;
		$GLOBALS['wynko_test_options']['wynko_throttle_window']   = 3;

		$this->assertSame(
			'7 per visitor, 99 per form, per 3 minutes',
			$this->row( 'Plugin', 'Signup rate limit' )['value']
		);
	}

	public function test_the_report_never_carries_the_key_or_its_fingerprint(): void {
		$GLOBALS['wynko_test_options']['wynko_api_key'] = 'super-secret-key';
		KeyStatus::record( 'super-secret-key', true );

		$text = $this->flattened();

		$this->assertStringNotContainsString( 'super-secret-key', $text );
		$this->assertStringNotContainsString( KeyStatus::fingerprint( 'super-secret-key' ), $text );
	}

	public function test_a_working_key_reads_as_connected(): void {
		$GLOBALS['wynko_test_options']['wynko_api_key'] = 'super-secret-key';
		KeyStatus::record( 'super-secret-key', true );

		$this->assertSame( 'Connected', $this->row( 'Plugin', 'Connection status' )['value'] );
		$this->assertSame( '', $this->row( 'Plugin', 'Connection status' )['note'] );
		$this->assertSame( 'Database', $this->row( 'Plugin', 'API key source' )['value'] );
	}

	public function test_a_failed_sync_reports_the_verdict_and_how_far_it_got_in_one_row(): void {
		$GLOBALS['wynko_test_options']['wynko_api_key'] = 'a-key';

		// No queued response, so the transport fails the way an unreachable
		// Laposta does — what "Sync now" hits when the host cannot be resolved.
		SettingsPage::record_sync_verdict( Cache::refresh() );

		$row = $this->row( 'Plugin', 'Connection status' );

		$this->assertStringContainsString( 'Not connected', $row['value'] );
		$this->assertStringContainsString( 'not reached', $row['note'] );
	}

	public function test_the_connection_row_is_the_only_one_reporting_reachability(): void {
		$labels = array();
		foreach ( SystemInfo::sections() as $section ) {
			foreach ( $section['rows'] as $row ) {
				$labels[] = $row['label'];
			}
		}

		$this->assertContains( 'Connection status', $labels );
		$this->assertNotContains( 'Laposta API reachable', $labels );
	}

	public function test_the_connection_row_offers_the_connection_test(): void {
		$this->assertSame( SystemInfo::ACTION_PING, $this->row( 'Plugin', 'Connection status' )['action'] );
	}

	public function test_a_transport_failure_means_not_reachable(): void {
		$this->assertSame(
			SystemInfo::REACHABLE_NO,
			SystemInfo::reachability(
				array(
					'ok'      => false,
					'message' => 'Could not connect to Laposta: timed out',
					'code'    => 'wynko_http',
				),
				'option'
			)
		);
	}

	public function test_a_rejected_key_still_means_reachable(): void {
		$this->assertSame(
			SystemInfo::REACHABLE_YES,
			SystemInfo::reachability(
				array(
					'ok'      => false,
					'message' => 'Invalid API key (HTTP 401)',
					'code'    => 'wynko_status',
				),
				'option'
			)
		);
	}

	public function test_success_means_reachable(): void {
		$this->assertSame(
			SystemInfo::REACHABLE_YES,
			SystemInfo::reachability(
				array(
					'ok'      => true,
					'message' => '',
					'code'    => '',
				),
				'option'
			)
		);
	}

	public function test_no_key_and_a_codeless_record_are_both_unknown(): void {
		$this->assertSame(
			SystemInfo::REACHABLE_UNKNOWN,
			SystemInfo::reachability(
				array(
					'ok'      => false,
					'message' => '',
					'code'    => '',
				),
				'none'
			)
		);
		$this->assertSame(
			SystemInfo::REACHABLE_UNKNOWN,
			SystemInfo::reachability(
				array(
					'ok'      => false,
					'message' => 'older record',
					'code'    => '',
				),
				'option'
			)
		);
	}

	public function test_the_https_row_reads_the_front_end_not_this_request(): void {
		// FORCE_SSL_ADMIN makes is_ssl() true on an admin screen whose front end
		// is still plain HTTP. The forms are on the front end, so that is what
		// the verdict has to follow.
		$GLOBALS['wynko_test_is_ssl']      = true;
		$GLOBALS['wynko_test_using_https'] = false;

		$row = $this->row( 'Server', 'HTTPS' );

		$this->assertSame( Requirements::STATUS_BELOW_ADVISED, $row['status'] );
		$this->assertNotSame( '', $row['note'] );
	}

	public function test_a_local_install_is_not_judged_on_https(): void {
		$GLOBALS['wynko_test_environment_type'] = 'local';
		$GLOBALS['wynko_test_using_https']      = false;

		$row = $this->row( 'Server', 'HTTPS' );

		$this->assertSame( Requirements::STATUS_INFO, $row['status'] );
	}

	public function test_a_site_on_https_clears_the_row(): void {
		$GLOBALS['wynko_test_using_https'] = true;

		$row = $this->row( 'Server', 'HTTPS' );

		$this->assertSame( Requirements::STATUS_OK, $row['status'] );
		$this->assertSame( '', $row['note'] );
	}

	public function test_the_tls_library_row_is_present_and_carries_a_verdict(): void {
		$row = $this->row( 'Server', 'TLS library' );

		$this->assertNotSame( '', $row['value'] );
		$this->assertContains(
			$row['status'],
			array( Requirements::STATUS_OK, Requirements::STATUS_BELOW_ADVISED, Requirements::STATUS_UNKNOWN )
		);
	}

	/**
	 * What PHP was handed is not what the browser negotiated: behind FastCGI or
	 * a CDN this reads HTTP/1.1 on a site serving HTTP/2 to everyone. A verdict
	 * drawn from it would be wrong on a large share of working hosts.
	 */
	public function test_the_request_protocol_reports_without_judging(): void {
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

		$row = $this->row( 'Server', 'Request protocol' );
		unset( $_SERVER['SERVER_PROTOCOL'] );

		$this->assertSame( 'HTTP/1.1', $row['value'] );
		$this->assertSame( Requirements::STATUS_INFO, $row['status'] );
	}

	/** mod_ssl sets it; most other stacks do not, and that is not a fault. */
	public function test_an_absent_request_tls_version_is_not_a_fault(): void {
		$row = $this->row( 'Server', 'Request TLS version' );

		$this->assertSame( Requirements::STATUS_INFO, $row['status'] );
	}

	public function test_the_environment_verdict_leaves_the_plugin_section_out(): void {
		$labels = array();
		foreach ( SystemInfo::environment()['items'] as $item ) {
			$labels[] = $item['name'];
		}

		foreach ( $labels as $label ) {
			$this->assertStringNotContainsString( 'Plugin', $label );
		}
	}

	public function test_a_shortfall_below_the_floor_outranks_one_below_the_advice(): void {
		$GLOBALS['wynko_test_wp_version'] = '6.0';

		$this->assertSame( Requirements::STATUS_BELOW_REQUIRED, SystemInfo::environment()['status'] );
	}

	public function test_an_environment_reading_changes_its_fingerprint(): void {
		$before = SystemInfo::environment()['fingerprint'];

		$GLOBALS['wynko_test_wp_version'] = '6.0';

		$this->assertNotSame( $before, SystemInfo::environment()['fingerprint'] );
	}

	public function test_the_same_environment_fingerprints_the_same(): void {
		$this->assertSame( SystemInfo::environment()['fingerprint'], SystemInfo::environment()['fingerprint'] );
	}

	/**
	 * curl links its own TLS library, so a build with curl and no openssl
	 * extension reaches Laposta perfectly well. Requiring either one alone would
	 * raise this feature's loudest signal on a site that works.
	 */
	public function test_one_transport_is_enough_for_outbound_https(): void {
		$row = $this->row( 'PHP modules', 'Outbound HTTPS' );

		$this->assertSame( Requirements::STATUS_OK, $row['status'] );
		$this->assertStringContainsString( 'Available', $row['value'] );
	}

	public function test_neither_curl_nor_openssl_is_a_required_module_on_its_own(): void {
		foreach ( array( 'curl', 'openssl' ) as $module ) {
			$this->assertNotSame(
				Requirements::STATUS_BELOW_REQUIRED,
				$this->row( 'PHP modules', $module )['status'],
				sprintf( '%s alone must not be a hard requirement.', $module )
			);
		}
	}
}
