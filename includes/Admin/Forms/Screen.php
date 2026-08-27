<?php
/**
 * Signup forms admin screen.
 *
 * @package Wynko
 */

namespace Wynko\Admin\Forms;

use Wynko\Admin\Menu;
use Wynko\Forms\FormData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One WordPress screen serving two views: the list of forms, and one form's
 * editor. A hidden submenu page per form would be the alternative; a query
 * argument keeps the menu honest about how many screens there are.
 */
final class Screen {

	const SLUG         = Menu::FORMS;
	const TAB_EDITOR   = 'editor';
	const TAB_MESSAGES = 'messages';
	const TAB_SETTINGS = 'settings';

	/**
	 * The menu callback.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation argument; the form is loaded by id and nothing changes on display.
		$form_id = isset( $_GET['form'] ) ? absint( wp_unslash( $_GET['form'] ) ) : 0;

		if ( $form_id > 0 && null !== FormData::load( $form_id ) ) {
			FormEditPage::render( $form_id );
			return;
		}

		FormsListPage::render();
	}

	/**
	 * The screen's tabs, slug to label, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			self::TAB_EDITOR   => __( 'Editor', 'wynko-for-laposta' ),
			self::TAB_MESSAGES => __( 'Messages', 'wynko-for-laposta' ),
			self::TAB_SETTINGS => __( 'Settings', 'wynko-for-laposta' ),
		);
	}

	/**
	 * Resolves a requested tab against the known list.
	 *
	 * @param string $requested Raw tab argument.
	 * @return string
	 */
	public static function current_tab( string $requested ): string {
		return array_key_exists( $requested, self::tabs() ) ? $requested : self::TAB_EDITOR;
	}

	/**
	 * Admin URL for the list of forms.
	 *
	 * @return string
	 */
	public static function list_url(): string {
		return Menu::url( self::SLUG );
	}

	/**
	 * Admin URL for one form's editor.
	 *
	 * @param int    $form_id Form post id.
	 * @param string $tab     Tab slug.
	 * @return string
	 */
	public static function edit_url( int $form_id, string $tab = self::TAB_EDITOR ): string {
		return add_query_arg( 'tab', self::current_tab( $tab ), add_query_arg( 'form', (string) $form_id, self::list_url() ) );
	}
}
