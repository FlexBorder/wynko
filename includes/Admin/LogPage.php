<?php
/**
 * Activity log screen.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Config;
use Wynko\Log;
use Wynko\Support\LogText;
use Wynko\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The activity log, with a view filter over what is stored and two actions that
 * change or emit it. The filter is navigation, so it is sanitized but not
 * nonced; Download and Clear are POSTs carrying a capability check and a nonce.
 */
final class LogPage {

	const ACTION_EXPORT = 'wynko_export_log';
	const ACTION_CLEAR  = 'wynko_clear_log';

	const ARG_LEVEL  = 'wynko_level';
	const ARG_SEARCH = 'wynko_s';

	/**
	 * Renders the activity-log screen.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}

		$level  = self::requested_level();
		$search = self::requested_search();

		echo '<div class="wrap"><h1>' . esc_html__( 'Activity log', 'wynko-for-laposta' ) . '</h1>';
		self::render_cleared_notice();
		self::render_threshold_hint();
		self::render_filter( $level, $search );

		$stored  = Log::all();
		$entries = Sanitizer::filter_log( $stored, $level, $search );
		self::render_count( count( $entries ), count( $stored ) );
		self::render_table( $entries );
		self::render_actions( $level, $search );
		echo '</div>';
	}

	/**
	 * The level asked for in the URL, or self::all-levels when none was.
	 *
	 * @return string
	 */
	public static function requested_level(): string {
		return self::clean_level( sanitize_key( self::query_arg( self::ARG_LEVEL ) ) );
	}

	/**
	 * The message substring asked for in the URL.
	 *
	 * @return string
	 */
	public static function requested_search(): string {
		return sanitize_text_field( self::query_arg( self::ARG_SEARCH ) );
	}

	/**
	 * Reads one view filter out of the URL, unslashed but not yet sanitized —
	 * each caller applies the sanitizer its own value needs. A non-string
	 * reads as absent: `?wynko_s[]=x` is not a search term.
	 *
	 * @param string $name Query argument name.
	 * @return string
	 */
	private static function query_arg( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only view filter, the same class of navigation argument as the settings page's tab: nothing here changes state, and both callers sanitize the returned value.
		return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? wp_unslash( $_GET[ $name ] ) : '';
	}

	/**
	 * Constrains a submitted level to one this screen offers. Applied to the
	 * export's hidden inputs as well as to the URL: a hidden field is request
	 * data, not screen state.
	 *
	 * @param mixed $value Raw level.
	 * @return string
	 */
	public static function clean_level( $value ): string {
		return Sanitizer::enum(
			$value,
			array_merge( array( Sanitizer::LEVEL_ALL ), Config::allowed_for( 'log_level' ) ),
			Sanitizer::LEVEL_ALL
		);
	}

	/**
	 * Prints what is currently being recorded, and where to change it. The
	 * threshold applies when an event happens, so a reader who filters for
	 * errors and sees none deserves to know whether they were ever stored.
	 *
	 * @return void
	 */
	private static function render_threshold_hint(): void {
		printf(
			'<p class="description">%s <a href="%s">%s</a></p>',
			esc_html( self::threshold_sentence() ),
			esc_url( SettingsPage::tab_url( SettingsPage::TAB_API ) ),
			esc_html__( 'Change in Settings', 'wynko-for-laposta' )
		);
	}

	/**
	 * Words the current threshold.
	 *
	 * @return string
	 */
	private static function threshold_sentence(): string {
		return sprintf(
			/* translators: %s: the wording of the current level threshold. */
			__( 'Recording: %s.', 'wynko-for-laposta' ),
			LogLevels::label( Log::threshold() )
		);
	}

