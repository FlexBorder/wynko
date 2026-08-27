<?php
/**
 * Laposta API transport.
 *
 * @package Wynko
 */

namespace Wynko\Api;

use Wynko\ApiKey;
use Wynko\Config;
use Wynko\Support\Sanitizer;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP transport for the Laposta API, called by the resource classes rather than
 * by anything talking to WordPress HTTP directly. It only ever calls paths under
 * the fixed Config::api_base() host.
 */
final class Client {

	/**
	 * Builds the HTTP Basic authorization header for a key.
	 *
	 * @param string $key Raw API key.
	 * @return string
	 */
	public static function auth_header( string $key ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth requires base64-encoded credentials, not obfuscation.
		return 'Basic ' . base64_encode( $key . ':' );
	}

	/**
	 * Puts wording on a classified HTTP status. New status semantics belong in
	 * Sanitizer::classify_status(); their prose belongs here.
	 *
	 * @param int $status HTTP status code.
	 * @return string
	 */
	public static function status_message( int $status ): string {
		$class = Sanitizer::classify_status( $status );
		if ( Sanitizer::STATUS_INVALID_KEY === $class ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Invalid API key (HTTP %d)', 'wynko-for-laposta' ), $status );
		}
		if ( Sanitizer::STATUS_RATE_LIMITED === $class ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Laposta is rate limiting this site (HTTP %d); requests will succeed again shortly.', 'wynko-for-laposta' ), $status );
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Unexpected response from Laposta (HTTP %d)', 'wynko-for-laposta' ), $status );
	}

	/**
	 * Performs a request against the Laposta API and decodes the JSON response.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Path relative to Config::api_base().
	 * @param array<string,mixed> $args   Optional 'key' (API key override) and 'body'.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function request( string $method, string $path, array $args = array() ) {
		$key = isset( $args['key'] ) ? (string) $args['key'] : ApiKey::resolve();
		if ( '' === trim( $key ) ) {
			return new WP_Error( 'wynko_no_key', __( 'No API key configured.', 'wynko-for-laposta' ) );
		}

		$request_args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 15,
			'headers' => array(
				'Authorization' => self::auth_header( $key ),
				'Accept'        => 'application/json',
			),
		);
		if ( isset( $args['body'] ) ) {
			$request_args['body'] = $args['body'];
		}

		$response = wp_remote_request( Config::api_base() . '/' . ltrim( $path, '/' ), $request_args );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: underlying HTTP error message. */
			return new WP_Error( 'wynko_http', sprintf( __( 'Could not connect to Laposta: %s', 'wynko-for-laposta' ), $response->get_error_message() ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			// Laposta reports why in the body of a 4xx. The decoded error object
			// travels as the WP_Error's data so Support\LapostaErrors can
			// classify it, and the status travels beside it so callers can tell
			// a 400 from a 404 without matching a translated message.
			$error = ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) ? $decoded['error'] : array();
			return new WP_Error(
				'wynko_status',
				self::status_message( $status ),
				array_merge( array( 'http_status' => $status ), $error )
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'wynko_parse', __( 'Could not parse the Laposta response.', 'wynko-for-laposta' ) );
		}

		return $decoded;
	}
}
