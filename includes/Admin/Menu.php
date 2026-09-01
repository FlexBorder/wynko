<?php
/**
 * Admin menu registration.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only class that talks to add_menu_page()/add_submenu_page(). A new admin
 * screen is added by appending to screens() and nowhere else.
 */
final class Menu {

	const PARENT       = 'wynko-settings';
	const FORMS        = 'wynko-forms';
	const INTEGRATIONS = 'wynko-integrations';
	const LOG          = 'wynko-log';
	const CAP          = 'manage_options';

	/**
	 * Where the menu sits: below core's last separator (99), with a gap above it
	 * rather than crowded against Settings at 80. Fractional because WordPress
	 * keys the menu by position, and resolves a collision unpredictably.
	 */
	const POSITION = 100.4;

	/**
	 * The screens under the Wynko menu, in menu order; the parent slug comes
	 * first, because its add_submenu_page() call relabels the entry WordPress
	 * creates for the top-level page. Optional 'load' callbacks run in order on
	 * load-{$hook}, before any output.
	 *
	 * @return array<int,array{slug:string,title:string,render:callable,load?:array<int,callable>}>
	 */
	public static function screens(): array {
		return array(
			array(
				'slug'   => self::PARENT,
				'title'  => __( 'Settings', 'wynko-for-laposta' ),
				'render' => array( SettingsPage::class, 'render_page' ),
			),
			array(
				'slug'   => self::FORMS,
				'title'  => __( 'Signup forms', 'wynko-for-laposta' ),
				'render' => array( \Wynko\Admin\Forms\Screen::class, 'render_page' ),
				'load'   => array(
					array( \Wynko\Admin\Forms\FormsListPage::class, 'handle_bulk_action' ),
				),
			),
			array(
				'slug'   => self::INTEGRATIONS,
				'title'  => __( 'Integrations', 'wynko-for-laposta' ),
				'render' => array( IntegrationsPage::class, 'render_page' ),
			),
			array(
				'slug'   => self::LOG,
				'title'  => __( 'Activity log', 'wynko-for-laposta' ),
				'render' => array( LogPage::class, 'render_page' ),
			),
		);
	}

	/**
	 * Registers the top-level menu and one submenu page per screen.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Only the menu titles are escaped here: menu-header.php echoes them raw
		// so plugins may put markup in an entry. Page titles are left alone,
		// because admin-header.php escapes them itself.
		add_menu_page(
			__( 'Wynko', 'wynko-for-laposta' ),
			esc_html__( 'Wynko', 'wynko-for-laposta' ),
			self::CAP,
			self::PARENT,
			array( SettingsPage::class, 'render_page' ),
			'dashicons-email-alt',
			self::POSITION
		);

		foreach ( self::screens() as $screen ) {
			$hook = add_submenu_page(
				self::PARENT,
				$screen['title'],
				esc_html( $screen['title'] ),
				self::CAP,
				$screen['slug'],
				$screen['render']
			);

			// Anything that may redirect has to run before admin-header.php
			// sends output, which is what load-{$hook} is for. A redirect from
			// the render callback would fire after the page has already begun.
			if ( false !== $hook && isset( $screen['load'] ) ) {
				foreach ( $screen['load'] as $callback ) {
					add_action( 'load-' . $hook, $callback );
				}
			}
		}
	}

	/**
	 * Absolute admin URL for a screen. Every link and redirect goes through
	 * here so no caller hard-codes the parent file.
	 *
	 * @param string $slug Screen slug.
	 * @return string
	 */
	public static function url( string $slug ): string {
		return admin_url( 'admin.php?page=' . $slug );
	}
}
