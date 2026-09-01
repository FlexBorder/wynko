<?php
/**
 * Field-set identity fingerprinting.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A stable hash of a field set's identity, so a rendered form can carry a
 * fingerprint of the fields it was built from and a later request can tell
 * whether Wynko's own view of those fields has since moved on — the signal
 * a page cache or CDN serving a stale copy of the form leaves behind.
 */
final class FieldFingerprint {

	/**
	 * Hashes which field ids exist and which are required, sorted by
	 * field_id before hashing so Laposta's own return order — not a
	 * documented contract — cannot cause a spurious mismatch. A field's
	 * label, type, or option list is deliberately left out: this answers
	 * "does a field exist that the rendered form doesn't know about," not
	 * "did anything about a field change."
	 *
	 * @param array<int,array<string,mixed>> $fields Normalized field definitions.
	 * @return string SHA-256 hex digest.
	 */
	public static function of( array $fields ): string {
		$rows = array();
		foreach ( $fields as $field ) {
			$id = isset( $field['field_id'] ) ? (string) $field['field_id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$rows[ $id ] = empty( $field['required'] ) ? '0' : '1';
		}
		ksort( $rows, SORT_STRING );

		$parts = array();
		foreach ( $rows as $id => $required ) {
			$parts[] = $id . ':' . $required;
		}

		return hash( 'sha256', implode( '|', $parts ) );
	}
}
