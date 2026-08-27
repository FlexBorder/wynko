<?php
/**
 * Field-definition logic.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-free field logic: normalize the /field response and layer a
 * form's stored overrides onto it. Laposta's five datatypes collapse to four
 * plugin types because the editor and the renderer treat select_single and
 * select_multiple identically apart from the multiple flag.
 */
final class Fields {

	const TYPE_TEXT   = 'text';
	const TYPE_NUMBER = 'number';
	const TYPE_DATE   = 'date';
	const TYPE_CHOICE = 'choice';

	/** How a field's name is shown: a label, a label and a placeholder, or a placeholder alone. */
	const LABEL_MODE_LABEL       = 'label';
	const LABEL_MODE_BOTH        = 'both';
	const LABEL_MODE_PLACEHOLDER = 'placeholder';

	/** The reserved field id of the email address, which is not a Laposta custom field. */
	const EMAIL_FIELD_ID = 'email';

	/** Why a field fetch produced nothing. Worded by the caller. */
	const FETCH_OK          = '';
	const FETCH_GONE        = 'gone';
	const FETCH_NO_KEY      = 'no_key';
	const FETCH_UNREACHABLE = 'unreachable';

	/** Why a default value was refused. Worded by the caller. */
	const DEFAULT_NOT_A_NUMBER = 'default_not_a_number';
	const DEFAULT_ABOVE_MAX    = 'default_above_max';
	const DEFAULT_BELOW_MIN    = 'default_below_min';

	/**
	 * The constraint attributes an override may carry, per plugin type, taken
	 * from the HTML standard's per-input-type table. Any other key is dropped,
	 * which is what stops a crafted override reaching the rendered input as an
	 * arbitrary HTML attribute.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const ATTRS = array(
		self::TYPE_NUMBER => array( 'min', 'max', 'step', 'style', 'autocomplete' ),
		self::TYPE_DATE   => array( 'min', 'max', 'autocomplete' ),
		self::TYPE_TEXT   => array( 'minlength', 'maxlength', 'pattern', 'title', 'autocomplete' ),
	);

	/**
	 * The keys a row carries whatever the field's type is.
	 *
	 * @var array<int,string>
	 */
	private const CONTENT = array( 'label', 'css_class', 'placeholder', 'help', 'value' );

	/**
	 * Laposta datatypes that describe something other than an input, and are
	 * dropped on import rather than degraded to a text box.
	 *
	 * `labels` is Laposta's own tagging of a subscriber: it arrives in /field
	 * looking like any other field, but there is nothing for a visitor to fill
	 * in. The datatype is the test, not the name.
	 *
	 * @var array<int,string>
	 */
	private const NON_INPUT_DATATYPES = array( 'labels' );

	/**
	 * Laposta datatype => plugin type.
	 *
	 * @var array<string,string>
	 */
	private const DATATYPES = array(
		'text'            => self::TYPE_TEXT,
		'numeric'         => self::TYPE_NUMBER,
		'date'            => self::TYPE_DATE,
		'select_single'   => self::TYPE_CHOICE,
		'select_multiple' => self::TYPE_CHOICE,
	);

	/**
	 * The plugin's field types.
	 *
	 * @return array<int,string>
	 */
	public static function types(): array {
		return array( self::TYPE_TEXT, self::TYPE_NUMBER, self::TYPE_DATE, self::TYPE_CHOICE );
	}

	/**
	 * The three label display modes.
	 *
	 * @return array<int,string>
	 */
	public static function label_modes(): array {
		return array( self::LABEL_MODE_LABEL, self::LABEL_MODE_BOTH, self::LABEL_MODE_PLACEHOLDER );
	}

	/**
	 * Which presentation attributes a type accepts.
	 *
	 * @param string $type One of self::TYPE_* .
	 * @return array<int,string>
	 */
	public static function attrs_for_type( string $type ): array {
		return self::ATTRS[ $type ] ?? array();
	}

	/**
	 * Reduces one stored or submitted override row to the exact shape the rest
	 * of the plugin relies on.
	 *
	 * The single definition of that shape, called on read and on write, so a key
	 * added to one cannot silently go missing from the other.
	 *
	 * @param mixed $row Candidate row.
	 * @return array{field_id:string,visible:bool,label:string,css_class:string,placeholder:string,help:string,value:string,attrs:array<string,string>}|null Null when the row has no usable field id.
	 */
	public static function normalize_override( $row ): ?array {
		if ( ! is_array( $row ) || empty( $row['field_id'] ) || ! is_scalar( $row['field_id'] ) ) {
			return null;
		}

		$out = array(
			'field_id' => (string) $row['field_id'],
			'visible'  => ! empty( $row['visible'] ),
		);

		foreach ( self::CONTENT as $key ) {
			$out[ $key ] = self::text( $row[ $key ] ?? '' );
		}

		$out['attrs'] = self::clean_attrs( $row['attrs'] ?? array() );

		/**
		 * Typed to match the method's return annotation.
		 *
		 * @var array{field_id:string,visible:bool,label:string,css_class:string,placeholder:string,help:string,value:string,attrs:array<string,string>} $out
		 */
		return $out;
	}

