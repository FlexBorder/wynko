<?php
/**
 * Minimal WP_List_Table stand-in for the unit tests.
 *
 * @package Wynko
 */

// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore -- Mirrors WP_List_Table's own property names; renaming them would stop FormsTable from working against the real class.

if ( class_exists( 'WP_List_Table' ) ) {
	return;
}

/**
 * Enough of wp-admin/includes/class-wp-list-table.php for FormsTable to be
 * constructed and its own cell methods exercised. It deliberately does not stand
 * in for display(), because a fake of it would test this file rather than the
 * plugin.
 */
class WP_List_Table {

	/**
	 * The rows.
	 *
	 * @var array<int,mixed>
	 */
	public $items = array();

	/**
	 * Columns, sortable columns, hidden columns.
	 *
	 * @var array<int,mixed>
	 */
	protected $_column_headers = array();

	/**
	 * Constructor arguments.
	 *
	 * @var array<string,mixed>
	 */
	protected $_args = array();

	/**
	 * Records the arguments the way WP_List_Table does.
	 *
	 * @param array<string,mixed> $args Constructor arguments.
	 */
	public function __construct( $args = array() ) {
		$this->_args = is_array( $args ) ? $args : array();
	}

	/**
	 * The bulk action the request asks for, false when none.
	 *
	 * Reproduces the real WP_List_Table::current_action() exactly, including
	 * its quirk of reading only `action` and never `action2` — FormsTable
	 * overrides it precisely because of that, and a more generous stub here
	 * would hide the bug the override exists to fix.
	 *
	 * @return string|false
	 */
	public function current_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test stub mirroring core; the caller is what verifies the nonce.
		if ( isset( $_REQUEST['filter_action'] ) && ! empty( $_REQUEST['filter_action'] ) ) {
			return false;
		}

		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			return (string) $_REQUEST['action'];
		}
		// phpcs:enable

		return false;
	}

	/**
	 * The hover actions beneath a cell's value.
	 *
	 * @param array<string,string> $actions Action key => markup.
	 * @param bool                 $always_visible Whether to always show them.
	 * @return string
	 */
	protected function row_actions( $actions, $always_visible = false ) {
		$out = array();
		foreach ( $actions as $key => $markup ) {
			$out[] = '<span class="' . $key . '">' . $markup . '</span>';
		}
		return '<div class="row-actions">' . implode( ' | ', $out ) . '</div>';
	}
}
