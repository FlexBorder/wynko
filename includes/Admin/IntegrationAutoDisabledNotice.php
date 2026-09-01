<?php
/**
 * The site-wide notice for an integration Integrations::demote_unavailable()
 * turned off on its own.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Config;
use Wynko\Integrations\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Says on the next admin screen an administrator sees that one or more
 * integrations were switched off because their own dependency vanished (the
 * plugin they bridge to was deactivated or removed) —
 * Integrations::demote_unavailable() does the actual switching-off and queues
 * the slug here, since an admin who was not looking at that exact moment
 * would otherwise have no way to learn why a bridge went quiet. Only the
 * slug is queued (not the name — see Integrations::queue_auto_disabled_notice()),
 * so this resolves it against Registry::all() at render time instead, falling
 * back to the bare slug for one that is no longer registered at all.
 *
 * The dismissal control is WordPress's own "X": the `is-dismissible` class
 * gets core's own layout (present on every admin screen's stylesheet,
 * unlike this plugin's own bundle — see Assets::is_wynko_screen()) for free,
 * but the `.notice-dismiss` element itself is a real nonced link this class
 * renders, not core's script-injected button, which only hides a notice for
 * the current page view; AlertNotice explains the same trade-off in more
 * depth. Core's own click handler still fades the notice out on click
 * (it delegates from `.notice-dismiss` generally, not only ones it injected
 * itself) — the link's own navigation to the dismiss URL just follows a
 * moment later.
 */
final class IntegrationAutoDisabledNotice {

	const ACTION_DISMISS = 'wynko_integrations_auto_disabled_dismiss';

	/**
	 * The queued slugs not yet shown to an admin.
	 *
	 * @return array<int,string>
	 */
	public static function queued(): array {
		$stored = get_option( Config::option_key( 'integrations_auto_disabled' ), Config::default_for( 'integrations_auto_disabled' ) );

		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}

	/**
	 * Prints the notice, or nothing when the queue is empty.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}

		$queued = self::queued();
		if ( array() === $queued ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p><p><a href="%s">%s</a></p><a href="%s" class="notice-dismiss"><span class="screen-reader-text">%s</span></a></div>',
			esc_html( self::message( $queued ) ),
			esc_url( Menu::url( Menu::INTEGRATIONS ) ),
			esc_html__( 'Review integrations', 'wynko-for-laposta' ),
			esc_url( self::dismiss_url() ),
			esc_html__( 'Dismiss this notice.', 'wynko-for-laposta' )
		);
	}

	/**
	 * The notice's own sentence, naming every distinct integration the queue
	 * carries by its current name() — falling back to the raw slug for one
	 * that is no longer registered at all, e.g. after a third-party
	 * integration's own plugin was deleted outright rather than merely
	 * deactivated.
	 *
	 * @param array<int,string> $queued Queued slugs.
	 * @return string
	 */
	private static function message( array $queued ): string {
		$registered = Registry::all();
		$names      = array_values(
			array_unique(
				array_map(
					static function ( string $slug ) use ( $registered ): string {
						return array_key_exists( $slug, $registered ) ? $registered[ $slug ]->name() : $slug;
					},
					$queued
				)
			)
		);

		return sprintf(
			/* translators: %s: comma-separated list of integration names. */
			_n(
				'Wynko turned off the %s integration because the plugin it depends on is no longer active.',
				'Wynko turned off the following integrations because the plugin each depends on is no longer active: %s.',
				count( $names ),
				'wynko-for-laposta'
			),
			implode( ', ', $names )
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
	 * Drains the queue.
	 *
	 * @return void
	 */
	public static function dismiss(): void {
		delete_option( Config::option_key( 'integrations_auto_disabled' ) );
	}

	/**
	 * Drains the queue and returns where the reader was.
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
}
