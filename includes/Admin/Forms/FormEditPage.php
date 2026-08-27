<?php
/**
 * Signup form editor.
 *
 * @package Wynko
 */

namespace Wynko\Admin\Forms;

use Wynko\Admin\Menu;
use Wynko\Api\Fields;
use Wynko\Api\Lists;
use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Forms\Messages;
use Wynko\Support\Fields as FieldData;
use Wynko\Support\LapostaErrors;
use Wynko\Urls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One form's editor: three tabs (Editor, Messages, Settings) over one POST
 * form, plus a separate delete form, and the create/save/delete admin-post
 * handlers those forms submit to.
 */
final class FormEditPage {

	const ACTION_NEW    = 'wynko_new_form';
	const ACTION_SAVE   = 'wynko_save_form';
	const ACTION_DELETE = 'wynko_delete_form';
	const ACTION_RENAME = 'wynko_rename_form';

	/** What a save did. A bad pattern is refused whole, so nothing is stored. */
	const SAVE_OK          = 'ok';
	const SAVE_NOT_FOUND   = 'not_found';
	const SAVE_BAD_PATTERN = 'bad_pattern';
	const SAVE_BAD_DEFAULT = 'bad_default';

	/** The query argument carrying a refused save back to the editor. */
	const ERROR_ARG = 'wynko_error';

	/** The query argument a successful save returns with. */
	const SAVED_ARG = 'wynko_form_saved';

	/** The posted destination of a tab switch that saves on its way. */
	const GOTO_ARG = 'goto_tab';

