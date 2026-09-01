<?php
/**
 * Bundled HTML Forms bridge.
 *
 * @package Wynko
 */

namespace Wynko\Integrations\HtmlForms;

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
 * Reads an HTML Forms submission for an opt-in checkbox and subscribes it to
 * a Laposta list. HTML Forms has no typed field system of its own — an admin
 * pastes raw HTML into the form's own markup textarea — so, unlike the
 * Contact Form 7 bridge, this bridge cannot ask the form which field is the
 * submitter's email; it instead reads a fixed `wynko-email` field name, the
 * same convention every other field it reads already follows. Nothing about
 * the form is modified or rendered by this bridge — the settings screen is a
 * builder: pick a known Laposta list from a dropdown and it shows the exact
 * HTML snippet, plus one per custom field, to paste into a form's own
 * markup. The list a submission joins travels in the checkbox's own field
 * name, not a site-wide setting, so different HTML Forms forms can subscribe
 * to different lists — a form is opted in by containing the input for
 * whichever list it should feed, nothing more.
 *
 * Field mapping follows the same convention as the Contact Form 7 bridge: a
 * list's required custom field `first_name` is supplied by an input literally
 * named `wynko-first_name` — an exact match.
 */
final class HtmlFormsIntegration implements Integration {

	const ACTION_SYNC = 'wynko_hf_sync';

	/** The submitter's email is always read from this fixed field name. */
	const EMAIL_FIELD = 'wynko-email';

	/** Prefix a field name carries to map to a Laposta custom field. */
	const FIELD_PREFIX = 'wynko-';

	/** Prefix the opt-in checkbox's field name carries; the list id follows it. */
	const OPTIN_PREFIX = 'wynko-optin-';

	/**
	 * This bridge's stable identifier and enabled-state key.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'html-forms';
	}

	/**
	 * Display name shown in the Integrations list.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'HTML Forms', 'wynko-for-laposta' );
	}

	/**
	 * One-sentence description shown in the Integrations list.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Subscribes via HTML Forms forms.', 'wynko-for-laposta' );
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
	 * Whether the HTML Forms plugin is active on this site.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( '\HTML_Forms\Forms' );
	}

	/**
	 * Names the concrete thing that stops working: the opt-in checkbox
	 * already pasted into a live HTML Forms form.
	 *
	 * @return string
	 */
	public function deactivation_warning(): string {
		return __( 'Deactivating this integration means the sign-up checkbox already pasted into any HTML Forms form will stop subscribing anyone, and those forms may not work as expected. Deactivate anyway?', 'wynko-for-laposta' );
	}

	/**
	 * Hooks HTML Forms' own success action, fired only once a submission has
	 * been accepted and every configured form action has run — the same
	 * point in the lifecycle the Contact Form 7 bridge hooks via
	 * wpcf7_before_send_mail. No form-tag registration: every field this
	 * bridge reads is a plain HTML input, pasted by the admin from this
	 * integration's own settings screen.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'hf_form_success', array( $this, 'maybe_subscribe' ), 10, 2 );
	}

	/**
	 * Subscribes an accepted HTML Forms submission's email address, if one of
	 * its wynko-optin-{list_id} checkboxes was checked. Called from HTML
	 * Forms' own hf_form_success action, after the submission has already
	 * been validated and accepted.
	 *
	 * Fire-and-forget by design: the result is never allowed to change HTML
	 * Forms' own success flow, and is metered through Throttle's per-IP
	 * counter first — HTML Forms has no rate limiting of its own, and this is
	 * the write that makes Laposta send a confirmation email to whatever
	 * address the caller names.
	 *
	 * @param mixed $submission The submitting HTML_Forms\Submission instance.
	 * @param mixed $form       The submitted HTML_Forms\Form instance, whose
	 *                          raw ->markup is the allowlist source.
	 * @return void
	 */
	public function maybe_subscribe( $submission, $form = null ): void {
		if ( ! is_object( $submission ) || ! isset( $submission->data ) || ! is_array( $submission->data ) ) {
			return;
		}

		$posted   = $submission->data;
		$declared = self::declared_field_names( $form );
		$list_id  = self::checked_list_id( $posted, $declared );
		if ( '' === $list_id ) {
			return;
		}

		$email = isset( $posted[ self::EMAIL_FIELD ] ) ? sanitize_email( (string) $posted[ self::EMAIL_FIELD ] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$mapped = self::mapped_custom_fields( $list_id, $posted, $declared );
		if ( null === $mapped ) {
			Log::warning( __( 'HTML Forms integration: a required Laposta field has no matching field on the form; check the field is named wynko-{field_name} exactly.', 'wynko-for-laposta' ) );
			return;
		}

		$ip = isset( $submission->ip_address ) ? (string) $submission->ip_address : '';
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
					? __( 'HTML Forms integration: the address was already subscribed.', 'wynko-for-laposta' )
					: __( 'HTML Forms integration: the signup could not be completed.', 'wynko-for-laposta' )
			);
			return;
		}