	/**
	 * Keeps only the attribute keys some type actually declares, so a crafted
	 * override cannot become an arbitrary attribute on the rendered input.
	 * Values are kept as strings; FormValidator is what interprets them.
	 *
	 * @param mixed $attrs Candidate attributes.
	 * @return array<string,string>
	 */
	private static function clean_attrs( $attrs ): array {
		if ( ! is_array( $attrs ) ) {
			return array();
		}

		$allowed = array();
		foreach ( self::ATTRS as $keys ) {
			foreach ( $keys as $key ) {
				$allowed[ $key ] = true;
			}
		}

		$clean = array();
		foreach ( $attrs as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $allowed[ $key ] ) || ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}
			$clean[ $key ] = (string) $value;
		}
		return $clean;
	}

	/**
	 * Casts to a string without warning on a non-scalar.
	 *
	 * @param mixed $value Candidate value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Identified, addressable fields only. An unknown datatype degrades to a text
	 * input rather than dropping the field, except for the datatypes named in
	 * NON_INPUT_DATATYPES, which are known not to be questions.
	 *
	 * @param array<string,mixed> $decoded Decoded API response.
	 * @return array<int,array{field_id:string,name:string,custom_name:string,type:string,required:bool,multiple:bool,options:array<int,string>,default:string}>
	 */
	public static function normalize( array $decoded ): array {
		$rows = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		$out  = array();
		foreach ( $rows as $row ) {
			// Laposta wraps each item as { field: {...} }; tolerate a flat shape too.
			$f = ( is_array( $row ) && isset( $row['field'] ) && is_array( $row['field'] ) ) ? $row['field'] : $row;
			if ( ! is_array( $f ) || empty( $f['field_id'] ) || empty( $f['custom_name'] ) ) {
				continue;
			}

			$datatype = isset( $f['datatype'] ) ? (string) $f['datatype'] : '';
			if ( in_array( $datatype, self::NON_INPUT_DATATYPES, true ) ) {
				continue;
			}

			$options = ( isset( $f['options'] ) && is_array( $f['options'] ) ) ? array_values( array_map( 'strval', $f['options'] ) ) : array();

			$out[] = array(
				'field_id'    => (string) $f['field_id'],
				'name'        => isset( $f['name'] ) ? (string) $f['name'] : (string) $f['custom_name'],
				'custom_name' => (string) $f['custom_name'],
				'type'        => self::DATATYPES[ $datatype ] ?? self::TYPE_TEXT,
				'required'    => ! empty( $f['required'] ),
				'multiple'    => 'select_multiple' === $datatype,
				'options'     => $options,
				'default'     => self::text( $f['defaultvalue'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * The synthetic definition of the email address.
	 *
	 * Email is not a Laposta custom field, so the API never describes it and it
	 * has no row an admin could label, class or move; a definition of the same
	 * shape as the real ones buys all of that from existing code.
	 *
	 * Always required and always visible, because Laposta identifies a subscriber
	 * by it.
	 *
	 * @param string $name Localized display name, supplied by the caller so
	 *                     this class stays free of translation calls.
	 * @return array<string,mixed>
	 */
	public static function email_definition( string $name ): array {
		return array(
			'field_id'    => self::EMAIL_FIELD_ID,
			'name'        => $name,
			'custom_name' => self::EMAIL_FIELD_ID,
			'type'        => self::TYPE_TEXT,
			'required'    => true,
			'multiple'    => false,
			'options'     => array(),
			'default'     => '',
		);
	}

	/**
	 * Whether a merged field is the synthetic email row.
	 *
	 * @param array<string,mixed> $field Merged field definition.
	 * @return bool
	 */
	public static function is_email( array $field ): bool {
		return self::EMAIL_FIELD_ID === ( $field['field_id'] ?? '' );
	}

	/**
	 * Layers a form's stored order, visibility, label, and CSS class onto the
	 * live definitions. The required flag is always the live one: a field
	 * Laposta made required is locked visible here, on every render, so a
	 * change on their side needs no re-save on ours.
	 *
	 * @param array<int,array<string,mixed>> $defs      Output of normalize().
	 * @param array<int,array<string,mixed>> $overrides Stored _wynko_fields rows.
	 * @return array<int,array{field_id:string,name:string,custom_name:string,type:string,required:bool,multiple:bool,options:array<int,string>,default:string,visible:bool,label:string,placeholder:string,css_class:string,help:string,value:string,attrs:array<string,string>}>
	 */
	public static function merge_overrides( array $defs, array $overrides ): array {
		$by_id = array();
		foreach ( $defs as $def ) {
			$by_id[ $def['field_id'] ] = $def;
		}

		$out  = array();
		$seen = array();
		foreach ( $overrides as $override ) {
			$id = isset( $override['field_id'] ) ? (string) $override['field_id'] : '';
			if ( '' === $id || ! isset( $by_id[ $id ] ) || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = self::apply( $by_id[ $id ], $override );
		}

		foreach ( $defs as $def ) {
			if ( ! isset( $seen[ $def['field_id'] ] ) ) {
				$out[] = self::apply( $def, array() );
			}
		}

		return $out;
	}

	/**
	 * Combines one definition with its override, filling the defaults an
	 * unedited field carries.
	 *
	 * @param array<string,mixed> $def      Normalized definition.
	 * @param array<string,mixed> $override Stored row, or empty.
	 * @return array<string,mixed>
	 */
	private static function apply( array $def, array $override ): array {
		$label = isset( $override['label'] ) ? (string) $override['label'] : '';
		$label = '' !== $label ? $label : (string) $def['name'];

		// An empty placeholder falls back to the label, so a form set to show
		// placeholders is one setting rather than one setting and some typing.
		$placeholder = isset( $override['placeholder'] ) ? (string) $override['placeholder'] : '';
		$placeholder = '' !== $placeholder ? $placeholder : $label;

		$attrs = isset( $override['attrs'] ) && is_array( $override['attrs'] ) ? $override['attrs'] : array();

		// What the field is filled in with: what the admin typed, else what
		// Laposta already holds. A type that takes no default resolves to
		// nothing, so removing the control also removes the value it used to
		// store — otherwise a value typed while the field was text would keep
		// prefilling it after Laposta retyped it.
		$value = isset( $override['value'] ) ? (string) $override['value'] : '';
		$value = '' !== $value ? $value : (string) ( $def['default'] ?? '' );
		$value = self::accepts_default_value( (string) $def['type'] ) ? $value : '';

		return array_merge(
			$def,
			array(
				'visible'     => $def['required'] ? true : ( array_key_exists( 'visible', $override ) ? (bool) $override['visible'] : true ),
				'label'       => $label,
				'placeholder' => $placeholder,
				'css_class'   => isset( $override['css_class'] ) ? (string) $override['css_class'] : '',
				'help'        => isset( $override['help'] ) ? (string) $override['help'] : '',
				'value'       => $value,
				// Narrowed to what this field's own type declares: a min/max
				// stored while the field was a number must not survive Laposta
				// retyping it to text.
				'attrs'       => array_intersect_key( $attrs, array_flip( self::attrs_for_type( (string) $def['type'] ) ) ),
			)
		);
	}

	/**
	 * A pattern attribute as an anchored PCRE, null when it does not compile.
	 *
	 * HTML's pattern is an ECMAScript regex and PCRE is a different grammar, so a
	 * construct valid in one can be invalid in the other. The editor compiles at
	 * save time, so preg_match() is never handed an unusable pattern during a
	 * submission.
	 *
	 * @param string $pattern Administrator-supplied pattern.
	 * @return string|null
	 */
	public static function compile_pattern( string $pattern ): ?string {
		if ( '' === $pattern ) {
			return null;
		}

		$compiled = '/\A(?:' . str_replace( '/', '\/', $pattern ) . ')\z/u';

		// The suppression is the point: this asks "does this compile?", and the
		// answer is the return value, not a warning in the log.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Compile test; failure is reported by the null return.
		return false === @preg_match( $compiled, '' ) ? null : $compiled;
	}

	/**
	 * Whether a value satisfies a pattern. A pattern that does not compile
	 * matches nothing, so a broken pattern refuses rather than waves through.
	 *
	 * @param string $pattern Administrator-supplied pattern.
	 * @param string $value   Submitted value.
	 * @return bool
	 */
	public static function pattern_matches( string $pattern, string $value ): bool {
		$compiled = self::compile_pattern( $pattern );
		return null !== $compiled && 1 === preg_match( $compiled, $value );
	}

	/**
	 * Whether a type's input accepts a placeholder attribute at all.
	 *
	 * Per the HTML standard, date, checkbox/radio and a number rendered as a
	 * range slider take none. Emitting one there is a control that saves and then
	 * does nothing.
	 *
	 * @param string               $type  One of self::TYPE_* .
	 * @param array<string,string> $attrs The field's narrowed attributes.
	 * @return bool
	 */
	public static function accepts_placeholder( string $type, array $attrs ): bool {
		if ( self::TYPE_NUMBER === $type ) {
			return 'range' !== ( $attrs['style'] ?? '' );
		}
		return self::TYPE_TEXT === $type;
	}

	/**
	 * Whether a field's label is decoration rather than the visitor's only
	 * naming of it — true only in placeholder mode, and only for a type whose
	 * input can actually carry a placeholder.
	 *
	 * A date, a choice and a range slider take no placeholder, so their label is
	 * the whole name whatever the mode says. The renderer and the editor ask the
	 * same question, so they ask it here.
	 *
	 * @param string               $mode  One of self::LABEL_MODE_* .
	 * @param string               $type  One of self::TYPE_* .
	 * @param array<string,string> $attrs The field's narrowed attributes.
	 * @return bool
	 */
	public static function label_is_decorative( string $mode, string $type, array $attrs ): bool {
		return self::LABEL_MODE_PLACEHOLDER === $mode && self::accepts_placeholder( $type, $attrs );
	}

	/**
	 * Why a field fetch failed, from the error code and status Api\Client
	 * reported. A 404 is the one answer that means the list itself is gone
	 * rather than momentarily out of reach, and that distinction decides
	 * whether the plugin alarms or waits.
	 *
	 * @param string $code   The WP_Error code Api\Client returned.
	 * @param int    $status The HTTP status it carried, 0 when there was none.
	 * @return string One of self::FETCH_* .
	 */
	public static function classify_fetch_error( string $code, int $status ): string {
		if ( 'wynko_no_key' === $code ) {
			return self::FETCH_NO_KEY;
		}
		if ( 404 === $status ) {
			return self::FETCH_GONE;
		}
		return self::FETCH_UNREACHABLE;
	}

	/**
	 * Whether a type can be filled in for the visitor at all.
	 *
	 * A date cannot: anything a date input does not recognize renders as an empty
	 * box. This gates apply() as well as the editor's control, so "a date has no
	 * default" is true wherever it is asked.
	 *
	 * @param string $type One of self::TYPE_* .
	 * @return bool
	 */
	public static function accepts_default_value( string $type ): bool {
		return self::TYPE_DATE !== $type;
	}

	/**
	 * The value a range slider starts at when nothing was typed for it.
	 *
	 * A range always has a value: with none given the browser puts the thumb at
	 * the midpoint of its bounds. The number shown beside the slider has to agree
	 * before anyone touches it, so the rule is computed rather than left to the
	 * browser.
	 *
	 * @param array<string,string> $attrs The field's narrowed attributes.
	 * @return string
	 */
	public static function range_default( array $attrs ): string {
		$min = self::text( $attrs['min'] ?? '' );
		$max = self::text( $attrs['max'] ?? '' );

		$min = is_numeric( $min ) ? (float) $min : 0.0;
		$max = is_numeric( $max ) ? (float) $max : 100.0;

		// The standard's own rule for a maximum below the minimum: the range is
		// empty and the value is the minimum.
		$value = $max < $min ? $min : $min + ( ( $max - $min ) / 2 );

		return (string) ( floor( $value ) === $value ? (int) $value : $value );
	}

	/**
	 * Why a row's default value cannot stand, or null when it can.
	 *
	 * Classification only, with the wording left to the caller that refuses the
	 * save. Only a number is checked, being the one type whose default can
	 * contradict bounds the editor collects.
	 *
	 * @param array<string,mixed> $row  Normalized override row.
	 * @param string              $type One of self::TYPE_* .
	 * @return string|null One of self::DEFAULT_* .
	 */
	public static function default_value_error( array $row, string $type ): ?string {
		$value = self::text( $row['value'] ?? '' );
		if ( self::TYPE_NUMBER !== $type || '' === $value ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_NOT_A_NUMBER;
		}

		$attrs = ( isset( $row['attrs'] ) && is_array( $row['attrs'] ) ) ? $row['attrs'] : array();
		$min   = self::text( $attrs['min'] ?? '' );
		$max   = self::text( $attrs['max'] ?? '' );

		if ( '' !== $max && is_numeric( $max ) && (float) $value > (float) $max ) {
			return self::DEFAULT_ABOVE_MAX;
		}
		if ( '' !== $min && is_numeric( $min ) && (float) $value < (float) $min ) {
			return self::DEFAULT_BELOW_MIN;
		}

		return null;
	}
}
