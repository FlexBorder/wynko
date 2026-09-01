<?php
/**
 * Collects registered integrations.
 *
 * @package Wynko
 */

namespace Wynko\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The only place `wynko_register_integrations` is applied. */
final class Registry {

	/**
	 * Every registered integration, keyed by slug. Registering an integration
	 * requires being an active plugin or theme — a trust level that already
	 * grants arbitrary PHP execution on the site, so no new privilege is
	 * created by collecting whatever this filter hands back.
	 *
	 * @return array<string,Integration>
	 */
	public static function all(): array {
		/**
		 * Filters the list of registered integrations.
		 *
		 * Any plugin or theme — including Wynko's own bundled code, from
		 * Wynko\Integrations::register_bundled() — appends an object
		 * implementing Wynko\Integrations\Integration.
		 *
		 * @since 1.2.0
		 * @param array<int,mixed> $integrations Registered integrations so far.
		 */
		$raw = (array) apply_filters( 'wynko_register_integrations', array() );

		$by_slug = array();
		foreach ( $raw as $candidate ) {
			if ( ! $candidate instanceof Integration ) {
				continue;
			}

			$slug = $candidate->slug();
			if ( '' === $slug || isset( $by_slug[ $slug ] ) ) {
				continue;
			}

			$by_slug[ $slug ] = $candidate;
		}

		return $by_slug;
	}
}