		Log::info( __( 'New signup through the HTML Forms integration.', 'wynko-for-laposta' ) );
	}

	/**
	 * The Laposta list id carried by whichever wynko-optin-{list_id}
	 * checkbox was actually checked in this submission, '' if none was. The
	 * list travels in the field's own name — pasted verbatim from this
	 * integration's settings screen — rather than a site-wide setting, so
	 * different forms can feed different lists.
	 *
	 * Only a key present in $declared is trusted — a POST field appended by
	 * the submitter that has no matching `name="..."` in the submitting
	 * form's own pasted markup is ignored, closing the cross-form injection
	 * RISK-001 describes.
	 *
	 * @param array<string,mixed> $posted   HTML Forms' posted submission data.
	 * @param array<string,bool>  $declared Field names this form's markup actually declares, from declared_field_names().
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
	 * Every field name the submitting form's own pasted markup actually
	 * declares, as a lookup set — the allowlist checked_list_id() and
	 * mapped_custom_fields() cross-check posted keys against, rather than
	 * trusting any wynko-* key present in the POST body regardless of
	 * origin. HTML Forms has no tag-scanning API of its own (unlike CF7), so
	 * this greps the form's raw ->markup for name="..." attributes instead —
	 * the same lower-cost approach TD-067 already names as available to this
	 * bridge. A checkbox-group field posts name="wynko-{x}[]"; the trailing
	 * [] is stripped so it matches the POST key HTML Forms actually sends.
	 *
	 * @param mixed $form The submitted HTML_Forms\Form instance.
	 * @return array<string,bool> Field name => true.
	 */
	private static function declared_field_names( $form ): array {
		if ( ! is_object( $form ) || ! isset( $form->markup ) || ! is_string( $form->markup ) ) {
			return array();
		}

		if ( ! preg_match_all( '/\bname=(["\'])(.*?)\1/i', $form->markup, $matches ) ) {
			return array();
		}

		$declared = array();
		foreach ( $matches[2] as $name ) {
			$name = preg_replace( '/\[\]$/', '', (string) $name );
			if ( '' !== $name ) {
				$declared[ $name ] = true;
			}
		}
		return $declared;
	}

	/**
	 * Collects every wynko-{custom_name} value present in a submission as
	 * that field's custom value, for every custom field the list defines
	 * other than email. A plain text/number/date input posts a string; a
	 * checkbox group (`name="wynko-{custom_name}[]"`) posts an array, reduced
	 * to a single string for a single-choice field or kept as an array for a
	 * multi-choice one, matching what Subscribers::create() expects either
	 * way. Returns null — abort the whole subscribe attempt, rather than send
	 * Laposta a write it will reject — when a *required* field's input is
	 * missing from the submission.
	 *
	 * @param string              $list_id  Laposta list id.
	 * @param array<string,mixed> $posted   HTML Forms' posted submission data.
	 * @param array<string,bool>  $declared Field names this form's markup actually declares, from declared_field_names().
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
	 * A list's custom fields other than email — the ones a form maps by
	 * name, since email is always read from the fixed wynko-email field
	 * instead. Carries the full field shape (type, options, default), not
	 * just the name, so render_field_instructions() can suggest the actual
	 * HTML snippet rather than a bare field name.
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
	 * The exact HTML snippet to paste for one mappable field, wrapped in
	 * HTML Forms' own default field markup — `<p><label>…</label>
	 * <input …></p>`, the same shape `Admin::get_default_form_content()`
	 * gives a brand-new form's own starter fields — so a pasted field reads
	 * as native HTML Forms markup, not a bare, unwrapped control: a plain
	 * `<input type="text|number|date">` for a plain field, a `<select>` for
	 * a single-choice field, or one checkbox per option — named
	 * `wynko-{custom_name}[]` — for a multi-choice one. `required` marks a
	 * required field, and the matching Laposta default is pre-selected/
	 * pre-checked when it matches one of the choices. A list edited after the
	 * snippet is pasted can drift from what the form already has; that is
	 * expected — this only ever suggests a starting point.
	 *
	 * @param array{custom_name:string,required:bool,type:string,multiple:bool,options:array<int,string>,default:string} $field One field from mappable_fields().
	 * @return string
	 */
	private static function html_for_field( array $field ): string {
		$name     = self::FIELD_PREFIX . $field['custom_name'];
		$required = $field['required'] ? ' required' : '';

		if ( array() !== $field['options'] ) {
			$control = $field['multiple']
				? self::html_checkbox_group( $name, $field['options'], $field['default'] )
				: self::html_select( $name, $required, $field['options'], $field['default'] );

			return self::html_field_paragraph( $field['custom_name'], $control );
		}

		$types = array(
			FieldData::TYPE_NUMBER => 'number',
			FieldData::TYPE_DATE   => 'date',
		);
		$type  = $types[ $field['type'] ] ?? 'text';

		$control = sprintf(
			'<input type="%s" name="%s" value="%s"%s>',
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( $field['default'] ),
			$required
		);

		return self::html_field_paragraph( $field['custom_name'], $control );
	}

	/**
	 * Wraps one field's label and control in HTML Forms' own default `<p>`
	 * wrapper — see html_for_field()'s own docblock for why.
	 *
	 * @param string $label   Plain-text label shown above the control.
	 * @param string $control The field's own HTML, already built.
	 * @return string
	 */
	private static function html_field_paragraph( string $label, string $control ): string {
		return sprintf( "<p>\n\t<label>%s</label>\n\t%s\n</p>", esc_html( $label ), $control );
	}

	/**
	 * Builds a `<select>` element for a single-choice field.
	 *
	 * @param string            $name          The `wynko-{custom_name}` field name.
	 * @param string            $required      ' required' or ''.
	 * @param array<int,string> $options       Laposta's own option values.
	 * @param string            $default_value Laposta's default value, '' for none.
	 * @return string
	 */
	private static function html_select( string $name, string $required, array $options, string $default_value ): string {
		$html = sprintf( '<select name="%s"%s>', esc_attr( $name ), $required );
		foreach ( $options as $option ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $option ),
				selected( $default_value, $option, false ),
				esc_html( $option )
			);
		}
		$html .= '</select>';
		return $html;
	}

	/**
	 * Builds one checkbox per option for a multi-choice field, each posting
	 * to the same array-named field.
	 *
	 * @param string            $name          The `wynko-{custom_name}` field name.
	 * @param array<int,string> $options       Laposta's own option values.
	 * @param string            $default_value Laposta's default value, '' for none.
	 * @return string
	 */
	private static function html_checkbox_group( string $name, array $options, string $default_value ): string {
		$rows = array();
		foreach ( $options as $option ) {
			$rows[] = sprintf(
				'<label><input type="checkbox" name="%s[]" value="%s"%s> %s</label>',
				esc_attr( $name ),
				esc_attr( $option ),
				checked( $default_value, $option, false ),
				esc_html( $option )
			);
		}
		return implode( "\n", $rows );
	}

	/**
	 * The `<input>` to paste for one list's opt-in, wrapped in HTML Forms'
	 * own default `<p>` field wrapper (see html_for_field()'s own docblock).
	 * Not required — that is the point of an opt-in — and carries a fixed
	 * label, so checking it is the only signal this bridge reads. Unlike a
	 * plain field, the label wraps the checkbox itself rather than sitting
	 * beside it, the natural pairing for a single checkbox with its own
	 * text. The list id lives in the field name itself, so pasting a
	 * different list's snippet into a different form subscribes it to that
	 * list instead.
	 *
	 * @param string $list_id Laposta list id this snippet opts a visitor into.
	 * @return string
	 */
	private static function html_optin_snippet( string $list_id ): string {
		$label = __( 'Sign up for our newsletter', 'wynko-for-laposta' );

		$control = sprintf(
			'<label><input type="checkbox" name="%s%s" value="1"> %s</label>',
			esc_attr( self::OPTIN_PREFIX ),
			esc_attr( $list_id ),
			esc_html( $label )
		);

		return sprintf( "<p>\n\t%s\n</p>", $control );
	}

	/**
	 * The fixed `<input>` for the submitter's email address, wrapped in HTML
	 * Forms' own default `<p>` field wrapper (see html_for_field()'s own
	 * docblock). Always included since this bridge has no way to ask HTML
	 * Forms which field is email-typed.
	 *
	 * @return string
	 */
	private static function html_email_snippet(): string {
		$control = sprintf( '<input type="email" name="%s" required>', esc_attr( self::EMAIL_FIELD ) );

		return self::html_field_paragraph( __( 'Email address', 'wynko-for-laposta' ), $control );
	}

	/**
	 * Prints this bridge's settings screen: a sync status line, and a
	 * four-step walkthrough (pick a list, pick its fields, copy the
	 * snippets, open the target HTML Forms form) that guides a first-time
	 * admin end to end without needing to look up how anywhere else. Steps
	 * 2–4 render every time — never hidden — dimmed until a list is picked so
	 * an admin who has already reached step 4 can still see and use an
	 * earlier step to change something.
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
				esc_html__( 'HTML Forms is not active on this site.', 'wynko-for-laposta' )
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

		self::render_step_open( 3, __( 'Copy the HTML', 'wynko-for-laposta' ), $pending );
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
		$requested = isset( $_GET['wynko_hf_list'] ) ? sanitize_text_field( wp_unslash( $_GET['wynko_hf_list'] ) ) : '';
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
	 * references, never a list only an HTML Forms form is bound to.
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

		echo '<form id="wynko-hf-sync" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SYNC ) . '" />';
		if ( '' !== $selected ) {
			printf( '<input type="hidden" name="wynko_hf_list" value="%s" />', esc_attr( $selected ) );
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
		echo '<input type="hidden" name="integration" value="html-forms" />';
		echo '<div class="wynko-actions">';
		echo '<select name="wynko_hf_list" onchange="this.form.submit()">';
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
			'<button type="submit" form="wynko-hf-sync" class="button button-secondary">%s</button>',
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
		$flag = isset( $_GET['wynko_hf'] ) ? sanitize_text_field( wp_unslash( $_GET['wynko_hf'] ) ) : '';
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
	 * Step 2's body: a checkbox for the fixed email field (first, since
	 * without it there is no address to subscribe), one per custom field,
	 * and one for the opt-in checkbox itself (last, since it is what makes a
	 * submission subscribe at all) — the email and opt-in rows plus every
	 * required field are pre-checked and cannot be unchecked. Before a list
	 * is picked there is nothing to list yet, so this prints a placeholder
	 * instead.
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
		echo '<div class="wynko-bridge-fields"><fieldset class="wynko-panel__group"><legend class="screen-reader-text">' . esc_html__( 'Fields to include', 'wynko-for-laposta' ) . '</legend><ul>';
		self::render_field_row( self::html_email_snippet(), __( 'Email address', 'wynko-for-laposta' ), true );
		foreach ( $fields as $field ) {
			self::render_field_row( self::html_for_field( $field ), $field['custom_name'], $field['required'] );
		}
		self::render_field_row( self::html_optin_snippet( $list_id ), __( 'Opt-in checkbox', 'wynko-for-laposta' ), true );
		echo '</ul></fieldset></div>';
	}

	/**
	 * One field row: a checkbox (checked, disabled when required) plus the
	 * exact HTML snippet it stands for.
	 *
	 * @param string $snippet  The HTML snippet this row's checkbox carries.
	 * @param string $label    What the row is called.
	 * @param bool   $required Whether the checkbox is locked checked.
	 * @return void
	 */
	private static function render_field_row( string $snippet, string $label, bool $required ): void {
		printf(
			'<li><label><input type="checkbox" class="wynko-bridge-field" data-tag="%s" checked="checked"%s /> %s%s</label><code>%s</code></li>',
			esc_attr( $snippet ),
			$required ? ' disabled="disabled"' : '',
			esc_html( $label ),
			$required ? ' <span class="description">' . esc_html__( '(required)', 'wynko-for-laposta' ) . '</span>' : '',
			esc_html( $snippet )
		);
	}

	/**
	 * Step 3's body: the combined block of every currently checked snippet,
	 * ready to copy in one action. Rebuilt live by forms.js from step 2's
	 * checkboxes, so the two steps live in separate `<li>` elements but stay
	 * in sync — the JS listens for any `.wynko-bridge-field` change
	 * document-wide rather than scoping to a shared ancestor.
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
		$rows     = (int) min( max( count( $fields ) + 3, 4 ), 20 );

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
	 * Step 4's body: a picker over this site's own HTML Forms forms and a
	 * button that opens HTML Forms' own edit screen for the one chosen, in a
	 * new tab — this screen (and the HTML copied in step 3) stays put in the
	 * original one, so getting back to them is a tab switch, not a Back
	 * button. A site with no HTML Forms form yet gets a link to create one
	 * instead of an empty picker, opened the same way.
	 *
	 * @return void
	 */
	private static function render_step_form_picker(): void {
		$forms = get_posts(
			array(
				'post_type'   => 'html-form',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		if ( array() === $forms ) {
			printf(
				'<p class="description">%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_html__( "You don't have an HTML Forms form yet.", 'wynko-for-laposta' ),
				esc_url( admin_url( 'admin.php?page=html-forms-add-form' ) ),
				esc_html__( 'Add a form', 'wynko-for-laposta' )
			);
			return;
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" target="_blank">';
		echo '<input type="hidden" name="page" value="html-forms" />';
		echo '<input type="hidden" name="view" value="edit" />';
		echo '<select name="form_id">';
		foreach ( $forms as $form ) {
			printf( '<option value="%d">%s</option>', (int) $form->ID, esc_html( $form->post_title ) );
		}
		echo '</select> ';
		printf( '<button type="submit" class="button button-secondary">%s</button>', esc_html__( 'Open form editor', 'wynko-for-laposta' ) );
		echo '</form>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( "Paste the code you copied in step 3 into the form's own code.", 'wynko-for-laposta' )
		);
	}

	/**
	 * Every field's snippet plus the email and opt-in snippets, email first
	 * and opt-in last — matching render_step_fields()'s own row order so the
	 * initial server-rendered value matches what a checkbox toggle rebuilds.
	 * Every row is included, matching the checkboxes' own default state of
	 * checked.
	 *
	 * @param string                                                                                                                $list_id Laposta list id.
	 * @param array<int,array{custom_name:string,required:bool,type:string,multiple:bool,options:array<int,string>,default:string}> $fields  From mappable_fields().
	 * @return string
	 */
	private static function combined_block( string $list_id, array $fields ): string {
		$snippets   = array();
		$snippets[] = self::html_email_snippet();
		foreach ( $fields as $field ) {
			$snippets[] = self::html_for_field( $field );
		}
		$snippets[] = self::html_optin_snippet( $list_id );
		return implode( "\n", $snippets );
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
				'integration' => 'html-forms',
				'wynko_hf'    => $flag,
			),
			Menu::url( Menu::INTEGRATIONS )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() in handle_sync(), the only caller, already verified this request.
		$list = isset( $_POST['wynko_hf_list'] ) ? sanitize_text_field( wp_unslash( $_POST['wynko_hf_list'] ) ) : '';
		return '' !== $list ? add_query_arg( 'wynko_hf_list', $list, $url ) : $url;
	}
}
