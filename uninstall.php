<?php
/**
 * Uninstall cleanup. It runs without the plugin bootstrap, so the keys are
 * hard-coded to match config/settings.php, and it deletes the stored API key
 * along with them.
 *
 * @package Wynko
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes the plugin's options and transients for the current site.
 *
 * @return void
 */
function wynko_delete_plugin_data(): void {
	// Keep in sync with config/settings.php.
	delete_option( 'wynko_api_key' );
	delete_option( 'wynko_cache_minutes' );
	delete_option( 'wynko_log' );
	delete_option( 'wynko_log_level' );
	delete_option( 'wynko_notify_enabled' );
	delete_option( 'wynko_notify_emails' );
	delete_option( 'wynko_last_sync' );
	delete_option( 'wynko_seen' );
	delete_option( 'wynko_alert_failure' );
	delete_option( 'wynko_env_dismissed' );
	delete_option( 'wynko_list_names' );
	delete_option( 'wynko_gone_lists' );
	delete_option( 'wynko_schema' );
	delete_option( 'wynko_max_forms' );
	// The counters themselves are prefixed transients with a ten-minute life,
	// left alone for the same reason the result transients below are; the epoch
	// they are keyed by is an ordinary option and does not expire. The caps and
	// the window are ordinary settings and go with the rest.
	delete_option( 'wynko_throttle_epoch' );
	delete_option( 'wynko_throttle_window' );
	delete_option( 'wynko_throttle_ip_max' );
	delete_option( 'wynko_throttle_form_max' );
	// Unlike the counters, the near-cap notice lives for a day, which is long
	// enough that leaving it behind would mean a reinstalled plugin warning
	// about a form that no longer exists.
	delete_transient( 'wynko_throttle_pressure_notice' );
	delete_transient( 'wynko_campaigns' );
	delete_transient( 'wynko_lists' );
	delete_transient( 'wynko_key_status' );
	delete_transient( 'wynko_unreadable_logged' );
	delete_transient( 'wynko_notify_sent' );
	delete_transient( 'wynko_fields' );

	// Signup forms: a post type registered with public => false is not covered by
	// core's uninstall cleanup, so its posts have to go explicitly. The
	// per-submission result transients are left alone, being keyed by a random
	// token, expiring within five minutes, and enumerable only through raw SQL.
	foreach (
		get_posts(
			array(
				'post_type'   => 'wynko_form',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) as $wynko_form
	) {
		// @phpstan-ignore function.impossibleType (WordPress stubs type 'fields' => 'ids' as int[], but the PHPUnit get_posts() test double ignores 'fields' and returns WP_Post objects, so this stays defensive for both.)
		wp_delete_post( (int) ( is_object( $wynko_form ) ? $wynko_form->ID : $wynko_form ), true );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $wynko_site_id ) {
		switch_to_blog( (int) $wynko_site_id );
		wynko_delete_plugin_data();
		restore_current_blog();
	}
} else {
	wynko_delete_plugin_data();
}
