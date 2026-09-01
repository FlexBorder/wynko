<?php
/**
 * REST route for refreshing a signup form's submit nonce.
 *
 * @package Wynko
 */

namespace Wynko\Rest;

use Wynko\Frontend\FormSubmitHandler;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets the in-place submit script recover from a nonce baked into a cached
 * page: form.js calls this after a submit answers not_found, to tell a stale
 * nonce apart from a form that genuinely no longer exists, without changing
 * what /submit itself reveals to a prober.
 *
 * Public and read-only by design. An anonymous visitor's nonce for a given
 * form-scoped action is already identical for every anonymous visitor and
 * already sitting in that form's public HTML; this route only gives a way to
 * fetch that same value live instead of trusting a possibly-cached copy.
 */
final class NonceController {

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			FieldsController::NAMESPACE_V1,
			'/forms/(?P<form_id>\d+)/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Answers the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::answer( absint( $request->get_param( 'form_id' ) ) ) );
	}

	/**
	 * Mints a live nonce for one form. Extracted from handle() so it is
	 * testable without a REST request.
	 *
	 * @param int $form_id Form post id.
	 * @return array{nonce:string}
	 */
	public static function answer( int $form_id ): array {
		return array( 'nonce' => wp_create_nonce( FormSubmitHandler::nonce_action( $form_id ) ) );
	}
}
