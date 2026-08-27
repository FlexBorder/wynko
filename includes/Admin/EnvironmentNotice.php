<?php
/**
 * The site-wide notice for an environment the plugin was not tested against.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Config;
use Wynko\Support\Requirements;
use Wynko\SystemInfo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Says on every admin screen that this site is running something the plugin was
 * not tested with, and sends the reader to the About tab for the detail. It
 * never blocks: falling short of the advised versions means untested rather than
 * broken.
 *
 * The markup, capability gate, and nonced dismissal follow AlertNotice. Nothing
 * is printed in network admin, and what is dismissed is the environment's
 * fingerprint rather than a flag, so a changed environment raises the notice
 * again.
 */
final class EnvironmentNotice {

	const ACTION_DISMISS = 'wynko_env_dismiss';

	/** How many shortfalls the notice names before it summarises the rest. */
	const MAX_ITEMS = 3;

	/**
	 * Whether a gathered environment is one to speak up about: it has to carry a
	 * shortfall, and not be the same environment that was last dismissed.
	 *
	 * Takes the reading rather than taking it, so that render() gathers once —
	 * SystemInfo::environment() is the expensive part of this class.
	 *
	 * @param array{status:string,items:array<int,array{name:string,value:string,note:string,status:string}>,fingerprint:string} $environment Gathered environment.
	 * @return bool
	 */
	public static function should_show( array $environment ): bool {
		if ( array() === $environment['items'] ) {
			return false;
		}

		return self::dismissed() !== $environment['fingerprint'];
	}

	/**
	 * Records that this environment has been acknowledged.
	 *
	 * @return void
	 */
	public static function dismiss(): void {
		update_option( Config::option_key( 'env_dismissed' ), SystemInfo::environment()['fingerprint'], false );
	}

	/**
	 * Prints the notice, or nothing when there is nothing to report.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) || self::on_about_tab() || is_network_admin() ) {
			return;
		}

		$verdict = SystemInfo::environment();
		if ( ! self::should_show( $verdict ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s"><p><strong>%s</strong> %s</p><ul>%s</ul><p>%s</p><p>%s</p></div>',
			Requirements::STATUS_BELOW_REQUIRED === $verdict['status'] ? 'error' : 'warning',
			esc_html__( 'Wynko:', 'wynko-for-laposta' ),
			esc_html__( 'this site is running versions this plugin has not been tested with. It may not work as expected.', 'wynko-for-laposta' ),
			self::items_markup( $verdict['items'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Builds its own escaped list; see items_markup().
			wp_kses(
				sprintf(
					/* translators: %s: URL of the About tab. */
					__( 'The full report, and what is recommended instead, is under <a href="%s">Settings → About</a>.', 'wynko-for-laposta' ),
					esc_url( SettingsPage::tab_url( SettingsPage::TAB_ABOUT ) )
				),
				SettingsPage::allowed_link_html()
			),
			sprintf(
				'<a href="%s" class="button button-secondary">%s</a>',
				esc_url( self::dismiss_url() ),
				esc_html__( 'Dismiss', 'wynko-for-laposta' )
			)
		);
	}

	/**
	 * Returns the shortfalls as an escaped list, capped so that a badly
	 * out-of-date host produces a notice rather than a page of one.
	 *
	 * @param array<int,array{name:string,value:string,note:string,status:string}> $items Shortfalls from SystemInfo::verdict().
	 * @return string
	 */
	private static function items_markup( array $items ): string {
		$shown  = array_slice( $items, 0, self::MAX_ITEMS );
		$hidden = count( $items ) - count( $shown );

		$markup = '';
		foreach ( $shown as $item ) {
			$markup .= sprintf(
				'<li>%s</li>',
				esc_html(
					'' === $item['note']
						? sprintf(
							/* translators: 1: what was measured, e.g. "PHP — Version"; 2: the reading, e.g. "8.1.29". */
							__( '%1$s: %2$s', 'wynko-for-laposta' ),
							$item['name'],
							$item['value']
						)
						: sprintf(
							/* translators: 1: what was measured, e.g. "PHP — Version"; 2: the reading, e.g. "8.1.29"; 3: what is wanted instead, e.g. "8.4 or newer is advised". */
							__( '%1$s: %2$s — %3$s', 'wynko-for-laposta' ),
							$item['name'],
							$item['value'],
							$item['note']
						)
				)
			);
		}

		if ( 0 < $hidden ) {
			$markup .= sprintf(
				'<li>%s</li>',
				esc_html(
					sprintf(
						/* translators: %d: number of further findings not listed. */
						_n( 'and %d more', 'and %d more', $hidden, 'wynko-for-laposta' ),
						$hidden
					)
				)
			);
		}

		return $markup;
	}

	/**
	 * Whether the reader is already looking at the report this notice points to.
	 * Repeating it above the thing it links to would read as a fault in the
	 * page rather than a summary of it.
	 *
	 * @return bool
	 */
	private static function on_about_tab(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of which screen is being viewed; nothing is changed on the strength of it.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return SettingsPage::PAGE === $page && SettingsPage::TAB_ABOUT === $tab;
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
	 * The fingerprint that was last dismissed, or ''.
	 *
	 * @return string
	 */
	private static function dismissed(): string {
		$stored = get_option( Config::option_key( 'env_dismissed' ), Config::default_for( 'env_dismissed' ) );

		return is_string( $stored ) ? $stored : '';
	}
}
