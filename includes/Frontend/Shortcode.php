<?php
/**
 * Signup-form shortcode.
 *
 * @package Wynko
 */

namespace Wynko\Frontend;

use Wynko\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** [wynko_form id="123"] — a thin wrapper over FormRenderer. */
final class Shortcode {

	/**
	 * Registers the shortcode under its configured tag.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( Config::form_shortcode(), array( self::class, 'render' ) );
	}

	/**
	 * Renders the form named by the id attribute.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts( array( 'id' => '' ), is_array( $atts ) ? $atts : array(), Config::form_shortcode() );

		return FormRenderer::render( absint( $atts['id'] ) );
	}
}
