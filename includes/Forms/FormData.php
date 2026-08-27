<?php
/**
 * One signup form's stored data.
 *
 * @package Wynko
 */

namespace Wynko\Forms;

use Wynko\Config;
use Wynko\Support\Fields as FieldData;
use Wynko\Support\LapostaErrors;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A value object over one wynko_form post and its meta, so the admin screens and
 * the front end never repeat a meta key or a default.
 *
 * Field definitions are deliberately absent: they are fetched live from
 * Api\Fields and merged in at call time.
 */
final class FormData {

	/**
	 * The underlying post.
	 *
	 * @var WP_Post|object
	 */
	private $post;

	/**
	 * Wraps a post.
	 *
	 * @param WP_Post|object $post The wynko_form post.
	 */
	private function __construct( $post ) {
		$this->post = $post;
	}

	/**
	 * Loads a form, null when the id is not one.
	 *
	 * @param int $form_id Post id.
	 * @return self|null
	 */
	public static function load( int $form_id ): ?self {
		if ( $form_id <= 0 ) {
			return null;
		}
		$post = get_post( $form_id );
		if ( null === $post || Config::form_post_type() !== $post->post_type ) {
			return null;
		}
		return new self( $post );
	}

	/**
	 * The form's post id.
	 *
	 * @return int
	 */
	public function id(): int {
		return (int) $this->post->ID;
	}

	/**
	 * The admin-facing form name.
	 *
	 * @return string
	 */
	public function name(): string {
		return (string) $this->post->post_title;
	}

	/**
	 * The name to show for a form nobody has named yet. A new form is created
	 * untitled so the editor can ask for a name with a placeholder rather than
	 * with a value to clear; this is what stands in until one is typed, and
	 * what a save without one stores.
	 *
	 * @return string
	 */
	public static function default_name(): string {
		return __( 'New signup form', 'wynko-for-laposta' );
	}

	/**
	 * The form's name, or the stand-in when it has none.
	 *
	 * @return string
	 */
	public function display_name(): string {
		$name = $this->name();

		return '' === $name ? self::default_name() : $name;
	}

	/**
	 * Whether the form may render on the front end.
	 *
	 * @return bool
	 */
	public function is_published(): bool {
		return 'publish' === $this->post->post_status;
	}

	/**
	 * The bound Laposta list id, '' when none.
	 *
	 * @return string
	 */
	public function list_id(): string {
		return self::str( $this->meta( 'list_id', '' ) );
	}

	/**
	 * The stored per-field opinions, in display order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function field_overrides(): array {
		$rows = $this->meta( 'fields', array() );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$clean = FieldData::normalize_override( $row );
			if ( null !== $clean ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * The live definitions with this form's opinions layered on, the email
	 * address among them.
	 *
	 * Email is injected before the merge rather than rendered separately, so it
	 * takes its position and presentation from the same code as every other
	 * field. Injecting after the merge would discard its overrides.
	 *
	 * @param array<int,array<string,mixed>> $defs Api\Fields::for_list() fields.
	 * @return array<int,array<string,mixed>>
	 */
	public function fields( array $defs ): array {
		array_unshift( $defs, FieldData::email_definition( __( 'Email address', 'wynko-for-laposta' ) ) );

		$overrides = $this->field_overrides();
		$merged    = FieldData::merge_overrides( $defs, $overrides );

		// merge_overrides() appends whatever the stored order does not mention,
		// which would drop the address to the bottom of a form saved before email
		// had a row of its own.
		foreach ( $overrides as $override ) {
			if ( FieldData::EMAIL_FIELD_ID === $override['field_id'] ) {
				return $merged;
			}
		}

		usort(
			$merged,
			static function ( $a, $b ) {
				return (int) FieldData::is_email( $b ) <=> (int) FieldData::is_email( $a );
			}
		);

		return $merged;
	}

	/**
	 * The fields that render, minus the email address.
	 *
	 * Callers that build a Laposta payload or validate custom fields must not see
	 * it, because /member takes email as a top-level parameter and FormValidator
	 * checks it separately.
	 *
	 * @param array<int,array<string,mixed>> $defs Api\Fields::for_list() fields.
	 * @return array<int,array<string,mixed>>
	 */
	public function visible_custom_fields( array $defs ): array {
		return array_values(
			array_filter(
				$this->visible_fields( $defs ),
				static function ( $field ) {
					return ! FieldData::is_email( $field );
				}
			)
		);
	}

	/**
	 * The fields that render, required ones always among them.
	 *
	 * @param array<int,array<string,mixed>> $defs Api\Fields::for_list() fields.
	 * @return array<int,array<string,mixed>>
	 */
	public function visible_fields( array $defs ): array {
		return array_values(
			array_filter(
				$this->fields( $defs ),
				static function ( $field ) {
					return ! empty( $field['visible'] );
				}
			)
		);
	}

	/**
	 * The custom wording for one slug, '' when the built-in default applies.
	 *
	 * @param string $slug One of LapostaErrors::SLUG_* .
	 * @return string
	 */
	public function message( string $slug ): string {
		$messages = $this->messages();
		return $messages[ $slug ] ?? '';
	}

