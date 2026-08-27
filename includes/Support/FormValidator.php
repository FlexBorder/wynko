<?php
/**
 * Signup submission validation.
 *
 * @package Wynko
 */

namespace Wynko\Support;

use DateTimeImmutable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-free validation of one submission against the live field
 * definitions. This is the security boundary: the front end's required/type
 * attributes are a convenience and prove nothing.
 */
final class FormValidator {

	const ERR_REQUIRED        = 'required';
	const ERR_INVALID_EMAIL   = 'invalid_email';
	const ERR_INVALID_VALUE   = 'invalid_value';
	const ERR_PATTERN         = 'pattern';
	const ERR_TERMS_UNCHECKED = 'terms_unchecked';

	const KEY_EMAIL = 'email';
	const KEY_TERMS = 'terms';

	/**
	 * Validates a submission. The email is always required: Laposta's /member
	 * endpoint requires it regardless of how the list's fields are configured.
	 *
	 * @param array<int,array<string,mixed>> $field_defs     Merged definitions, filtered to visible.
	 * @param array<string,mixed>            $submitted      Values keyed by custom_name, plus KEY_EMAIL and KEY_TERMS.
	 * @param bool                           $terms_required Whether the terms checkbox is in play.
	 * @return array<string,string> Empty on success; else key => self::ERR_* .
	 */
	public static function validate( array $field_defs, array $submitted, bool $terms_required ): array {
		$errors = array();

		$email = isset( $submitted[ self::KEY_EMAIL ] ) && is_scalar( $submitted[ self::KEY_EMAIL ] ) ? trim( (string) $submitted[ self::KEY_EMAIL ] ) : '';
		if ( '' === $email ) {
			$errors[ self::KEY_EMAIL ] = self::ERR_REQUIRED;
		} elseif ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors[ self::KEY_EMAIL ] = self::ERR_INVALID_EMAIL;
		}

		foreach ( $field_defs as $def ) {
			$key   = (string) $def['custom_name'];
			$error = self::check( $def, $submitted[ $key ] ?? null );
			if ( '' !== $error ) {
				$errors[ $key ] = $error;
			}
		}

		if ( $terms_required && empty( $submitted[ self::KEY_TERMS ] ) ) {
			$errors[ self::KEY_TERMS ] = self::ERR_TERMS_UNCHECKED;
		}

