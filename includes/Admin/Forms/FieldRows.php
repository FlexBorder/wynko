<?php
/**
 * The signup form editor's field rows.
 *
 * @package Wynko
 */

namespace Wynko\Admin\Forms;

use Wynko\Forms\Button;
use Wynko\Support\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only place the editor's field table is written, called both on page load
 * and by the REST route that swaps rows when the bound list changes.
 *
 * Every field's type, options, and required flag come from Laposta and are shown
 * rather than chosen, because the plugin cannot create or retype a Laposta field.
 */
final class FieldRows {

	/**
	 * How many of a choice field's options are listed before the rest are
	 * summarized. Enough to recognize the field, short enough not to own the row.
	 */
	private const OPTION_PREVIEW = 4;

	/**
	 * The whole field table: the Laposta fields, then the signup button.
	 *
	 * @param array<int,array<string,mixed>>       $fields     Merged field definitions.
	 * @param array{label:string,css_class:string} $button     The stored signup button.
	 * @param string                               $label_mode The form's label mode.
	 * @return string
	 */
	public static function table( array $fields, array $button, string $label_mode = Fields::LABEL_MODE_BOTH ): string {
		$html  = '<table class="wp-list-table widefat striped wynko-table wynko-fields"><thead><tr>';
		$html .= sprintf( '<th scope="col" class="wynko-col-order">%s</th>', esc_html__( 'Order', 'wynko-for-laposta' ) );
		$html .= sprintf( '<th scope="col">%s</th>', esc_html__( 'Field', 'wynko-for-laposta' ) );
		$html .= sprintf( '<th scope="col">%s</th>', esc_html__( 'Type', 'wynko-for-laposta' ) );
		$html .= sprintf( '<th scope="col" class="wynko-col-shown">%s</th>', esc_html__( 'Shown', 'wynko-for-laposta' ) );
		$html .= sprintf( '<th scope="col" class="wynko-col-label">%s</th>', esc_html__( 'Label', 'wynko-for-laposta' ) );
		$html .= sprintf( '<th scope="col" class="wynko-col-label">%s</th>', esc_html__( 'Placeholder', 'wynko-for-laposta' ) );
		$html .= sprintf(
			'<th scope="col" class="wynko-col-panel"><span class="screen-reader-text">%s</span></th>',
			esc_html__( 'Options', 'wynko-for-laposta' )
		);
		$html .= '</tr></thead>';
		$html .= self::tbody( $fields, $label_mode );
		$html .= self::button_body( $button );
		$html .= '</table>';

		return $html;
	}

	/**
	 * The signup button's row, in a body of its own.
	 *
	 * Separate from the field body, which the REST route replaces wholesale when
	 * the bound list changes; the button belongs to the form rather than to any
	 * list. It stays last, so it carries no drag handle or move buttons.
	 *
	 * @param array{label:string,css_class:string} $button The stored signup button.
	 * @return string
	 */
	private static function button_body( array $button ): string {
		$html  = '<tbody class="wynko-fields__button"><tr class="wynko-row wynko-row--button">';
		$html .= '<td class="wynko-col-order"></td>';
		$html .= sprintf( '<td><strong>%s</strong></td>', esc_html__( 'Sign up button', 'wynko-for-laposta' ) );
		/* translators: A row's entry in the field table's Type column, beside Text, Number, and Date. */
		$html .= sprintf( '<td>%s</td>', esc_html__( 'Button', 'wynko-for-laposta' ) );
		$html .= '<td class="wynko-col-shown"></td>';
		$html .= sprintf(
			'<td class="wynko-col-label">'
			. '<input type="text" name="wynko_button[label]" value="%s" placeholder="%s" /></td>',
			esc_attr( $button['label'] ),
			esc_attr( Button::default_label() )
		);
		$html .= '<td class="wynko-col-label"></td>';
		$html .= sprintf(
			'<td class="wynko-col-panel">'
			. '<button type="button" class="wynko-panel-toggle" aria-expanded="false" aria-controls="wynko-panel-button">'
			. '<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>'
			. '<span class="screen-reader-text">%s</span></button></td>',
			esc_attr__( 'Options for the sign up button', 'wynko-for-laposta' )
		);
		$html .= '</tr>';

		$html .= '<tr class="wynko-row__panel" id="wynko-panel-button" hidden="hidden"><td colspan="7"><div class="wynko-panel">';
		$html .= '<div class="wynko-panel__group">';
		$html .= self::text_control( 'wynko-button', 'wynko_button', 'css_class', __( 'CSS class', 'wynko-for-laposta' ), $button['css_class'] );
		$html .= '</div></div></td></tr>';

		return $html . '</tbody>';
	}

