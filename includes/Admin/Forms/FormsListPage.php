<?php
/**
 * Signup forms list.
 *
 * @package Wynko
 */

namespace Wynko\Admin\Forms;

use Wynko\Admin\Menu;
use Wynko\Api\Lists;
use Wynko\Config;
use Wynko\Forms\FormData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The table of signup forms: name, bound list, shortcode, submission counts. */
final class FormsListPage {

	/** The query argument carrying a result back to this screen. */
	const NOTICE_ARG = 'wynko_forms';

	const NOTICE_DELETED = 'deleted';
	const NOTICE_RENAMED = 'renamed';

	/**
	 * This screen's URL carrying one result flag.
	 *
	 * @param string $notice One of self::NOTICE_* .
	 * @return string
	 */
	public static function notice_url( string $notice ): string {
		return add_query_arg( self::NOTICE_ARG, $notice, Screen::list_url() );
	}

	/**
	 * Every signup form, alphabetical by name.
	 *
	 * @return array<int,FormData>
	 */
	public static function forms(): array {
		$posts = get_posts(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'any',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		$forms = array();
		foreach ( $posts as $post ) {
			$form = FormData::load( (int) $post->ID );
			if ( null !== $form ) {
				$forms[] = $form;
			}
		}
		return $forms;
	}

	/**
	 * Deletes several forms, skipping ids that are not forms. The capability
	 * check is repeated here rather than trusted from the caller: this is the
	 * function that destroys data.
	 *
	 * @param array<int,mixed> $ids Submitted post ids.
	 * @return int How many forms were deleted.
	 */
	public static function bulk_delete( array $ids ): int {
		if ( ! current_user_can( Menu::CAP ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( null === FormData::load( $id ) ) {
				continue;
			}
			wp_delete_post( $id, true );
			++$deleted;
		}
		return $deleted;
	}

	/**
	 * The shortcode that places one form.
	 *
	 * @param int $form_id Form post id.
	 * @return string
	 */
	public static function shortcode_for( int $form_id ): string {
		return sprintf( '[%s id="%d"]', Config::form_shortcode(), $form_id );
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Signup forms', 'wynko-for-laposta' ) . '</h1>';
		self::render_new_form_button();
		self::render_notice();

		$table = new FormsTable();
		$table->prepare_items();

		// The list form posts to this screen rather than admin-post.php because
		// WP_List_Table emits its own <select name="action">, which a hidden
		// admin-post routing field of the same name would either override or be
		// overridden by. Letting the table own the action removes the collision
		// instead of racing it.
		printf( '<form method="post" action="%s">', esc_url( Screen::list_url() ) );
		$table->display();
		echo '</form>';

		self::render_rename_forms( $table->items );
		echo '</div>';
	}

	/**
	 * The id of the form element one row's rename box posts to.
	 *
	 * @param int $form_id Form post id.
	 * @return string
	 */
	public static function rename_form_id( int $form_id ): string {
		return 'wynko-rename-form-' . $form_id;
	}

	/**
	 * Declares one posting form per row, after the list table's own form.
	 *
	 * They carry only the routing and the nonce; the name input itself lives in
	 * the row, bound here by its form attribute, because a form nested inside
	 * another form is markup a browser throws away.
	 *
	 * @param array<int,FormData> $forms The listed forms.
	 * @return void
	 */
	private static function render_rename_forms( array $forms ): void {
		foreach ( $forms as $form ) {
			printf(
				'<form id="%s" method="post" action="%s">',
				esc_attr( self::rename_form_id( $form->id() ) ),
				esc_url( admin_url( 'admin-post.php' ) )
			);
			printf( '<input type="hidden" name="action" value="%s" />', esc_attr( FormEditPage::ACTION_RENAME ) );
			printf( '<input type="hidden" name="form_id" value="%d" />', (int) $form->id() );
			wp_nonce_field( FormEditPage::ACTION_RENAME );
			echo '</form>';
		}
	}

	/**
	 * Runs a bulk action, then reloads the screen so a refresh cannot repeat it.
	 *
	 * Hooked to load-{$hook} rather than called from render(), because the
	 * redirect has to happen before admin-header.php sends output.
	 *
	 * The nonce is the one WP_List_Table::display_tablenav() emits, 'bulk-' plus
	 * the plural noun passed to the constructor. current_action() returns false
	 * while the select is still on "Bulk actions".
	 *
	 * @return void
	 */
	public static function handle_bulk_action(): void {
		$table = new FormsTable();
		if ( 'delete' !== $table->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-wynko_forms' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verifies this request.
		$ids = isset( $_POST['form_ids'] ) && is_array( $_POST['form_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['form_ids'] ) ) : array();

		$deleted = self::bulk_delete( $ids );

		wp_safe_redirect(
			0 === $deleted
				? Screen::list_url()
				: add_query_arg( 'wynko_count', (string) $deleted, self::notice_url( self::NOTICE_DELETED ) )
		);
		exit;
	}

	/**
	 * The "Add form" control. It posts rather than links, since creating a form
	 * is a state change and so carries a nonce.
	 *
	 * The trailing rule is printed either way, because WordPress moves admin
	 * notices to wp-header-end.
	 *
	 * @return void
	 */
	private static function render_new_form_button(): void {
		printf(
			'<form method="post" action="%s" style="display:inline-block;margin-left:.5em;">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( FormEditPage::ACTION_NEW ) );
		wp_nonce_field( FormEditPage::ACTION_NEW );
		printf( '<button type="submit" class="page-title-action">%s</button>', esc_html__( 'Add form', 'wynko-for-laposta' ) );
		echo '</form>';

		echo '<hr class="wp-header-end" />';
	}

	/**
	 * Prints what a create or a delete just did, if this request carries one.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cosmetic flags set by this plugin's own wp_safe_redirect; no state change on display.
		$flag = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cosmetic count, printed as an integer.
		$count = isset( $_GET['wynko_count'] ) ? absint( wp_unslash( $_GET['wynko_count'] ) ) : 1;

		if ( self::NOTICE_DELETED === $flag ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of forms deleted. */
						_n( '%d form deleted.', '%d forms deleted.', max( 1, $count ), 'wynko-for-laposta' ),
						max( 1, $count )
					)
				)
			);
			return;
		}

		if ( self::NOTICE_RENAMED === $flag ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Form renamed.', 'wynko-for-laposta' )
			);
		}
	}
}
