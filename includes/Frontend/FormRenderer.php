<?php
/**
 * Front-end signup form markup.
 *
 * @package Wynko
 */

namespace Wynko\Frontend;

use Wynko\Api\Fields;
use Wynko\Forms\Button;
use Wynko\Forms\FormData;
use Wynko\Forms\Messages;
use Wynko\Rest\FieldsController;
use Wynko\Support\FieldFingerprint;
use Wynko\Support\Fields as FieldData;
use Wynko\Support\LapostaErrors;
use Wynko\Urls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only place a signup form's HTML is written, called by both the shortcode
 * and the block, so there is one markup shape to keep accessible, translatable
 * and escaped. Colour, type, spacing and borders are the theme's; the shipped
 * stylesheet is structural only.
 *
 * Which attributes each input carries follows the HTML standard's per-type
 * table, so a date emits no placeholder and a range no required. They mirror the
 * server-side validation for immediate feedback; FormValidator is the boundary.
 */
final class FormRenderer {

	const HANDLE = 'wynko-form';

	/**
	 * Renders one form, taking its outcome from the one-shot token in the URL.
	 *
	 * @param int $form_id Form post id.
	 * @return string
	 */
	public static function render( int $form_id ): string {
		self::enqueue();
		return self::render_with_result( $form_id, self::result_for( $form_id ) );
	}

	/**
	 * Renders one form against a known outcome, '' when it cannot be shown.
	 *
	 * The redirect path reads its outcome from a one-shot transient and the REST
	 * path already holds one, but both render through here, so every outcome
	 * behaves the same whether or not the page reloaded.
	 *
	 * @param int                      $form_id Form post id.
	 * @param array<string,mixed>|null $result  Submission outcome, null when there is none.
	 * @return string
	 */
	public static function render_with_result( int $form_id, ?array $result ): string {
		$form = FormData::load( $form_id );
		if ( null === $form || ! $form->is_published() ) {
			return '';
		}

		$settings = $form->settings();
		$notice   = self::notice( $form, $result );

		$succeeded = null !== $result && FormSubmitHandler::STATUS_SUCCESS === $result['status'];
		if ( $succeeded && $settings['hide_after_submit'] ) {
			return sprintf( '<div class="wynko-form wynko-form--done">%s</div>', $notice );
		}

		$list_id = $form->list_id();
		if ( '' === $list_id ) {
			return self::admin_note( __( 'Wynko: this signup form has no Laposta list selected yet.', 'wynko-for-laposta' ) );
		}

		$definitions = Fields::for_list( $list_id );
		if ( $definitions['error'] ) {
			return self::admin_note( __( 'Wynko: could not load this list\'s fields. Check the API key on the Wynko settings screen.', 'wynko-for-laposta' ) );
		}

		$errors = ( null !== $result && is_array( $result['errors'] ) ) ? $result['errors'] : array();
		$values = ( null !== $result && is_array( $result['values'] ) ) ? $result['values'] : array();

		$html  = sprintf( '<div class="wynko-form wynko-form-%d">', (int) $form_id );
		$html .= $notice;
		// novalidate switches off the browser's own error bubble, which would
		// otherwise fire first and show its wording instead of the admin's. The
		// required and type attributes stay, for assistive technology.
		$html .= sprintf( '<form class="wynko-form__form" method="post" action="%s" novalidate="novalidate">', esc_url( admin_url( 'admin-post.php' ) ) );
		$html .= sprintf( '<input type="hidden" name="action" value="%s" />', esc_attr( FormSubmitHandler::ACTION ) );
		$html .= sprintf( '<input type="hidden" name="wynko_form_id" value="%d" />', (int) $form_id );
		$html .= wp_nonce_field( FormSubmitHandler::nonce_action( $form_id ), FormSubmitHandler::NONCE_FIELD, true, false );
		$html .= sprintf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( FormSubmitHandler::FIELD_FINGERPRINT_FIELD ),
			esc_attr( FieldFingerprint::of( $definitions['fields'] ) )
		);
		$html .= self::honeypot();

		$submitted = isset( $values['fields'] ) && is_array( $values['fields'] ) ? $values['fields'] : array();
		$email     = isset( $values['email'] ) ? (string) $values['email'] : '';

		$mode = (string) $settings['label_mode'];

