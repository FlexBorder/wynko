<?php
/**
 * The site-wide notice for an alert that could not be emailed.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Says on every admin screen that a critical-email alert could not be sent. The
 * log records it too, but the alert existed to bring someone to that screen, so
 * the report has to go where the administrator already is.
 *
 * Dismissal is a nonced link rather than the is-dismissible button, which only
 * hides the notice for one page load and would need a script the plugin does
 * not load outside its own screens. The stored timestamp silences that failure
 * while a later one raises the notice again.
 */
final class AlertNotice {

	const ACTION_DISMISS = 'wynko_alert_dismiss';

	/**
	 * Records an alert that could not be sent, raising the notice even if an
	 * earlier one was dismissed.
	 *
	 * @param string $message Why the send failed, already translated.
	 * @return void
	 */
	public static function record( string $message ): void {
		$stored = self::stored();

		update_option(
			Config::option_key( 'alert_failure' ),
			array(
				'seq'       => (int) ( $stored['seq'] ?? 0 ) + 1,
				'at'        => time(),
				'message'   => $message,
				'dismissed' => (int) ( $stored['dismissed'] ?? 0 ),
			),
			false
		);
	}

	/**
	 * Whether there is an undismissed failure to report.
	 *
	 * @return bool
	 */
	public static function should_show(): bool {
		$stored = self::stored();

		return (int) ( $stored['seq'] ?? 0 ) > (int) ( $stored['dismissed'] ?? 0 );
	}

	/**
	 * Silences the current failure without forgetting it.
	 *
	 * @return void
	 */
	public static function dismiss(): void {
		$stored = self::stored();
		if ( array() === $stored ) {
			return;
		}

		$stored['dismissed'] = (int) ( $stored['seq'] ?? 0 );
		update_option( Config::option_key( 'alert_failure' ), $stored, false );
	}

	/**
	 * Prints the notice, or nothing when there is nothing to report.
	 *
	 * Nothing is printed in network admin: both destinations it offers are
	 * per-site screens registered on `admin_menu`, so on a network the notice
	 * would point at pages that are not in that context's menu.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) || is_network_admin() || ! self::should_show() ) {
			return;
		}

		$stored = self::stored();

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p>%s</p><p>%s</p></div>',
			esc_html__( 'Wynko:', 'wynko-for-laposta' ),
			esc_html(
				sprintf(
					/* translators: %s: the reason the mail system gave. */
					__( 'an error was recorded but the alert email could not be sent — %s', 'wynko-for-laposta' ),
					(string) ( $stored['message'] ?? '' )
				)
			),
			wp_kses(
				sprintf(
					/* translators: 1: activity log URL, 2: notification settings URL. */
					__( 'The error itself is in the <a href="%1$s">activity log</a>. Check the <a href="%2$s">notification settings</a> and this site\'s mail configuration.', 'wynko-for-laposta' ),
					esc_url( Menu::url( Menu::LOG ) ),
					esc_url( SettingsPage::tab_url( SettingsPage::TAB_NOTIFICATIONS ) )
				),
				array( 'a' => array( 'href' => array() ) )
			),
			sprintf(
				'<a href="%s" class="button button-secondary">%s</a>',
				esc_url( self::dismiss_url() ),
				esc_html__( 'Dismiss', 'wynko-for-laposta' )
			)
		);
	}

	/**
	 * The nonced link that dismisses the notice.
	 *
	 * @return string
	 */
	public static function dismiss_url(): string {
		return wp_nonce_url(
			add_query_arg( 'action', self::ACTION_DISMISS, admin_url( 'admin-post.php' ) ),
			self::ACTION_DISMISS
		);
	}

	/**
	 * Dismisses the notice and returns where the reader was.
	 *
	 * @return void
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_DISMISS );

		self::dismiss();

		$back = wp_get_referer();
		wp_safe_redirect( false !== $back ? $back : admin_url() );
		exit;
	}

	/**
	 * The stored failure, or an empty array.
	 *
	 * @return array<string,mixed>
	 */
	private static function stored(): array {
		$stored = get_option( Config::option_key( 'alert_failure' ), Config::default_for( 'alert_failure' ) );

		return is_array( $stored ) ? $stored : array();
	}
}
