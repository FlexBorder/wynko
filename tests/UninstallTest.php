<?php
/**
 * Tests for uninstall cleanup.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use PHPUnit\Framework\TestCase;

/**
 * uninstall.php declares functions at include time, so one include per process
 * is all that is possible — hence one test that asserts everything at once.
 */
final class UninstallTest extends TestCase {

	public function test_it_deletes_the_options_transients_and_forms(): void {
		wynko_test_reset_store();

		update_option( 'wynko_api_key', 'secret' );
		update_option(
			'wynko_last_sync',
			array(
				'at' => 123,
				'ok' => true,
			)
		);
		update_option( 'wynko_log', array( array( 'level' => 'info' ) ) );
		update_option( 'wynko_seen', array( 'campaigns' => array( 'a' ) ) );
		update_option( 'wynko_list_names', array( 'list_a' => 'A' ) );
		update_option( 'wynko_gone_lists', array( 'list_a' ) );
		update_option( 'wynko_log_level', 'warning' );
		update_option( 'wynko_notify_enabled', true );
		update_option( 'wynko_notify_emails', 'ops@example.org' );
		update_option( 'wynko_max_forms', 5 );
		update_option( 'wynko_env_dismissed', str_repeat( 'a', 64 ) );
		update_option( 'wynko_throttle_window', 20 );
		update_option( 'wynko_throttle_ip_max', 30 );
		update_option( 'wynko_throttle_form_max', 900 );
		set_transient( 'wynko_throttle_pressure_notice', array( 'Footer signup' ), 86400 );
		set_transient( 'wynko_notify_sent', 1, 3600 );
		set_transient( 'wynko_fields', array( 'list_a' => array() ), 60 );
		$form_id = wynko_test_insert_post(
			array(
				'post_type'   => 'wynko_form',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $form_id, '_wynko_list_id', 'list_a' );
		$page_id = wynko_test_insert_post( array( 'post_type' => 'page' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wynko/wynko.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( 'wynko_api_key', false ) );
		$this->assertFalse( get_option( 'wynko_last_sync', false ) );
		$this->assertFalse( get_option( 'wynko_log', false ) );
		$this->assertFalse( get_option( 'wynko_seen', false ) );
		$this->assertFalse( get_option( 'wynko_list_names', false ) );
		$this->assertFalse( get_option( 'wynko_gone_lists', false ) );
		$this->assertFalse( get_option( 'wynko_log_level', false ) );
		$this->assertFalse( get_option( 'wynko_notify_enabled', false ) );
		$this->assertFalse( get_option( 'wynko_notify_emails', false ) );
		$this->assertFalse( get_option( 'wynko_max_forms', false ) );
		$this->assertFalse( get_option( 'wynko_env_dismissed', false ) );
		$this->assertFalse( get_option( 'wynko_throttle_window', false ) );
		$this->assertFalse( get_option( 'wynko_throttle_ip_max', false ) );
		$this->assertFalse( get_option( 'wynko_throttle_form_max', false ) );
		$this->assertFalse( get_transient( 'wynko_throttle_pressure_notice' ) );
		$this->assertFalse( get_transient( 'wynko_notify_sent' ) );
		$this->assertFalse( get_transient( 'wynko_fields' ) );
		$this->assertNull( get_post( $form_id ) );
		$this->assertSame( '', get_post_meta( $form_id, '_wynko_list_id', true ) );
		$this->assertNotNull( get_post( $page_id ), 'uninstall must not touch other post types' );
	}
}