		// The email row is in this list like any other, so it renders wherever
		// the admin dragged it and with whatever label and class they set.
		foreach ( $form->visible_fields( $definitions['fields'] ) as $field ) {
			$html .= FieldData::is_email( $field )
				? self::email_field( $form, $field, $errors, $email, $mode )
				: self::field( $form, $field, $errors, $submitted, $mode );
		}

		if ( $settings['terms_required'] ) {
			$html .= self::terms_field( $form, $settings, $errors );
		}

		// wynko-form__submit stays whatever else the admin adds: the in-place
		// submit script finds the button by it.
		$button = Button::resolve( $form );
		$html  .= sprintf(
			'<p class="wynko-form__actions"><button type="submit" class="%s">%s</button></p>',
			esc_attr( trim( 'wynko-form__submit ' . $button['css_class'] ) ),
			esc_html( $button['label'] )
		);
		$html  .= '</form></div>';

		return $html;
	}

	/**
	 * Loads the in-place submit script, only where a form renders.
	 *
	 * Without it the form still works: it posts to admin-post.php and comes
	 * back through the redirect, which is what a visitor without JavaScript
	 * gets either way.
	 *
	 * @return void
	 */
	private static function enqueue(): void {
		$asset = self::asset_meta();

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/frontend/form.js', WYNKO_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script( self::HANDLE, 'wynkoForm', self::script_data() );

		self::register_style();
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * Registers the form's stylesheet without enqueueing it.
	 *
	 * The block names this handle in its block.json, which is what gets the
	 * stylesheet inside the editor's iframe. Registering happens in one place,
	 * because a handle registered twice keeps whichever src won the race.
	 *
	 * @return void
	 */
	public static function register_style(): void {
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style(
			self::HANDLE,
			plugins_url( 'build/frontend/form.css', WYNKO_FILE ),
			array(),
			self::asset_meta()['version']
		);
	}

	/**
	 * What the build wrote about the front-end bundle.
	 *
	 * @return array{dependencies:array<int,string>,version:string|false}
	 */
	private static function asset_meta(): array {
		$file = plugin_dir_path( WYNKO_FILE ) . 'build/frontend/form.asset.php';

		/**
		 * Typed to match what the build writes.
		 *
		 * @var array{dependencies:array<int,string>,version:string|false} $asset
		 */
		$asset = is_readable( $file ) ? require $file : array(
			'dependencies' => array(),
			'version'      => false,
		);

		return $asset;
	}

	/**
	 * What the front-end script is given.
	 *
	 * The REST nonce is not a second authorization check but what stops core
	 * downgrading a cookie-authenticated request to user 0. Without it the form's
	 * user-scoped nonce would verify against the wrong user and fail every
	 * submission a logged-in visitor made.
	 *
	 * @return array<string,string>
	 */
	public static function script_data(): array {
		return array(
			'restRoot'  => esc_url_raw( rest_url( FieldsController::NAMESPACE_V1 . '/' ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * The one-shot outcome for this form, null when there is none. Reading it
	 * destroys it, so a reload shows a clean form rather than a stale message.
	 *
	 * @param int $form_id Form post id.
	 * @return array<string,mixed>|null
	 */
	private static function result_for( int $form_id ): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display token minted by our own redirect; it selects a stored outcome and changes nothing.
		$token = isset( $_GET[ FormSubmitHandler::RESULT_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ FormSubmitHandler::RESULT_ARG ] ) ) : '';
		if ( '' === $token ) {
			return null;
		}

		return FormSubmitHandler::take_result( $token, $form_id );
	}

	/**
	 * The form-level message above the fields, '' when there is nothing to say.
	 *
	 * This is the one message an administrator may write markup into, so that a
	 * "check your inbox" can link somewhere. It is filtered on the way in too;
	 * running wp_kses() here catches wording stored before that rule existed.
	 *
	 * @param FormData                 $form   The form.
	 * @param array<string,mixed>|null $result One-shot outcome.
	 * @return string
	 */
	private static function notice( FormData $form, ?array $result ): string {
		if ( null === $result || FormSubmitHandler::STATUS_INVALID === $result['status'] ) {
			return '';
		}

		$success = FormSubmitHandler::STATUS_SUCCESS === $result['status'];
		return sprintf(
			'<p class="wynko-form__notice wynko-form__notice--%s" role="status">%s</p>',
			$success ? 'success' : 'error',
			wp_kses( Messages::resolve( $form, (string) $result['slug'] ), Messages::allowed_html() )
		);
	}

	/**
	 * The bot trap: an ordinary-looking text input that no visitor can reach.
	 *
	 * It is a real input moved off-canvas by form.scss, because type="hidden" and
	 * display:none are both signals a capable scraper skips. aria-hidden and
	 * tabindex keep it out of assistive technology and the tab order.
	 *
	 * @return string
	 */
	private static function honeypot(): string {
		return sprintf(
			'<div class="wynko-form__hp" aria-hidden="true"><label>%1$s<input type="text" name="%2$s" value="" tabindex="-1" autocomplete="off" /></label></div>',
			esc_html__( 'Leave this field empty', 'wynko-for-laposta' ),
			esc_attr( FormSubmitHandler::HONEYPOT_FIELD )
		);
	}

	/**
	 * A note only an administrator sees: a misconfigured form must not leak
	 * into a visitor's page, but it must not be silent for whoever can fix it.
	 *
	 * @param string $message Untranslated-at-call-site notice text.
	 * @return string
	 */
	private static function admin_note( string $message ): string {
		return current_user_can( 'manage_options' ) ? sprintf( '<p>%s</p>', esc_html( $message ) ) : '';
	}

	/**
	 * The always-present email input.
	 *
	 * It posts as wynko_email rather than wynko_field[email], because Laposta
	 * takes the address as a top-level parameter. Everything else about it comes
	 * from its row like any other field's.
	 *
	 * @param FormData             $form   The form.
	 * @param array<string,mixed>  $field  Merged field definition.
	 * @param array<string,string> $errors Field key => message slug.
	 * @param string               $value  Value to redisplay.
	 * @param string               $mode   The form's label mode.
	 * @return string
	 */
	private static function email_field( FormData $form, array $field, array $errors, string $value, string $mode ): string {
		$id      = 'wynko-email-' . $form->id();
		$classes = trim( 'wynko-form__field wynko-form__field--email ' . (string) $field['css_class'] );
		$value   = '' !== $value ? $value : (string) ( $field['value'] ?? '' );

		return sprintf(
			'<p class="%1$s">%2$s%3$s<input type="email" id="%4$s" name="wynko_email" value="%5$s" required="required" autocomplete="email"%6$s%7$s%8$s />%9$s</p>',
			esc_attr( self::field_classes( $classes, $errors, 'email' ) ),
			self::label_for( $field, $id, $mode ),
			self::error_for( $form, $errors, 'email', $id ),
			esc_attr( $id ),
			esc_attr( $value ),
			self::placeholder_attr( $field, $mode ),
			isset( $errors['email'] ) ? ' aria-invalid="true"' : '',
			self::described_by( $field, $id, $errors, 'email' ),
			self::help_for( $field, $id )
		);
	}

	/**
	 * One custom field, shaped by its type.
	 *
	 * @param FormData             $form      The form.
	 * @param array<string,mixed>  $field     Merged field definition.
	 * @param array<string,string> $errors    Field key => message slug.
	 * @param array<string,mixed>  $submitted Values to redisplay.
	 * @param string               $mode      The form's label mode.
	 * @return string
	 */
	private static function field( FormData $form, array $field, array $errors, array $submitted, string $mode ): string {
		$key     = (string) $field['custom_name'];
		$id      = 'wynko-' . $form->id() . '-' . $key;
		$value   = $submitted[ $key ] ?? '';
		$classes = trim( 'wynko-form__field ' . (string) $field['css_class'] );

		if ( FieldData::TYPE_CHOICE === $field['type'] ) {
			$selected = is_array( $value ) ? $value : array( (string) $value );
			if ( array( '' ) === $selected ) {
				$selected = array( (string) ( $field['value'] ?? '' ) );
			}

			// The group carries what an input carries elsewhere. A radio or a
			// checkbox is one of several answers to one question, so marking
			// any single control invalid would misplace the problem — and
			// form.js focuses [aria-invalid="true"], which the fieldset can be.
			return sprintf(
				'<fieldset class="%1$s"%2$s%3$s><legend>%4$s</legend>%5$s%6$s%7$s</fieldset>',
				esc_attr( self::field_classes( $classes, $errors, $key ) ),
				isset( $errors[ $key ] ) ? ' aria-invalid="true"' : '',
				self::described_by( $field, $id, $errors, $key ),
				esc_html( (string) $field['label'] ),
				self::error_for( $form, $errors, $key, $id ),
				self::choices( $field, $key, $id, $selected ),
				self::help_for( $field, $id )
			);
		}

		$types = array(
			FieldData::TYPE_NUMBER => 'number',
			FieldData::TYPE_DATE   => 'date',
		);

		$type  = $types[ $field['type'] ] ?? 'text';
		$attrs = ( isset( $field['attrs'] ) && is_array( $field['attrs'] ) ) ? $field['attrs'] : array();

		// A range slider is a number input with a different control; the value
		// it posts and the way it is validated are identical. Per the HTML
		// standard it takes no required attribute — it always has a value — so
		// emitting one would be markup the browser ignores.
		$is_range = FieldData::TYPE_NUMBER === $field['type'] && 'range' === ( $attrs['style'] ?? '' );
		if ( $is_range ) {
			$type = 'range';
		}

		$value = is_scalar( $value ) ? (string) $value : '';
		$value = '' !== $value ? $value : (string) ( $field['value'] ?? '' );
		if ( $is_range && '' === $value ) {
			$value = FieldData::range_default( $attrs );
		}

		return sprintf(
			'<p class="%1$s">%2$s%3$s<input type="%4$s" id="%5$s" name="wynko_field[%6$s]" value="%7$s"%8$s%9$s%10$s%11$s%12$s /> %14$s%13$s</p>',
			esc_attr( self::field_classes( $classes, $errors, $key ) ),
			self::label_for( $field, $id, $mode ),
			self::error_for( $form, $errors, $key, $id, self::pattern_description( $field ) ),
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $key ),
			esc_attr( $value ),
			( ! empty( $field['required'] ) && ! $is_range ) ? ' required="required"' : '',
			self::placeholder_attr( $field, $mode ),
			self::attr_string( $field, $attrs ),
			isset( $errors[ $key ] ) ? ' aria-invalid="true"' : '',
			self::described_by( $field, $id, $errors, $key ),
			self::help_for( $field, $id ),
			$is_range ? self::range_output( $id, $value ) : ''
		);
	}

	/**
	 * The number a slider is currently on.
	 *
	 * A range gives a visitor no way to read the value it is posting, and the
	 * output element is what HTML has for exactly that, tied to the input by
	 * `for`. The server writes the starting number, and form.js follows the
	 * slider from there.
	 *
	 * @param string $id    Input's element id.
	 * @param string $value Value the input starts on.
	 * @return string
	 */
	private static function range_output( string $id, string $value ): string {
		return sprintf(
			'<output class="wynko-form__range-value" for="%s">%s</output>',
			esc_attr( $id ),
			esc_html( $value )
		);
	}

	/**
	 * One field's label element.
	 *
	 * In placeholder mode the label is still rendered and tied to the input, only
	 * hidden, because a placeholder is not an accessible name: it vanishes on
	 * focus and is not reliably announced. A field whose type takes no
	 * placeholder keeps a visible label whatever the form is set to.
	 *
	 * @param array<string,mixed> $field Merged field definition.
	 * @param string              $id    Input's element id.
	 * @param string              $mode  The form's label mode.
	 * @return string
	 */
	private static function label_for( array $field, string $id, string $mode ): string {
		$hidden = FieldData::label_is_decorative( $mode, (string) $field['type'], self::attrs( $field ) );

		return sprintf(
			'<label for="%s" class="%s">%s</label>',
			esc_attr( $id ),
			esc_attr( $hidden ? 'wynko-form__label screen-reader-text' : 'wynko-form__label' ),
			esc_html( (string) $field['label'] )
		);
	}

	/**
	 * The placeholder attribute, '' in plain label mode or on a type that takes
	 * none.
	 *
	 * @param array<string,mixed> $field Merged field definition.
	 * @param string              $mode  The form's label mode.
	 * @return string
	 */
	private static function placeholder_attr( array $field, string $mode ): string {
		$text = (string) ( $field['placeholder'] ?? '' );

		if ( FieldData::LABEL_MODE_LABEL === $mode || '' === $text ) {
			return '';
		}
		if ( ! FieldData::accepts_placeholder( (string) $field['type'], self::attrs( $field ) ) ) {
			return '';
		}
		return sprintf( ' placeholder="%s"', esc_attr( $text ) );
	}

	/**
	 * One field's narrowed attributes.
	 *
	 * @param array<string,mixed> $field Merged field definition.
	 * @return array<string,string>
	 */
	private static function attrs( array $field ): array {
		if ( ! isset( $field['attrs'] ) || ! is_array( $field['attrs'] ) ) {
			return array();
		}
		return array_map( 'strval', $field['attrs'] );
	}

	/**
	 * A field's help text: a toggle and the text it reveals, '' when it has
	 * none.
	 *
	 * The text is always in the DOM and referenced by the input's
	 * aria-describedby, so the button is decoration over content that is already
	 * there. The wrapper is what the tooltip is positioned against, so the bubble
	 * lands beneath the button that opened it.
	 *
	 * @param array<string,mixed> $field Merged field definition.
	 * @param string              $id    Input's element id.
	 * @return string
	 */
	private static function help_for( array $field, string $id ): string {
		$text = (string) ( $field['help'] ?? '' );
		if ( '' === $text ) {
			return '';
		}

		return sprintf(
			'<span class="wynko-form__help-wrap">'
			. '<button type="button" class="wynko-form__help-toggle" aria-expanded="false" aria-controls="%1$s-help">'
			. '<span aria-hidden="true">?</span><span class="screen-reader-text">%2$s</span></button>'
			. '<span class="wynko-form__help" id="%1$s-help" role="tooltip" hidden="hidden">%3$s</span>'
			. '</span>',
			esc_attr( $id ),
			esc_html__( 'More information', 'wynko-for-laposta' ),
			esc_html( $text )
		);
	}

	/**
	 * The aria-describedby attribute for one input, '' when it describes
	 * nothing.
	 *
	 * @param array<string,mixed>  $field  Merged field definition.
	 * @param string               $id     Input's element id.
	 * @param array<string,string> $errors Field key => message slug.
	 * @param string               $key    Field key.
	 * @return string
	 */
	private static function described_by( array $field, string $id, array $errors, string $key ): string {
		$ids = array();
		if ( '' !== (string) ( $field['help'] ?? '' ) ) {
			$ids[] = $id . '-help';
		}
		if ( isset( $errors[ $key ] ) ) {
			$ids[] = $id . '-error';
		}

		return array() === $ids ? '' : sprintf( ' aria-describedby="%s"', esc_attr( implode( ' ', $ids ) ) );
	}

	/**
	 * The configured presentation attributes as markup. Only the keys the
	 * field's own type declares reach this — Support\Fields narrows them — so a
	 * stored attribute cannot become an arbitrary one on the input.
	 *
	 * @param array<string,mixed>  $field Merged field definition.
	 * @param array<string,string> $attrs Narrowed attributes.
	 * @return string
	 */
	private static function attr_string( array $field, array $attrs ): string {
		$out = '';
		foreach ( $attrs as $name => $value ) {
			// Presentation only; the input already carries its type.
			if ( 'style' === $name ) {
				continue;
			}
			$out .= sprintf( ' %s="%s"', esc_attr( (string) $name ), esc_attr( (string) $value ) );
		}
		return $out;
	}

	/**
	 * A choice field's options: checkboxes when several may be picked, radios
	 * when one may.
	 *
	 * @param array<string,mixed> $field    Merged field definition.
	 * @param string              $key      custom_name.
	 * @param string              $id       Base element id.
	 * @param array<int,mixed>    $selected Values to redisplay.
	 * @return string
	 */
	private static function choices( array $field, string $key, string $id, array $selected ): string {
		$multiple = ! empty( $field['multiple'] );
		$name     = $multiple ? sprintf( 'wynko_field[%s][]', $key ) : sprintf( 'wynko_field[%s]', $key );
		$selected = array_map( 'strval', array_filter( $selected, 'is_scalar' ) );

		$html = '';
		foreach ( $field['options'] as $index => $option ) {
			$option_id = $id . '-' . (int) $index;
			$html     .= sprintf(
				'<label for="%1$s"><input type="%2$s" id="%1$s" name="%3$s" value="%4$s"%5$s /> %6$s</label>',
				esc_attr( $option_id ),
				$multiple ? 'checkbox' : 'radio',
				esc_attr( $name ),
				esc_attr( (string) $option ),
				in_array( (string) $option, $selected, true ) ? ' checked="checked"' : '',
				esc_html( (string) $option )
			);
		}
		return $html;
	}

	/**
	 * The terms checkbox. Never pre-checked, whatever was submitted: agreeing
	 * on someone's behalf is not ours to do.
	 *
	 * @param FormData             $form     The form.
	 * @param array<string,mixed>  $settings The form's settings.
	 * @param array<string,string> $errors   Field key => message slug.
	 * @return string
	 */
	private static function terms_field( FormData $form, array $settings, array $errors ): string {
		$id   = 'wynko-terms-' . $form->id();
		$text = '' !== $settings['terms_text'] ? (string) $settings['terms_text'] : __( 'I agree to the terms and conditions', 'wynko-for-laposta' );

		$label = esc_html( $text );
		$href  = self::terms_href( $settings );
		if ( '' !== $href ) {
			// The href is the admin's own, but its target and rel still come from
			// the registry — config/urls.php owns link targets, and form_terms is
			// registered there with an empty url exactly like campaign_web.
			$label = sprintf(
				'<a href="%s" target="%s" rel="%s">%s</a>',
				esc_url( $href ),
				esc_attr( Urls::target( 'form_terms' ) ),
				esc_attr( Urls::rel( 'form_terms' ) ),
				esc_html( $text )
			);
		}

		return sprintf(
			'<p class="%1$s">%2$s<label for="%3$s">'
			. '<input type="checkbox" id="%3$s" name="wynko_terms" value="1" required="required"%5$s%6$s /> %4$s</label></p>',
			esc_attr( self::field_classes( 'wynko-form__field wynko-form__field--terms', $errors, 'terms' ) ),
			self::error_for( $form, $errors, 'terms', $id ),
			esc_attr( $id ),
			$label,
			isset( $errors['terms'] ) ? ' aria-invalid="true"' : '',
			isset( $errors['terms'] ) ? sprintf( ' aria-describedby="%s-error"', esc_attr( $id ) ) : ''
		);
	}

	/**
	 * Where the terms text links, '' when it does not link at all. A chosen
	 * page is resolved at render time rather than stored as a URL, so the link
	 * follows the page if its permalink changes.
	 *
	 * @param array<string,mixed> $settings The form's settings.
	 * @return string
	 */
	private static function terms_href( array $settings ): string {
		$mode = (string) ( $settings['terms_link_type'] ?? '' );

		if ( 'page' === $mode ) {
			return (string) get_permalink( absint( $settings['terms_page_id'] ?? 0 ) );
		}
		if ( 'url' === $mode ) {
			return (string) $settings['terms_url'];
		}
		return '';
	}

	/**
	 * One field's error message, '' when it has none.
	 *
	 * It renders between the label and the control and in the flow, because a
	 * message that overlays hides the next field and one below the control pushes
	 * it away as the visitor types.
	 *
	 * The alert role announces it after an in-place submission, and the hidden
	 * prefix says what it is to a reader who gets no colour. A field whose
	 * pattern refused the answer is told in the administrator's own words.
	 *
	 * @param FormData             $form        The form.
	 * @param array<string,string> $errors      Field key => message slug.
	 * @param string               $key         Field key.
	 * @param string               $id          Input's element id.
	 * @param string               $description The field's pattern description, '' when it has none.
	 * @return string
	 */
	private static function error_for( FormData $form, array $errors, string $key, string $id, string $description = '' ): string {
		if ( ! isset( $errors[ $key ] ) ) {
			return '';
		}

		$slug    = (string) $errors[ $key ];
		$message = ( LapostaErrors::SLUG_PATTERN === $slug && '' !== $description )
			? $description
			: Messages::resolve( $form, $slug );

		return sprintf(
			'<span class="wynko-form__error" id="%s-error" role="alert"><span class="screen-reader-text">%s</span> %s</span>',
			esc_attr( $id ),
			esc_html__( 'Error:', 'wynko-for-laposta' ),
			esc_html( $message )
		);
	}

	/**
	 * What the administrator wrote about a field's pattern, '' when the field
	 * has no pattern or none was written. It is the same text the input carries
	 * as its title attribute.
	 *
	 * Custom fields only, since the email row is validated as an address rather
	 * than against a pattern.
	 *
	 * @param array<string,mixed> $field Merged field definition.
	 * @return string
	 */
	private static function pattern_description( array $field ): string {
		$attrs = self::attrs( $field );
		return '' === ( $attrs['pattern'] ?? '' ) ? '' : (string) ( $attrs['title'] ?? '' );
	}

	/**
	 * A field wrapper's classes, with the errored modifier when it has one, so
	 * a theme can style the whole row and not only the message.
	 *
	 * @param string               $classes Classes the field already carries.
	 * @param array<string,string> $errors  Field key => message slug.
	 * @param string               $key     Field key.
	 * @return string
	 */
	private static function field_classes( string $classes, array $errors, string $key ): string {
		return isset( $errors[ $key ] ) ? $classes . ' wynko-form__field--error' : $classes;
	}
}
