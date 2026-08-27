<?php
/**
 * Message slugs and Laposta error classification.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-free classification. It owns the message-slug vocabulary
 * shared by a form's _wynko_messages meta, FormValidator and the front-end
 * renderer, so an admin's custom wording covers both a Laposta rejection and a
 * local validation failure.
 *
 * It returns a slug, never a sentence; the prose lives in Forms\Messages.
 */
final class LapostaErrors {

	const SLUG_SUCCESS       = 'success';
	const SLUG_GENERIC       = 'error_generic';
	const SLUG_DUPLICATE     = 'error_duplicate';
	const SLUG_INVALID_EMAIL = 'error_invalid_email';
	const SLUG_REQUIRED      = 'error_required';
	const SLUG_INVALID_VALUE = 'error_invalid_value';
	const SLUG_PATTERN       = 'error_pattern';
	const SLUG_TERMS         = 'error_terms';

	/**
	 * Documented Laposta error code => slug. 203 (unknown parameter) and 210
	 * (malformed JSON) are our bugs, not the visitor's, so they land on the
	 * generic message rather than blaming a field they typed.
	 *
	 * @var array<int,string>
	 */
	private const CODES = array(
		201 => self::SLUG_REQUIRED,
		202 => self::SLUG_INVALID_VALUE,
		203 => self::SLUG_GENERIC,
		204 => self::SLUG_DUPLICATE,
		205 => self::SLUG_INVALID_VALUE,
		206 => self::SLUG_INVALID_VALUE,
		207 => self::SLUG_INVALID_VALUE,
		208 => self::SLUG_INVALID_EMAIL,
		209 => self::SLUG_INVALID_VALUE,
		210 => self::SLUG_GENERIC,
		999 => self::SLUG_GENERIC,
	);

	/**
	 * Every message slug a form can carry custom wording for.
	 *
	 * @return array<int,string>
	 */
	public static function slugs(): array {
		return array(
			self::SLUG_SUCCESS,
			self::SLUG_GENERIC,
			self::SLUG_DUPLICATE,
			self::SLUG_INVALID_EMAIL,
			self::SLUG_REQUIRED,
			self::SLUG_INVALID_VALUE,
			self::SLUG_PATTERN,
			self::SLUG_TERMS,
		);
	}

	/**
	 * Classifies one documented Laposta error code.
	 *
	 * @param int $code Laposta error code.
	 * @return string One of self::SLUG_* .
	 */
	public static function slug_for_code( int $code ): string {
		return self::CODES[ $code ] ?? self::SLUG_GENERIC;
	}

	/**
	 * Classifies a decoded {"error":{...}} payload.
	 *
	 * @param array<string,mixed> $error Decoded error object.
	 * @return string One of self::SLUG_* .
	 */
	public static function slug_for_error( array $error ): string {
		$code = $error['code'] ?? null;
		if ( ! is_numeric( $code ) ) {
			return self::SLUG_GENERIC;
		}
		return self::slug_for_code( (int) $code );
	}

	/**
	 * Whether a failure means the plugin's cached field definitions have fallen
	 * behind Laposta's. Three shapes count, all of them the plugin's problem
	 * rather than the visitor's:
	 *
	 * - 203, "unknown parameter": a field the plugin sent and Laposta no longer
	 *   has.
	 * - 201, "required field missing", naming a field the form does not show.
	 *   FormValidator has already passed on every field the plugin knows about,
	 *   so this can only mean Laposta requires something never rendered.
	 * - A 400 carrying no code, or one this plugin does not know.
	 *
	 * A 201 that does name a shown field is excluded, so a local validation gap
	 * never spends an API call on a pointless refetch.
	 *
	 * @param array<string,mixed> $data  WP_Error data from Api\Client::request().
	 * @param array<int,string>   $shown The field names the form rendered and sent.
	 * @return bool
	 */
	public static function is_field_drift( array $data, array $shown = array() ): bool {
		if ( 400 !== self::status_of( $data ) ) {
			return false;
		}

		$code = $data['code'] ?? null;
		if ( ! is_numeric( $code ) ) {
			return true;
		}
		$code = (int) $code;

		if ( 201 === $code ) {
			$parameter = isset( $data['parameter'] ) ? (string) $data['parameter'] : '';
			return '' === $parameter || ! in_array( $parameter, $shown, true );
		}

		return 203 === $code || ! isset( self::CODES[ $code ] );
	}

	/**
	 * The HTTP status an Api\Client error carried, or 0 when it carried none.
	 * Kept beside the code classification because both read the same data
	 * payload, and a caller deciding what a failure means needs both.
	 *
	 * @param array<string,mixed> $data WP_Error data from Api\Client::request().
	 * @return int
	 */
	public static function status_of( array $data ): int {
		return isset( $data['http_status'] ) && is_numeric( $data['http_status'] ) ? (int) $data['http_status'] : 0;
	}
}
