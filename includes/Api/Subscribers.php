<?php
/**
 * Member resource.
 *
 * @package Wynko
 */

namespace Wynko\Api;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Laposta /member endpoint — the plugin's first write. Transport,
 * authentication, and error handling are Client's; this class only builds the
 * request body.
 */
final class Subscribers {

	/**
	 * Creates a member on a list.
	 *
	 * Every optional parameter is omitted rather than sent empty. That matters
	 * most for options.ignore_doubleoptin, a list-level Laposta setting that
	 * sending nothing leaves alone.
	 *
	 * @param string              $list_id       Laposta list id.
	 * @param string              $email         Subscriber email.
	 * @param string              $ip            Submitting IP, required by Laposta.
	 * @param string              $source_url    Page the form was submitted from, '' to omit.
	 * @param array<string,mixed> $custom_fields Values keyed by custom_name; multi-select values are arrays.
	 * @param bool                $skip_doi      Send ignore_doubleoptin for this form only.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function create( string $list_id, string $email, string $ip, string $source_url, array $custom_fields, bool $skip_doi ) {
		$body = array(
			'list_id' => $list_id,
			'email'   => $email,
			'ip'      => $ip,
		);

		if ( '' !== $source_url ) {
			$body['source_url'] = $source_url;
		}
		if ( array() !== $custom_fields ) {
			$body['custom_fields'] = $custom_fields;
		}
		if ( $skip_doi ) {
			$body['options'] = array( 'ignore_doubleoptin' => true );
		}

		/**
		 * Filters the request body for creating a Laposta subscriber.
		 *
		 * The lower-level twin of wynko_form_subscriber_data: this runs for
		 * every code path that writes a subscriber, not just the form UI.
		 *
		 * @since 1.1.0
		 * @param array<string,mixed> $body    The request body as built above.
		 * @param string              $list_id Laposta list id.
		 * @param string              $email   Subscriber email.
		 */
		$body = (array) apply_filters( 'wynko_subscriber_data', $body, $list_id, $email );

		return Client::request( 'POST', 'member', array( 'body' => $body ) );
	}
}