	/**
	 * The table body: one row per field, the email address among them. It is a
	 * synthetic definition injected by FormData::fields(), so it sorts, labels,
	 * and classes exactly like a real one — only its required flag is fixed.
	 *
	 * @param array<int,array<string,mixed>> $fields     Merged field definitions.
	 * @param string                         $label_mode The form's label mode.
	 * @return string
	 */
	public static function tbody( array $fields, string $label_mode = Fields::LABEL_MODE_BOTH ): string {
		$html = '<tbody class="wynko-fields__body">';
		foreach ( array_values( $fields ) as $index => $field ) {
			$html .= self::row( (int) $index, $field, $label_mode );
		}
		return $html . '</tbody>';
	}

	/**
	 * A field's type in words. Laposta's five datatypes collapse to four plugin
	 * types, and a choice field splits again on whether several may be picked —
	 * which is exactly the distinction an admin cannot otherwise see, and the
	 * one FormValidator enforces on every submission.
	 *
	 * @param array<string,mixed> $field Merged field definition.
	 * @return string
	 */
	public static function type_label( array $field ): string {
		if ( Fields::TYPE_CHOICE === $field['type'] ) {
			return empty( $field['multiple'] )
				? __( 'Single choice', 'wynko-for-laposta' )
				: __( 'Multiple choice', 'wynko-for-laposta' );
		}

		$labels = array(
			Fields::TYPE_NUMBER => __( 'Number', 'wynko-for-laposta' ),
			Fields::TYPE_DATE   => __( 'Date (YYYY-MM-DD)', 'wynko-for-laposta' ),
		);

		return $labels[ $field['type'] ] ?? __( 'Text', 'wynko-for-laposta' );
	}

