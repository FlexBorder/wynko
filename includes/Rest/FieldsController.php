<?php
/**
 * REST route for the editor's field list.
 *
 * @package Wynko
 */

namespace Wynko\Rest;

use Wynko\Admin\Forms\FieldRows;
use Wynko\Admin\Menu;
use Wynko\Api\Fields;
use Wynko\Forms\FormData;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the editor's field rows for an arbitrary list, so choosing a list shows
 * its fields without saving first. Read-only and administrator-only.
 *
 * The response is markup rather than data, because FieldRows is the single
 * source for the editor's field table and JSON would mean building it twice.
 */
final class FieldsController {

	const NAMESPACE_V1 = 'wynko/v1';

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/forms/(?P<form_id>\d+)/fields',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'args'                => array(
					'form_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'list_id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'refresh' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);
	}

	/**
	 * Who may call this: the same capability that opens the editor screen.
	 *
	 * @return bool
	 */
	public static function can_edit(): bool {
		return current_user_can( Menu::CAP );
	}

	/**
	 * Answers the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$result = self::rows(
			absint( $request->get_param( 'form_id' ) ),
			sanitize_text_field( (string) $request->get_param( 'list_id' ) ),
			(bool) $request->get_param( 'refresh' )
		);

		return new WP_REST_Response( $result, $result['error'] ? 502 : 200 );
	}

	/**
	 * The rows for one list, with this form's stored opinions layered on.
	 * Extracted from handle() so it is testable without a REST request.
	 *
	 * A list the form is not bound to yet has no overrides that match, so a newly
	 * chosen list renders at its defaults. $refresh asks Laposta again for this
	 * one list without discarding what is cached for any other.
	 *
	 * @param int    $form_id Form post id.
	 * @param string $list_id Laposta list id.
	 * @param bool   $refresh Refetch this list's fields before rendering.
	 * @return array{html:string,error:bool}
	 */
	public static function rows( int $form_id, string $list_id, bool $refresh = false ): array {
		$form = FormData::load( $form_id );
		if ( null === $form || '' === $list_id ) {
			return array(
				'html'  => '',
				'error' => true,
			);
		}

		$definitions = Fields::for_list( $list_id, $refresh );
		if ( $definitions['error'] ) {
			return array(
				'html'  => '',
				'error' => true,
			);
		}

		// The whole table, not just its body: the editor's container may be
		// empty (a form with no list bound yet), so there is not always a body
		// to swap. The signup button's row comes along with it and is redrawn
		// from stored meta, which is why the editor confirms before swapping
		// over unsaved work.
		return array(
			'html'  => FieldRows::table( $form->fields( $definitions['fields'] ), $form->button(), (string) $form->settings()['label_mode'] ),
			'error' => false,
		);
	}
}
