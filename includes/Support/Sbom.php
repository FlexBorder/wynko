<?php
/**
 * CycloneDX component reader.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-free reader for a CycloneDX 1.6 document. It takes the
 * document's contents rather than a path, since reading a file is the caller's
 * concern.
 *
 * Every failure mode degrades to "no components", because an inventory the
 * plugin cannot read is not one it should guess at.
 */
final class Sbom {

	/**
	 * Reads the components from one CycloneDX document.
	 *
	 * @param string $json Document contents.
	 * @return array<int,array{name:string,version:string,license:string}>
	 */
	public static function components( string $json ): array {
		$document = json_decode( $json, true );
		if ( ! is_array( $document ) || ! isset( $document['components'] ) || ! is_array( $document['components'] ) ) {
			return array();
		}

		$components = array();
		foreach ( $document['components'] as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$name = trim( (string) ( $component['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$components[] = array(
				'name'    => $name,
				'version' => trim( (string) ( $component['version'] ?? '' ) ),
				'license' => self::license( $component ),
			);
		}

		return $components;
	}

	/**
	 * The first licence a component declares, as an SPDX id or expression.
	 *
	 * @param array<string,mixed> $component One CycloneDX component.
	 * @return string
	 */
	private static function license( array $component ): string {
		if ( ! isset( $component['licenses'] ) || ! is_array( $component['licenses'] ) ) {
			return '';
		}

		foreach ( $component['licenses'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( isset( $entry['expression'] ) && is_string( $entry['expression'] ) ) {
				return trim( $entry['expression'] );
			}
			if ( isset( $entry['license']['id'] ) && is_string( $entry['license']['id'] ) ) {
				return trim( $entry['license']['id'] );
			}
			if ( isset( $entry['license']['name'] ) && is_string( $entry['license']['name'] ) ) {
				return trim( $entry['license']['name'] );
			}
		}

		return '';
	}
}