	/**
	 * One Laposta field's row.
	 *
	 * @param int                 $index      Row index, which is also its order.
	 * @param array<string,mixed> $field      Merged field definition.
	 * @param string              $label_mode The form's label mode.
	 * @return string
	 */
	private static function row( int $index, array $field, string $label_mode ): string {
		$name     = 'wynko_fields[' . $index . ']';
		$is_email = Fields::is_email( $field );
		$attrs    = self::attrs( $field );

		// The row carries whether its type can take a placeholder at all, so the
		// editor script can answer the same question the server just did without
		// reimplementing accepts_placeholder() in JavaScript.
		$html  = sprintf(
			'<tr class="wynko-row%s" data-wynko-placeholderable="%s">',
			$is_email ? ' wynko-row--email' : '',
			Fields::accepts_placeholder( (string) $field['type'], $attrs ) ? '1' : '0'
		);
		$html .= sprintf(
			'<td class="wynko-col-order">'
			. '<span class="wynko-handle dashicons dashicons-menu" aria-hidden="true"></span>'
			. '<button type="button" class="button-link wynko-move" data-direction="up" aria-label="%4$s">'
			. '<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>'
			. '<button type="button" class="button-link wynko-move" data-direction="down" aria-label="%5$s">'
			. '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>'
			. '<input type="hidden" class="wynko-order" name="%1$s[order]" value="%2$d" />'
			. '<input type="hidden" name="%1$s[field_id]" value="%3$s" />'
			. '</td>',
			esc_attr( $name ),
			$index,
			esc_attr( (string) $field['field_id'] ),
			esc_attr(
				sprintf(
					/* translators: %s: field name. */
					__( 'Move %s up', 'wynko-for-laposta' ),
					(string) $field['name']
				)
			),
			esc_attr(
				sprintf(
					/* translators: %s: field name. */
					__( 'Move %s down', 'wynko-for-laposta' ),
					(string) $field['name']
				)
			)
		);

		$html .= sprintf(
			'<td><strong>%s</strong><br /><code>%s</code></td>',
			esc_html( (string) $field['name'] ),
			esc_html( (string) $field['custom_name'] )
		);

		$type  = $is_email ? __( 'Email', 'wynko-for-laposta' ) : self::type_label( $field );
		$html .= sprintf( '<td>%s%s</td>', esc_html( $type ), self::options_note( $field ) );

		if ( ! empty( $field['required'] ) ) {
			// A required field cannot be hidden, so it submits its visibility
			// rather than offering a control that would have to be ignored.
			// The tooltip says whose rule this is: the badge otherwise reads as
			// something this screen decided and will not let you undo.
			$html .= sprintf(
				'<td><input type="hidden" name="%s[visible]" value="1" />'
				. '<span class="wynko-badge wynko-badge--required" title="%s">%s</span></td>',
				esc_attr( $name ),
				esc_attr__( 'This field is set as required in Laposta, so the form always shows it and always asks for it. To change that, edit the field in Laposta.', 'wynko-for-laposta' ),
				esc_html__( 'Required', 'wynko-for-laposta' )
			);
		} else {
			$html .= sprintf(
				'<td><input type="checkbox" name="%s[visible]" value="1"%s /></td>',
				esc_attr( $name ),
				checked( ! empty( $field['visible'] ), true, false )
			);
		}

		// Read-only, never disabled — the mirror of placeholder_cell()'s reason:
		// a disabled input posts nothing, so the next save would blank every
		// label on the form.
		$decorative = Fields::label_is_decorative( $label_mode, (string) $field['type'], $attrs );
		$html      .= sprintf(
			'<td class="wynko-col-label"><input type="text" class="wynko-label" name="%s[label]" value="%s"%s /></td>',
			esc_attr( $name ),
			esc_attr( (string) $field['label'] ),
			$decorative ? sprintf( ' readonly="readonly" title="%s"', esc_attr__( 'This form shows placeholders instead of labels, so this label is not displayed.', 'wynko-for-laposta' ) ) : ''
		);

		$html .= self::placeholder_cell( $name, $field, $attrs, $label_mode );

		$html .= sprintf(
			'<td class="wynko-col-panel">'
			. '<button type="button" class="wynko-panel-toggle" aria-expanded="false" aria-controls="wynko-panel-%1$d">'
			. '<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>'
			. '<span class="screen-reader-text">%2$s</span></button></td>',
			$index,
			esc_attr(
				sprintf(
					/* translators: %s: field name. */
					__( 'Options for %s', 'wynko-for-laposta' ),
					(string) $field['name']
				)
			)
		);

		return $html . '</tr>' . self::panel( $index, $name, $field );
	}

	/**
	 * A field's narrowed presentation attributes.
	 *
	 * @param array<string,mixed> $field Merged field definition.
	 * @return array<string,string>
	 */
	private static function attrs( array $field ): array {
		return ( isset( $field['attrs'] ) && is_array( $field['attrs'] ) ) ? array_map( 'strval', $field['attrs'] ) : array();
	}

