<?php
/**
 * The Integrations admin screen.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Integrations;
use Wynko\Integrations\Integration;
use Wynko\Integrations\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists every registered integration and toggles it on or off, the same way
 * Plugins/Themes screens do: a row action link for one at a time (a
 * nonce-protected GET request, no confirmation dialog) plus a checkbox
 * column and a bulk-actions dropdown for several at once.
 */
final class IntegrationsPage {

	const ACTION      = 'wynko_toggle_integration';
	const ACTION_BULK = 'wynko_bulk_toggle_integration';

	/**
	 * Renders the screen: a disclaimer about third-party integrations, then
	 * one row per registered integration — or, when a specific integration is
	 * requested, that integration's own settings screen instead.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Integrations', 'wynko-for-laposta' ) . '</h1>';

		$integrations = Registry::all();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation argument, validated against a known list below; no state change on display.
		$requested = isset( $_GET['integration'] ) ? sanitize_key( wp_unslash( $_GET['integration'] ) ) : '';
		if ( '' !== $requested && array_key_exists( $requested, $integrations ) ) {
			self::render_settings_view( $integrations[ $requested ] );
			echo '</div>';
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Integrations not provided by Wynko are developed and supported by their own authors — for a problem with one, contact its developer, not Wynko.', 'wynko-for-laposta' )
		);

		if ( array() === $integrations ) {
			printf( '<p>%s</p></div>', esc_html__( 'No integrations are registered.', 'wynko-for-laposta' ) );
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_BULK ) . '" />';
		wp_nonce_field( self::ACTION_BULK );
		self::render_bulk_actions_bar();

		echo '<table class="widefat striped wynko-integrations-table"><thead><tr>';
		echo '<td class="manage-column check-column"><input type="checkbox" id="wynko-integrations-select-all" /></td>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Name', 'wynko-for-laposta' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Description', 'wynko-for-laposta' ) );
		echo '</tr></thead><tbody>';

		foreach ( $integrations as $slug => $integration ) {
			self::render_row( $slug, $integration );
		}

		echo '</tbody></table>';
		self::render_unavailable_notice( $integrations );
		echo '</form></div>';
	}

	/**
	 * The one, table-wide explanation for why some rows have no Activate
	 * link, replacing what used to be a repeated line under every such
	 * row's own description. Shown only when at least one registered
	 * integration is currently unavailable.
	 *
	 * @param array<string,Integration> $integrations Every registered integration.
	 * @return void
	 */
	private static function render_unavailable_notice( array $integrations ): void {
		foreach ( $integrations as $integration ) {
			if ( ! $integration->is_available() ) {
				printf(
					'<p class="description wynko-integrations-unavailable-notice">%s</p>',
					esc_html__( 'Integrations can only be activated once the plugin they depend on is active.', 'wynko-for-laposta' )
				);
				return;
			}
		}
	}

	/**
	 * The bulk-actions dropdown above the table: an action to apply to every
	 * checked row, matching how Plugins/Themes screens do bulk enable/disable.
	 *
	 * @return void
	 */
	private static function render_bulk_actions_bar(): void {
		echo '<div class="tablenav top"><div class="alignleft actions bulkactions">';
		echo '<label for="wynko-bulk-action" class="screen-reader-text">' . esc_html__( 'Select bulk action', 'wynko-for-laposta' ) . '</label>';
		echo '<select name="bulk_action" id="wynko-bulk-action">';
		printf( '<option value="">%s</option>', esc_html__( 'Bulk actions', 'wynko-for-laposta' ) );
		printf( '<option value="activate">%s</option>', esc_html__( 'Activate', 'wynko-for-laposta' ) );
		printf( '<option value="deactivate">%s</option>', esc_html__( 'Deactivate', 'wynko-for-laposta' ) );
		echo '</select>';
		submit_button( __( 'Apply', 'wynko-for-laposta' ), 'action', '', false );
		echo '</div></div>';
	}

