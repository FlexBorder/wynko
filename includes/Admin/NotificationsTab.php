<?php
/**
 * The settings screen's Notifications tab.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Config;
use Wynko\Notifier;
use Wynko\Support\Recipients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where an administrator switches critical-email alerts on and says who gets
 * them. Its two settings register into their own group, not the API tab's:
 * wp-admin/options.php writes *every* option registered to the submitted group,
 * passing null for the ones the form did not post, so sharing a group across
 * two tabs would make saving either one reset the other's values.
 */
final class NotificationsTab {

	const ACTION_TEST = 'wynko_notify_test';

	/**
	 * Prints the tab: what the feature does, the warning if it applies, and the
	 * settings form with its own Save button.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::render_notice();

		echo '<h3>' . esc_html__( 'Critical email alerts', 'wynko-for-laposta' ) . '</h3>';
		printf(
			'<p>%s</p>',
			esc_html__( 'When the plugin records an error, it can email you. Warnings and informational entries never send. At most one alert goes out per hour; the activity log has everything else.', 'wynko-for-laposta' )
		);

		echo self::warning(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns '' or one fixed markup literal wrapping an escaped translation; no caller input reaches it.

		echo '<form method="post" action="options.php">';
		settings_fields( SettingsPage::GROUP_NOTIFICATIONS );
		do_settings_sections( SettingsPage::PAGE_NOTIFICATIONS );
		echo '<div class="wynko-actions">';
		submit_button( __( 'Save changes', 'wynko-for-laposta' ), 'primary', 'submit', false );
		echo '</div>';
		echo '</form>';

		echo '<form id="wynko-notify-test" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_TEST ) );
		wp_nonce_field( self::ACTION_TEST );
		echo '</form>';
	}

	/**
	 * Sends a test alert and returns to the tab with a result flag.
	 *
	 * @return void
	 */
	public static function handle_test(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_TEST );

