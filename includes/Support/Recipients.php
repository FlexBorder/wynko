<?php
/**
 * Alert recipient list parsing.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a typed address list into a clean array and back. Pure and
 * WordPress-free: it decides shape, never validity — whether an address is
 * deliverable is is_email()'s verdict, and the caller's to ask for.
 */
final class Recipients {

	/**
	 * Splits a typed list into candidate addresses.
	 *
	 * Semicolons and newlines split as well as commas: a list pasted out of a
	 * mail client arrives with all three, and rejecting the paste helps nobody.
	 *
	 * @param string $raw Typed list.
	 * @param int    $max Maximum addresses to return.
	 * @return array<int,string>
	 */
	public static function parse( string $raw, int $max ): array {
		if ( $max <= 0 ) {
			return array();
		}

		$found  = array();
		$seen   = array();
		$pieces = preg_split( '/[,;\r\n]+/', $raw );
		foreach ( is_array( $pieces ) ? $pieces : array() as $piece ) {
			$address = trim( $piece );
			$key     = strtolower( $address );
			if ( '' === $address || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$found[]      = $address;
			if ( count( $found ) === $max ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * Renders a list back into the one separator the field documents.
	 *
	 * @param array<int,string> $addresses Addresses.
	 * @return string
	 */
	public static function join( array $addresses ): string {
		return implode( ', ', $addresses );
	}
}