	/**
	 * A field's placeholder, beside its label rather than folded away.
	 *
	 * On a form set to show placeholders it is the only text the visitor reads,
	 * so it belongs where the label is. A type that takes no placeholder says so
	 * rather than offering a box that saves and does nothing.
	 *
	 * Where the label mode renders no placeholder, the box is read-only rather
	 * than absent or disabled: either of those would blank what is stored on the
	 * next save.
	 *
	 * @param string               $name       Input name prefix for this row.
	 * @param array<string,mixed>  $field      Merged field definition.
	 * @param array<string,string> $attrs      The field's narrowed attributes.
	 * @param string               $label_mode The form's label mode.
	 * @return string
	 */
	private static function placeholder_cell( string $name, array $field, array $attrs, string $label_mode ): string {
		if ( ! Fields::accepts_placeholder( (string) $field['type'], $attrs ) ) {
			return sprintf(
				'<td class="wynko-col-label"><span aria-hidden="true">&mdash;</span>'
				. '<span class="screen-reader-text">%s</span></td>',
				esc_html__( 'This type of field takes no placeholder.', 'wynko-for-laposta' )
			);
		}

		$placeholder = (string) ( $field['placeholder'] ?? '' );

		$shown = Fields::LABEL_MODE_LABEL !== $label_mode;

		return sprintf(
			'<td class="wynko-col-label"><input type="text" class="wynko-placeholder" name="%s[placeholder]" value="%s"%s /></td>',
			esc_attr( $name ),
			esc_attr( $placeholder === (string) $field['label'] ? '' : $placeholder ),
			$shown ? '' : sprintf( ' readonly="readonly" title="%s"', esc_attr__( 'Set "Field labels" above to show a placeholder before typing one.', 'wynko-for-laposta' ) )
		);
	}

	/**
	 * A field's folded options: the content every type carries, then whatever
	 * constraints its own type declares.
	 *
	 * Folded because spelling all of it out inline made a row six controls wide;
	 * closed, the table is one line per field.
	 *
	 * @param int                 $index Row index.
	 * @param string              $name  Input name prefix for this row.
	 * @param array<string,mixed> $field Merged field definition.
	 * @return string
	 */
	private static function panel( int $index, string $name, array $field ): string {
		$prefix = 'wynko-field-' . $index;

		$html = sprintf(
			'<tr class="wynko-row__panel" id="wynko-panel-%d" hidden="hidden"><td colspan="7"><div class="wynko-panel">',
			$index
		);

		$html .= self::content_controls( $prefix, $name, $field );
		$html .= self::attr_controls( $prefix, $name, $field );

		return $html . '</div></td></tr>';
	}

	/**
	 * What every control in a panel means, so the panel can be read without
	 * knowing the plugin. Keyed by the row key or the attribute the control
	 * writes; the two vocabularies do not overlap.
	 *
	 * @return array<string,string>
	 */
	private static function hints(): array {
		return array(
			'help'         => __( 'A sentence explaining what to enter. It appears behind a (?) beside the field.', 'wynko-for-laposta' ),
			'value'        => __( 'Filled in for the visitor, who can still change it. A value Laposta already holds is shown here and keeps following Laposta unless you type something else; empty the box to go back to it.', 'wynko-for-laposta' ),
			'css_class'    => __( 'Added to this field on the rendered form, for your theme or your own CSS to style.', 'wynko-for-laposta' ),
			'min'          => __( 'The lowest value the form accepts.', 'wynko-for-laposta' ),
			'max'          => __( 'The highest value the form accepts.', 'wynko-for-laposta' ),
			'step'         => __( 'How far apart the accepted values are — 5 accepts 0, 5, 10, and refuses 7.', 'wynko-for-laposta' ),
			'minlength'    => __( 'How many characters the answer must have at least.', 'wynko-for-laposta' ),
			'maxlength'    => __( 'How many characters the field takes. Typing stops there.', 'wynko-for-laposta' ),
			'pattern'      => __( 'A regular expression the answer must match, without slashes — [0-9]{4} takes four digits and nothing else.', 'wynko-for-laposta' ),
			'title'        => __( 'What the visitor is told when the pattern refuses their answer. Describe the pattern, do not repeat it.', 'wynko-for-laposta' ),
			'autocomplete' => __( 'Lets the browser offer what it already knows about the visitor. Choose what this field holds.', 'wynko-for-laposta' ),
			'style'        => __( 'Shows the number as a slider between its lowest and highest value instead of a box.', 'wynko-for-laposta' ),
		);
	}

