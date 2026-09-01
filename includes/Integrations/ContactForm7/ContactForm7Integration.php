<?php
/**
 * Bundled Contact Form 7 bridge.
 *
 * @package Wynko
 */

namespace Wynko\Integrations\ContactForm7;

use Wynko\Admin\Menu;
use Wynko\Admin\SettingsPage;
use Wynko\Api\Fields;
use Wynko\Api\Lists;
use Wynko\Api\Subscribers;
use Wynko\Cache;
use Wynko\Integrations\Integration;
use Wynko\Log;
use Wynko\Support\Fields as FieldData;
use Wynko\Support\LapostaErrors;
use Wynko\Throttle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a Contact Form 7 submission for an opt-in checkbox and subscribes it
 * to a Laposta list. Nothing about the form is modified or rendered by this
 * bridge — the settings screen is a builder: pick a known Laposta list from a
 * dropdown and it shows the exact native CF7 `[checkbox wynko-optin-{list_id}
 * ...]` tag, plus one per custom field, to copy into a form's own template.
 * The list a submission joins travels in the checkbox's own field name, not a
 * site-wide setting, so different CF7 forms can subscribe to different lists
 * — a form is opted in by containing the tag for whichever list it should
 * feed, nothing more.
 *
 * Field mapping follows the convention MC4WP's own Contact Form 7
 * integration uses: a list's required custom field `first_name` is supplied
 * by a CF7 field literally named `wynko-first_name` — an exact match.
 */
final class ContactForm7Integration implements Integration {

	const ACTION_SYNC = 'wynko_cf7_sync';

	/** Prefix a CF7 field name carries to map to a Laposta custom field. */
	const FIELD_PREFIX = 'wynko-';

	/** Prefix the opt-in checkbox's field name carries; the list id follows it. */
	const OPTIN_PREFIX = 'wynko-optin-';

	/**
	 * This bridge's stable identifier and enabled-state key.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'contact-form-7';
	}

	/**
	 * Display name shown in the Integrations list.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'Contact Form 7', 'wynko-for-laposta' );
	}

	/**
	 * One-sentence description shown in the Integrations list.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Subscribes via Contact Form 7 forms.', 'wynko-for-laposta' );
	}

	/**
	 * Bundled with Wynko itself.
	 *
	 * @return string
	 */
	public function author(): string {
		return '';
	}

	/**
	 * Ignored: author() is '', so this is never shown.
	 *
	 * @return string
	 */
	public function author_uri(): string {
		return '';
	}

	/**
	 * No separate documentation page of its own — this settings screen's own
	 * step-by-step walkthrough is the documentation.
	 *
	 * @return string
	 */
	public function documentation_uri(): string {
		return '';
	}

	/**
	 * Bundled with Wynko itself, so it releases and versions with the plugin.
	 *
	 * @return string
	 */
	public function version(): string {
		return defined( 'WYNKO_VERSION' ) ? (string) WYNKO_VERSION : '';
	}

	/**
	 * Whether the Contact Form 7 plugin is active on this site.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( 'WPCF7_ContactForm' );
	}

	/**
	 * Names the concrete thing that stops working: the opt-in checkbox
	 * already pasted into a live Contact Form 7 form.
	 *
	 * @return string
	 */
	public function deactivation_warning(): string {
		return __( 'Deactivating this integration means the sign-up checkbox already pasted into any Contact Form 7 form will stop subscribing anyone, and those forms may not work as expected. Deactivate anyway?', 'wynko-for-laposta' );
	}