	/**
	 * One integration's own settings screen, with a link back to the list.
	 *
	 * @param Integration $integration Registered integration.
	 * @return void
	 */
	private static function render_settings_view( Integration $integration ): void {
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( Menu::url( Menu::INTEGRATIONS ) ),
			esc_html__( '← Back to Integrations', 'wynko-for-laposta' )
		);
		$integration->render_settings();
	}

	/**
	 * One integration's row.
	 *
	 * @param string      $slug        Integration slug.
	 * @param Integration $integration Registered integration.
	 * @return void
	 */
	private static function render_row( string $slug, Integration $integration ): void {
		$enabled      = Integrations::is_enabled( $slug );
		$has_settings = self::has_settings( $integration );

		printf( '<tr class="%s">', $enabled ? 'wynko-integration-row--enabled' : 'wynko-integration-row--disabled' );
		printf(
			'<th scope="row" class="check-column"><input type="checkbox" class="wynko-integration-row-checkbox" name="slugs[]" value="%s" /></th>',
			esc_attr( $slug )
		);
		printf(
			'<td class="plugin-title">%s</td>',
			wp_kses( self::name_cell( $slug, $integration, $enabled, $has_settings ), self::allowed_name_html() )
		);
		printf( '<td class="column-description">%s</td>', wp_kses( self::description_cell( $integration ), self::allowed_description_html() ) );
		echo '</tr>';
	}

	/**
	 * The markup the Name cell is allowed to carry: the (optionally linked)
	 * name plus its row actions.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function allowed_name_html(): array {
		return array(
			'strong' => array(),
			'a'      => array(
				'href'    => true,
				'onclick' => true,
			),
			'div'    => array( 'class' => true ),
			'span'   => array(),
		);
	}

	/**
	 * The markup the Description cell is allowed to carry: the description
	 * itself plus the version/author/documentation/notice lines beneath it.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function allowed_description_html(): array {
		return array(
			'p' => array( 'class' => true ),
			'a' => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
		);
	}

	/**
	 * Whether an integration's render_settings() prints anything — the
	 * interface has no separate "has settings" method, so buffering its
	 * output and checking whether it printed anything is the only generic
	 * way to ask.
	 *
	 * @param Integration $integration Registered integration.
	 * @return bool
	 */
	private static function has_settings( Integration $integration ): bool {
		ob_start();
		$integration->render_settings();
		return '' !== trim( (string) ob_get_clean() );
	}

	/**
	 * The Name cell, styled like the Plugins screen's own Plugin column: the
	 * name, linked to the integration's own settings screen when it has one
	 * and is enabled (disabled means not booted — nothing live to link to),
	 * with row actions underneath.
	 *
	 * @param string      $slug         Integration slug.
	 * @param Integration $integration  Registered integration.
	 * @param bool        $enabled      Current enabled state.
	 * @param bool        $has_settings Whether render_settings() prints anything.
	 * @return string
	 */
	private static function name_cell( string $slug, Integration $integration, bool $enabled, bool $has_settings ): string {
		$name = esc_html( $integration->name() );
		if ( $enabled && $has_settings ) {
			$name = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 'integration', $slug, Menu::url( Menu::INTEGRATIONS ) ) ),
				$name
			);
		}

		return sprintf(
			'<strong>%s</strong>%s',
			$name,
			self::row_actions( $slug, $integration, $enabled, $has_settings )
		);
	}

	/**
	 * The row-actions line under a name, matching the Plugins screen's own:
	 * an enabled integration with its own settings screen gets "Settings |
	 * Deactivate", one without settings just "Deactivate". A disabled but
	 * available one gets "Activate"; a disabled and unavailable one gets no
	 * action links at all — Integrations::demote_unavailable() has already
	 * demoted anything that was enabled when its dependency vanished by the
	 * time this renders, so an unavailable row is never also the enabled one, and
	 * the table-wide notice under the table explains why Activate is
	 * missing rather than repeating it per row. Settings never shows for a
	 * disabled integration, since it isn't booted and the screen would have
	 * nothing live to act on. Both links are the same nonce-protected GET
	 * request Activate/Deactivate use on Plugins — Deactivate additionally
	 * confirms, since turning an integration off can break a form that
	 * already relies on it — and, unlike Plugins, stay visible at rest
	 * rather than only on hover (see `.wynko-integrations-table
	 * .row-actions` in forms.scss).
	 *
	 * @param string      $slug         Integration slug.
	 * @param Integration $integration  Registered integration.
	 * @param bool        $enabled      Current enabled state.
	 * @param bool        $has_settings Whether render_settings() prints anything.
	 * @return string
	 */
	private static function row_actions( string $slug, Integration $integration, bool $enabled, bool $has_settings ): string {
		$links = array();

		if ( $enabled ) {
			if ( $has_settings ) {
				$links[] = sprintf(
					'<span><a href="%s">%s</a></span>',
					esc_url( add_query_arg( 'integration', $slug, Menu::url( Menu::INTEGRATIONS ) ) ),
					esc_html__( 'Settings', 'wynko-for-laposta' )
				);
			}
			$links[] = self::deactivate_link( $slug, $integration );
		} elseif ( $integration->is_available() ) {
			$links[] = self::toggle_link( $slug, __( 'Activate', 'wynko-for-laposta' ) );
		}

		return array() === $links ? '' : '<div class="row-actions">' . implode( ' | ', $links ) . '</div>';
	}

	/**
	 * One row action: a nonce-protected GET link to the toggle handler.
	 *
	 * @param string $slug  Integration slug.
	 * @param string $label Link text.
	 * @return string
	 */
	private static function toggle_link( string $slug, string $label ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'slug'   => $slug,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $slug )
		);

		return sprintf( '<span><a href="%s">%s</a></span>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * The "Deactivate" row action, confirming first: turning an integration
	 * off can silently break a form that already relies on it (e.g. a
	 * checkbox pasted into a live Contact Form 7 form stops doing anything),
	 * so this asks before following through, the same
	 * `onclick="return confirm(...)"` pattern FormsTable's own delete link
	 * uses.
	 *
	 * @param string      $slug        Integration slug.
	 * @param Integration $integration Registered integration.
	 * @return string
	 */
	private static function deactivate_link( string $slug, Integration $integration ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'slug'   => $slug,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $slug )
		);

		return sprintf(
			'<span><a href="%s" onclick="return confirm(%s);">%s</a></span>',
			esc_url( $url ),
			esc_attr( (string) wp_json_encode( self::deactivation_warning( $integration ) ) ),
			esc_html__( 'Deactivate', 'wynko-for-laposta' )
		);
	}

	/**
	 * The confirmation text for deactivating one integration: its own
	 * deactivation_warning() when it names one, or a generic fallback
	 * otherwise.
	 *
	 * @param Integration $integration Registered integration.
	 * @return string
	 */
	private static function deactivation_warning( Integration $integration ): string {
		$warning = $integration->deactivation_warning();

		return '' !== $warning
			? $warning
			: __( 'Deactivating this integration may stop a form that relies on it from working as expected. Deactivate anyway?', 'wynko-for-laposta' );
	}

	/**
	 * The Description cell, styled like the Plugins screen's own Description
	 * column: the description text, a "Version X | By Author" meta line
	 * (the author name linked when author_uri() names one), an optional
	 * "View documentation" link, then any notice this integration needs an
	 * admin to see.
	 *
	 * @param Integration $integration Registered integration.
	 * @return string
	 */
	private static function description_cell( Integration $integration ): string {
		$author = $integration->author();
		$meta   = '' === $author
			? sprintf(
				/* translators: %s: version number. */
				__( 'Version %s | Provided by Wynko', 'wynko-for-laposta' ),
				esc_html( $integration->version() )
			)
			: sprintf(
				/* translators: 1: version number, 2: author name, already an HTML link or escaped plain text. */
				__( 'Version %1$s | By %2$s', 'wynko-for-laposta' ),
				esc_html( $integration->version() ),
				self::author_link( $author, $integration->author_uri() )
			);

		$html  = '<p>' . esc_html( $integration->description() ) . '</p>';
		$html .= '<p class="description">' . $meta . '</p>';

		if ( '' !== $integration->documentation_uri() ) {
			$html .= sprintf(
				'<p class="description"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_url( $integration->documentation_uri() ),
				esc_html__( 'View documentation', 'wynko-for-laposta' )
			);
		}

		if ( '' !== $author ) {
			$html .= '<p class="description">' . esc_html__( 'Contact this developer directly for support with this integration.', 'wynko-for-laposta' ) . '</p>';
		}

		return $html;
	}

	/**
	 * The author name, linked to author_uri() when one is given, plain text
	 * otherwise. A '' URI (esc_url() returning '' for an unsafe scheme, e.g.
	 * javascript:) also falls back to plain text rather than an empty href.
	 *
	 * @param string $author     Author or plugin name.
	 * @param string $author_uri Where the name links to, '' for none.
	 * @return string
	 */
	private static function author_link( string $author, string $author_uri ): string {
		$url = esc_url( $author_uri );
		if ( '' === $url ) {
			return esc_html( $author );
		}

		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			$url,
			esc_html( $author )
		);
	}

	/**
	 * The nonce action for one slug's toggle, scoped so one integration's
	 * token cannot toggle another.
	 *
	 * @param string $slug Integration slug.
	 * @return string
	 */
	public static function nonce_action( string $slug ): string {
		return self::ACTION . '_' . $slug;
	}

	/**
	 * Sets one slug's membership in the enabled list explicitly. A thin
	 * delegate to Integrations::set_enabled() — the actual mutation (and the
	 * is_available() guard on turning one on) lives there so
	 * Integrations::demote_unavailable() can demote a slug without this
	 * Admin-only class needing to be loaded on the front end.
	 *
	 * @param string $slug    Integration slug.
	 * @param bool   $enabled Target state.
	 * @return void
	 */
	public static function set_enabled( string $slug, bool $enabled ): void {
		Integrations::set_enabled( $slug, $enabled );
	}

	/**
	 * Flips one slug's enabled state. Extracted from handle_toggle() so the
	 * mutation is testable without shimming wp_safe_redirect() and exit.
	 *
	 * @param string $slug Integration slug.
	 * @return void
	 */
	public static function toggle( string $slug ): void {
		self::set_enabled( $slug, ! Integrations::is_enabled( $slug ) );
	}

	/**
	 * Handles one row's toggle link.
	 *
	 * @return void
	 */
	public static function handle_toggle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() below verifies it; the slug is read first only to build the per-slug nonce action name.
		$slug = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
		check_admin_referer( self::nonce_action( $slug ) );

		self::toggle( $slug );

		wp_safe_redirect( Menu::url( Menu::INTEGRATIONS ) );
		exit;
	}

	/**
	 * Handles the bulk-actions post: applies "activate" or "deactivate" to
	 * every checked slug. Any other (or blank) action is ignored, matching
	 * the Plugins screen's own behavior of doing nothing rather than
	 * guessing intent from an unselected dropdown.
	 *
	 * @return void
	 */
	public static function handle_bulk_toggle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_BULK );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above already verified this request.
		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above already verified this request.
		$slugs = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['slugs'] ) ) : array();

		if ( in_array( $bulk_action, array( 'activate', 'deactivate' ), true ) ) {
			foreach ( $slugs as $slug ) {
				self::set_enabled( $slug, 'activate' === $bulk_action );
			}
		}

		wp_safe_redirect( Menu::url( Menu::INTEGRATIONS ) );
		exit;
	}
}