	/**
	 * A control's (?) and the text behind it, '' when the key has none.
	 *
	 * The text is in the page either way and the control points at it through
	 * aria-describedby, so it is read out whether or not the tooltip is open.
	 *
	 * @param string $id  The control's element id.
	 * @param string $key Row key or attribute name.
	 * @return string
	 */
	private static function hint( string $id, string $key ): string {
		$text = self::hints()[ $key ] ?? '';
		if ( '' === $text ) {
			return '';
		}

		return sprintf(
			'<span class="wynko-hint">'
			. '<button type="button" class="wynko-hint__toggle" aria-expanded="false" aria-controls="%1$s-hint">'
			. '<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>'
			. '<span class="screen-reader-text">%2$s</span></button>'
			. '<span class="wynko-hint__text" id="%1$s-hint" role="tooltip" hidden="hidden">%3$s</span></span>',
			esc_attr( $id ),
			esc_html__( 'What is this?', 'wynko-for-laposta' ),
			esc_html( $text )
		);
	}

	/**
	 * One control: its label, its (?), and the input itself.
	 *
	 * The label is an element beside the input rather than a wrapper around it,
	 * because the (?) belongs beside the label and a button inside a label
	 * would hand its clicks to the input.
	 *
	 * @param string $id    The control's element id.
	 * @param string $key   Row key or attribute name.
	 * @param string $label Visible label.
	 * @param string $input The input markup, already escaped.
	 * @return string
	 */
	private static function control( string $id, string $key, string $label, string $input ): string {
		return sprintf(
			'<div class="wynko-panel__control"><span class="wynko-panel__label">'
			. '<label for="%s">%s</label>%s</span>%s</div>',
			esc_attr( $id ),
			esc_html( $label ),
			self::hint( $id, $key ),
			$input
		);
	}

	/**
	 * The aria-describedby attribute pointing a control at its own hint, '' for
	 * a key that carries none.
	 *
	 * @param string $id  The control's element id.
	 * @param string $key Row key or attribute name.
	 * @return string
	 */
	private static function described_by( string $id, string $key ): string {
		return isset( self::hints()[ $key ] ) ? sprintf( ' aria-describedby="%s-hint"', esc_attr( $id ) ) : '';
	}

	/**
	 * The options a field carries whatever its type is.
	 *
	 * @param string              $prefix Element id prefix for this row.
	 * @param string              $name   Input name prefix for this row.
	 * @param array<string,mixed> $field  Merged field definition.
	 * @return string
	 */
	private static function content_controls( string $prefix, string $name, array $field ): string {
		$html = sprintf(
			'<fieldset class="wynko-panel__group"><legend class="wynko-panel__legend">%s</legend><div class="wynko-panel__grid">',
			esc_html__( 'What the visitor sees', 'wynko-for-laposta' )
		);

		$html .= self::text_control( $prefix, $name, 'help', __( 'Help text', 'wynko-for-laposta' ), (string) ( $field['help'] ?? '' ) );

		if ( Fields::accepts_default_value( (string) $field['type'] ) ) {
			$html .= self::default_value_control( $prefix, $name, $field );
		}

		$html .= self::text_control( $prefix, $name, 'css_class', __( 'CSS class', 'wynko-for-laposta' ), (string) $field['css_class'] );

		return $html . '</div></fieldset>';
	}