	/**
	 * Hooks Contact Form 7's own mail-send action. No form-tag registration:
	 * every tag this bridge reads is a native CF7 tag type, pasted by the
	 * admin from this integration's own settings screen.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'wpcf7_before_send_mail', array( $this, 'maybe_subscribe' ) );
	}

	/**
	 * Subscribes an accepted CF7 submission's email address, if one of its
	 * wynko-optin-{list_id} checkboxes was checked. Called from CF7's own
	 * wpcf7_before_send_mail action, after CF7 has already validated and
	 * accepted the submission.
	 *
	 * Fire-and-forget by design: the result is never allowed to change CF7's
	 * own success flow, which has no equivalent of reveal_duplicate to turn
	 * that behavior on safely, and is metered through Throttle's per-IP
	 * counter first — CF7 has no rate limiting of its own, and this is the
	 * write that makes Laposta send a confirmation email to whatever address
	 * the caller names.
	 *
	 * @param mixed $contact_form The submitting WPCF7_ContactForm instance.
	 * @return void
	 */
	public function maybe_subscribe( $contact_form ): void {
		if ( ! is_object( $contact_form ) ) {
			return;
		}

		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = \WPCF7_Submission::get_instance();
		if ( null === $submission ) {
			return;
		}

		$posted   = $submission->get_posted_data();
		$declared = self::declared_field_names( $contact_form );
		$list_id  = self::checked_list_id( $posted, $declared );
		if ( '' === $list_id ) {
			return;
		}

		$email_field = self::email_field_name( $contact_form );
		$email       = ( '' !== $email_field && isset( $posted[ $email_field ] ) )
			? sanitize_email( (string) $posted[ $email_field ] )
			: '';
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$mapped = self::mapped_custom_fields( $list_id, $posted, $declared );
		if ( null === $mapped ) {
			Log::warning( __( 'Contact Form 7 integration: a required Laposta field has no matching field on the form; check the field is named wynko-{field_name} exactly.', 'wynko-for-laposta' ) );
			return;
		}

		$ip = (string) $submission->get_meta( 'remote_ip' );
		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';

		if ( ! Throttle::allows_ip( $ip ) ) {
			return;
		}

		$result = Subscribers::create( $list_id, $email, $ip, '', $mapped, false );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$slug = LapostaErrors::slug_for_error( is_array( $data ) ? $data : array() );

			Log::warning(
				LapostaErrors::SLUG_DUPLICATE === $slug
					? __( 'Contact Form 7 integration: the address was already subscribed.', 'wynko-for-laposta' )
					: __( 'Contact Form 7 integration: the signup could not be completed.', 'wynko-for-laposta' )
			);
			return;
		}

