<?php
/**
 * The signup forms list table.
 *
 * @package Wynko
 */

namespace Wynko\Admin\Forms;

use Wynko\Api\Lists;
use Wynko\Forms\FormData;
use Wynko\Urls;
use WP_List_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * A thin WP_List_Table view over FormsListPage::forms(), so the screen gets
 * WordPress's own selection, bulk actions, row actions and screen-reader text
 * rather than a hand-written table.
 *
 * Every decision worth testing lives in FormsListPage, which the unit tests can
 * reach without WordPress; this class is markup.
 */
final class FormsTable extends WP_List_Table {

	/**
	 * List id => name, fetched once per render.
	 *
	 * @var array<string,string>
	 */
	private array $list_names = array();

	/** Sets the singular and plural nouns WordPress uses in its own strings. */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wynko_form',
				'plural'   => 'wynko_forms',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The table's columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		// Signups, not Successful/Failed: those two were em-dash placeholders
		// for a submission log Phase 1 does not keep. This one counts what the
		// form has delivered over its whole life — the rate-limit window is a
		// different question, and the Security tab is where it is asked.
		return array(
			'cb'        => '<input type="checkbox" />',
			'name'      => __( 'Name', 'wynko-for-laposta' ),
			'list'      => __( 'List', 'wynko-for-laposta' ),
			'shortcode' => __( 'Shortcode', 'wynko-for-laposta' ),
			'signups'   => __( 'Signups', 'wynko-for-laposta' ),
		);
	}

	/**
	 * The bulk actions offered above and below the table.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		return array( 'delete' => __( 'Delete', 'wynko-for-laposta' ) );
	}

	/**
	 * The bulk action the request asks for, false when none.
	 *
	 * WP_List_Table renders a second bulk select below the table named `action2`,
	 * but its own current_action() reads only the upper one, which posts "-1"
	 * untouched. Falling back to `action2` is what makes that control real.
	 *
	 * @return string|false
	 */
	public function current_action() {
		$action = parent::current_action();
		if ( false !== $action ) {
			return $action;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects which action was asked for; FormsListPage::handle_bulk_action() verifies the nonce before acting on it.
		$second = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';

		return ( '' !== $second && '-1' !== $second ) ? $second : false;
	}

	/**
	 * Loads the rows.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = FormsListPage::forms();

		$this->list_names = array();
		foreach ( Lists::for_editor()['options'] as $option ) {
			$this->list_names[ $option['value'] ] = $option['label'];
		}
	}

	/**
	 * The row's selection checkbox.
	 *
	 * @param FormData $item The form.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="form_ids[]" value="%1$d" /><span class="screen-reader-text">%2$s</span>',
			(int) $item->id(),
			esc_html(
				sprintf(
					/* translators: %s: form name. */
					__( 'Select %s', 'wynko-for-laposta' ),
					$item->name()
				)
			)
		);
	}

	/**
	 * The name cell: a link to the editor plus the row actions.
	 *
	 * @param FormData $item The form.
	 * @return string
	 */
	public function column_name( FormData $item ): string {
		$actions = array(
			'edit'                 => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Screen::edit_url( $item->id() ) ),
				esc_html__( 'Edit', 'wynko-for-laposta' )
			),
			'inline hide-if-no-js' => sprintf(
				'<button type="button" class="button-link wynko-rename-open" data-form="%d">%s</button>',
				(int) $item->id(),
				esc_html__( 'Quick edit', 'wynko-for-laposta' )
			),
			'delete'               => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
				esc_url( self::delete_url( $item->id() ) ),
				esc_attr( (string) wp_json_encode( __( 'Delete this form? Places that use its shortcode will show nothing.', 'wynko-for-laposta' ) ) ),
				esc_html__( 'Delete', 'wynko-for-laposta' )
			),
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s%s',
			esc_url( Screen::edit_url( $item->id() ) ),
			esc_html( $item->display_name() ),
			self::rename_field( $item ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * The rename box for one row, hidden until Quick edit opens it.
	 *
	 * The input and its buttons post to a form declared after the list table, by
	 * way of the form attribute, because a nested form element is invalid markup
	 * that browsers drop.
	 *
	 * @param FormData $item The form.
	 * @return string
	 */
	private static function rename_field( FormData $item ): string {
		$form = FormsListPage::rename_form_id( $item->id() );

		return sprintf(
			'<div class="wynko-rename wynko-hidden" data-form="%1$d">'
			. '<label class="screen-reader-text" for="wynko-rename-%1$d">%2$s</label>'
			. '<input type="text" id="wynko-rename-%1$d" name="wynko_form_name" form="%3$s" value="%4$s" placeholder="%5$s" class="regular-text" />'
			. '<button type="submit" form="%3$s" class="button button-primary">%6$s</button> '
			. '<button type="button" class="button wynko-rename-cancel">%7$s</button>'
			. '</div>',
			(int) $item->id(),
			esc_html__( 'Form name', 'wynko-for-laposta' ),
			esc_attr( $form ),
			esc_attr( $item->name() ),
			esc_attr( FormData::default_name() ),
			esc_html__( 'Save', 'wynko-for-laposta' ),
			esc_html__( 'Cancel', 'wynko-for-laposta' )
		);
	}

	/**
	 * Every other cell.
	 *
	 * @param FormData $item        The form.
	 * @param string   $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'list':
				$list_id = $item->list_id();
				if ( '' === $list_id ) {
					return esc_html__( 'No list selected', 'wynko-for-laposta' );
				}
				return sprintf(
					'<a href="%s" target="%s" rel="%s">%s</a>',
					esc_url( Urls::laposta_list_url( $list_id ) ),
					esc_attr( Urls::target( Urls::LAPOSTA_LIST ) ),
					esc_attr( Urls::rel( Urls::LAPOSTA_LIST ) ),
					esc_html( $this->list_names[ $list_id ] ?? $list_id )
				);

			case 'shortcode':
				return sprintf(
					'<input type="text" class="code wynko-shortcode" readonly onfocus="this.select()" value="%s" />',
					esc_attr( FormsListPage::shortcode_for( $item->id() ) )
				);

			case 'signups':
				// A bare number, and 0 rather than an em dash: this is a
				// lifetime total, so "none yet" is a fact the form can state.
				// The rate-limit window belongs to the Security tab, which
				// says which period it counts beside every number it prints.
				return esc_html( (string) $item->signup_total() );

			default:
				// A column nothing knows how to fill. An em dash says "not
				// recorded"; a 0 would say "none happened".
				return '&mdash;';
		}
	}

	/**
	 * What an empty table says.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No signup forms yet. Add one to get started.', 'wynko-for-laposta' );
	}

	/**
	 * The nonced URL that deletes one form from a row action. A link rather
	 * than a form because WP_List_Table's row actions are links; the nonce is
	 * what makes it safe, and handle_delete() reads $_REQUEST for that reason.
	 *
	 * @param int $form_id Form post id.
	 * @return string
	 */
	private static function delete_url( int $form_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => FormEditPage::ACTION_DELETE,
					'form_id' => $form_id,
				),
				admin_url( 'admin-post.php' )
			),
			FormEditPage::ACTION_DELETE
		);
	}
}