	/**
	 * Renders one form's editor.
	 *
	 * @param int $form_id Form post id.
	 * @return void
	 */
	public static function render( int $form_id ): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}

		$form = FormData::load( $form_id );
		if ( null === $form ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation argument, validated against a known list by current_tab(); no state change on display.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$tab       = Screen::current_tab( $requested );

		// Every admin screen needs an h1: it is what a screen reader announces
		// as the page, and what tells the reader which screen they are on. The
		// form's own name is an editable input, not a heading, so it cannot do
		// that job.
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Edit signup form', 'wynko-for-laposta' ) . '</h1>';

		self::render_save_error();
		self::render_saved_notice();

		// The form opens before the header so the title posts with whatever tab is
		// being saved. The tabs are plain links, so the id here is what lets the
		// unsaved-changes guard submit this form before following one.
		printf( '<form id="wynko-form-edit" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_SAVE ) );
		printf( '<input type="hidden" name="form_id" value="%d" />', (int) $form->id() );
		printf( '<input type="hidden" name="tab" value="%s" />', esc_attr( $tab ) );
		// Empty unless a tab click filled it in. Deliberately not the tab input
		// above: that one says which tab's data to write, and a save must never
		// take one for the other.
		printf( '<input type="hidden" id="wynko-goto-tab" name="%s" value="" />', esc_attr( self::GOTO_ARG ) );
		wp_nonce_field( self::ACTION_SAVE );

		self::render_header( $form );

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( Screen::tabs() as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s" data-tab="%s">%s</a>',
				esc_url( Screen::edit_url( $form->id(), $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_attr( $slug ),
				esc_html( $label )
			);
		}
		echo '</h2>';

		if ( Screen::TAB_MESSAGES === $tab ) {
			self::render_messages_tab( $form );
		} elseif ( Screen::TAB_SETTINGS === $tab ) {
			self::render_settings_tab( $form );
		} else {
			self::render_editor_tab( $form );
		}

		// Save and Delete share a row but stay two forms with two actions and two
		// nonces, so a save cannot trigger a delete.
		echo '<div class="wynko-form-actions">';
		submit_button( __( 'Save form', 'wynko-for-laposta' ), 'primary', 'submit', false );
		echo '</form>';

		self::render_delete_form( $form );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Handles the editor's post: capability, nonce, save, redirect.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		self::require_capability();
		check_admin_referer( self::ACTION_SAVE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verifies this request; every value below is sanitized in save().
		$raw     = wp_unslash( $_POST );
		$form_id = isset( $raw['form_id'] ) ? absint( $raw['form_id'] ) : 0;
		$tab     = Screen::current_tab( isset( $raw['tab'] ) ? sanitize_key( $raw['tab'] ) : '' );

		$outcome = self::save( $form_id, $raw );

		if ( self::SAVE_NOT_FOUND === $outcome ) {
			wp_die( esc_html__( 'That form does not exist.', 'wynko-for-laposta' ), '', array( 'response' => 404 ) );
		}

		$url = self::saved_redirect_url( $form_id, self::destination_tab( $raw, $tab ) );
		if ( self::SAVE_OK !== $outcome ) {
			// Back to the tab that was saved, whatever tab was being headed
			// for: the refusal is about controls that live on this one.
			$url = add_query_arg( self::ERROR_ARG, $outcome, Screen::edit_url( $form_id, $tab ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Where a save lands: the tab a click was heading for, else the one saved.
	 *
	 * Screen::current_tab() answers "editor" for anything it does not know, so
	 * the argument's presence is tested before it is resolved — otherwise an
	 * ordinary Save from the Settings tab would be indistinguishable from a
	 * click on Editor and would leave the tab the admin was working on.
	 *
	 * @param array<string,mixed> $raw   Unslashed request data.
	 * @param string              $saved Tab that was saved.
	 * @return string
	 */
	public static function destination_tab( array $raw, string $saved ): string {
		$goto = isset( $raw[ self::GOTO_ARG ] ) ? sanitize_key( (string) $raw[ self::GOTO_ARG ] ) : '';

		return '' === $goto ? $saved : Screen::current_tab( $goto );
	}

	/**
	 * Creates an empty form and opens its editor.
	 *
	 * @return void
	 */
	public static function handle_new(): void {
		self::require_capability();
		check_admin_referer( self::ACTION_NEW );

		// No title. The editor asks for one with a placeholder, and a form
		// saved without one is named then — a stored "New signup form" is a
		// value the operator has to clear before typing their own.
		$form_id = (int) wp_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
				'post_title'  => '',
			)
		);

		wp_safe_redirect( self::new_redirect_url( $form_id ) );
		exit;
	}

	/**
	 * Where creating a form lands: its editor, or the list when the insert
	 * failed. Extracted so the destination is testable without shimming exit.
	 *
	 * @param int $form_id Newly created form id, 0 on failure.
	 * @return string
	 */
	public static function new_redirect_url( int $form_id ): string {
		return $form_id > 0 ? Screen::edit_url( $form_id ) : Screen::list_url();
	}

	/**
	 * Renames one form from the list screen and returns to it.
	 *
	 * Its own action rather than a trip through save(): a rename posts the name
	 * and nothing else, and save() writes the submitted tab's meta from what
	 * the request carries — which, here, is nothing.
	 *
	 * @return void
	 */
	public static function handle_rename(): void {
		self::require_capability();
		check_admin_referer( self::ACTION_RENAME );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verifies this request.
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verifies this request.
		$name = isset( $_POST['wynko_form_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wynko_form_name'] ) ) : '';

		wp_safe_redirect( self::rename( $form_id, $name ) ? FormsListPage::notice_url( FormsListPage::NOTICE_RENAMED ) : Screen::list_url() );
		exit;
	}

	/**
	 * Stores one form's new name. Separate from handle_rename() so the rule —
	 * a blank name falls back to the placeholder, an unknown id changes
	 * nothing — is testable without shimming exit.
	 *
	 * @param int    $form_id Form post id.
	 * @param string $name    Submitted name.
	 * @return bool Whether the name actually changed.
	 */
	public static function rename( int $form_id, string $name ): bool {
		$form = FormData::load( $form_id );
		if ( null === $form ) {
			return false;
		}

		// A name that is already the stored one is not a rename, and saying
		// "Form renamed" for it would be reporting something that did not
		// happen.
		$name = '' === trim( $name ) ? FormData::default_name() : $name;
		if ( $name === $form->name() ) {
			return false;
		}

		wp_update_post(
			array(
				'ID'         => $form_id,
				'post_title' => $name,
			)
		);
		return true;
	}

	/**
	 * Deletes a form and returns to the list.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		self::require_capability();
		check_admin_referer( self::ACTION_DELETE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verifies this request. $_REQUEST rather than $_POST because the list table's row action is a nonced link, not a form post.
		$form_id = isset( $_REQUEST['form_id'] ) ? absint( wp_unslash( $_REQUEST['form_id'] ) ) : 0;

		$deleted = null !== FormData::load( $form_id );
		if ( $deleted ) {
			wp_delete_post( $form_id, true );
		}

		wp_safe_redirect( $deleted ? FormsListPage::notice_url( FormsListPage::NOTICE_DELETED ) : Screen::list_url() );
		exit;
	}

	/**
	 * Sanitizes and stores one form's submitted editor state. Separate from
	 * handle_save() so the sanitizing is testable without shimming exit.
	 *
	 * Only the submitted tab's own meta is written, since each tab renders only
	 * its own inputs and writing all three would let one tab's save wipe
	 * another's. The name is the exception, posted from the shared header on
	 * every tab.
	 *
	 * @param int                 $form_id Form post id.
	 * @param array<string,mixed> $raw     Unslashed request data.
	 * @return string One of self::SAVE_* .
	 */
	public static function save( int $form_id, array $raw ): string {
		$form = FormData::load( $form_id );
		if ( null === $form ) {
			return self::SAVE_NOT_FOUND;
		}

		$tab = Screen::current_tab( isset( $raw['tab'] ) ? sanitize_key( (string) $raw['tab'] ) : '' );

		// A blank name is answered with the placeholder the field showed, so a
		// form saved without one is named rather than left untitled.
		$name = isset( $raw['wynko_form_name'] ) ? sanitize_text_field( (string) $raw['wynko_form_name'] ) : '';
		$name = '' === $name ? FormData::default_name() : $name;
		if ( $name !== $form->name() ) {
			wp_update_post(
				array(
					'ID'         => $form_id,
					'post_title' => $name,
				)
			);
		}

		if ( Screen::TAB_MESSAGES === $tab ) {
			$form->save_messages( self::clean_messages( $raw['wynko_messages'] ?? array() ) );
		} elseif ( Screen::TAB_SETTINGS === $tab ) {
			$form->save_settings( self::clean_settings( $raw['wynko_settings'] ?? array() ) );
		} else {
			$list_id = isset( $raw['wynko_list_id'] ) ? sanitize_text_field( (string) $raw['wynko_list_id'] ) : '';
			$fields  = self::clean_fields( $raw['wynko_fields'] ?? array(), $list_id );

			// Refused whole rather than stored: an uncompilable pattern would
			// make preg_match() warn on every submission, and a warning on an
			// admin screen is what raises core's php-error body class.
			if ( self::has_bad_pattern( $fields ) ) {
				return self::SAVE_BAD_PATTERN;
			}

			// Likewise refused whole: a default outside the bounds set two
			// controls away is a value the form fills in and then rejects.
			if ( self::has_bad_default( $fields, $list_id ) ) {
				return self::SAVE_BAD_DEFAULT;
			}

			// Saving the rows rewrites them through normalize_override(), which
			// no longer carries label_mode — so a form that still derives its
			// mode from those rows must have it written down first, or the
			// first Editor save an administrator makes would silently reset it.
			$form->persist_derived_label_mode();

			$submitted = isset( $raw['wynko_settings'] ) && is_array( $raw['wynko_settings'] ) ? $raw['wynko_settings'] : array();
			if ( isset( $submitted['label_mode'] ) ) {
				$form->save_settings( array( 'label_mode' => self::clean_label_mode( $submitted['label_mode'] ) ) );
			}

			$form->save_list_id( $list_id );
			$form->save_field_overrides( $fields );
			$form->save_button( self::clean_button( $raw['wynko_button'] ?? array() ) );
		}

		return self::SAVE_OK;
	}

	/**
	 * One row's default value, '' when it is the one Laposta already holds.
	 *
	 * The editor prefills the box with Laposta's value, and storing that back
	 * would freeze it rather than let the field follow the list. Dropping it here
	 * lets the box show the value and still mean "whatever Laposta says".
	 *
	 * @param mixed  $submitted Submitted value.
	 * @param string $laposta   What the live definition holds.
	 * @return string
	 */
	private static function clean_default_value( $submitted, string $laposta ): string {
		$value = is_scalar( $submitted ) ? sanitize_text_field( (string) $submitted ) : '';

		return $value === $laposta ? '' : $value;
	}

	/**
	 * Whether any row carries a pattern that will not compile.
	 *
	 * @param array<int,array<string,mixed>> $fields Cleaned rows.
	 * @return bool
	 */
	private static function has_bad_pattern( array $fields ): bool {
		foreach ( $fields as $row ) {
			$attrs   = ( isset( $row['attrs'] ) && is_array( $row['attrs'] ) ) ? $row['attrs'] : array();
			$pattern = isset( $attrs['pattern'] ) ? (string) $attrs['pattern'] : '';
			if ( '' !== $pattern && null === FieldData::compile_pattern( $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any row's default value is one the form would then refuse.
	 *
	 * The type is read from the live definitions rather than the submission:
	 * the bounds a default is checked against belong to a type only Laposta
	 * assigns, and a browser is free to post whatever it likes.
	 *
	 * @param array<int,array<string,mixed>> $fields  Cleaned rows.
	 * @param string                         $list_id Bound list.
	 * @return bool
	 */
	private static function has_bad_default( array $fields, string $list_id ): bool {
		if ( '' === $list_id ) {
			return false;
		}

		$defs = Fields::for_list( $list_id );
		if ( $defs['error'] ) {
			return false;
		}

		$types = array();
		foreach ( $defs['fields'] as $def ) {
			$types[ $def['field_id'] ] = (string) $def['type'];
		}

		foreach ( $fields as $row ) {
			$type = $types[ $row['field_id'] ?? '' ] ?? '';
			if ( '' !== $type && null !== FieldData::default_value_error( $row, $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Where a save returns to.
	 *
	 * @param int    $form_id Form post id.
	 * @param string $tab     Tab that was saved.
	 * @return string
	 */
	public static function saved_redirect_url( int $form_id, string $tab ): string {
		return add_query_arg( self::SAVED_ARG, '1', Screen::edit_url( $form_id, $tab ) );
	}

	/**
	 * Sanitizes the submitted field rows, orders them, and drops anything the
	 * bound list does not actually have. The required flag is re-read from live
	 * field data on every save, so a field Laposta made required is stored
	 * visible whatever the browser sent — the UI lock is a convenience, this is
	 * the enforcement.
	 *
	 * @param mixed  $rows    Submitted wynko_fields.
	 * @param string $list_id Bound list.
	 * @return array<int,array{field_id:string,visible:bool,label:string,css_class:string}>
	 */
	private static function clean_fields( $rows, string $list_id ): array {
		if ( ! is_array( $rows ) || '' === $list_id ) {
			return array();
		}

		$defs     = Fields::for_list( $list_id );
		$required = array( FieldData::EMAIL_FIELD_ID => true );
		// The email row is ours, not Laposta's, so it is never in the response
		// the allowlist is built from — without this its label, class, and
		// position would be dropped on every save.
		$known = array( FieldData::EMAIL_FIELD_ID => true );
		// What Laposta already fills the field in with. The editor shows it in
		// the box, so a save that changed nothing posts it straight back; it is
		// dropped rather than stored, or the field would stop following Laposta
		// the moment anyone pressed Save.
		$laposta_defaults = array();
		foreach ( $defs['fields'] as $def ) {
			$known[ $def['field_id'] ]            = true;
			$laposta_defaults[ $def['field_id'] ] = (string) ( $def['default'] ?? '' );
			if ( ! empty( $def['required'] ) ) {
				$required[ $def['field_id'] ] = true;
			}
		}

		// An unreachable list would drop every override, silently wiping the
		// admin's layout. Keeping what was submitted is the lesser harm; the
		// merge at render time still enforces the required set.
		if ( $defs['error'] ) {
			$known = array();
		}

		$clean = array();
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) || empty( $row['field_id'] ) ) {
				continue;
			}
			$field_id = sanitize_text_field( (string) $row['field_id'] );
			if ( array() !== $known && ! isset( $known[ $field_id ] ) ) {
				continue;
			}

			$clean[] = array(
				'order'       => isset( $row['order'] ) ? absint( $row['order'] ) : (int) $index,
				'field_id'    => $field_id,
				'visible'     => isset( $required[ $field_id ] ) ? true : ! empty( $row['visible'] ),
				'label'       => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
				'css_class'   => self::clean_css_classes( isset( $row['css_class'] ) ? (string) $row['css_class'] : '' ),
				// The content keys are handed on as submitted;
				// Support\Fields::normalize_override() is the one place that
				// decides which of them are real, and FormData calls it on the
				// way in and on the way out.
				'placeholder' => isset( $row['placeholder'] ) ? sanitize_text_field( (string) $row['placeholder'] ) : '',
				'help'        => isset( $row['help'] ) ? sanitize_text_field( (string) $row['help'] ) : '',
				'value'       => self::clean_default_value( $row['value'] ?? '', $laposta_defaults[ $field_id ] ?? '' ),
				'attrs'       => self::clean_attrs( $row['attrs'] ?? array() ),
			);
		}

		usort(
			$clean,
			static function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		return array_map(
			static function ( $row ) {
				unset( $row['order'] );
				return $row;
			},
			$clean
		);
	}

	/**
	 * Sanitizes the submitted per-field attributes to plain text. Which keys
	 * are real, and which belong to which type, is decided once in
	 * Support\Fields — this only makes the values safe to store.
	 *
	 * @param mixed $attrs Submitted attrs map.
	 * @return array<string,string>
	 */
	private static function clean_attrs( $attrs ): array {
		if ( ! is_array( $attrs ) ) {
			return array();
		}

		$clean = array();
		foreach ( $attrs as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
		}
		return $clean;
	}

	/**
	 * Sanitizes the submitted signup button. Which keys are stored is decided
	 * by FormData::save_button(); this only makes the values safe.
	 *
	 * @param mixed $button Submitted wynko_button.
	 * @return array<string,string>
	 */
	private static function clean_button( $button ): array {
		if ( ! is_array( $button ) ) {
			return array();
		}

		return array(
			'label'     => sanitize_text_field( isset( $button['label'] ) && is_scalar( $button['label'] ) ? (string) $button['label'] : '' ),
			'css_class' => self::clean_css_classes( isset( $button['css_class'] ) && is_scalar( $button['css_class'] ) ? (string) $button['css_class'] : '' ),
		);
	}

	/**
	 * Reduces a space-separated class list to what sanitize_html_class() allows.
	 *
	 * @param string $value Submitted classes.
	 * @return string
	 */
	private static function clean_css_classes( string $value ): string {
		$classes = array();
		$parts   = preg_split( '/\s+/', trim( $value ) );
		foreach ( false === $parts ? array() : $parts as $class ) {
			$class = sanitize_html_class( $class );
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}
		return implode( ' ', $classes );
	}

	/**
	 * Sanitizes the submitted messages, keyed to the known slugs. A message
	 * that renders above the form keeps the markup Messages allows; one that
	 * renders beside a field is reduced to text, which is what it may be.
	 *
	 * @param mixed $map Submitted wynko_messages.
	 * @return array<string,string>
	 */
	private static function clean_messages( $map ): array {
		if ( ! is_array( $map ) ) {
			return array();
		}

		$clean = array();
		foreach ( LapostaErrors::slugs() as $slug ) {
			$value          = isset( $map[ $slug ] ) ? (string) $map[ $slug ] : '';
			$clean[ $slug ] = Messages::allows_html( $slug )
				? trim( wp_kses( $value, Messages::allowed_html() ) )
				: sanitize_text_field( $value );
		}
		return $clean;
	}

	/**
	 * Sanitizes the submitted settings by the type of each configured default:
	 * a checkbox to a real boolean, a URL through esc_url_raw, a destination
	 * mode against its three known values, a page id to an integer, and the
	 * rest as plain text.
	 *
	 * @param mixed $settings Submitted wynko_settings.
	 * @return array<string,mixed>
	 */
	private static function clean_settings( $settings ): array {
		$settings = is_array( $settings ) ? $settings : array();
		$urls     = array( 'redirect_url', 'terms_url' );
		$types    = array( 'redirect_type', 'terms_link_type' );
		$pages    = array( 'redirect_page_id', 'terms_page_id' );
		$modes    = array( '', 'page', 'url' );

		$clean = array();
		foreach ( Config::form_settings_defaults() as $key => $default ) {
			if ( is_bool( $default ) ) {
				$clean[ $key ] = ! empty( $settings[ $key ] );
				continue;
			}

			if ( in_array( $key, $types, true ) ) {
				$mode          = isset( $settings[ $key ] ) ? sanitize_key( (string) $settings[ $key ] ) : '';
				$clean[ $key ] = in_array( $mode, $modes, true ) ? $mode : '';
				continue;
			}

			if ( in_array( $key, $pages, true ) ) {
				$clean[ $key ] = (string) absint( $settings[ $key ] ?? 0 );
				continue;
			}

			// The label mode belongs to the Editor tab, which is where a form's
			// appearance is arranged. Cleaning it here as well would blank it on
			// every Settings save, since Settings does not submit it.
			if ( 'label_mode' === $key ) {
				continue;
			}

			$value         = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
			$clean[ $key ] = in_array( $key, $urls, true ) ? esc_url_raw( $value ) : sanitize_text_field( $value );
		}
		return $clean;
	}

	/**
	 * One of the known label modes, falling back to a plain label.
	 *
	 * @param mixed $mode Submitted mode.
	 * @return string
	 */
	private static function clean_label_mode( $mode ): string {
		$mode = is_scalar( $mode ) ? sanitize_key( (string) $mode ) : '';

		return in_array( $mode, FieldData::label_modes(), true ) ? $mode : FieldData::LABEL_MODE_LABEL;
	}

	/**
	 * Fails the request when the current user may not manage forms.
	 *
	 * @return void
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * The form's title and its shortcode, above the tabs the way a post's title
	 * sits above its editor.
	 *
	 * The name lives here once, and is the one field every tab posts.
	 *
	 * @param FormData $form The form.
	 * @return void
	 */
	private static function render_header( FormData $form ): void {
		$shortcode = FormsListPage::shortcode_for( $form->id() );

		echo '<div class="wynko-form-header">';

		printf(
			'<label for="wynko-form-name" class="screen-reader-text">%s</label>'
			. '<input type="text" id="wynko-form-name" class="wynko-form-title" name="wynko_form_name" value="%s" placeholder="%s" />',
			esc_html__( 'Form name', 'wynko-for-laposta' ),
			esc_attr( $form->name() ),
			esc_attr( FormData::default_name() )
		);

		echo '<div class="wynko-shortcode-box">';
		printf( '<span class="wynko-shortcode-box__label">%s</span>', esc_html__( 'Shortcode', 'wynko-for-laposta' ) );
		printf(
			'<input type="text" class="code" readonly onfocus="this.select()" value="%s" />',
			esc_attr( $shortcode )
		);
		printf(
			'<button type="button" class="button wynko-copy" data-copy="%s">%s</button>',
			esc_attr( $shortcode ),
			esc_html__( 'Copy', 'wynko-for-laposta' )
		);
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Which list the editor opens on: the bound one, or the account's only
	 * list when the form has none yet.
	 *
	 * An account with one list has no choice to offer, and the field table stays
	 * empty until one is picked. Nothing is stored until the form is saved, so
	 * this preselects rather than decides.
	 *
	 * @param string                                      $bound   The form's stored list id.
	 * @param array<int,array{value:string,label:string}> $options Lists the account holds.
	 * @return string
	 */
	public static function preselected_list( string $bound, array $options ): string {
		if ( '' !== $bound || 1 !== count( $options ) ) {
			return $bound;
		}

		return (string) $options[0]['value'];
	}

	/**
	 * The Editor tab: the list picker, then the list's fields.
	 *
	 * @param FormData $form The form.
	 * @return void
	 */
	private static function render_editor_tab( FormData $form ): void {
		$lists   = Lists::for_editor();
		$list_id = self::preselected_list( $form->list_id(), $lists['options'] );

		echo '<table class="form-table" role="presentation"><tr>';
		printf( '<th scope="row"><label for="wynko-list">%s</label></th><td>', esc_html__( 'Laposta list', 'wynko-for-laposta' ) );
		printf( '<select id="wynko-list" class="wynko-list-select" data-form="%d" name="wynko_list_id">', (int) $form->id() );
		printf( '<option value="">%s</option>', esc_html__( '— Choose a list —', 'wynko-for-laposta' ) );
		foreach ( $lists['options'] as $option ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $option['value'] ),
				selected( $option['value'], $list_id, false ),
				esc_html( $option['label'] )
			);
		}
		echo '</select>';
		// Fields are cached, so a field added in Laposta is invisible here until
		// the cache expires. This asks for this one list again without
		// discarding what is held for the others.
		printf(
			' <button type="button" class="button wynko-refresh-fields"%s>%s</button>',
			'' === $list_id ? ' disabled="disabled"' : '',
			esc_html__( 'Refresh fields', 'wynko-for-laposta' )
		);
		if ( '' !== $list_id ) {
			printf(
				'<p class="description"><a href="%s" target="%s" rel="%s">%s</a></p>',
				esc_url( Urls::laposta_list_url( $list_id ) ),
				esc_attr( Urls::target( Urls::LAPOSTA_LIST ) ),
				esc_attr( Urls::rel( Urls::LAPOSTA_LIST ) ),
				esc_html__( 'Open this list in Laposta', 'wynko-for-laposta' )
			);
		}
		if ( $lists['error'] ) {
			printf( '<p class="description">%s</p>', esc_html__( 'Could not load your lists. Check the API key on the Settings screen.', 'wynko-for-laposta' ) );
		}
		echo '</td></tr>';

		// Above the early return, so the mode is offered — and posted back —
		// even on a form whose list is not bound or cannot be read.
		self::render_label_mode_row( (string) $form->settings()['label_mode'] );
		echo '</table>';

		// The error path renders no table on purpose: the rows it could draw
		// would be the email row alone, and saving that over a list whose
		// fields cannot be read would store it as the form's whole layout.
		$result = Fields::for_list( $list_id );
		if ( '' !== $list_id && $result['error'] ) {
			printf( '<p>%s</p>', esc_html__( 'Could not load the fields for this list. Check the API key on the Settings screen.', 'wynko-for-laposta' ) );
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Fields', 'wynko-for-laposta' ) );

		if ( '' === $list_id ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Choose a list above and its fields appear here. Nothing is stored until you save the form.', 'wynko-for-laposta' )
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Field names, types, and whether a field is required all come from Laposta — change them there. Drag a row by its handle to reorder, or use the arrow buttons.', 'wynko-for-laposta' )
		);

		// The table lives in a container that is always present and may be empty:
		// choosing a list fills it over the REST route, choosing none empties it.
		if ( '' !== $list_id ) {
			// Outside the container on purpose: it is computed from what is
			// stored, so it must not look like it applies to rows the picker
			// has just swapped in but nobody has saved.
			self::render_placeholder_warning( $form, $form->fields( $result['fields'] ) );
		}

		echo '<div class="wynko-fields__slot">';
		if ( '' !== $list_id ) {
			// FieldRows escapes every value at the point it writes it; echoing
			// the assembled markup whole keeps the field table in one place.
			echo FieldRows::table( $form->fields( $result['fields'] ), $form->button(), (string) $form->settings()['label_mode'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled by FieldRows, which escapes each value it writes.
		}
		echo '</div>';
	}

	/**
	 * Says when a form set to show placeholders has none typed.
	 *
	 * Nothing is broken in that state, since an empty placeholder falls back to
	 * the label, but the admin who chose "A placeholder only" is getting their
	 * labels repurposed silently. "Typed" is read from the stored overrides,
	 * because the merged fields have already taken that fallback.
	 *
	 * @param FormData                       $form   The form.
	 * @param array<int,array<string,mixed>> $fields Merged field definitions.
	 * @return void
	 */
	private static function render_placeholder_warning( FormData $form, array $fields ): void {
		if ( FieldData::LABEL_MODE_PLACEHOLDER !== (string) $form->settings()['label_mode'] ) {
			return;
		}

		$takes_one = false;
		foreach ( $fields as $field ) {
			$attrs = ( isset( $field['attrs'] ) && is_array( $field['attrs'] ) ) ? array_map( 'strval', $field['attrs'] ) : array();
			if ( ! empty( $field['visible'] ) && FieldData::accepts_placeholder( (string) $field['type'], $attrs ) ) {
				$takes_one = true;
				break;
			}
		}

		if ( ! $takes_one ) {
			return;
		}

		foreach ( $form->field_overrides() as $row ) {
			if ( '' !== (string) ( $row['placeholder'] ?? '' ) ) {
				return;
			}
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'This form shows placeholders instead of labels, but no placeholder has been filled in — so each field shows its label as the grey text inside it. Fill in the Placeholder column to say something else. Dates, choice fields, and sliders keep a visible label whatever this is set to.', 'wynko-for-laposta' )
		);
	}

	/**
	 * The Messages tab: one field per slug, the built-in wording as the
	 * placeholder so an empty box reads as "using the default".
	 *
	 * The slugs are split by where they end up rather than listed in one run:
	 * the two groups take different input, and an admin who cannot tell which
	 * message lands beside a field cannot tell which one may carry a link.
	 *
	 * @param FormData $form The form.
	 * @return void
	 */
	private static function render_messages_tab( FormData $form ): void {
		$notice = Messages::notice_slugs();
		$field  = array_values( array_diff( LapostaErrors::slugs(), $notice ) );

		self::render_message_group(
			$form,
			__( 'Shown above the form', 'wynko-for-laposta' ),
			__( 'The outcome of a submission, in the form\'s place or above it. These may carry simple HTML: a link, <strong>, <em>, and <br>.', 'wynko-for-laposta' ),
			$notice
		);

		self::render_message_group(
			$form,
			__( 'Shown beside a field', 'wynko-for-laposta' ),
			__( 'Attached to the one field they are about, in the space next to it. Plain text only — markup there breaks the layout of the row.', 'wynko-for-laposta' ),
			$field
		);

		printf( '<p class="description">%s</p>', esc_html__( 'Leave a message empty to use the wording shown in grey.', 'wynko-for-laposta' ) );
	}

	/**
	 * One group of the Messages tab. A message that may carry markup gets a
	 * textarea: a single-line box invites a sentence, and these are the ones
	 * that may hold more than one.
	 *
	 * @param FormData          $form        The form.
	 * @param string            $title       Group heading.
	 * @param string            $description What the group is, and what it takes.
	 * @param array<int,string> $slugs       Slugs in the group.
	 * @return void
	 */
	private static function render_message_group( FormData $form, string $title, string $description, array $slugs ): void {
		$defaults = Messages::defaults();

		printf( '<h2>%s</h2>', esc_html( $title ) );
		printf( '<p class="description">%s</p>', esc_html( $description ) );

		echo '<table class="form-table" role="presentation">';
		foreach ( $slugs as $slug ) {
			$id   = 'wynko-message-' . $slug;
			$idle = self::message_is_idle( $form, $slug );
			echo '<tr>';
			printf( '<th scope="row"><label for="%s">%s</label></th><td>', esc_attr( $id ), esc_html( Messages::label( $slug ) ) );

			if ( Messages::allows_html( $slug ) ) {
				printf(
					'<textarea id="%s" name="wynko_messages[%s]" class="large-text" rows="2" placeholder="%s"%s>%s</textarea>',
					esc_attr( $id ),
					esc_attr( $slug ),
					esc_attr( $defaults[ $slug ] ),
					$idle ? ' readonly="readonly"' : '',
					esc_textarea( $form->message( $slug ) )
				);
			} else {
				printf(
					'<input type="text" id="%s" name="wynko_messages[%s]" class="large-text" value="%s" placeholder="%s"%s />',
					esc_attr( $id ),
					esc_attr( $slug ),
					esc_attr( $form->message( $slug ) ),
					esc_attr( $defaults[ $slug ] ),
					$idle ? ' readonly="readonly"' : ''
				);
			}

			self::render_message_note( $form, $slug );

			echo '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * Whether a message is configured but cannot currently render.
	 *
	 * Only the success message can be, since it is shown in the form's place and
	 * a form that redirects never reaches it. The test is the resolved
	 * destination rather than the stored setting, because a form pointed at a
	 * deleted page falls back to showing the message.
	 *
	 * @param FormData $form The form.
	 * @param string   $slug One of LapostaErrors::SLUG_* .
	 * @return bool
	 */
	private static function message_is_idle( FormData $form, string $slug ): bool {
		return LapostaErrors::SLUG_SUCCESS === $slug && '' !== $form->redirect_url();
	}

	/**
	 * Says why a message cannot render, and where to change that.
	 *
	 * @param FormData $form The form.
	 * @param string   $slug One of LapostaErrors::SLUG_* .
	 * @return void
	 */
	private static function render_message_note( FormData $form, string $slug ): void {
		if ( ! self::message_is_idle( $form, $slug ) ) {
			return;
		}

		printf(
			'<p class="description">%s <a href="%s">%s</a></p>',
			esc_html__( 'This form sends the visitor to another page after signing up, so this message is never shown.', 'wynko-for-laposta' ),
			esc_url( Screen::edit_url( $form->id(), Screen::TAB_SETTINGS ) ),
			esc_html__( 'Change what happens after a successful signup', 'wynko-for-laposta' )
		);
	}

	/**
	 * Confirms a save, the way every other WordPress screen does.
	 *
	 * @return void
	 */
	private static function render_saved_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect; it selects wording and changes nothing.
		$saved = isset( $_GET[ self::SAVED_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::SAVED_ARG ] ) ) : '';

		if ( '1' !== $saved ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Form saved.', 'wynko-for-laposta' )
		);
	}

	/**
	 * Says why a save was refused, when the redirect carried a reason.
	 *
	 * @return void
	 */
	private static function render_save_error(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect; it selects wording and changes nothing.
		$error = isset( $_GET[ self::ERROR_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::ERROR_ARG ] ) ) : '';

		$messages = array(
			self::SAVE_BAD_PATTERN => __( 'One of the fields has a pattern that is not a valid expression, so nothing was saved.', 'wynko-for-laposta' ),
			self::SAVE_BAD_DEFAULT => __( 'One of the number fields has a default value outside its own minimum and maximum, so nothing was saved.', 'wynko-for-laposta' ),
		);

		if ( ! isset( $messages[ $error ] ) ) {
			return;
		}

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $messages[ $error ] ) );
	}

	/**
	 * How every field in the form is named.
	 *
	 * One setting rather than one per field, which made both the editor and the
	 * rendered form inconsistent. It sits on the Editor tab beside the rows it
	 * governs and the labels it switches between.
	 *
	 * @param string $current The form's stored mode.
	 * @return void
	 */
	private static function render_label_mode_row( string $current ): void {
		$modes = array(
			FieldData::LABEL_MODE_LABEL       => __( 'A label above each field', 'wynko-for-laposta' ),
			FieldData::LABEL_MODE_BOTH        => __( 'A label and a placeholder', 'wynko-for-laposta' ),
			FieldData::LABEL_MODE_PLACEHOLDER => __( 'A placeholder only', 'wynko-for-laposta' ),
		);

		echo '<tr>';
		printf( '<th scope="row">%s</th><td>', esc_html__( 'Field labels', 'wynko-for-laposta' ) );
		// The class is the editor script's hook: changing the mode enables or
		// disables the placeholder column without a save in between.
		echo '<select class="wynko-label-mode" name="wynko_settings[label_mode]">';
		foreach ( $modes as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $value, $current, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Dates and choice fields cannot carry a placeholder, so they keep a visible label whatever this is set to.', 'wynko-for-laposta' )
		);
		echo '</td></tr>';
	}

	/**
	 * The Settings tab.
	 *
	 * @param FormData $form The form.
	 * @return void
	 */
	private static function render_settings_tab( FormData $form ): void {
		$settings = $form->settings();

		echo '<table class="form-table" role="presentation">';

		$mode = $settings['redirect_type'];

		echo '<tr>';
		printf( '<th scope="row">%s</th><td>', esc_html__( 'After a successful signup', 'wynko-for-laposta' ) );

		printf(
			'<p><label><input type="radio" name="wynko_settings[redirect_type]" value=""%s /> %s</label>',
			checked( '', $mode, false ),
			esc_html__( 'Stay on the page and show the success message', 'wynko-for-laposta' )
		);
		printf(
			'<br /><span class="description">%s <a href="%s">%s</a></span></p>',
			esc_html__( 'The wording of that message is on the Messages tab.', 'wynko-for-laposta' ),
			esc_url( Screen::edit_url( $form->id(), Screen::TAB_MESSAGES ) ),
			esc_html__( 'Edit the success message', 'wynko-for-laposta' )
		);

		printf(
			'<p><label><input type="radio" name="wynko_settings[redirect_type]" value="page"%s /> %s</label> ',
			checked( 'page', $mode, false ),
			esc_html__( 'Send the visitor to a page', 'wynko-for-laposta' )
		);
		wp_dropdown_pages(
			array(
				'name'              => 'wynko_settings[redirect_page_id]',
				'selected'          => absint( $settings['redirect_page_id'] ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() prints its own markup and runs this through esc_html() itself; pre-escaping here would double-encode it.
				'show_option_none'  => __( '— Choose a page —', 'wynko-for-laposta' ),
				'option_none_value' => '0',
			)
		);
		echo '</p>';

		printf(
			'<p><label><input type="radio" name="wynko_settings[redirect_type]" value="url"%s /> %s</label> ',
			checked( 'url', $mode, false ),
			esc_html__( 'Send the visitor to a URL', 'wynko-for-laposta' )
		);
		printf(
			'<input type="url" name="wynko_settings[redirect_url]" class="regular-text" value="%s" placeholder="%s" /></p>',
			esc_attr( $settings['redirect_url'] ),
			esc_attr__( 'example.com/thanks', 'wynko-for-laposta' )
		);

		echo '</td></tr>';

		echo '<tr>';
		printf( '<th scope="row">%s</th><td><label>', esc_html__( 'After signup', 'wynko-for-laposta' ) );
		printf(
			'<input type="checkbox" name="wynko_settings[hide_after_submit]" value="1"%s /> %s',
			checked( $settings['hide_after_submit'], true, false ),
			esc_html__( 'Hide the form and show only the success message', 'wynko-for-laposta' )
		);
		echo '</label></td></tr>';

		echo '<tr>';
		printf( '<th scope="row">%s</th><td><label>', esc_html__( 'Double opt-in', 'wynko-for-laposta' ) );
		printf(
			'<input type="checkbox" disabled="disabled"%s /> %s',
			checked( $settings['skip_doi'], true, false ),
			esc_html__( 'Skip double opt-in for this form', 'wynko-for-laposta' )
		);
		// The control is disabled, and a disabled checkbox submits nothing, so
		// what is stored travels in a hidden input instead. Without it a save of
		// this tab would silently clear a setting nobody could see to re-tick.
		if ( $settings['skip_doi'] ) {
			echo '<input type="hidden" name="wynko_settings[skip_doi]" value="1" />';
		}
		printf(
			'</label><p class="description">%s</p>',
			esc_html__( 'Double opt-in is a setting on the Laposta list itself, and this plugin leaves it alone. Skipping it needs a paid Laposta plan, so the option is shown but cannot be used — on a free plan there is no confirmation step to skip.', 'wynko-for-laposta' )
		);
		echo '</td></tr>';

		self::render_duplicate_row( $settings );
		self::render_terms_rows( $settings );

		echo '</table>';
	}

	/**
	 * Whether a signup from an address already on the list says so.
	 *
	 * Off by default, because answering "already subscribed" to an anonymous
	 * caller turns the form into a membership test for any address somebody cares
	 * to type.
	 *
	 * @param array<string,mixed> $settings The form's settings.
	 * @return void
	 */
	private static function render_duplicate_row( array $settings ): void {
		echo '<tr>';
		printf( '<th scope="row">%s</th><td><label>', esc_html__( 'Already subscribed', 'wynko-for-laposta' ) );
		printf(
			'<input type="checkbox" name="wynko_settings[reveal_duplicate]" value="1"%s /> %s',
			checked( ! empty( $settings['reveal_duplicate'] ), true, false ),
			esc_html__( 'Tell the visitor when their address is already on the list', 'wynko-for-laposta' )
		);
		printf(
			'</label><p class="description">%s</p>',
			esc_html__( 'Left off, an address that is already subscribed gets the same thank-you as a new one. Turning this on lets anyone with a link to this form check whether a particular address is on your list, one address at a time.', 'wynko-for-laposta' )
		);
		echo '</td></tr>';
	}

	/**
	 * The terms checkbox and the two settings that only apply once it is ticked.
	 *
	 * Those two sit inside the checkbox's own cell rather than in sibling rows,
	 * where they would read as three unrelated settings. They are hidden in the
	 * markup rather than by JavaScript on load, which flashes.
	 *
	 * @param array{terms_required:bool,terms_text:string,terms_link_type:string,terms_page_id:string,terms_url:string} $settings The form's settings.
	 * @return void
	 */
	private static function render_terms_rows( array $settings ): void {
		$on = (bool) $settings['terms_required'];

		echo '<tr>';
		printf( '<th scope="row">%s</th><td><label>', esc_html__( 'Terms checkbox', 'wynko-for-laposta' ) );
		printf(
			'<input type="checkbox" id="wynko-terms-required" name="wynko_settings[terms_required]" value="1" aria-controls="wynko-terms-detail"%s /> %s',
			checked( $on, true, false ),
			esc_html__( 'Require visitors to agree before subscribing', 'wynko-for-laposta' )
		);
		echo '</label>';

		printf( '<div class="wynko-subfields" id="wynko-terms-detail"%s>', $on ? '' : ' hidden' );

		echo '<div class="wynko-subfield">';
		printf( '<label class="wynko-subfield__label" for="wynko-terms-text">%s</label>', esc_html__( 'Checkbox text', 'wynko-for-laposta' ) );
		printf(
			'<input type="text" id="wynko-terms-text" name="wynko_settings[terms_text]" class="regular-text" value="%s" placeholder="%s" />',
			esc_attr( $settings['terms_text'] ),
			esc_attr__( 'I agree to the terms and conditions', 'wynko-for-laposta' )
		);
		echo '</div>';

		echo '<div class="wynko-subfield">';
		printf( '<span class="wynko-subfield__label">%s</span>', esc_html__( 'Link the text to', 'wynko-for-laposta' ) );
		self::render_terms_link_choice( $settings );
		printf( '<p class="description">%s</p>', esc_html__( 'The checkbox text becomes a link to whichever you choose.', 'wynko-for-laposta' ) );
		echo '</div>';

		echo '</div></td></tr>';
	}

	/**
	 * The terms link's destination: nothing, a page on this site, or a typed
	 * address. Mirrors the redirect choice above it — same three modes, same
	 * two destinations kept side by side so switching does not discard the
	 * other.
	 *
	 * @param array{terms_link_type:string,terms_page_id:string,terms_url:string} $settings The form's settings.
	 * @return void
	 */
	private static function render_terms_link_choice( array $settings ): void {
		$mode = (string) $settings['terms_link_type'];

		printf(
			'<p><label><input type="radio" name="wynko_settings[terms_link_type]" value=""%s /> %s</label></p>',
			checked( '', $mode, false ),
			esc_html__( 'Nothing — leave the text unlinked', 'wynko-for-laposta' )
		);

		printf(
			'<p><label><input type="radio" name="wynko_settings[terms_link_type]" value="page"%s /> %s</label> ',
			checked( 'page', $mode, false ),
			esc_html__( 'A page on this site', 'wynko-for-laposta' )
		);
		wp_dropdown_pages(
			array(
				'name'              => 'wynko_settings[terms_page_id]',
				'selected'          => absint( $settings['terms_page_id'] ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() prints its own markup and runs this through esc_html() itself; pre-escaping here would double-encode it.
				'show_option_none'  => __( '— Choose a page —', 'wynko-for-laposta' ),
				'option_none_value' => '0',
			)
		);
		echo '</p>';

		printf(
			'<p><label><input type="radio" name="wynko_settings[terms_link_type]" value="url"%s /> %s</label> ',
			checked( 'url', $mode, false ),
			esc_html__( 'A URL', 'wynko-for-laposta' )
		);
		printf(
			'<input type="url" name="wynko_settings[terms_url]" class="regular-text" value="%s" placeholder="%s" /></p>',
			esc_attr( $settings['terms_url'] ),
			esc_attr__( 'example.com/terms', 'wynko-for-laposta' )
		);
	}

	/**
	 * The delete control, in its own form with its own action and nonce, so it
	 * cannot be triggered by the save button.
	 *
	 * @param FormData $form The form.
	 * @return void
	 */
	private static function render_delete_form( FormData $form ): void {
		printf( '<form class="wynko-form-actions__delete" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_DELETE ) );
		printf( '<input type="hidden" name="form_id" value="%d" />', (int) $form->id() );
		wp_nonce_field( self::ACTION_DELETE );
		printf(
			'<button type="submit" class="button button-link-delete" onclick="return confirm(%s);">%s</button>',
			esc_attr( wp_json_encode( __( 'Delete this form? Places that use its shortcode will show nothing.', 'wynko-for-laposta' ) ) ),
			esc_html__( 'Delete form', 'wynko-for-laposta' )
		);
		echo '</form>';
	}
}
