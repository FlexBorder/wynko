<?php
/**
 * Response headers for the plugin's REST namespace.
 *
 * @package Wynko
 */

namespace Wynko\Rest;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks every reply on wynko/v1 as uncacheable.
 *
 * Core sends no cache headers to a logged-out caller, and the public submit
 * route answers one with the form re-rendered around their own values and a
 * fresh nonce — which a proxy or page cache would serve to the next visitor. The
 * values come from core's wp_get_nocache_headers(), so there is one definition
 * of what "do not cache this" means.
 */
final class Headers {

	/**
	 * Registers the filter. Called on rest_api_init, so it is not added on
	 * requests that will never dispatch a route.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_post_dispatch', array( self::class, 'filter' ), 10, 3 );
	}

	/**
	 * Adds the headers to one dispatched response.
	 *
	 * Core hands this filter a WP_REST_Response, but an earlier-priority filter
	 * from another plugin may not; anything else passes through untouched.
	 *
	 * @param mixed           $response Dispatch result, usually WP_REST_Response.
	 * @param mixed           $server   Server instance, unused.
	 * @param WP_REST_Request $request  The request that was dispatched.
	 * @return mixed
	 */
	public static function filter( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! self::is_ours( (string) $request->get_route() ) ) {
			return $response;
		}

		foreach ( self::nocache() as $key => $value ) {
			$response->header( $key, $value );
		}

		return $response;
	}

	/**
	 * Whether a route belongs to this plugin. Matched against the namespace
	 * with its separator so a future `/wynko/v1x/…` cannot pass, plus the
	 * namespace index itself.
	 *
	 * @param string $route Dispatched route.
	 * @return bool
	 */
	public static function is_ours( string $route ): bool {
		$namespace = '/' . FieldsController::NAMESPACE_V1;

		return $route === $namespace || 0 === strpos( $route, $namespace . '/' );
	}

	/**
	 * Core's no-cache headers, minus the ones it signals by an empty value —
	 * those mean "remove this header", which is not something a response's
	 * header bag can express, and sending them would emit an empty header.
	 *
	 * @return array<string,string>
	 */
	public static function nocache(): array {
		$headers = array();

		foreach ( wp_get_nocache_headers() as $key => $value ) {
			if ( '' !== (string) $value ) {
				$headers[ (string) $key ] = (string) $value;
			}
		}

		return $headers;
	}
}