	/**
	 * Prints the filter bar: a GET form back to this same screen.
	 *
	 * @param string $level  Current level filter.
	 * @param string $search Current message filter.
	 * @return void
	 */
	private static function render_filter( string $level, string $search ): void {
		printf( '<form method="get" action="%s" class="wynko-log-filter">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( Menu::LOG ) );

		printf( '<label class="screen-reader-text" for="wynko-level">%s</label>', esc_html__( 'Filter by level', 'wynko-for-laposta' ) );
		printf( '<select name="%s" id="wynko-level">', esc_attr( self::ARG_LEVEL ) );
		foreach ( self::filter_options() as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $value, $level, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		printf( '<label class="screen-reader-text" for="wynko-search">%s</label>', esc_html__( 'Search messages', 'wynko-for-laposta' ) );
		printf(
			'<input type="search" name="%s" id="wynko-search" value="%s" placeholder="%s" />',
			esc_attr( self::ARG_SEARCH ),
			esc_attr( $search ),
			esc_attr__( 'Search messages', 'wynko-for-laposta' )
		);

		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Filter', 'wynko-for-laposta' ) );

		if ( Sanitizer::LEVEL_ALL !== $level || '' !== $search ) {
			printf( ' <a href="%s">%s</a>', esc_url( Menu::url( Menu::LOG ) ), esc_html__( 'Reset', 'wynko-for-laposta' ) );
		}

		echo '</form>';
	}

	/**
	 * The filter dropdown's wording. "and up" throughout, because the filter
	 * uses the same ranking as the recording threshold.
	 *
	 * @return array<string,string>
	 */
	private static function filter_options(): array {
		return array(
			Sanitizer::LEVEL_ALL     => __( 'All levels', 'wynko-for-laposta' ),
			Sanitizer::LEVEL_WARNING => LogLevels::label( Sanitizer::LEVEL_WARNING ),
			Sanitizer::LEVEL_ERROR   => LogLevels::label( Sanitizer::LEVEL_ERROR ),
		);
	}

	/**
	 * Prints how much of the log is on screen.
	 *
	 * @param int $shown Entries after filtering.
	 * @param int $total Entries stored.
	 * @return void
	 */
	private static function render_count( int $shown, int $total ): void {
		if ( 0 === $total ) {
			return;
		}
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: entries shown, 2: entries stored. */
					__( 'Showing %1$d of %2$d entries.', 'wynko-for-laposta' ),
					$shown,
					$total
				)
			)
		);
	}

	/**
	 * Renders the log table, or the empty state.
	 *
	 * @param array<int,array<string,string>> $entries Entries to show, newest first.
	 * @return void
	 */
	private static function render_table( array $entries ): void {
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No activity to show.', 'wynko-for-laposta' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'wynko-for-laposta' ) . '</th>';
		echo '<th>' . esc_html__( 'Level', 'wynko-for-laposta' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'wynko-for-laposta' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $entries as $e ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $e['time'] ?? '' ),
				esc_html( $e['level'] ?? '' ),
				esc_html( $e['message'] ?? '' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Prints the two actions. Each posts its own form with its own nonce, so
	 * neither button can trigger the other — the same separation the system
	 * report keeps between its download and its ping.
	 *
	 * @param string $level  Current level filter, carried into the download.
	 * @param string $search Current message filter, carried into the download.
	 * @return void
	 */
	private static function render_actions( string $level, string $search ): void {
		echo '<div class="wynko-actions">';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_EXPORT ) );
		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::ARG_LEVEL ), esc_attr( $level ) );
		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::ARG_SEARCH ), esc_attr( $search ) );
		wp_nonce_field( self::ACTION_EXPORT );
		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Download log (.txt)', 'wynko-for-laposta' ) );
		echo '</form>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_CLEAR ) );
		wp_nonce_field( self::ACTION_CLEAR );
		printf(
			'<button type="submit" class="button button-secondary" onclick="return confirm(%s);">%s</button>',
			esc_attr( wp_json_encode( __( 'Clear the activity log? Every stored entry is deleted.', 'wynko-for-laposta' ) ) ),
			esc_html__( 'Clear log', 'wynko-for-laposta' )
		);
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Streams the log as a text attachment. The filter on screen is the filter
	 * in the file, so what an operator reads is what they send on; the hidden
	 * inputs carrying it are request data and get sanitized again here.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_EXPORT );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verifies this request.
		$raw   = wp_unslash( $_POST );
		$level = self::clean_level( $raw[ self::ARG_LEVEL ] ?? '' );
		// is_string before the cast: a posted wynko_s[] would otherwise raise an
		// array-to-string conversion. clean_level() is already guarded inside
		// Sanitizer::enum().
		$search = isset( $raw[ self::ARG_SEARCH ] ) && is_string( $raw[ self::ARG_SEARCH ] )
			? sanitize_text_field( $raw[ self::ARG_SEARCH ] )
			: '';

		$body = LogText::format(
			Sanitizer::filter_log( Log::all(), $level, $search ),
			self::export_header( $level, $search )
		);

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . self::filename() . '"' );
		header( 'Content-Length: ' . strlen( $body ) );

		// nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- A text/plain attachment, not an HTML document; escaping here would corrupt the file the operator downloads.
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A text/plain attachment, not an HTML document; escaping here would corrupt the file the operator downloads.
		exit;
	}

	/**
	 * The block above the entries: enough context to read the file months later
	 * or paste it into a support thread, and no key material.
	 *
	 * @param string $level  Applied level filter.
	 * @param string $search Applied message filter.
	 * @return array<string,string>
	 */
	public static function export_header( string $level, string $search ): array {
		return array(
			__( 'Site', 'wynko-for-laposta' )      => (string) get_bloginfo( 'url' ),
			__( 'Generated', 'wynko-for-laposta' ) => (string) wp_date( 'Y-m-d H:i:s' ),
			__( 'Plugin', 'wynko-for-laposta' )    => defined( 'WYNKO_VERSION' ) ? (string) WYNKO_VERSION : '',
			__( 'WordPress', 'wynko-for-laposta' ) => (string) get_bloginfo( 'version' ),
			__( 'PHP', 'wynko-for-laposta' )       => PHP_VERSION,
			__( 'Recording', 'wynko-for-laposta' ) => LogLevels::label( Log::threshold() ),
			__( 'Filter', 'wynko-for-laposta' )    => sprintf( 'level=%s search=%s', $level, '' === $search ? '-' : $search ),
		);
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

		return sanitize_file_name( sprintf( 'wynko-activity-log-%s-%s.txt', $host, (string) wp_date( 'Y-m-d' ) ) );
	}

	/**
	 * Empties the log and returns to the screen.
	 *
	 * @return void
	 */
	public static function handle_clear(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_CLEAR );

		Log::clear();

		wp_safe_redirect( self::clear_redirect_url() );
		exit;
	}

	/**
	 * Where Clear log comes back to. Extracted from the handler so the target
	 * is testable without shimming wp_safe_redirect() and exit.
	 *
	 * @return string
	 */
	public static function clear_redirect_url(): string {
		return add_query_arg( 'wynko_cleared', '1', Menu::url( Menu::LOG ) );
	}

	/**
	 * Prints the notice left behind by a Clear log redirect.
	 *
	 * @return void
	 */
	private static function render_cleared_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cosmetic flag set by handle_clear()'s own wp_safe_redirect; no state change on display.
		if ( ! isset( $_GET['wynko_cleared'] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'The activity log has been cleared.', 'wynko-for-laposta' )
		);
	}
}
