<?php
/**
 * Integrations bootstrap.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Integrations\ContactForm7\ContactForm7Integration;
use Wynko\Integrations\HtmlForms\HtmlFormsIntegration;
use Wynko\Integrations\Integration;
use Wynko\Integrations\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors Plugin's own name and shape: where Wynko's bundled integrations
 * are registered, and where enabled + available ones are booted.
 */
final class Integrations {

	/**
	 * The `wynko_register_integrations` filter callback for Wynko's own
	 * bundled integrations. Registered from Plugin::boot(); anything a
	 * third-party plugin or theme adds arrives through the same filter,
	 * from its own bootstrap.
	 *
	 * @param array<int,mixed> $integrations Registered integrations so far.
	 * @return array<int,mixed>
	 */
	public static function register_bundled( array $integrations ): array {
		$integrations[] = new ContactForm7Integration();
		$integrations[] = new HtmlFormsIntegration();
		return $integrations;
	}

	/**
	 * Calls boot() on every integration that is both enabled and
	 * is_available(). Runs on plugins_loaded at a late-enough priority (see
	 * Plugin::boot()) that every plugin has had the chance to register
	 * through the filter first.
	 *
	 * Deliberately does nothing else: plugins_loaded fires before WordPress
	 * considers it safe to load a text domain (WP 6.7+ logs a
	 * "_load_textdomain_just_in_time" notice, and printing one mid-request
	 * derails whatever redirect the current request was about to send), so
	 * nothing reachable from here may call a translation function — not
	 * directly, and not through Integration::name() or Log::error(), both of
	 * which do. demote_unavailable() does the equivalent cleanup from a hook
	 * that runs late enough to translate safely.
	 *
	 * @return void
	 */
	public static function boot(): void {
		$enabled = self::enabled();

		foreach ( Registry::all() as $slug => $integration ) {
			if ( $integration->is_available() && in_array( $slug, $enabled, true ) ) {
				$integration->boot();
			}
		}
	}

	/**
	 * Turns off every enabled integration whose own dependency has gone
	 * missing since it was switched on — stored intent should not keep
	 * claiming "on" for something that cannot run — and queues it for
	 * IntegrationAutoDisabledNotice plus an error-level log entry (Notifier
	 * turns that into an email, since a form the site owner is relying on
	 * just silently stopped working). Hooked on `init` rather than folded
	 * into boot() itself: `init` is the first point WordPress documents as
	 * safe to translate a plugin's own strings, and set_enabled()'s logging
	 * needs to do exactly that.
	 *
	 * @return void
	 */
	public static function demote_unavailable(): void {
		$enabled = self::enabled();

		foreach ( Registry::all() as $slug => $integration ) {
			if ( in_array( $slug, $enabled, true ) && ! $integration->is_available() ) {
				self::set_enabled( $slug, false, true );
			}
		}
	}

	/**
	 * The slugs an administrator has switched on. Availability is not
	 * reflected here — this is stored intent, not the runtime verdict.
	 *
	 * @return array<int,string>
	 */
	public static function enabled(): array {
		$enabled = Config::get( 'integrations_enabled' );

		return is_array( $enabled ) ? array_values( array_map( 'strval', $enabled ) ) : array();
	}

	/**
	 * Whether one slug is in the enabled list.
	 *
	 * @param string $slug Integration slug.
	 * @return bool
	 */
	public static function is_enabled( string $slug ): bool {
		return in_array( $slug, self::enabled(), true );
	}

	/**
	 * Sets one slug's membership in the enabled list explicitly, validated
	 * against Registry::all() first so a forged or stale slug cannot pollute
	 * the stored option. Turning one on is refused when it is not
	 * is_available() — its own row in the Integrations screen already hides
	 * the Activate link for that case, so this closes the same door against a
	 * forged or stale bulk-action request. The one place enabling or
	 * disabling an integration actually happens — IntegrationsPage's toggle
	 * and bulk handler both delegate here rather than writing the option
	 * themselves.
	 *
	 * A transition from on to off is logged: an error, queued for
	 * IntegrationAutoDisabledNotice too, when $automatic says the site did
	 * this to itself because the dependency vanished (an event the owner did
	 * not choose and may need to act on); plain info when an administrator
	 * chose it through the Integrations screen. Turning one off that was
	 * never on, or turning one on, logs nothing — there is no "may have
	 * broken something" event to report.
	 *
	 * @param string $slug      Integration slug.
	 * @param bool   $enabled   Target state.
	 * @param bool   $automatic Whether this is Integrations::demote_unavailable()
	 *                          acting on a vanished dependency, rather than an
	 *                          administrator's own choice.
	 * @return void
	 */
	public static function set_enabled( string $slug, bool $enabled, bool $automatic = false ): void {
		$integrations = Registry::all();
		if ( ! array_key_exists( $slug, $integrations ) ) {
			return;
		}

		if ( $enabled && ! $integrations[ $slug ]->is_available() ) {
			return;
		}

		$was_enabled = self::is_enabled( $slug );

		$current = self::enabled();
		$current = $enabled
			? array_values( array_unique( array_merge( $current, array( $slug ) ) ) )
			: array_values( array_diff( $current, array( $slug ) ) );

		update_option( Config::option_key( 'integrations_enabled' ), $current );

		if ( $was_enabled && ! $enabled ) {
			self::log_deactivation( $integrations[ $slug ], $automatic );
			if ( $automatic ) {
				self::queue_auto_disabled_notice( $slug );
			}
		}
	}

	/**
	 * Records why an integration just went from enabled to disabled.
	 *
	 * @param Integration $integration The integration turning off.
	 * @param bool        $automatic   Whether this was Integrations::demote_unavailable()
	 *                                 rather than an administrator's own choice.
	 * @return void
	 */
	private static function log_deactivation( Integration $integration, bool $automatic ): void {
		if ( ! $automatic ) {
			Log::info(
				sprintf(
					/* translators: %s: integration name. */
					__( 'The %s integration was deactivated.', 'wynko-for-laposta' ),
					$integration->name()
				)
			);
			return;
		}

		Log::error(
			sprintf(
				/* translators: %s: integration name. */
				__( 'The %s integration was turned off automatically because the plugin it depends on is no longer active. This may affect a form that relies on it.', 'wynko-for-laposta' ),
				$integration->name()
			)
		);
	}

	/**
	 * Appends one slug to the auto-disabled queue IntegrationAutoDisabledNotice
	 * drains, so an admin who was not looking at this exact moment still
	 * finds out why an integration went quiet. Only the slug is stored — not
	 * the display name — since resolving it means calling name(), and this
	 * runs from set_enabled(), which demote_unavailable() (the only automatic
	 * caller) already calls from a hook safe to translate from; queuing stays
	 * translation-free regardless so nothing here depends on that staying true.
	 *
	 * @param string $slug Integration slug.
	 * @return void
	 */
	private static function queue_auto_disabled_notice( string $slug ): void {
		$queued = Config::get( 'integrations_auto_disabled' );
		$queued = is_array( $queued ) ? $queued : array();

		if ( in_array( $slug, $queued, true ) ) {
			return;
		}

		$queued[] = $slug;

		update_option( Config::option_key( 'integrations_auto_disabled' ), $queued );
	}
}