		Log::info( __( 'New signup through the Contact Form 7 integration.', 'wynko-for-laposta' ) );
	}

	/**
	 * The Laposta list id carried by whichever wynko-optin-{list_id}
	 * checkbox was actually checked in this submission, '' if none was. The
	 * list travels in the field's own name — pasted verbatim from this
	 * integration's settings screen — rather than a site-wide setting, so
	 * different forms can feed different lists. Only a key present in
	 * $declared is trusted — a POST field appended by the submitter that has
	 * no matching tag on the submitting form's own template is ignored,
	 * closing the cross-form injection RISK-001 describes.
	 *
	 * @param array<string,mixed> $posted   CF7's posted field values.
	 * @param array<string,bool>  $declared Tag names this form actually declares, from declared_field_names().
	 * @return string
	 */
	private static function checked_list_id( array $posted, array $declared ): string {
		foreach ( $posted as $key => $value ) {
			if ( 0 !== strpos( $key, self::OPTIN_PREFIX ) || ! isset( $declared[ $key ] ) ) {
				continue;
			}
			$checked = is_array( $value ) ? array() !== $value : ! empty( $value );
			if ( $checked ) {
				return substr( $key, strlen( self::OPTIN_PREFIX ) );
			}
		}
		return '';
	}

	/**
	 * Every field name the submitting form's own template actually declares,
	 * as a lookup set — the allowlist checked_list_id() and
	 * mapped_custom_fields() cross-check posted keys against, rather than
	 * trusting any wynko-* key present in the POST body regardless of
	 * origin.
	 *
	 * @param mixed $contact_form The submitting WPCF7_ContactForm instance.
	 * @return array<string,bool> Tag name => true.
	 */
	private static function declared_field_names( $contact_form ): array {
		if ( ! method_exists( $contact_form, 'scan_form_tags' ) ) {
			return array();
		}

		$declared = array();
		foreach ( $contact_form->scan_form_tags() as $tag ) {
			if ( is_object( $tag ) && isset( $tag->name ) && '' !== $tag->name ) {
				$declared[ (string) $tag->name ] = true;
			}
		}
		return $declared;
	}

	/**
	 * The name of the submitting form's email-type field, found by asking CF7
	 * itself rather than assuming a fixed name like the default template's
	 * `your-email` — a form is free to name its fields however it likes, and
	 * CF7 already knows which one is typed as email.
	 *
	 * @param mixed $contact_form The submitting WPCF7_ContactForm instance.
	 * @return string
	 */
	private static function email_field_name( $contact_form ): string {
		if ( ! method_exists( $contact_form, 'scan_form_tags' ) ) {
			return '';
		}

		$tags = $contact_form->scan_form_tags( array( 'basetype' => 'email' ) );
		if ( empty( $tags ) ) {
			return '';
		}

		$first = $tags[0];
		return ( is_object( $first ) && isset( $first->name ) ) ? (string) $first->name : '';
	}

	/**
	 * Collects every wynko-{custom_name} value present in a submission as
	 * that field's custom value, for every custom field the list defines
	 * other than email. A plain text/number/date field posts a string; a
	 * choice field posts an array (CF7's native checkbox tag), reduced to a
	 * single string for a single-choice field or kept as an array for a
	 * multi-choice one, matching what Subscribers::create() expects either
	 * way. Returns null — abort the whole subscribe attempt, rather than
	 * send Laposta a write it will reject — when a *required* field's tag is
	 * missing from the submission.
	 *
	 * @param string              $list_id  Laposta list id.
	 * @param array<string,mixed> $posted   CF7's posted field values.
	 * @param array<string,bool>  $declared Tag names this form actually declares, from declared_field_names().
	 * @return array<string,mixed>|null
	 */
	private static function mapped_custom_fields( string $list_id, array $posted, array $declared ): ?array {
		$mapped = array();

		foreach ( self::mappable_fields( $list_id ) as $field ) {
			$tag   = self::FIELD_PREFIX . $field['custom_name'];
			$value = isset( $declared[ $tag ] ) ? ( $posted[ $tag ] ?? null ) : null;
			$empty = is_array( $value ) ? array() === $value : ( null === $value || '' === trim( (string) $value ) );

			if ( $empty ) {
				if ( $field['required'] ) {
					return null;
				}
				continue;
			}

			if ( is_array( $value ) ) {
				$clean                           = array_values( array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) );
				$mapped[ $field['custom_name'] ] = $field['multiple'] ? $clean : $clean[0];
			} else {
				$mapped[ $field['custom_name'] ] = sanitize_text_field( (string) $value );
			}
		}

		return $mapped;
	}

	/**
	 * A list's custom fields other than email — the ones a CF7 form maps by
	 * name, since email is read from CF7's own email-type field instead.
	 * Carries the full field shape (type, options, default), not just the
	 * name, so render_field_instructions() can suggest the actual CF7 tag
	 * rather than a bare field name.
	 *
	 * @param string $list_id Laposta list id.
	 * @return array<int,array{custom_name:string,required:bool,type:string,multiple:bool,options:array<int,string>,default:string}>
	 */
	private static function mappable_fields( string $list_id ): array {
		$definitions = Fields::for_list( $list_id );

		$fields = array();
		foreach ( $definitions['fields'] as $field ) {
			$name = (string) ( $field['custom_name'] ?? '' );
			if ( '' === $name || 'email' === $name ) {
				continue;
			}
			$fields[] = array(
				'custom_name' => $name,
				'required'    => ! empty( $field['required'] ),
				'type'        => (string) ( $field['type'] ?? FieldData::TYPE_TEXT ),
				'multiple'    => ! empty( $field['multiple'] ),
				'options'     => is_array( $field['options'] ?? null ) ? array_map( 'strval', $field['options'] ) : array(),
				'default'     => (string) ( $field['default'] ?? '' ),
			);
		}
		return $fields;
	}

	/**
	 * The exact Contact Form 7 tag to paste for one mappable field: `text`/
	 * `number`/`date` for a plain field, or CF7's own `checkbox` tag — with
	 * `use_label_element` (CF7's own accessibility default) and `exclusive`
	 * when Laposta allows only one choice — for a field with options. A `*`
	 * marks a required field, and `default:N` pre-selects Laposta's own
	 * default when it matches one of the choices. A list edited after the
	 * tag is pasted can drift from what the form already has; that is
	 * expected — this only ever suggests a starting point.
	 *
	 * @param array{custom_name:string,required:bool,type:string,multiple:bool,options:array<int,string>,default:string} $field One field from mappable_fields().
	 * @return string
	 */
	private static function cf7_tag_for_field( array $field ): string {
		$name     = self::FIELD_PREFIX . $field['custom_name'];
		$required = $field['required'] ? '*' : '';

		if ( array() !== $field['options'] ) {
			return self::cf7_checkbox_tag( $name, $required, $field['options'], $field['multiple'], $field['default'] );
		}

		$types   = array(
			FieldData::TYPE_NUMBER => 'number',
			FieldData::TYPE_DATE   => 'date',
		);
		$type    = $types[ $field['type'] ] ?? 'text';
		$default = '' !== $field['default'] ? ' "' . str_replace( '"', '', $field['default'] ) . '"' : '';

		return '[' . $type . $required . ' ' . $name . $default . ']';
	}

	/**
	 * Builds a `[checkbox ...]` tag: `exclusive` when only one choice is
	 * allowed (Laposta's select_single), omitted for a multi-choice field
	 * (select_multiple) so CF7 lets more than one be ticked.
	 *
	 * @param string            $name          The `wynko-{custom_name}` field name.
	 * @param string            $required      '*' or ''.
	 * @param array<int,string> $options       Laposta's own option values.
	 * @param bool              $multiple      Whether more than one choice is allowed.
	 * @param string            $default_value Laposta's default value, '' for none.
	 * @return string
	 */
	private static function cf7_checkbox_tag( string $name, string $required, array $options, bool $multiple, string $default_value ): string {
		$quoted = array_map(
			static function ( string $option ): string {
				return '"' . str_replace( '"', '', $option ) . '"';
			},
			$options
		);

		$parts = array( '[checkbox' . $required, $name, 'use_label_element' );
		if ( ! $multiple ) {
			$parts[] = 'exclusive';
		}
		$parts = array_merge( $parts, $quoted );

		$default_index = array_search( $default_value, $options, true );
		if ( false !== $default_index ) {
			$parts[] = 'default:' . ( (int) $default_index + 1 );
		}

		return implode( ' ', $parts ) . ']';
	}

	/**
	 * The `[checkbox ...]` tag to paste for one list's opt-in. Not required —
	 * that is the point of an opt-in — and carries a fixed label as its one
	 * choice, so checking it is the only signal this bridge reads. The list
	 * id lives in the field name itself, so pasting a different list's tag
	 * into a different form subscribes it to that list instead.
	 *
	 * @param string $list_id Laposta list id this tag opts a visitor into.
	 * @return string
	 */
	private static function cf7_optin_tag( string $list_id ): string {
		$label = __( 'Sign up for our newsletter', 'wynko-for-laposta' );

		return sprintf( '[checkbox %s%s use_label_element "%s"]', self::OPTIN_PREFIX, $list_id, str_replace( '"', '', $label ) );
	}

	/**
	 * Prints this bridge's settings screen: a sync status line, and a
	 * four-step walkthrough (pick a list, pick its fields, copy the tags,
	 * open the target CF7 form) that guides a first-time admin end to end
	 * without needing to look up how anywhere else. Steps 2–4 render every
	 * time — never hidden — dimmed until a list is picked so an admin who
	 * has already reached step 4 can still see and use an earlier step to
	 * change something, per the design discussion that shaped this screen.
	 *
	 * @return void
	 */
	public function render_settings(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}

		if ( ! $this->is_available() ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Contact Form 7 is not active on this site.', 'wynko-for-laposta' )
			);
			return;
		}

		self::render_notice();

		$options  = Lists::for_editor()['options'];
		$selected = self::selected_list_id( array_column( $options, 'value' ) );

		if ( array() === $options ) {
			self::render_sync_bar( $selected );
			printf(
				'<p>%s</p>',
				esc_html__( 'No Laposta lists found yet — sync campaigns on the API tab first.', 'wynko-for-laposta' )
			);
			return;
		}

		$pending = ( '' === $selected );
		$fields  = $pending ? array() : self::mappable_fields( $selected );

		echo '<ol class="wynko-bridge-steps">';

		self::render_step_open( 1, __( 'Choose a Laposta list', 'wynko-for-laposta' ), false );
		self::render_list_picker( $options, $selected );
		self::render_sync_bar( $selected );
		self::render_step_close();

		self::render_step_open( 2, __( 'Choose which fields to collect', 'wynko-for-laposta' ), $pending );
		self::render_step_fields( $selected, $fields, $pending );
		self::render_step_close();

		self::render_step_open( 3, __( 'Copy the tags', 'wynko-for-laposta' ), $pending );
		self::render_step_copy( $selected, $fields, $pending );
		self::render_step_close();

		self::render_step_open( 4, __( 'Open your form and paste', 'wynko-for-laposta' ), $pending );
		self::render_step_form_picker();
		self::render_step_close();

		echo '</ol>';
	}

	/**
	 * The list a dropdown selection named, validated against the known ids —
	 * an unknown or missing selection shows the picker with nothing chosen
	 * rather than fetch an arbitrary id.
	 *
	 * @param array<int,string> $ids Every Laposta list id this site knows about.
	 * @return string
	 */
	private static function selected_list_id( array $ids ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation argument, validated against a known list below; no state change on display.
		$requested = isset( $_GET['wynko_cf7_list'] ) ? sanitize_text_field( wp_unslash( $_GET['wynko_cf7_list'] ) ) : '';
		return in_array( $requested, $ids, true ) ? $requested : '';
	}

	/**
	 * The sync status line, printed under step 1's own list picker — it
	 * applies to the list this step chooses, not to the screen as a whole —
	 * plus the otherwise-invisible form the picker row's own Refresh button
	 * posts to via the `form` attribute (the same two-forms-one-row trick
	 * SettingsPage's own API tab uses), so a dropdown change and a refresh
	 * can never be posted as one request.
	 *
	 * Refresh runs the same bust-then-refresh the API tab's own "Sync now"
	 * runs — no separate caching or logging mechanism — because that global
	 * sync only refetches field definitions for lists a Wynko signup form
	 * references, never a list only a Contact Form 7 form is bound to.
	 *
	 * @param string $selected Currently selected list id, '' for none.
	 * @return void
	 */
	private static function render_sync_bar( string $selected ): void {
		printf(
			'<p class="description">%s %s</p>',
			esc_html( Cache::last_refresh_sentence() ),
			esc_html__( "Laposta field changes made after the last sync won't appear here until you refresh.", 'wynko-for-laposta' )
		);

		echo '<form id="wynko-cf7-sync" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SYNC ) . '" />';
		if ( '' !== $selected ) {
			printf( '<input type="hidden" name="wynko_cf7_list" value="%s" />', esc_attr( $selected ) );
		}
		wp_nonce_field( self::ACTION_SYNC );
		echo '</form>';
	}

	/**
	 * Opens one step: its numbered badge and heading.
	 *
	 * @param int    $number  Step number, 1-based.
	 * @param string $title   Step heading.
	 * @param bool   $pending Whether to dim it — true until a list is picked, for
	 *                        every step after the first.
	 * @return void
	 */
	private static function render_step_open( int $number, string $title, bool $pending ): void {
		printf(
			'<li class="wynko-bridge-steps__step%s"><span class="wynko-bridge-steps__badge">%d</span><div class="wynko-bridge-steps__body"><h2>%s</h2>',
			$pending ? ' wynko-bridge-steps__step--pending' : '',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d already casts to int; nothing to escape.
			$number,
			esc_html( $title )
		);
	}

	/**
	 * Closes one step opened by render_step_open().
	 *
	 * @return void
	 */
	private static function render_step_close(): void {
		echo '</div></li>';
	}

	/**
	 * The list picker, GET navigation rather than a setting since there is
	 * nothing to save — submits itself on change, with a fallback button for
	 * a browser without JavaScript. The Refresh button sits in the same row
	 * but is not part of this form: it submits render_sync_bar()'s own
	 * hidden form via the `form` attribute, so picking a list and refreshing
	 * stay two separate requests.
	 *
	 * @param array<int,array{value:string,label:string}> $options  Known Laposta lists.
	 * @param string                                      $selected Currently selected list id, '' for none.
	 * @return void
	 */
	private static function render_list_picker( array $options, string $selected ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( Menu::INTEGRATIONS ) );
		echo '<input type="hidden" name="integration" value="contact-form-7" />';
		echo '<div class="wynko-actions">';
		echo '<select name="wynko_cf7_list" onchange="this.form.submit()">';
		printf( '<option value="">%s</option>', esc_html__( '— Select a list —', 'wynko-for-laposta' ) );
		foreach ( $options as $option ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $option['value'] ),
				selected( $selected, $option['value'], false ),
				esc_html( $option['label'] )
			);
		}
		echo '</select>';
		printf( '<noscript><button type="submit" class="button">%s</button></noscript>', esc_html__( 'Show', 'wynko-for-laposta' ) );
		printf(
			'<button type="submit" form="wynko-cf7-sync" class="button button-secondary">%s</button>',
			esc_html__( 'Refresh', 'wynko-for-laposta' )
		);
		echo '</div>';
		echo '</form>';
	}

	/**
	 * Prints the result notice, if this request carries one.
	 *
	 * @return void
	 */
	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cosmetic flag set by handle_sync()'s own wp_safe_redirect; no state change on display.
		$flag = isset( $_GET['wynko_cf7'] ) ? sanitize_text_field( wp_unslash( $_GET['wynko_cf7'] ) ) : '';
		if ( 'ok' !== $flag && 'error' !== $flag ) {
			return;
		}
		$ok = ( 'ok' === $flag );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$ok ? 'success' : 'error',
			esc_html( $ok ? __( 'Synced with Laposta.', 'wynko-for-laposta' ) : __( 'Sync failed — see the activity log.', 'wynko-for-laposta' ) )
		);
	}

	/**
	 * Step 2's body: a checkbox per custom field plus one for the opt-in
	 * checkbox itself (last, since it is what makes a submission subscribe
	 * at all) — required fields and the opt-in checkbox are pre-checked and
	 * cannot be unchecked. Before a list is picked there is nothing to list
	 * yet, so this prints a placeholder instead.
	 *
	 * @param string                                                                                                                $list_id Laposta list id, '' when pending.
	 * @param array<int,array{custom_name:string,required:bool,type:string,multiple:bool,options:array<int,string>,default:string}> $fields  From mappable_fields(), empty when pending.
	 * @param bool                                                                                                                  $pending Whether no list is picked yet.
	 * @return void
	 */
	private static function render_step_fields( string $list_id, array $fields, bool $pending ): void {
		if ( $pending ) {
			echo '<p class="description">' . esc_html__( 'Pick a list in step 1 first.', 'wynko-for-laposta' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'A required field is always included and cannot be left out — a signup is skipped if it is missing from the submission.', 'wynko-for-laposta' ) . '</p>';
		echo '<div class="wynko-bridge-fields"><fieldset class="wynko-panel__group"><legend class="screen-reader-text">' . esc_html__( 'Tags to include', 'wynko-for-laposta' ) . '</legend><ul>';
		foreach ( $fields as $field ) {
			self::render_field_row( self::cf7_tag_for_field( $field ), $field['custom_name'], $field['required'] );
		}
		self::render_field_row( self::cf7_optin_tag( $list_id ), __( 'Opt-in checkbox', 'wynko-for-laposta' ), true );
		echo '</ul></fieldset></div>';
	}

	/**
	 * One field row: a checkbox (checked, disabled when required) plus the
	 * exact CF7 tag it stands for.
	 *
	 * @param string $tag      The CF7 tag this row's checkbox carries.
	 * @param string $label    What the row is called.
	 * @param bool   $required Whether the checkbox is locked checked.
	 * @return void
	 */
	private static function render_field_row( string $tag, string $label, bool $required ): void {
		printf(
			'<li><label><input type="checkbox" class="wynko-bridge-field" data-tag="%s" checked="checked"%s /> %s%s</label><code>%s</code></li>',
			esc_attr( $tag ),
			$required ? ' disabled="disabled"' : '',
			esc_html( $label ),
			$required ? ' <span class="description">' . esc_html__( '(required)', 'wynko-for-laposta' ) . '</span>' : '',
			esc_html( $tag )
		);
	}

	/**
	 * Step 3's body: the combined block of every currently checked tag,
	 * ready to copy in one action. Rebuilt live by forms.js from step 2's
	 * checkboxes, so the two steps live in separate `<li>` elements but stay
	 * in sync — the JS listens for any `.wynko-bridge-field` change document-
	 * wide rather than scoping to a shared ancestor.
	 *
	 * @param string                                                                                                                $list_id Laposta list id, '' when pending.
	 * @param array<int,array{custom_name:string,required:bool,type:string,multiple:bool,options:array<int,string>,default:string}> $fields  From mappable_fields(), empty when pending.
	 * @param bool                                                                                                                  $pending Whether no list is picked yet.
	 * @return void
	 */
	private static function render_step_copy( string $list_id, array $fields, bool $pending ): void {
		if ( $pending ) {
			echo '<p class="description">' . esc_html__( 'Pick a list in step 1 first.', 'wynko-for-laposta' ) . '</p>';
			return;
		}

		$combined = self::combined_block( $list_id, $fields );
		$rows     = (int) min( max( count( $fields ) + 2, 4 ), 20 );

		echo '<div class="wynko-shortcode-box wynko-shortcode-box--block">';
		printf(
			'<textarea class="code wynko-bridge-combined" rows="%d" readonly="readonly" onfocus="this.select()">%s</textarea>',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d already casts to int; nothing to escape.
			$rows,
			esc_textarea( $combined )
		);
		printf(
			'<button type="button" class="button wynko-copy" data-copy="%s">%s</button>',
			esc_attr( $combined ),
			esc_html__( 'Copy', 'wynko-for-laposta' )
		);
		echo '</div>';
	}

	/**
	 * Step 4's body: a picker over this site's own Contact Form 7 forms and
	 * a button that opens CF7's own edit screen for the one chosen, in a new
	 * tab — this screen (and the tags copied in step 3) stays put in the
	 * original one, so getting back to them is a tab switch, not a Back
	 * button. A site with no CF7 form yet gets a link to create one instead
	 * of an empty picker, opened the same way.
	 *
	 * @return void
	 */
	private static function render_step_form_picker(): void {
		$forms = get_posts(
			array(
				'post_type'   => 'wpcf7_contact_form',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		if ( array() === $forms ) {
			printf(
				'<p class="description">%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_html__( "You don't have a Contact Form 7 form yet.", 'wynko-for-laposta' ),
				esc_url( admin_url( 'admin.php?page=wpcf7-new' ) ),
				esc_html__( 'Add a contact form', 'wynko-for-laposta' )
			);
			return;
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" target="_blank">';
		echo '<input type="hidden" name="page" value="wpcf7" />';
		echo '<input type="hidden" name="action" value="edit" />';
		echo '<select name="post">';
		foreach ( $forms as $form ) {
			printf( '<option value="%d">%s</option>', (int) $form->ID, esc_html( $form->post_title ) );
		}
		echo '</select> ';
		printf( '<button type="submit" class="button button-secondary">%s</button>', esc_html__( 'Open form editor', 'wynko-for-laposta' ) );
		echo '</form>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( "Paste the code you copied in step 3 into the form's template.", 'wynko-for-laposta' )
		);
	}

	/**
	 * Every field's tag plus the opt-in tag, one per line, opt-in last —
	 * matching render_step_fields()'s own row order so the initial
	 * server-rendered value matches what a checkbox toggle rebuilds. Every
	 * row is included, matching the checkboxes' own default state of
	 * checked.
	 *
	 * @param string                                                                                                                $list_id Laposta list id.
	 * @param array<int,array{custom_name:string,required:bool,type:string,multiple:bool,options:array<int,string>,default:string}> $fields  From mappable_fields().
	 * @return string
	 */
	private static function combined_block( string $list_id, array $fields ): string {
		$tags = array();
		foreach ( $fields as $field ) {
			$tags[] = self::cf7_tag_for_field( $field );
		}
		$tags[] = self::cf7_optin_tag( $list_id );
		return implode( "\n", $tags );
	}

	/**
	 * Handles the "Refresh" post: runs the exact same sync
	 * SettingsPage::handle_sync() ("Sync now") runs — bust the shared cache,
	 * refresh() it (which is also what writes the one activity-log entry a
	 * sync produces), then record what it just proved about the key via
	 * SettingsPage::record_sync_verdict(), so the About tab's connection row
	 * reflects this sync too, not only one triggered from Settings — and
	 * redirects back to the picked list, if any.
	 *
	 * @return void
	 */
	public static function handle_sync(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_SYNC );

		Cache::bust();
		$result = Cache::refresh();
		SettingsPage::record_sync_verdict( $result );

		wp_safe_redirect( self::sync_redirect_url( is_wp_error( $result ) ? 'error' : 'ok' ) );
		exit;
	}

	/**
	 * Where "Refresh" returns to, carrying the picked list and the sync
	 * result forward. Extracted from handle_sync() so the target is
	 * testable without shimming wp_safe_redirect() and exit.
	 *
	 * @param string $flag Result flag, 'ok' or 'error'.
	 * @return string
	 */
	public static function sync_redirect_url( string $flag ): string {
		$url = add_query_arg(
			array(
				'integration' => 'contact-form-7',
				'wynko_cf7'   => $flag,
			),
			Menu::url( Menu::INTEGRATIONS )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() in handle_sync(), the only caller, already verified this request.
		$list = isset( $_POST['wynko_cf7_list'] ) ? sanitize_text_field( wp_unslash( $_POST['wynko_cf7_list'] ) ) : '';
		return '' !== $list ? add_query_arg( 'wynko_cf7_list', $list, $url ) : $url;
	}
}
