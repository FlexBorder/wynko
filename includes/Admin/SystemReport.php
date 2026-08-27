<?php
/**
 * The About tab's system report.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\KeyStatus;
use Wynko\Log;
use Wynko\Support\Requirements;
use Wynko\SystemInfo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders SystemInfo's rows twice over: as tables on the About tab, and as the
 * plain-text file an operator attaches to a support request. Both read the same
 * array, so the pasted report and the screen cannot disagree.
 */
final class SystemReport {

	const ACTION_EXPORT = 'wynko_system_report';
	const ACTION_PING   = 'wynko_api_ping';

	/**
	 * Prints the report: one table per section.
	 *
	 * @return void
	 */
	public static function render(): void {
		echo '<h3>' . esc_html__( 'System report', 'wynko-for-laposta' ) . '</h3>';
		echo '<p>' . esc_html__( 'What this site is running, and what the plugin makes of it. Download it and attach it to a support request.', 'wynko-for-laposta' ) . '</p>';

		echo '<p class="description">';
		echo esc_html( implode( ' · ', self::header_lines() ) );
		echo '</p>';

		foreach ( SystemInfo::sections() as $section ) {
			printf( '<h4>%s</h4>', esc_html( $section['title'] ) );
			echo '<table class="widefat striped wynko-report"><tbody>';
			foreach ( $section['rows'] as $row ) {
				printf(
					'<tr><th scope="row">%s</th><td>%s%s%s%s</td></tr>',
					esc_html( $row['label'] ),
					self::icon( $row['status'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns one of three fixed markup literals, no caller input.
					esc_html( $row['value'] ),
					'' === $row['note'] ? '' : ' <span class="description">(' . esc_html( $row['note'] ) . ')</span>',
					self::row_action( $row['action'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Builds its own escaped markup from a fixed slug; see row_action().
				);
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Returns the control a row offers, as markup, or '' when it offers none.
	 * The button lives in the row it reports on rather than in the action strip
	 * below, so what it re-checks is not left to be inferred.
	 *
	 * @param string $action A SystemInfo::ACTION_* slug, or ''.
	 * @return string
	 */
	private static function row_action( string $action ): string {
		if ( SystemInfo::ACTION_PING !== $action ) {
			return '';
		}

		return sprintf(
			'<form class="wynko-report__action" method="post" action="%s">'
			. '<input type="hidden" name="action" value="%s" />%s'
			. '<button type="submit" class="button button-secondary">%s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION_PING ),
			wp_nonce_field( self::ACTION_PING, '_wpnonce', true, false ),
			esc_html__( 'Test connection now', 'wynko-for-laposta' )
		);
	}

	/**
	 * Returns what identifies this report: which plugin wrote it, when, and for
	 * which site. Both renderings print these, so the screen an operator reads
	 * and the file they attach carry the same header.
	 *
	 * @return array<int,string>
	 */
	public static function header_lines(): array {
		return array(
			__( 'Wynko — system report', 'wynko-for-laposta' ),
			sprintf(
				/* translators: 1: date and time; 2: timezone name. */
				__( 'Generated: %1$s (%2$s)', 'wynko-for-laposta' ),
				(string) wp_date( 'Y-m-d H:i' ),
				(string) wp_timezone_string()
			),
			sprintf(
				/* translators: %s: site address. */
				__( 'Site: %s', 'wynko-for-laposta' ),
				(string) get_bloginfo( 'url' )
			),
		);
	}

	/**
	 * Returns the status icon for one row. An informational row gets none: a
	 * tick beside "Multisite: no" would read as a verdict.
	 *
	 * @param string $status A Requirements::STATUS_* constant.
	 * @return string
	 */
	private static function icon( string $status ): string {
		if ( Requirements::STATUS_OK === $status ) {
			return '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> ';
		}
		if ( Requirements::STATUS_BELOW_REQUIRED === $status ) {
			return '<span class="dashicons dashicons-warning" style="color:#d63638;"></span> ';
		}
		if ( Requirements::STATUS_BELOW_ADVISED === $status ) {
			return '<span class="dashicons dashicons-warning" style="color:#dba617;"></span> ';
		}
		return '';
	}

	/**
	 * Returns the plain-text rendering of the same sections.
	 *
	 * @param array<int,array{title:string,rows:array<int,array{label:string,value:string,note:string,status:string,action:string}>}> $sections Report sections.
	 * @return string
	 */
	public static function text( array $sections ): string {
		$lines = self::header_lines();

		foreach ( $sections as $section ) {
			$lines[] = '';
			$lines[] = '== ' . $section['title'] . ' ==';
			foreach ( $section['rows'] as $row ) {
				$note    = '' === $row['note'] ? '' : ' (' . $row['note'] . ')';
				$lines[] = $row['label'] . ': ' . $row['value'] . self::marker( $row['status'] ) . $note;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Returns the text marker for a row that needs attention, so a reader can
	 * scan a pasted report without reading every line.
	 *
	 * @param string $status A Requirements::STATUS_* constant.
	 * @return string
	 */
	private static function marker( string $status ): string {
		if ( Requirements::STATUS_BELOW_REQUIRED === $status ) {
			return '  [fail]';
		}
		if ( Requirements::STATUS_BELOW_ADVISED === $status ) {
			return '  [warn]';
		}
		return '';
	}

	/**
	 * Returns the download's filename: the site's host and today's date, both
	 * made safe before they reach a Content-Disposition header.
	 *
	 * @return string
	 */
	public static function filename(): string {
		$host = (string) wp_parse_url( (string) get_bloginfo( 'url' ), PHP_URL_HOST );
		$host = '' === $host ? 'site' : $host;

		return sanitize_file_name( sprintf( 'wynko-system-report-%s-%s.txt', $host, (string) wp_date( 'Y-m-d' ) ) );
	}

	/**
	 * Returns where "Test connection now" comes back to. Extracted from the
	 * handler so the target is testable without shimming wp_safe_redirect() and
	 * exit — the same split SettingsPage::sync_redirect_url() uses.
	 *
	 * @param string $flag Result flag, 'ok' or 'error'.
	 * @return string
	 */
	public static function ping_redirect_url( string $flag ): string {
		return add_query_arg( 'wynko_ping', $flag, SettingsPage::tab_url( SettingsPage::TAB_ABOUT ) );
	}

	/**
	 * Prints the report's download action; the connection test renders inside the
	 * row it re-checks, by way of row_action(). Both post their own form with
	 * their own nonce, so neither button can trigger the other.
	 *
	 * @return void
	 */
	public static function render_actions(): void {
		echo '<div class="wynko-actions">';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_EXPORT ) );
		wp_nonce_field( self::ACTION_EXPORT );
		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Download report (.txt)', 'wynko-for-laposta' ) );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Prints the notice left behind by a "Test connection now" redirect.
	 *
	 * @return void
	 */
	public static function render_ping_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cosmetic flag set by handle_ping()'s own wp_safe_redirect; no state change on display.
		$flag = isset( $_GET['wynko_ping'] ) ? sanitize_text_field( wp_unslash( $_GET['wynko_ping'] ) ) : '';
		if ( '' === $flag ) {
			return;
		}

		$ok = ( 'ok' === $flag );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$ok ? 'success' : 'error',
			esc_html(
				$ok
					? __( 'Laposta answered and the API key was accepted.', 'wynko-for-laposta' )
					: __( 'The check failed — see the connection row below for why.', 'wynko-for-laposta' )
			)
		);
	}

	/**
	 * Streams the report as a text attachment. It is written to be pasted into a
	 * public support thread, so it carries the API key's source and the verdict
	 * about it, never the key or its fingerprint.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_EXPORT );

		$body = self::text( SystemInfo::sections() );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::filename() . '"' );
		header( 'Content-Length: ' . strlen( $body ) );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A text/plain attachment, not an HTML document; escaping here would corrupt the file the operator downloads.
		exit;
	}

	/**
	 * Re-probes the API on demand and returns to the About tab. The only path
	 * in this feature that talks to Laposta — the report itself reads cache.
	 *
	 * @return void
	 */
	public static function handle_ping(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_PING );

		delete_transient( KeyStatus::TRANSIENT );
		$verdict = KeyStatus::current();
		self::log_ping( $verdict );

		wp_safe_redirect( self::ping_redirect_url( $verdict['ok'] ? 'ok' : 'error' ) );
		exit;
	}

	/**
	 * Reports the one outcome the probe itself cannot: KeyStatus::verify() returns
	 * early for an empty key without calling Laposta, so nothing would otherwise
	 * reach the log. Extracted from the handler so it is testable without shimming
	 * wp_safe_redirect() and exit.
	 *
	 * @param array{ok:bool,message:string,code:string} $verdict KeyStatus verdict.
	 * @return void
	 */
	public static function log_ping( array $verdict ): void {
		if ( $verdict['ok'] || '' !== $verdict['code'] || '' !== $verdict['message'] ) {
			return;
		}
		Log::warning( __( 'Connection test skipped: no API key is configured.', 'wynko-for-laposta' ) );
	}
}