		wp_safe_redirect( self::test_redirect_url( Notifier::send_test() ) );
		exit;
	}

	/**
	 * Where a test send returns to. Extracted from handle_test() so the target
	 * is testable without shimming wp_safe_redirect() and exit.
	 *
	 * @param string $flag One of Notifier::TEST_* .
	 * @return string
	 */
	public static function test_redirect_url( string $flag ): string {
		return add_query_arg( 'wynko_notify_test', $flag, SettingsPage::tab_url( SettingsPage::TAB_NOTIFICATIONS ) );
	}

	/**
	 * Prints the result of a test send, if this request carries one. A flag
	 * that is not one of the three known outcomes prints nothing rather than
	 * guessing at a message.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cosmetic flag set by handle_test()'s own wp_safe_redirect; no state change on display.
		$flag = isset( $_GET['wynko_notify_test'] ) ? sanitize_text_field( wp_unslash( $_GET['wynko_notify_test'] ) ) : '';

		$messages = array(
			Notifier::TEST_OK            => array(
				'success',
				__( 'Test email sent. If it does not arrive, look at this site\'s mail configuration rather than the plugin.', 'wynko-for-laposta' ),
			),
			Notifier::TEST_NO_RECIPIENTS => array(
				'error',
				__( 'Nothing was sent — there is no valid address configured above.', 'wynko-for-laposta' ),
			),
			// Deliberately not the wording above: the address is fine and
			// saying otherwise sends the reader after the wrong problem.
			Notifier::TEST_FAILED        => array(
				'error',
				__( 'The address is fine, but this site could not send the message. The reason the mail system gave is in the activity log.', 'wynko-for-laposta' ),
			),
		);

		if ( ! isset( $messages[ $flag ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $flag ][0] ),
			esc_html( $messages[ $flag ][1] )
		);
	}

	/**
	 * The inline notice for a feature switched on with nobody deliverable to
	 * mail, or '' when there is nothing to say.
	 *
	 * @return string
	 */
	public static function warning(): string {
		if ( ! Notifier::enabled() || array() !== Notifier::recipients() ) {
			return '';
		}

		return sprintf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'Alerts are switched on, but no valid address is configured — nothing will be sent.', 'wynko-for-laposta' )
		);
	}

	/**
	 * Prints the enable checkbox.
	 *
	 * @return void
	 */
	public static function field_enabled(): void {
		if ( ! SettingsPage::render_override( 'notify_enabled' ) ) {
			$forced = Notifier::forced();
			printf(
				'<label><input type="checkbox" id="wynko-notify-enabled" name="%s" value="1"%s%s /> %s</label>',
				esc_attr( Config::option_key( 'notify_enabled' ) ),
				checked( Notifier::enabled(), true, false ),
				$forced ? ' disabled="disabled"' : '',
				esc_html__( 'Email me when an error is recorded', 'wynko-for-laposta' )
			);
			if ( $forced ) {
				self::render_forced_note();
			}
		}

		self::field_emails();
	}

	/**
	 * Says why the switch above is on and cannot be turned off here, and keeps
	 * the stored value posting.
	 *
	 * A disabled control submits nothing, and wp-admin/options.php writes every
	 * option registered to the submitted group — so without the hidden field a
	 * save would blank what the administrator had chosen, and removing the
	 * constant later would come back to alerts off rather than to the setting
	 * they left behind.
	 *
	 * @return void
	 */
	private static function render_forced_note(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: name of the setting that supplies the recipients. */
					__( 'Switched on because %s supplies the addresses below. Remove it to manage this setting from this page.', 'wynko-for-laposta' ),
					Config::override( 'notify_emails' )['name']
				)
			)
		);
		printf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( Config::option_key( 'notify_enabled' ) ),
			esc_attr( get_option( Config::option_key( 'notify_enabled' ) ) ? '1' : '' )
		);
	}

	/**
	 * Prints who the alerts go to, and the test send, nested under the switch
	 * that decides whether either means anything. Its own row before, which
	 * left the "Send to" label standing over a field that was not there; the
	 * whole block now appears and disappears together, indented under the
	 * checkbox that governs it.
	 *
	 * @return void
	 */
	public static function field_emails(): void {
		// Hidden, never disabled. options.php writes every option registered to
		// the submitted group, so a field that does not post would blank the
		// stored addresses on the next save.
		printf(
			'<div class="wynko-notify-emails%s">',
			Notifier::enabled() ? '' : ' wynko-hidden'
		);
		printf(
			'<p><label for="wynko-notify-emails">%s</label></p>',
			esc_html__( 'Send to', 'wynko-for-laposta' )
		);

		if ( ! SettingsPage::render_override( 'notify_emails' ) ) {
			printf(
				'<input type="text" id="wynko-notify-emails" name="%s" value="%s" class="regular-text" autocomplete="off" placeholder="%s" />',
				esc_attr( Config::option_key( 'notify_emails' ) ),
				esc_attr( (string) Config::get( 'notify_emails' ) ),
				esc_attr( 'name@domain1.com,name@domain2.com' )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: maximum number of alert recipients. */
						__( 'Separate addresses with commas. Maximum %d addresses.', 'wynko-for-laposta' ),
						Config::notify_max_recipients()
					)
				)
			);
		}

		// Posts to the test form declared by render(); a form attribute lets
		// the button sit here, under the addresses it exercises, while staying
		// out of the settings form.
		printf(
			'<p><button type="submit" form="wynko-notify-test" class="button button-secondary">%s</button></p>',
			esc_html__( 'Send test email', 'wynko-for-laposta' )
		);
		echo '</div>';
	}

	/**
	 * Coerces the submitted checkbox to a bool.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public static function sanitize_enabled( $value ): bool {
		return '1' === (string) $value;
	}

	/**
	 * Normalises the submitted list and stores only deliverable addresses.
	 * Rejects are named rather than dropped silently: a typo the operator
	 * cannot see is a feature that quietly does not work.
	 *
	 * @param mixed $value Submitted list.
	 * @return string
	 */
	public static function sanitize_emails( $value ): string {
		// Split before sanitising, never after: sanitize_text_field() collapses
		// newlines and tabs to spaces, which would fuse a pasted multi-line list
		// into one blob that is then rejected wholesale.
		$parsed  = array_map( 'sanitize_text_field', Recipients::parse( (string) $value, Config::notify_max_recipients() ) );
		$valid   = array();
		$invalid = array();
		foreach ( $parsed as $address ) {
			if ( false !== is_email( $address ) ) {
				$valid[] = $address;
				continue;
			}
			$invalid[] = $address;
		}

		if ( array() !== $invalid ) {
			add_settings_error(
				Config::option_key( 'notify_emails' ),
				'wynko_notify_invalid',
				esc_html(
					sprintf(
						/* translators: %s: the addresses that were not stored, comma-separated. */
						__( 'Not a valid email address, so it was not saved: %s', 'wynko-for-laposta' ),
						Recipients::join( $invalid )
					)
				),
				'error'
			);
		}

		return Recipients::join( $valid );
	}
}
