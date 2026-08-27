<?php
/**
 * Critical-email alerts.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Admin\AlertNotice;
use Wynko\Admin\Menu;
use Wynko\Support\Recipients;
use Wynko\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mails a plain-text alert when an error is logged, at most once an hour per
 * site. Plain text on purpose: the body carries log messages that can contain
 * remote text from Laposta, and plain text removes the question of escaping it.
 */
final class Notifier {

	/**
	 * Outcomes of a test send. Three rather than a bool, because "nobody is
	 * configured" and "the mailer refused the message" need different remedies.
	 */
	const TEST_OK            = 'ok';
	const TEST_NO_RECIPIENTS = 'norecipients';
	const TEST_FAILED        = 'failed';

	/**
	 * True while a send is in flight, so a log call made from inside one cannot
	 * re-enter and recurse.
	 *
	 * @var bool
	 */
	private static bool $sending = false;

	/**
	 * Clears the in-flight guard. Tests only — one request is one send.
	 *
	 * @return void
	 */
	public static function reset_guard(): void {
		self::$sending = false;
	}

	/**
	 * Sets the in-flight guard. Tests only, since the throttle is written before
	 * the send and blocks a re-entrant call on its own.
	 *
	 * @param bool $sending Guard state.
	 * @return void
	 */
	public static function set_guard_for_test( bool $sending ): void {
		self::$sending = $sending;
	}

	/**
	 * Sends an alert for an error entry, or returns having decided not to.
	 *
	 * The throttle is written before the send is attempted: a wp_mail() that
	 * fatals must not leave the site free to try again on the very next error.
	 *
	 * @param string $level   The entry's level.
	 * @param string $message The entry's message, already translated.
	 * @return void
	 */
	public static function maybe_notify( string $level, string $message ): void {
		if ( Sanitizer::LEVEL_ERROR !== $level || self::$sending || ! self::enabled() || self::throttled() ) {
			return;
		}

		$to = self::recipients();
		if ( array() === $to ) {
			return;
		}

		set_transient( Config::notify_transient_key(), time(), Config::notify_interval() );

		// The guard stays up across the failure entry as well as the send: that
		// entry is itself an error, and recording it is the one log call
		// guaranteed to happen while a send is in flight.
		self::$sending = true;
		if ( ! self::send( $to, $message ) ) {
			$failure = sprintf(
				/* translators: %d: number of intended recipients. */
				__( 'Critical-email alert could not be sent to %d recipient(s); check this site\'s mail configuration.', 'wynko-for-laposta' ),
				count( $to )
			);
			Log::error( $failure );
			// The log is the screen the alert existed to bring someone to, so
			// recording the failure only there reports it where nobody is
			// looking. AlertNotice puts it on every admin screen instead.
			AlertNotice::record( $failure );
		}
		self::$sending = false;
	}

	/**
	 * Whether alerts are switched on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return self::forced() || (bool) Config::get( 'notify_enabled' );
	}

	/**
	 * Whether the deployment switched the alerts on by naming who they go to.
	 *
	 * A deployment that supplies recipients is already asking for alerts, though
	 * the switch's own override still outranks this. Addresses nothing can be
	 * posted to do not count.
	 *
	 * @return bool
	 */
	public static function forced(): bool {
		return ! Config::is_overridden( 'notify_enabled' )
			&& Config::is_overridden( 'notify_emails' )
			&& array() !== self::recipients();
	}

	/**
	 * Whether an alert has already gone out inside the current window.
	 *
	 * @return bool
	 */
	public static function throttled(): bool {
		return false !== get_transient( Config::notify_transient_key() );
	}

	/**
	 * The stored addresses that are actually deliverable. Filtering with
	 * is_email() is also what keeps a newline out of the mail headers.
	 *
	 * @return array<int,string>
	 */
	public static function recipients(): array {
		$parsed = Recipients::parse( (string) Config::get( 'notify_emails' ), Config::notify_max_recipients() );

		return array_values(
			array_filter(
				$parsed,
				static function ( string $address ): bool {
					return false !== is_email( $address );
				}
			)
		);
	}

	/**
	 * Mails one alert. Callers must pass addresses that have already been through
	 * recipients(), because $to becomes a mail header unvalidated.
	 *
	 * @param array<int,string> $to      Validated addresses.
	 * @param string            $message The error message to carry.
	 * @return bool Whether wp_mail() accepted it.
	 */
	public static function send( array $to, string $message ): bool {
		return (bool) wp_mail( $to, self::subject(), self::body( $message ) );
	}

	/**
	 * The subject line. The site name is decoded because get_bloginfo() returns
	 * it HTML-encoded, and a plain-text subject should not carry entities.
	 *
	 * @return string
	 */
	public static function subject(): string {
		return sprintf(
			/* translators: %s: this site's name. */
			__( '[%s] Laposta error', 'wynko-for-laposta' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * The plain-text body: where, when, what, and where to look for the rest.
	 *
	 * @param string $message The error message to carry.
	 * @return string
	 */
	public static function body( string $message ): string {
		$lines = array(
			sprintf(
				/* translators: %s: this site's address. */
				__( 'Site: %s', 'wynko-for-laposta' ),
				home_url( '/' )
			),
			sprintf(
				/* translators: %s: the time the error was recorded, in site time. */
				__( 'Time: %s', 'wynko-for-laposta' ),
				current_time( 'mysql' )
			),
			'',
			$message,
			'',
			__( 'Further errors within the hour are not mailed. The activity log has them:', 'wynko-for-laposta' ),
			Menu::url( Menu::LOG ),
		);

		return implode( "\n", $lines );
	}

	/**
	 * Sends a test alert. It ignores the throttle and does not write one, so
	 * checking the configuration cannot suppress a real alert.
	 *
	 * Both outcomes are logged, a refusal with whatever reason the mailer gave:
	 * the screen can say the send failed, only the log can say why.
	 *
	 * @return string One of self::TEST_* .
	 */
	public static function send_test(): string {
		$to = self::recipients();
		if ( array() === $to ) {
			return self::TEST_NO_RECIPIENTS;
		}

		$reason  = '';
		$capture = static function ( $error ) use ( &$reason ): void {
			if ( is_wp_error( $error ) ) {
				$reason = $error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $capture );

		// Inside the guard for the same reason the real path is: the entry
		// logged below is an error, and an error logged mid-send must not start
		// a second one.
		self::$sending = true;
		$sent          = self::send( $to, __( 'This is a test alert. The plugin has not recorded an error; someone pressed the test button on the Notifications tab.', 'wynko-for-laposta' ) );
		if ( $sent ) {
			Log::info(
				sprintf(
					/* translators: %d: number of recipients. */
					_n( 'Test email sent to %d recipient.', 'Test email sent to %d recipients.', count( $to ), 'wynko-for-laposta' ),
					count( $to )
				)
			);
		} else {
			Log::error(
				sprintf(
					/* translators: %s: the reason the mail system gave for refusing the message. */
					__( 'Test alert could not be sent: %s', 'wynko-for-laposta' ),
					'' !== $reason ? $reason : __( 'the mail system gave no reason.', 'wynko-for-laposta' )
				)
			);
		}
		self::$sending = false;

		remove_action( 'wp_mail_failed', $capture );

		return $sent ? self::TEST_OK : self::TEST_FAILED;
	}
}
