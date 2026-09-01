<?php
/**
 * Minimal stand-in for Contact Form 7's WPCF7_ContactForm, declared in the
 * global namespace to match where the real plugin declares it — needed so
 * ContactForm7Integration's own class_exists( 'WPCF7_ContactForm' ) checks
 * see it.
 *
 * @package Wynko
 */

if ( ! class_exists( 'WPCF7_ContactForm', false ) ) {
	/** Only what ContactForm7Integration reads: an id and its email field. */
	class WPCF7_ContactForm {

		/** @var int */
		private $id;

		/** @var string */
		private $email_field_name;

		/** @var array<int,string>|null */
		private $declared_fields;

		/**
		 * @param int                     $id               Form post id.
		 * @param string                  $email_field_name Name of this form's email-type field, '' for none.
		 * @param array<int,string>|null  $declared_fields  Field names this form's template declares, for the
		 *                                                   unfiltered scan_form_tags() call declared_field_names()
		 *                                                   makes. Defaults to whatever the active test submission
		 *                                                   posted — standing in for a form that declares every
		 *                                                   field it received, the shape most of these tests
		 *                                                   exercise. Pass an explicit (smaller) list to test a
		 *                                                   field the submission carries but the form's own
		 *                                                   template never declared.
		 */
		public function __construct( int $id, string $email_field_name = 'your-email', ?array $declared_fields = null ) {
			$this->id               = $id;
			$this->email_field_name = $email_field_name;
			$this->declared_fields  = $declared_fields;
		}

		/**
		 * @return int
		 */
		public function id(): int {
			return $this->id;
		}

		/**
		 * Stands in for CF7's own tag scan: `array( 'basetype' => 'email' )`
		 * returns only the email-type tag, matching real CF7; any other call
		 * (including the unfiltered one declared_field_names() makes) returns
		 * every declared field.
		 *
		 * @param array<string,mixed> $cond Scan condition.
		 * @return array<int,object>
		 */
		public function scan_form_tags( array $cond = array() ): array {
			if ( 'email' === ( $cond['basetype'] ?? '' ) ) {
				return '' !== $this->email_field_name ? array( (object) array( 'name' => $this->email_field_name ) ) : array();
			}

			$names = $this->declared_fields ?? self::posted_field_names();
			if ( '' !== $this->email_field_name && ! in_array( $this->email_field_name, $names, true ) ) {
				$names[] = $this->email_field_name;
			}

			return array_map(
				static function ( string $name ): object {
					return (object) array( 'name' => $name );
				},
				$names
			);
		}

		/**
		 * @return array<int,string>
		 */
		private static function posted_field_names(): array {
			$submission = WPCF7_Submission::get_instance();
			return null !== $submission ? array_keys( $submission->get_posted_data() ) : array();
		}
	}
}