		return $errors;
	}

	/**
	 * Puts an error code into the message-slug vocabulary, so a form's custom
	 * wording covers a local failure the same way it covers a Laposta one.
	 *
	 * @param string $error One of self::ERR_* .
	 * @return string One of LapostaErrors::SLUG_* .
	 */
	public static function slug_for( string $error ): string {
		$map = array(
			self::ERR_REQUIRED        => LapostaErrors::SLUG_REQUIRED,
			self::ERR_INVALID_EMAIL   => LapostaErrors::SLUG_INVALID_EMAIL,
			self::ERR_INVALID_VALUE   => LapostaErrors::SLUG_INVALID_VALUE,
			self::ERR_PATTERN         => LapostaErrors::SLUG_PATTERN,
			self::ERR_TERMS_UNCHECKED => LapostaErrors::SLUG_TERMS,
		);
		return $map[ $error ] ?? LapostaErrors::SLUG_GENERIC;
	}

	/**
	 * Checks one field's value, '' when it is acceptable.
	 *
	 * @param array<string,mixed> $def   Merged field definition.
	 * @param mixed               $value Submitted value, null when absent.
	 * @return string
	 */
	private static function check( array $def, $value ): string {
		$empty = self::is_empty( $value );

		if ( $empty ) {
			return ! empty( $def['required'] ) ? self::ERR_REQUIRED : '';
		}

		if ( ! empty( $def['multiple'] ) ) {
			if ( ! is_array( $value ) ) {
				return self::ERR_INVALID_VALUE;
			}
			foreach ( $value as $one ) {
				if ( ! is_scalar( $one ) || ! in_array( (string) $one, $def['options'], true ) ) {
					return self::ERR_INVALID_VALUE;
				}
			}
			return '';
		}

		if ( ! is_scalar( $value ) ) {
			return self::ERR_INVALID_VALUE;
		}
		$value = trim( (string) $value );

		$attrs = ( isset( $def['attrs'] ) && is_array( $def['attrs'] ) ) ? $def['attrs'] : array();

		switch ( $def['type'] ) {
			case Fields::TYPE_NUMBER:
				if ( ! is_numeric( $value ) ) {
					return self::ERR_INVALID_VALUE;
				}
				return self::within_number_bounds( (float) $value, $attrs ) ? '' : self::ERR_INVALID_VALUE;
			case Fields::TYPE_DATE:
				if ( ! self::is_date( $value ) ) {
					return self::ERR_INVALID_VALUE;
				}
				return self::within_date_bounds( $value, $attrs ) ? '' : self::ERR_INVALID_VALUE;
			case Fields::TYPE_CHOICE:
				return in_array( $value, $def['options'], true ) ? '' : self::ERR_INVALID_VALUE;
			default:
				return self::check_text( $value, $attrs );
		}
	}

	/**
	 * Whether a text value satisfies the length and pattern the administrator
	 * configured, '' when it does. A pattern failure has its own error code,
	 * because it is the one constraint the administrator can describe in words
	 * for the renderer to show.
	 *
	 * @param string               $value Submitted text.
	 * @param array<string,string> $attrs Configured attributes.
	 * @return string
	 */
	private static function check_text( string $value, array $attrs ): string {
		$length = mb_strlen( $value );

		$min = isset( $attrs['minlength'] ) ? (int) $attrs['minlength'] : 0;
		if ( $min > 0 && $length < $min ) {
			return self::ERR_INVALID_VALUE;
		}

		$max = isset( $attrs['maxlength'] ) ? (int) $attrs['maxlength'] : 0;
		if ( $max > 0 && $length > $max ) {
			return self::ERR_INVALID_VALUE;
		}

		$pattern = isset( $attrs['pattern'] ) ? (string) $attrs['pattern'] : '';
		if ( '' !== $pattern && ! Fields::pattern_matches( $pattern, $value ) ) {
			return self::ERR_PATTERN;
		}

		return '';
	}

	/**
	 * Whether a number sits inside the bounds the administrator configured.
	 *
	 * These bounds are the plugin's rather than Laposta's, so a value Laposta
	 * would accept can be refused here. The rendered min/max only makes an
	 * out-of-range value hard to submit from a browser, never impossible.
	 *
	 * @param float                $value Submitted number.
	 * @param array<string,string> $attrs Configured attributes.
	 * @return bool
	 */
	private static function within_number_bounds( float $value, array $attrs ): bool {
		if ( isset( $attrs['min'] ) && is_numeric( $attrs['min'] ) && $value < (float) $attrs['min'] ) {
			return false;
		}
		if ( isset( $attrs['max'] ) && is_numeric( $attrs['max'] ) && $value > (float) $attrs['max'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a Y-m-d date sits inside the configured range. String comparison
	 * is exact for this format, and both ends have already been proven to be
	 * real dates by the caller.
	 *
	 * @param string               $value Submitted date, already validated.
	 * @param array<string,string> $attrs Configured attributes.
	 * @return bool
	 */
	private static function within_date_bounds( string $value, array $attrs ): bool {
		if ( isset( $attrs['min'] ) && self::is_date( $attrs['min'] ) && $value < $attrs['min'] ) {
			return false;
		}
		if ( isset( $attrs['max'] ) && self::is_date( $attrs['max'] ) && $value > $attrs['max'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a submitted value counts as "not filled in". An empty array and
	 * a whitespace-only string both do; the integer 0 does not.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	private static function is_empty( $value ): bool {
		if ( null === $value ) {
			return true;
		}
		if ( is_array( $value ) ) {
			return array() === $value;
		}
		return is_scalar( $value ) && '' === trim( (string) $value );
	}

	/**
	 * Whether a value is a real calendar date in Laposta's Y-m-d shape.
	 * createFromFormat alone accepts 1990-02-31 and rolls it forward, so the
	 * round-trip comparison is what rejects it.
	 *
	 * @param string $value Submitted value.
	 * @return bool
	 */
	private static function is_date( string $value ): bool {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}
}
