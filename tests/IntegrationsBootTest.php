<?php
/**
 * Tests for the integrations bootstrap.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Integrations;
use Wynko\Log;
use PHPUnit\Framework\TestCase;

/** Tests for Integrations::enabled()/is_enabled()/boot(). */
final class IntegrationsBootTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_enabled_reads_the_stored_option(): void {
		update_option( 'wynko_integrations_enabled', array( 'a', 'b' ) );

		$this->assertSame( array( 'a', 'b' ), Integrations::enabled() );
	}

	public function test_enabled_defaults_to_an_empty_array(): void {
		$this->assertSame( array(), Integrations::enabled() );
	}

	public function test_is_enabled_checks_the_stored_list(): void {
		update_option( 'wynko_integrations_enabled', array( 'contact-form-7' ) );

		$this->assertTrue( Integrations::is_enabled( 'contact-form-7' ) );
		$this->assertFalse( Integrations::is_enabled( 'something-else' ) );
	}

	public function test_boot_calls_boot_only_on_enabled_and_available_integrations(): void {
		update_option( 'wynko_integrations_enabled', array( 'available-enabled', 'unavailable-enabled' ) );

		$booted = array();
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) use ( &$booted ) {
				$integrations[] = new RecordingIntegration( 'available-enabled', true, $booted );
				$integrations[] = new RecordingIntegration( 'unavailable-enabled', false, $booted );
				$integrations[] = new RecordingIntegration( 'available-disabled', true, $booted );
				return $integrations;
			}
		);

		Integrations::boot();

		$this->assertSame( array( 'available-enabled' ), $booted );
	}

	/**
	 * boot() runs at plugins_loaded, before WordPress considers it safe to
	 * translate a plugin's own strings — so it must never touch the enabled
	 * list or the auto-disabled queue itself, both of which end up
	 * translating through Log::error()/Integration::name(). That is
	 * demote_unavailable()'s job, covered below.
	 */
	public function test_boot_leaves_an_unavailable_integration_still_enabled(): void {
		update_option( 'wynko_integrations_enabled', array( 'unavailable-enabled' ) );

		$booted = array();
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) use ( &$booted ) {
				$integrations[] = new RecordingIntegration( 'unavailable-enabled', false, $booted );
				return $integrations;
			}
		);

		Integrations::boot();

		$this->assertTrue( Integrations::is_enabled( 'unavailable-enabled' ) );
		$this->assertSame( array(), get_option( 'wynko_integrations_auto_disabled', array() ) );
	}

	public function test_demote_unavailable_turns_off_an_integration_whose_dependency_is_gone(): void {
		update_option( 'wynko_integrations_enabled', array( 'unavailable-enabled' ) );

		$booted = array();
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) use ( &$booted ) {
				$integrations[] = new RecordingIntegration( 'unavailable-enabled', false, $booted );
				return $integrations;
			}
		);

		Integrations::demote_unavailable();

		$this->assertFalse( Integrations::is_enabled( 'unavailable-enabled' ) );
	}

	public function test_demote_unavailable_queues_a_notice(): void {
		update_option( 'wynko_integrations_enabled', array( 'unavailable-enabled' ) );

		$booted = array();
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) use ( &$booted ) {
				$integrations[] = new RecordingIntegration( 'unavailable-enabled', false, $booted );
				return $integrations;
			}
		);

		Integrations::demote_unavailable();

		$this->assertSame( array( 'unavailable-enabled' ), get_option( 'wynko_integrations_auto_disabled' ) );
	}

	public function test_demote_unavailable_logs_an_error(): void {
		update_option( 'wynko_integrations_enabled', array( 'unavailable-enabled' ) );

		$booted = array();
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) use ( &$booted ) {
				$integrations[] = new RecordingIntegration( 'unavailable-enabled', false, $booted );
				return $integrations;
			}
		);

		Integrations::demote_unavailable();

		$log = Log::all();
		$this->assertSame( 'error', $log[0]['level'] );
		$this->assertStringContainsString( 'unavailable-enabled', $log[0]['message'] );
	}

	public function test_demote_unavailable_does_not_queue_a_notice_for_an_available_integration(): void {
		update_option( 'wynko_integrations_enabled', array( 'available-enabled' ) );

		$booted = array();
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) use ( &$booted ) {
				$integrations[] = new RecordingIntegration( 'available-enabled', true, $booted );
				return $integrations;
			}
		);

		Integrations::demote_unavailable();

		$this->assertSame( array(), get_option( 'wynko_integrations_auto_disabled', array() ) );
		$this->assertSame( array(), Log::all() );
	}

	public function test_demote_unavailable_does_not_requeue_an_already_queued_slug(): void {
		update_option( 'wynko_integrations_enabled', array( 'unavailable-enabled' ) );
		update_option( 'wynko_integrations_auto_disabled', array( 'unavailable-enabled' ) );

		$booted = array();
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) use ( &$booted ) {
				$integrations[] = new RecordingIntegration( 'unavailable-enabled', false, $booted );
				return $integrations;
			}
		);

		Integrations::demote_unavailable();

		$this->assertSame( array( 'unavailable-enabled' ), get_option( 'wynko_integrations_auto_disabled' ) );
	}

	public function test_set_enabled_logs_info_for_a_manual_deactivation(): void {
		update_option( 'wynko_integrations_enabled', array( 'available-enabled' ) );

		$booted = array();
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) use ( &$booted ) {
				$integrations[] = new RecordingIntegration( 'available-enabled', true, $booted );
				return $integrations;
			}
		);

		Integrations::set_enabled( 'available-enabled', false );

		$log = Log::all();
		$this->assertSame( 'info', $log[0]['level'] );
		$this->assertSame( array(), get_option( 'wynko_integrations_auto_disabled', array() ) );
	}

	public function test_set_enabled_logs_nothing_for_an_already_disabled_slug(): void {
		$booted = array();
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) use ( &$booted ) {
				$integrations[] = new RecordingIntegration( 'available-enabled', true, $booted );
				return $integrations;
			}
		);

		Integrations::set_enabled( 'available-enabled', false );

		$this->assertSame( array(), Log::all() );
	}

	public function test_set_enabled_refuses_to_turn_on_an_unavailable_integration(): void {
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$booted         = array();
				$integrations[] = new RecordingIntegration( 'unavailable', false, $booted );
				return $integrations;
			}
		);

		Integrations::set_enabled( 'unavailable', true );

		$this->assertFalse( Integrations::is_enabled( 'unavailable' ) );
	}

	public function test_set_enabled_still_allows_turning_off_an_unavailable_integration(): void {
		update_option( 'wynko_integrations_enabled', array( 'unavailable' ) );
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$booted         = array();
				$integrations[] = new RecordingIntegration( 'unavailable', false, $booted );
				return $integrations;
			}
		);

		Integrations::set_enabled( 'unavailable', false );

		$this->assertFalse( Integrations::is_enabled( 'unavailable' ) );
	}

	public function test_register_bundled_adds_the_contact_form_7_bridge(): void {
		$registered = Integrations::register_bundled( array() );

		$slugs = array_map(
			static function ( $integration ) {
				return $integration->slug();
			},
			$registered
		);

		$this->assertContains( 'contact-form-7', $slugs );
	}

	public function test_register_bundled_adds_the_html_forms_bridge(): void {
		$registered = Integrations::register_bundled( array() );

		$slugs = array_map(
			static function ( $integration ) {
				return $integration->slug();
			},
			$registered
		);

		$this->assertContains( 'html-forms', $slugs );
	}
}
