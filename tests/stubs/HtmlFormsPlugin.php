<?php
/**
 * Minimal stand-in for the HTML Forms plugin's own Forms class, declared in
 * the HTML_Forms namespace to match where the real plugin declares it —
 * needed so HtmlFormsIntegration's own class_exists( '\HTML_Forms\Forms' )
 * check sees it.
 *
 * @package Wynko
 */

namespace HTML_Forms;

if ( ! class_exists( '\HTML_Forms\Forms', false ) ) {
	/** Only what HtmlFormsIntegration::is_available() checks for. */
	class Forms {
	}
}