	/**
	 * What the field is filled in with.
	 *
	 * A number's box carries the field's own min, max, and step, so a default
	 * outside them is refused while it is being typed; that is a convenience, and
	 * FormEditPage refuses the same value on save.
	 *
	 * A value Laposta already holds is shown rather than hinted, and does not
	 * become an override by being shown: clean_default_value() drops a submitted
	 * value that still equals Laposta's.
	 *
	 * @param string              $prefix Element id prefix for this row.
	 * @param string              $name   Input name prefix for this row.
	 * @param array<string,mixed> $field  Merged field definition.
	 * @return string
	 */
	private static function default_value_control( string $prefix, string $name, array $field ): string {
		$id    = $prefix . '-value';
		$attrs = ( isset( $field['attrs'] ) && is_array( $field['attrs'] ) ) ? array_map( 'strval', $field['attrs'] ) : array();
		// Already resolved by Support\Fields::apply(): the admin's own value, or
		// Laposta's when there is none.
		$laposta = (string) ( $field['default'] ?? '' );
		$value   = (string) ( $field['value'] ?? '' );

		$bounds = '';
		$type   = 'text';
		if ( Fields::TYPE_NUMBER === $field['type'] ) {
			$type = 'number';
			foreach ( array( 'min', 'max', 'step' ) as $key ) {
				if ( '' !== ( $attrs[ $key ] ?? '' ) ) {
					$bounds .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $attrs[ $key ] ) );
				}
			}
		}

		return self::control(
			$id,
			'value',
			__( 'Default value', 'wynko-for-laposta' ),
			sprintf(
				'<input type="%s" id="%s" name="%s[value]" value="%s" placeholder="%s"%s%s />',
				esc_attr( $type ),
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $value ),
				esc_attr( $laposta ),
				$bounds,
				self::described_by( $id, 'value' )
			)
		);
	}

	/**
	 * One labelled text input inside a panel.
	 *
	 * @param string $prefix Element id prefix for this row.
	 * @param string $name   Input name prefix for this row.
	 * @param string $key    Row key.
	 * @param string $label  Visible label.
	 * @param string $value  Current value.
	 * @return string
	 */
	private static function text_control( string $prefix, string $name, string $key, string $label, string $value ): string {
		$id = $prefix . '-' . $key;

		return self::control(
			$id,
			$key,
			$label,
			sprintf(
				'<input type="text" id="%s" name="%s[%s]" value="%s"%s />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $key ),
				esc_attr( $value ),
				self::described_by( $id, $key )
			)
		);
	}

	/**
	 * The presentation options a field's type accepts, if any. These bounds are
	 * the site's rather than Laposta's, so they can refuse a value Laposta would
	 * have taken; FormValidator enforces every one server-side.
	 *
	 * @param string              $prefix Element id prefix for this row.
	 * @param string              $name   Input name prefix for this row.
	 * @param array<string,mixed> $field  Merged field definition.
	 * @return string
	 */
	private static function attr_controls( string $prefix, string $name, array $field ): string {
		$keys = Fields::attrs_for_type( (string) $field['type'] );
		if ( array() === $keys ) {
			return '';
		}

		$attrs  = ( isset( $field['attrs'] ) && is_array( $field['attrs'] ) ) ? $field['attrs'] : array();
		$labels = array(
			'min'          => __( 'Min', 'wynko-for-laposta' ),
			'max'          => __( 'Max', 'wynko-for-laposta' ),
			'step'         => __( 'Step', 'wynko-for-laposta' ),
			'minlength'    => __( 'Min length', 'wynko-for-laposta' ),
			'maxlength'    => __( 'Max length', 'wynko-for-laposta' ),
			'pattern'      => __( 'Pattern', 'wynko-for-laposta' ),
			'title'        => __( 'Pattern description', 'wynko-for-laposta' ),
			'autocomplete' => __( 'Autofill', 'wynko-for-laposta' ),
		);

		$html = sprintf(
			'<fieldset class="wynko-panel__group wynko-attrs">'
			. '<legend class="wynko-panel__legend">%s</legend><div class="wynko-panel__grid">',
			esc_html__( 'What the form accepts', 'wynko-for-laposta' )
		);

		foreach ( $keys as $key ) {
			$value = (string) ( $attrs[ $key ] ?? '' );
			$id    = $prefix . '-attr-' . $key;

			if ( 'style' === $key ) {
				// The same two-part shape as every other control — a heading row
				// over the input — so the grid lines it up by itself rather than
				// by a measured padding. The heading is a span, not a second
				// label: the checkbox's own label is the word beside it.
				$html .= sprintf(
					'<div class="wynko-panel__control">'
					. '<span class="wynko-panel__label">%1$s%2$s</span>'
					. '<label class="wynko-panel__check" for="%3$s">'
					. '<input type="checkbox" id="%3$s" name="%4$s[attrs][style]" value="range"%5$s%6$s /> %7$s</label></div>',
					esc_html__( 'Shown as', 'wynko-for-laposta' ),
					self::hint( $id, $key ),
					esc_attr( $id ),
					esc_attr( $name ),
					checked( 'range', $value, false ),
					self::described_by( $id, $key ),
					esc_html__( 'Slider', 'wynko-for-laposta' )
				);
				continue;
			}

			if ( 'autocomplete' === $key ) {
				$html .= self::autocomplete_control( $id, $name, $labels[ $key ], $value );
				continue;
			}

			// Lengths count characters, so they are numbers whatever the field
			// is; a pattern and its description are free text; the rest follow
			// the field's own type.
			$is_text = in_array( $key, array( 'pattern', 'title' ), true );
			$type    = ( Fields::TYPE_DATE === $field['type'] && in_array( $key, array( 'min', 'max' ), true ) ) ? 'date' : 'number';

			$html .= self::control(
				$id,
				$key,
				$labels[ $key ] ?? $key,
				sprintf(
					'<input type="%s" id="%s" name="%s[attrs][%s]" value="%s"%s />',
					esc_attr( $is_text ? 'text' : $type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $key ),
					esc_attr( $value ),
					self::described_by( $id, $key )
				)
			);
		}
		return $html . '</div></fieldset>';
	}

	/**
	 * The autofill token a browser may use to fill the field.
	 *
	 * An allowlist rather than a free text box: the standard's token vocabulary
	 * is long, most of it is meaningless on a signup form, and a typo produces
	 * an attribute the browser silently ignores.
	 *
	 * @param string $id    The control's element id.
	 * @param string $name  Input name prefix for this row.
	 * @param string $label Visible label.
	 * @param string $value Current token.
	 * @return string
	 */
	private static function autocomplete_control( string $id, string $name, string $label, string $value ): string {
		$tokens = array(
			''               => __( 'Not set', 'wynko-for-laposta' ),
			'off'            => __( 'Never autofill', 'wynko-for-laposta' ),
			'name'           => __( 'Full name', 'wynko-for-laposta' ),
			'given-name'     => __( 'First name', 'wynko-for-laposta' ),
			'family-name'    => __( 'Last name', 'wynko-for-laposta' ),
			'email'          => __( 'Email address', 'wynko-for-laposta' ),
			'tel'            => __( 'Telephone', 'wynko-for-laposta' ),
			'organization'   => __( 'Company', 'wynko-for-laposta' ),
			'street-address' => __( 'Street address', 'wynko-for-laposta' ),
			'postal-code'    => __( 'Postcode', 'wynko-for-laposta' ),
			'country-name'   => __( 'Country', 'wynko-for-laposta' ),
			'bday'           => __( 'Date of birth', 'wynko-for-laposta' ),
		);

		$options = '';
		foreach ( $tokens as $token => $text ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $token ),
				selected( $token, $value, false ),
				esc_html( $text )
			);
		}

		return self::control(
			$id,
			'autocomplete',
			$label,
			sprintf(
				'<select id="%s" name="%s[attrs][autocomplete]"%s>%s</select>',
				esc_attr( $id ),
				esc_attr( $name ),
				self::described_by( $id, 'autocomplete' ),
				$options
			)
		);
	}

	/**
	 * A choice field's accepted values, so an admin can see what the form will
	 * and will not take. '' for every other type.
	 *
	 * @param array<string,mixed> $field Merged field definition.
	 * @return string
	 */
	private static function options_note( array $field ): string {
		if ( Fields::TYPE_CHOICE !== $field['type'] || array() === $field['options'] ) {
			return '';
		}

		$options = array_map( 'strval', (array) $field['options'] );
		$shown   = array_slice( $options, 0, self::OPTION_PREVIEW );
		$note    = implode( ', ', $shown );

		$extra = count( $options ) - count( $shown );
		if ( $extra > 0 ) {
			$note .= ', ' . sprintf(
				/* translators: %d: how many further options the field has. */
				_n( '+%d more', '+%d more', $extra, 'wynko-for-laposta' ),
				$extra
			);
		}

		return sprintf( '<br /><span class="description">%s</span>', esc_html( $note ) );
	}
}
