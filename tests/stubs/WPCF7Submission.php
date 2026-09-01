<?php
/**
 * Minimal stand-in for Contact Form 7's WPCF7_Submission singleton, declared
 * in the global namespace to match where the real plugin declares it.
 *
 * @package Wynko
 */

if ( ! class_exists( 'WPCF7_Submission', false ) ) {
	/** Only what ContactForm7Integration::maybe_subscribe() reads. */
	class WPCF7_Submission {

		/** @var self|null */
		private static $instance = null;

		/** @var array<string,mixed> */
		private $posted = array();

		/** @var string */
		private $ip = '';

		/**
		 * Sets the singleton this test run's submission returns. Test helper
		 * only — the real class populates itself from the actual POST.
		 *
		 * @param array<string,mixed> $posted Posted field values.
		 * @param string              $ip     Submitting IP.
		 * @return void
		 */
		public static function set_test_instance( array $posted, string $ip ): void {
			$submission         = new self();
			$submission->posted = $posted;
			$submission->ip     = $ip;
			self::$instance     = $submission;
		}

		/**
		 * Test helper only.
		 *
		 * @return void
		 */
		public static function clear_test_instance(): void {
			self::$instance = null;
		}

		/**
		 * @return self|null
		 */
		public static function get_instance() {
			return self::$instance;
		}

		/**
		 * @return array<string,mixed>
		 */
		public function get_posted_data(): array {
			return $this->posted;
		}

		/**
		 * @param string $key Meta key, e.g. 'remote_ip'.
		 * @return mixed
		 */
		public function get_meta( string $key ) {
			return 'remote_ip' === $key ? $this->ip : null;
		}
	}
}
