<?php
/**
 * Tests for the system report's probe.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\SettingsPage;
use Wynko\ApiKey;
use Wynko\Cache;
use Wynko\Config;
use Wynko\KeyStatus;
use Wynko\Support\Requirements;
use Wynko\SystemInfo;
use Wynko\Throttle;
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
			array( 'WordPress', 'PHP', 'Database', 'PHP modules', 'Server', 'Plugin', 'Security' ),
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

	/** Encryption is only a meaningful question for a key actually in the database. */
	public function test_an_environment_sourced_key_carries_no_encryption_reading(): void {
		putenv( 'WYNKO_API_KEY=env-key' );

		$row = $this->row( 'Plugin', 'API key source' );

		putenv( 'WYNKO_API_KEY' );

		$this->assertStringNotContainsString( 'Encrypted', $row['value'] );
		$this->assertSame( '', $row['action'] );
	}

	public function test_a_plaintext_stored_key_reports_not_encrypted_with_a_help_link(): void {
		$GLOBALS['wynko_test_options']['wynko_api_key'] = 'plain-secret-key';

		$row = $this->row( 'Plugin', 'API key source' );

		$this->assertStringContainsString( 'Database', $row['value'] );
		$this->assertStringContainsString( 'Not encrypted', $row['value'] );
		$this->assertSame( SystemInfo::PROTECTION_DISABLED, $row['status'] );
		$this->assertSame( SystemInfo::ACTION_ENCRYPT_HELP, $row['action'] );
	}

	public function test_a_stored_key_that_opens_reports_encrypted(): void {
		// Guarded: some earlier test in this same PHPUnit process may already
		// have defined these, and redefining an existing constant is a no-op
		// with a warning rather than an error — either way, key_material()
		// ends up non-empty and store() below produces a real envelope.
		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			define( 'SECURE_AUTH_KEY', 'test-auth-key' );
			define( 'SECURE_AUTH_SALT', 'test-auth-salt' );
		}
		$GLOBALS['wynko_test_options']['wynko_api_key'] = ApiKey::store( 'a-real-key' );

		$row = $this->row( 'Plugin', 'API key source' );

		$this->assertStringContainsString( 'Encrypted', $row['value'] );
		$this->assertStringNotContainsString( 'Not encrypted', $row['value'] );
		$this->assertSame( SystemInfo::PROTECTION_ENABLED, $row['status'] );
		$this->assertSame( '', $row['action'] );
	}

	/** An envelope that cannot be opened is its own message, not "not encrypted". */
	public function test_an_unreadable_stored_key_keeps_its_own_message(): void {
		$GLOBALS['wynko_test_options']['wynko_api_key'] = 'wynko:v1:not-a-real-envelope';

		$row = $this->row( 'Plugin', 'API key source' );

		$this->assertStringContainsString( 'unreadable', $row['value'] );
		$this->assertStringNotContainsString( 'Not encrypted', $row['value'] );
		$this->assertSame( '', $row['action'] );
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
			$this->row( 'Security', 'Signup rate limit' )['value']
		);
	}

	/** Both protections default to on, so a fresh install reports Yes on both. */
	public function test_a_fresh_install_reports_both_protections_enabled(): void {
		$nonce    = $this->row( 'Security', 'Nonce verification' );
		$throttle = $this->row( 'Security', 'Rate limiting' );

		$this->assertSame( 'Yes', $nonce['value'] );
		$this->assertSame( SystemInfo::PROTECTION_ENABLED, $nonce['status'] );
		$this->assertSame( 'Yes', $throttle['value'] );
		$this->assertSame( SystemInfo::PROTECTION_ENABLED, $throttle['status'] );
	}

	public function test_a_disabled_nonce_check_reports_no_and_the_disabled_status(): void {
		$GLOBALS['wynko_test_options']['wynko_disable_form_nonce'] = true;

		$row = $this->row( 'Security', 'Nonce verification' );

		$this->assertSame( 'No', $row['value'] );
		$this->assertSame( SystemInfo::PROTECTION_DISABLED, $row['status'] );
	}

	public function test_a_disabled_throttle_reports_no_and_the_disabled_status(): void {
		$GLOBALS['wynko_test_options']['wynko_disable_form_throttle'] = true;

		$row = $this->row( 'Security', 'Rate limiting' );

		$this->assertSame( 'No', $row['value'] );
		$this->assertSame( SystemInfo::PROTECTION_DISABLED, $row['status'] );
	}

	public function test_no_forms_means_no_usage_to_report(): void {
		$this->assertSame(
			'No signup forms yet',
			$this->row( 'Security', 'Signups in the current window' )['value']
		);
	}

	public function test_current_usage_is_reported_per_form_against_the_cap(): void {
		$GLOBALS['wynko_test_options']['wynko_throttle_form_max'] = 400;
		$id = wynko_test_insert_post(
			array(
				'post_title'  => 'Newsletter signup',
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
		Throttle::allows( $id, '203.0.113.5' );
		Throttle::allows( $id, '203.0.113.6' );

		$this->assertSame(
			'Newsletter signup: 2/400',
			$this->row( 'Security', 'Signups in the current window' )['value']
		);
	}

	/**
	 * Joined with newlines, not commas: SystemReport renders a newline-carrying
	 * value as a list rather than one long line once there is more than one
	 * form to report.
	 */
	public function test_more_than_one_form_is_joined_with_newlines_for_the_list_rendering(): void {
		$GLOBALS['wynko_test_options']['wynko_throttle_form_max'] = 400;
		wynko_test_insert_post(
			array(
				'post_title'  => 'Newsletter signup',
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
		wynko_test_insert_post(
			array(
				'post_title'  => 'Footer signup',
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);

		$value = $this->row( 'Security', 'Signups in the current window' )['value'];

		$this->assertStringNotContainsString( ', ', $value );
		$this->assertSame(
			array( 'Footer signup: 0/400', 'Newsletter signup: 0/400' ),
			explode( "\n", $value )
		);
	}

	public function test_no_caching_plugin_reports_no(): void {
		$this->assertSame( 'No', $this->row( 'WordPress', 'Page caching' )['value'] );
	}

	public function test_an_active_caching_drop_in_is_named(): void {
		$GLOBALS['wynko_test_dropins'] = array(
			'advanced-cache.php' => array( 'Name' => 'WP Super Cache' ),
		);

		$this->assertSame( 'WP Super Cache', $this->row( 'WordPress', 'Page caching' )['value'] );
	}

	/**
	 * get_plugin_data() falls back to the bare filename when a drop-in
	 * carries no real plugin header — true of most page-caching plugins'
	 * advanced-cache.php in practice (confirmed against a real WP Super
	 * Cache install). A "Name" that is just the filename must not be
	 * reported as if it identified anything.
	 */
	public function test_a_drop_in_with_no_real_header_reports_enabled_generically(): void {
		$GLOBALS['wynko_test_dropins'] = array(
			'advanced-cache.php' => array( 'Name' => 'advanced-cache.php' ),
		);

		$this->assertSame( 'Yes', $this->row( 'WordPress', 'Page caching' )['value'] );
	}

	public function test_no_cdn_headers_report_no(): void {
		$this->assertSame( 'No', $this->row( 'Server', 'CDN / proxy' )['value'] );
	}

	public function test_a_cloudflare_header_is_identified(): void {
		$_SERVER['HTTP_CF_RAY'] = '8a1b2c3d4e5f6789-AMS';

		$row = $this->row( 'Server', 'CDN / proxy' );
		unset( $_SERVER['HTTP_CF_RAY'] );

		$this->assertSame( 'Cloudflare', $row['value'] );
		$this->assertNotSame( '', $row['note'] );
	}

	public function test_a_bare_forwarding_header_is_unidentified_rather_than_guessed(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';

		$row = $this->row( 'Server', 'CDN / proxy' );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

		$this->assertSame( 'Unidentified reverse proxy', $row['value'] );
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
		$this->assertStringStartsWith( 'Database', $this->row( 'Plugin', 'API key source' )['value'] );
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

	public function test_it_lists_a_registered_integration_with_its_state_and_version(): void {
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'one', 'One' );
				return $integrations;
			}
		);

		$row = $this->row( 'Integrations', 'One' );

		$this->assertSame( 'disabled', $row['value'] );
		$this->assertStringContainsString( 'version 1.2.3', $row['note'] );
	}

	public function test_it_shows_an_enabled_integration_as_enabled(): void {
		update_option( 'wynko_integrations_enabled', array( 'one' ) );
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'one', 'One' );
				return $integrations;
			}
		);

		$this->assertSame( 'enabled', $this->row( 'Integrations', 'One' )['value'] );
	}

	public function test_it_names_the_provider_of_a_third_party_integration(): void {
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeThirdPartyIntegration();
				return $integrations;
			}
		);

		$row = $this->row( 'Integrations', 'Two <script>alert(1)</script>' );

		$this->assertStringContainsString( 'provided by', $row['note'] );
	}

	public function test_it_strips_embedded_newlines_from_a_third_party_integrations_strings(): void {
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeNewlineIntegration();
				return $integrations;
			}
		);

		$row = $this->row( 'Integrations', 'Three == Section == [fail] forged' );

		$this->assertStringNotContainsString( "\n", $row['label'] );
		$this->assertStringNotContainsString( "\n", $row['note'] );
		$this->assertStringContainsString( 'Jane [fail] forged', $row['note'] );
		$this->assertStringContainsString( '1.0 [fail] forged', $row['note'] );
	}

	public function test_it_omits_the_integrations_section_when_none_are_registered(): void {
		$titles = array();
		foreach ( SystemInfo::sections() as $section ) {
			$titles[] = $section['title'];
		}

		$this->assertNotContains( 'Integrations', $titles );
	}
}
