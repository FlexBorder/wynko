<?php
/**
 * Built-in wording for the signup button.
 *
 * @package Wynko
 */

namespace Wynko\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The signup button is an element of the form, not one of its Laposta fields:
 * nothing about it comes from the API, so its wording and its class are the
 * administrator's alone. What is stored is only what they changed — an empty
 * label means the wording below, which is translated and so cannot live in
 * config/settings.php with the rest of the defaults.
 */
final class Button {

	/**
	 * The wording a form shows when the administrator set none.
	 *
	 * @return string
	 */
	public static function default_label(): string {
		return __( 'Subscribe', 'wynko-for-laposta' );
	}

	/**
	 * The button a form actually renders.
	 *
	 * @param FormData $form One form.
	 * @return array{label:string,css_class:string}
	 */
	public static function resolve( FormData $form ): array {
		$button = $form->button();
		$label  = trim( $button['label'] );

		return array(
			'label'     => '' !== $label ? $label : self::default_label(),
			'css_class' => $button['css_class'],
		);
	}
}
