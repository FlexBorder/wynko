<?php
/**
 * Minimal stand-in for the HTML Forms plugin's own Submission class,
 * declared in the HTML_Forms namespace to match where the real plugin
 * declares it.
 *
 * @package Wynko
 */

namespace HTML_Forms;

if ( ! class_exists( '\HTML_Forms\Submission', false ) ) {
	/** Only what HtmlFormsIntegration::maybe_subscribe() reads. */
	class Submission {

		/** @var array<string,mixed> */
		public $data = array();

		/** @var string */
		public $ip_address = '';
	}
}