	/**
	 * Every custom message, keyed by slug. Unknown slugs are dropped on read as
	 * well as on write, so a vocabulary change cannot resurrect stale wording.
	 *
	 * @return array<string,string>
	 */
	public function messages(): array {
		$stored = $this->meta( 'messages', array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();
		foreach ( LapostaErrors::slugs() as $slug ) {
			if ( isset( $stored[ $slug ] ) && is_string( $stored[ $slug ] ) && '' !== $stored[ $slug ] ) {
				$out[ $slug ] = $stored[ $slug ];
			}
		}
		return $out;
	}

	/**
	 * The form's settings, every key present.
	 *
	 * @return array{redirect_type:string,redirect_page_id:string,redirect_url:string,label_mode:string,hide_after_submit:bool,skip_doi:bool,reveal_duplicate:bool,terms_required:bool,terms_text:string,terms_link_type:string,terms_page_id:string,terms_url:string}
	 */
	public function settings(): array {
		$defaults = Config::form_settings_defaults();
		$stored   = $this->meta( 'settings', array() );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$out = array();
		foreach ( $defaults as $key => $default ) {
			$value       = $stored[ $key ] ?? $default;
			$out[ $key ] = is_bool( $default ) ? (bool) $value : self::str( $value );
		}

		if ( ! isset( $stored['label_mode'] ) ) {
			$out['label_mode'] = $this->legacy_label_mode();
		}
		if ( ! isset( $stored['terms_link_type'] ) ) {
			$out['terms_link_type'] = '' === $out['terms_url'] ? '' : 'url';
		}
		/**
		 * Typed to match the method's return annotation.
		 *
		 * @var array{redirect_type:string,redirect_page_id:string,redirect_url:string,label_mode:string,hide_after_submit:bool,skip_doi:bool,reveal_duplicate:bool,terms_required:bool,terms_text:string,terms_link_type:string,terms_page_id:string,terms_url:string} $out
		 */
		return $out;
	}

	/**
	 * The signup button as stored: '' for anything the administrator left
	 * alone. Forms\Button turns that into what renders.
	 *
	 * @return array{label:string,css_class:string}
	 */
	public function button(): array {
		$defaults = Config::form_button_defaults();
		$stored   = $this->meta( 'button', array() );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$out = array();
		foreach ( $defaults as $key => $default ) {
			$out[ $key ] = self::str( $stored[ $key ] ?? $default );
		}
		/**
		 * Typed to match the method's return annotation.
		 *
		 * @var array{label:string,css_class:string} $out
		 */
		return $out;
	}

	/**
	 * The label mode a form had before the setting existed.
	 *
	 * It used to be stored once per field, so taking the mode most rows carried
	 * keeps an already-configured form looking the way it was configured. A form
	 * with no rows has never been configured and takes the current default.
	 *
	 * @return string
	 */
	private function legacy_label_mode(): string {
		$rows = $this->meta( 'fields', array() );
		if ( ! is_array( $rows ) || array() === $rows ) {
			return (string) Config::form_settings_defaults()['label_mode'];
		}

		$tally = array();
		foreach ( $rows as $row ) {
			$mode = ( is_array( $row ) && isset( $row['label_mode'] ) && is_scalar( $row['label_mode'] ) )
				? (string) $row['label_mode']
				: '';
			if ( in_array( $mode, FieldData::label_modes(), true ) ) {
				$tally[ $mode ] = ( $tally[ $mode ] ?? 0 ) + 1;
			}
		}

		if ( array() === $tally ) {
			return FieldData::LABEL_MODE_LABEL;
		}

		arsort( $tally );
		return (string) array_key_first( $tally );
	}

	/**
	 * Where a successful signup sends the visitor, '' when it should stay put
	 * and show the success message in place.
	 *
	 * Resolving a page id to its permalink is a WordPress concern, so it lives
	 * here rather than in Support/. A deleted page resolves to '', falling back
	 * to the in-place message rather than a 404.
	 *
	 * @return string
	 */
	public function redirect_url(): string {
		$settings = $this->settings();

		if ( 'page' === $settings['redirect_type'] ) {
			$page_id = absint( $settings['redirect_page_id'] );
			$url     = $page_id > 0 ? get_permalink( $page_id ) : false;
			return is_string( $url ) ? $url : '';
		}

		if ( 'url' === $settings['redirect_type'] ) {
			return $settings['redirect_url'];
		}

		return '';
	}

	/**
	 * How many published signup forms are bound to each list, keyed by list id.
	 *
	 * One query answers both questions the sync asks — which lists matter, and
	 * how many forms break if one disappears — so neither the sync nor the
	 * removed-list alarm walks the posts table on its own.
	 *
	 * @return array<string,int>
	 */
	public static function list_reference_counts(): array {
		$posts = get_posts(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		$counts = array();
		foreach ( $posts as $post ) {
			$list_id = (string) get_post_meta( (int) $post->ID, Config::form_meta_key( 'list_id' ), true );
			if ( '' !== $list_id ) {
				$counts[ $list_id ] = ( $counts[ $list_id ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * The distinct list ids that published signup forms are bound to.
	 *
	 * @return array<int,string>
	 */
	public static function referenced_list_ids(): array {
		return array_keys( self::list_reference_counts() );
	}

	/**
	 * Stores the bound list.
	 *
	 * @param string $list_id Laposta list id.
	 * @return void
	 */
	public function save_list_id( string $list_id ): void {
		update_post_meta( $this->id(), Config::form_meta_key( 'list_id' ), $list_id );
	}

	/**
	 * Stores the per-field opinions, normalized to the documented shape by the
	 * same function that reads them back, so the two cannot diverge.
	 *
	 * @param array<int,mixed> $rows Ordered rows, each expected to be an array.
	 * @return void
	 */
	public function save_field_overrides( array $rows ): void {
		$clean = array();
		foreach ( $rows as $row ) {
			$one = FieldData::normalize_override( $row );
			if ( null !== $one ) {
				$clean[] = $one;
			}
		}
		update_post_meta( $this->id(), Config::form_meta_key( 'fields' ), $clean );
	}

	/**
	 * Writes down a label mode that is still being inferred from the field
	 * rows, so it survives those rows being rewritten without it.
	 *
	 * A no-op once the setting exists, which makes it safe to call on every
	 * save: what an administrator chose always wins over what was inferred.
	 *
	 * @return void
	 */
	public function persist_derived_label_mode(): void {
		$stored = $this->meta( 'settings', array() );
		if ( is_array( $stored ) && isset( $stored['label_mode'] ) ) {
			return;
		}

		$this->save_settings( $this->settings() );
	}

	/**
	 * Stores custom message wording. An empty string is dropped rather than
	 * stored, so "no override" and "deliberately blank" cannot diverge.
	 *
	 * @param array<string,mixed> $map Slug => wording.
	 * @return void
	 */
	public function save_messages( array $map ): void {
		$clean = array();
		foreach ( LapostaErrors::slugs() as $slug ) {
			$value = self::str( $map[ $slug ] ?? '' );
			if ( '' !== $value ) {
				$clean[ $slug ] = $value;
			}
		}
		update_post_meta( $this->id(), Config::form_meta_key( 'messages' ), $clean );
	}

	/**
	 * Stores the form settings, keyed and typed by the configured defaults so a
	 * submitted key that is not one of ours never reaches the database.
	 *
	 * A key the caller leaves out keeps the value the form already has, because
	 * the Editor and Settings tabs each submit only their own. An unticked
	 * checkbox is not such a key: the tab that owns it always submits a boolean.
	 *
	 * @param array<string,mixed> $settings Submitted settings.
	 * @return void
	 */
	public function save_settings( array $settings ): void {
		$current = $this->settings();
		$clean   = array();
		foreach ( Config::form_settings_defaults() as $key => $default ) {
			$value         = $settings[ $key ] ?? $current[ $key ];
			$clean[ $key ] = is_bool( $default ) ? (bool) $value : self::str( $value );
		}
		update_post_meta( $this->id(), Config::form_meta_key( 'settings' ), $clean );
	}

	/**
	 * Stores the signup button, keyed by the configured defaults so a
	 * submitted key that is not one of ours never reaches the database.
	 *
	 * @param array<string,mixed> $button Submitted button.
	 * @return void
	 */
	public function save_button( array $button ): void {
		$clean = array();
		foreach ( Config::form_button_defaults() as $key => $default ) {
			$clean[ $key ] = self::str( $button[ $key ] ?? $default );
		}
		update_post_meta( $this->id(), Config::form_meta_key( 'button' ), $clean );
	}

	/**
	 * How many signups this form has placed in Laposta over its whole life.
	 *
	 * @return int
	 */
	public function signup_total(): int {
		return max( 0, (int) $this->meta( 'signups', 0 ) );
	}

	/**
	 * Counts one signup that Laposta accepted.
	 *
	 * Read-modify-write, so two submissions landing in the same instant can both
	 * read the same total and write the same successor.
	 *
	 * @return void
	 */
	public function record_signup(): void {
		update_post_meta( $this->id(), Config::form_meta_key( 'signups' ), $this->signup_total() + 1 );
	}

	/**
	 * Casts to a string without warning on a non-scalar, so a crafted array
	 * value degrades to '' instead of the literal string "Array".
	 *
	 * @param mixed $value Candidate value.
	 * @return string
	 */
	private static function str( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Reads one meta value by its configured name.
	 *
	 * @param string $name     One of the forms.meta names.
	 * @param mixed  $fallback Returned when the value was never written.
	 * @return mixed
	 */
	private function meta( string $name, $fallback ) {
		$value = get_post_meta( $this->id(), Config::form_meta_key( $name ), true );
		return ( '' === $value || null === $value ) ? $fallback : $value;
	}
}
