<?php
/**
 * REST route for public signup submissions.
 *
 * @package Wynko
 */

namespace Wynko\Rest;

use Wynko\Forms\FormData;
use Wynko\Frontend\FormRenderer;
use Wynko\Frontend\FormSubmitHandler;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The in-place counterpart to the wynko_submit_form admin-post action, public by
 * design and carrying the same protections because it runs the same code:
 * FormSubmitHandler verifies the form-scoped nonce before reading anything.
 *
 * The answer is markup rather than message slugs, since FormRenderer is the only
 * place a signup form's HTML is written. The redirect path is untouched and
 * remains the no-JavaScript path.
 */
final class SubmitController {

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			FieldsController::NAMESPACE_V1,
			'/forms/(?P<form_id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				// Public on purpose, since a visitor is not logged in: this
				// endpoint's gate is the form-scoped nonce process() checks
				// before reading anything else.
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
		$answer = self::answer( (array) $request->get_body_params() );

		return new WP_REST_Response( $answer, self::status_code( $answer['status'] ) );
	}

	/**
	 * The HTTP status for one outcome. Extracted from handle() so it is
	 * testable without a REST request.
	 *
	 * Only the two refusals get their own code. A rejected submission is a 200,
	 * because the browser needs to render the redisplayed form rather than treat
	 * it as an error.
	 *
	 * @param string $status One of FormSubmitHandler::STATUS_* .
	 * @return int
	 */
	public static function status_code( string $status ): int {
		$codes = array(
			FormSubmitHandler::STATUS_NOT_FOUND => 404,
			FormSubmitHandler::STATUS_THROTTLED => 429,
		);

		return $codes[ $status ] ?? 200;
	}

	/**
	 * Processes one submission and shapes the reply. Extracted from handle()
	 * so it is testable without a REST request.
	 *
	 * A bad nonce and an unknown form collapse to one answer, as
	 * FormSubmitHandler::handle() does, so a probe learns nothing.
	 *
	 * @param array<string,mixed> $raw Request body.
	 * @return array{status:string,redirect:string,html:string}
	 */
	public static function answer( array $raw ): array {
		$result = FormSubmitHandler::process( $raw );

		// A throttled request still renders the form back with its notice,
		// rather than an empty body: form.js treats empty html as a failed
		// request and falls back to posting the form again, which is the last
		// thing a throttled visitor should be made to do. The re-render reads
		// the list's fields from the cache that every rendering of this form
		// has already filled.

		if ( FormSubmitHandler::STATUS_NOT_FOUND === $result['status'] || FormSubmitHandler::STATUS_BAD_NONCE === $result['status'] ) {
			return array(
				'status'   => FormSubmitHandler::STATUS_NOT_FOUND,
				'redirect' => '',
				'html'     => '',
			);
		}

		$form_id  = (int) $result['form_id'];
		$form     = FormData::load( $form_id );
		$redirect = '';

		if ( null !== $form && FormSubmitHandler::STATUS_SUCCESS === $result['status'] ) {
			$redirect = $form->redirect_url();
		}

		return array(
			'status'   => (string) $result['status'],
			'redirect' => $redirect,
			// Nothing to render when the browser is about to leave the page.
			'html'     => '' !== $redirect ? '' : FormRenderer::render_with_result( $form_id, $result ),
		);
	}
}
